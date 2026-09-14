<?php

declare(strict_types=1);

/**
 * Создание ЭТрН в SABY.
 *
 * ЭТрН — формализованный документ: создаётся отправкой XML-титула
 * грузоотправителя (TRNACLGROT, формат 5.01) как вложения.
 * Вызывается СБИС.ЗаписатьДокумент с Документ.Вложение[].Файл.ДвоичныеДанные (base64).
 *
 * Идемпотентность: ВнешнийИдентификатор.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/saby.php';
require_once __DIR__ . '/etrn_title.php';
require_once __DIR__ . '/saby_members.php';

/**
 * Создаёт ЭТрН в SABY.
 *
 * Вход: массив полей из бизнес-процесса + SABY-сессия.
 * Выход: ['ok' => bool, 'sabyId' => string, 'error' => string, 'raw' => ...]
 */
function sabyCreateEtrn(string $sabySession, array $input): array
{
    // --- Валидация обязательных полей ---

    $receiverInn = trim((string)($input['receiverInn'] ?? ''));

    // Черновик: создаём с тем, что есть. Жёстко требуем только получателя —
    // без него SABY не сможет адресовать документ.
    if ($receiverInn === '') {
        return ['ok' => false, 'error' => 'Не задан ИНН получателя (не удалось определить компанию).'];
    }

    $carrierInn = trim((string)($input['carrierInn'] ?? ''));
    $carrierName = trim((string)($input['carrierName'] ?? ''));

    // Остальное — мягко: недостающее уйдёт пустым, SABY создаст черновик с отметкой об ошибках
    $driverName = trim((string)($input['driverName'] ?? ''));

    $goods = trim((string)($input['goods'] ?? ''));
    $quantity = (int)($input['quantity'] ?? 0);

    $dep = trim((string)($input['dep'] ?? ''));
    $dest = trim((string)($input['dest'] ?? ''));

    // --- Идентификатор (идемпотентность) ---
    $externalId = trim((string)($input['externalId'] ?? ''));
    if ($externalId === '') {
        $externalId = 'bp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($input['bpDocumentId'] ?? '')) . '_' . time();
    }

    // --- ИдФайл титула: формат с идентификаторами участников ЭДО ---
    // ON_TRNACLGROT_<ИдПолучателя>_<ИдОтправителя>_<ИдПеревозчика>_<0>_<ГГГГММДД>_<GUID>
    $guid = strtolower(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)));

    $idReceiver = sabyMemberId($sabySession, $receiverInn, (string)($input['receiverKpp'] ?? ''));
    $idOur      = sabyMemberId($sabySession, OUR_INN, OUR_KPP);
    $idCarrier  = $carrierInn !== ''
        ? sabyMemberId($sabySession, $carrierInn, (string)($input['carrierKpp'] ?? ''))
        : '';

    // Порядок сегментов по живому образцу: ПЕРЕВОЗЧИК_ОТПРАВИТЕЛЬ(мы)_ПОЛУЧАТЕЛЬ
    $fileId = 'ON_TRNACLGROT'
        . ($idCarrier !== '' ? '_' . $idCarrier : '')
        . ($idOur !== '' ? '_' . $idOur : '')
        . ($idReceiver !== '' ? '_' . $idReceiver : '')
        . '_0_' . date('Ymd') . '_' . $guid;

    $fileName = $fileId . '.xml';

    // --- XML титул грузоотправителя ---
    $xmlTitle = etrnBuildTitleXml([
        'number'       => (string)($input['number'] ?? ''),
        'date'         => (string)($input['date'] ?? '') ?: date('d.m.Y'),
        'dep'          => $dep,
        'dest'         => $dest,
        'goods'        => $goods,
        'weight'       => (string)($input['weight'] ?? '0'),
        'size'         => (string)($input['size'] ?? '0'),
        'quantity'     => (string)$quantity,
        'senderInn'    => OUR_INN,
        'senderKpp'    => OUR_KPP,
        'senderFull'   => OUR_NAME_FULL,
        'receiverInn'  => $receiverInn,
        'receiverKpp'  => (string)($input['receiverKpp'] ?? ''),
        'receiverName' => (string)($input['receiverName'] ?? ''),
        'receiverFull' => mb_strtoupper((string)($input['receiverName'] ?? '')),
        'carrierInn'   => $carrierInn,
        'carrierKpp'   => (string)($input['carrierKpp'] ?? ''),
        'carrierName'  => $carrierName,
        'carrierPhone' => (string)($input['carrierPhone'] ?? ''),
        'driverName'   => $driverName,
        'driverPhone'  => (string)($input['driverPhone'] ?? ''),
        'signerName'   => (string)($input['signerName'] ?? ''),
        'idFile'       => $fileId,
    ]);

    // Логируем титул для отладки
    if (function_exists('appLog')) {
        @file_put_contents(sys_get_temp_dir() . '/etrn_last_title.xml', $xmlTitle);
    }

    $fileB64 = base64_encode($xmlTitle);

    // --- Структура ЗаписатьДокумент ---
    $params = [
        'Документ' => [
            'Тип'             => 'ConsignmentNote',
            'Регламент'       => ['Идентификатор' => 'ad40d873-9d1e-47d7-b7e9-f39636b36301'],
            'ВнешнийИдентификатор' => $externalId,
            'НашаОрганизация' => [
                'СвЮЛ' => [
                    'ИНН'      => OUR_INN,
                    'КПП'      => OUR_KPP,
                    'Название' => OUR_NAME_SHORT,
                ],
            ],
            'Контрагент'      => [
                'СвЮЛ' => array_filter([
                    'ИНН'      => $receiverInn,
                    'КПП'      => (string)($input['receiverKpp'] ?? ''),
                    'Название' => (string)($input['receiverName'] ?? '') ?: ('ИНН ' . $receiverInn),
                ], static fn ($v) => (string)$v !== ''),
            ],
            'Вложение'        => [
                [
                    // Без «Тип»/«Направление» — SABY формализует сам (иначе ВерсияФормата не заполняется)
                    'Файл' => [
                        'Имя'            => $fileName,
                        'ДвоичныеДанные' => $fileB64,
                    ],
                ],
            ],
        ],
    ];

    // --- Вызов SABY ---
    try {
        $resp = sabyRequest(
            SABY_SERVICE_URL,
            'СБИС.ЗаписатьДокумент',
            ['Документ' => $params['Документ']],
            $sabySession
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => cleanMessage($e->getMessage())];
    }

    $result = $resp['result'] ?? [];

    // Ответ: объект документа напрямую (ключи документа в result)
    $docId = (string)($result['Идентификатор'] ?? '');

    // Ошибки на черновике (во вложениях)
    $errors = [];
    foreach ((array)($result['Вложение'] ?? []) as $att) {
        if (is_array($att) && (int)($att['КоличествоОшибок'] ?? 0) > 0) {
            $errors[] = (string)($att['Ошибки'] ?? ('Ошибок: ' . $att['КоличествоОшибок']));
        }
    }

    if ($errors) {
        return ['ok' => false, 'error' => implode('; ', $errors), 'raw' => $result];
    }

    if ($docId === '') {
        return ['ok' => false, 'error' => 'SABY не вернул идентификатор документа.', 'raw' => $result];
    }

    return [
        'ok'     => true,
        'sabyId' => $docId,
        'number' => (string)($result['Номер'] ?? ($input['number'] ?? '')),
        'date'   => (string)($result['Дата'] ?? ($input['date'] ?? '')),
        'raw'    => $result,
    ];
}