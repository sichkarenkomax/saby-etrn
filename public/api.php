<?php

declare(strict_types=1);

/**
 * API приложения: только read-only вызовы SABY.
 *
 * GET ?action=etrn_list            — последние ЭТрН (ConsignmentNote) из SABY.
 * GET ?action=etrn_details&id=UUID — титульные данные одной ЭТрН (водитель, груз, адреса).
 *
 * Сессия SABY берётся из PHP-сессии приложения (та же вкладка браузера).
 * Никаких созданий/изменений документов.
 */

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/saby.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// settings_* разрешают POST; остальные — только GET (read-only)
// -------------------------------------------------------------

$action = (string)($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'GET'
    && !($action === 'settings_set' && $_SERVER['REQUEST_METHOD'] === 'POST')) {
    jsonOut(['ok' => false, 'error' => 'Только GET-запросы.'], 405);
}

// settings_* не требуют SABY-сессии
if (in_array($action, ['settings_get', 'settings_set'], true)) {

    if ($action === 'settings_get') {

        $file = __DIR__ . '/../storage/settings.json';

        $settings = is_file($file)
            ? (json_decode((string)file_get_contents($file), true) ?: [])
            : [];

        $defaults = [
            'filter' => ['mode' => 'all', 'stages' => [], 'stageMode' => 'include', 'fields' => []],
            'autoSync' => ['enabled' => false, 'intervalMin' => 15],
            'bitrixWebhook' => '',
            'log' => ['maxBytes' => 5 * 1024 * 1024],
            'notify' => ['responsibleId' => 0, 'onFail' => true, 'onAuthFail' => true, 'onUnmatched' => true, 'skipIds' => [], 'workdayOnly' => false],
        ];

        $merged = array_merge($defaults, $settings);
        // notify/log — мердж на 2 уровня, чтобы новые ключи (onAuthFail и др.) получали дефолты
        $merged['notify'] = array_merge($defaults['notify'], (array)($merged['notify'] ?? []));
        $merged['log'] = array_merge($defaults['log'], (array)($merged['log'] ?? []));
        $merged['autoSync'] = array_merge($defaults['autoSync'], (array)($merged['autoSync'] ?? []));

        // Вебхук — секрет: через API не отдаём
        if (!empty($merged['bitrixWebhook'])) {
            $merged['bitrixWebhook'] = '***set***';
        }

        jsonOut([
            'ok'       => true,
            'settings' => $merged,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $raw  = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            jsonOut(['ok' => false, 'error' => 'Некорректный JSON настроек.'], 400);
        }

        // Прежние настройки: сохраняем bitrixWebhook, если в POST его нет
        // (поле убрано из UI коробочной версии; cron_sync.php требует его)
        $file = __DIR__ . '/../storage/settings.json';
        $prev = is_file($file)
            ? (json_decode((string)file_get_contents($file), true) ?: [])
            : [];

        $clean = [
            'filter' => [
                'mode'      => in_array($data['filter']['mode'] ?? 'all', ['all', 'custom'], true) ? ($data['filter']['mode'] ?? 'all') : 'all',
                'stageMode' => in_array($data['filter']['stageMode'] ?? 'include', ['include', 'exclude'], true) ? ($data['filter']['stageMode'] ?? 'include') : 'include',
                'stages'    => array_slice(array_map('strval', (array)($data['filter']['stages'] ?? [])), 0, 50),
                'fields'    => array_slice(array_values(array_filter((array)($data['filter']['fields'] ?? []), static fn ($f) => is_array($f) && isset($f['field'], $f['op']))), 0, 20),
            ],
            'autoSync' => [
                'enabled'    => (bool)($data['autoSync']['enabled'] ?? false),
                'intervalMin' => max(5, min(1440, (int)($data['autoSync']['intervalMin'] ?? 15))),
            ],
            'bitrixWebhook' => (isset($data['bitrixWebhook']) && trim((string)$data['bitrixWebhook']) !== '***set***')
                ? trim((string)$data['bitrixWebhook'])
                : (string)($prev['bitrixWebhook'] ?? ''),
            'log' => [
                'maxBytes' => max(1024 * 1024, min(50 * 1024 * 1024,
                    (int)($data['log']['maxBytes'] ?? (int)($prev['log']['maxBytes'] ?? 5 * 1024 * 1024)))),
                'channels' => [
                    'interval' => (bool)($data['log']['channels']['interval'] ?? (bool)($prev['log']['channels']['interval'] ?? true)),
                    'sync' => (bool)($data['log']['channels']['sync'] ?? (bool)($prev['log']['channels']['sync'] ?? true)),
                    'activity' => (bool)($data['log']['channels']['activity'] ?? (bool)($prev['log']['channels']['activity'] ?? true)),
                    'notify' => (bool)($data['log']['channels']['notify'] ?? (bool)($prev['log']['channels']['notify'] ?? true)),
                ],
            ],
            'notify' => [
                'responsibleId' => max(0, (int)($data['notify']['responsibleId'] ?? (int)($prev['notify']['responsibleId'] ?? 0))),
                'onFail' => (bool)($data['notify']['onFail'] ?? (bool)($prev['notify']['onFail'] ?? true)),
                'onAuthFail' => (bool)($data['notify']['onAuthFail'] ?? (bool)($prev['notify']['onAuthFail'] ?? true)),
                'onUnmatched' => (bool)($data['notify']['onUnmatched'] ?? (bool)($prev['notify']['onUnmatched'] ?? true)),
                'skipIds' => array_slice(array_map('strval', (array)($data['notify']['skipIds'] ?? ($prev['notify']['skipIds'] ?? []))), 0, 200),
                'workdayOnly' => (bool)($data['notify']['workdayOnly'] ?? (bool)($prev['notify']['workdayOnly'] ?? false)),
            ],
        ];

        $file = __DIR__ . '/../storage/settings.json';

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0770, true);
        }

        $written = @file_put_contents($file, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($written === false) {
            jsonOut(['ok' => false, 'error' => 'Не удалось записать настройки (storage/).'], 500);
        }

        @chmod($file, 0660);

        jsonOut(['ok' => true, 'settings' => $clean]);
    }

    jsonOut(['ok' => false, 'error' => 'settings_set требует POST.'], 405);
}

// -------------------------------------------------------------
// action: cron_install / cron_remove / cron_status —
// управление crontab коробочного портала (работает только self-hosted)
// -------------------------------------------------------------

if (in_array($action, ['cron_install', 'cron_remove', 'cron_status'], true)) {

    // Строка cron, которую обслуживает приложение
    $cronMarker = 'saby-etrn-cron';
    $secretFile = __DIR__ . '/../storage/cron_secret.txt';

    if (!is_file($secretFile)) {
        $secret = bin2hex(random_bytes(16));
        @file_put_contents($secretFile, $secret);
        @chmod($secretFile, 0664);
    } else {
        $secret = trim((string)file_get_contents($secretFile));
    }

    if ($secret === '') {
        $secret = bin2hex(random_bytes(16));
        @file_put_contents($secretFile, $secret);
        @chmod($secretFile, 0664);
    }

    $scheme = 'https';
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));

    // URL синхронизации — БЕЗ домена приложения-файлы могут лежать где угодно;
    // для коробки стандартный путь: https://<host>/local/saby-etrn/public/cron_sync.php
    $appUrl = trim((string)($_SESSION['SABY_ETRN']['APP_URL'] ?? ''));

    if ($appUrl === '') {
        // Определяем по текущему пути (приложение живёт в /local/saby-etrn)
        $appUrl = $scheme . '://' . $host . '/local/saby-etrn/public/cron_sync.php';
    }

    $cronLine = '*/5 * * * * curl -s "' . $appUrl . '?secret=' . $secret . '" > /dev/null 2>&1 #' . $cronMarker;

    // exec доступен?
    if (!function_exists('exec')) {
        jsonOut(['ok' => false, 'error' => 'Функция exec() недоступна на этом портале (облако или запрет). Установите cron вручную: ' . $cronLine]);
    }

    // Текущий crontab
    $current = [];
    exec('crontab -l 2>/dev/null', $current, $rc);

    if ($rc !== 0 && $rc !== 1) {
        jsonOut(['ok' => false, 'error' => 'crontab недоступен (код ' . $rc . '). Вероятно, это облачный портал — cron-запись нужно добавить на сервере хостинга приложения вручную.', 'cronLine' => $cronLine]);
    }

    $installed = false;

    foreach ($current as $l) {
        if (strpos($l, $cronMarker) !== false) {
            $installed = true;
            break;
        }
    }

    $lines = array_values(array_filter($current, static fn ($l) => trim($l) !== '' && strpos($l, $cronMarker) === false));

    if ($action === 'cron_install') {
        $lines[] = $cronLine;
    }

    // Записываем crontab
    $tmpFile = tempnam(sys_get_temp_dir(), 'crontab');
    @file_put_contents($tmpFile, implode("\n", $lines) . "\n");

    exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $rc2);
    @unlink($tmpFile);

    if ($rc2 !== 0) {
        jsonOut(['ok' => false, 'error' => 'Не удалось записать crontab: ' . implode('; ', $out)]);
    }

    jsonOut([
        'ok'          => true,
        'action'      => $action,
        'cronLine'    => $cronLine,
        'installed'   => $action === 'cron_install' ? true : $installed,
        'crontabLineCount' => count($current),
    ]);
}

// -------------------------------------------------------------
// SABY-сессия (не нужна для log_get)
// -------------------------------------------------------------

$appSession  = $_SESSION['SABY_ETRN'] ?? [];
$sabySession = (string)($appSession['SABY_API_SESSION'] ?? '');

// Общая сессия SABY: если пользователь не авторизовывался лично — используем общий токен (saby_token.json)
if ($sabySession === '' && $action !== 'log_get') {
    $tokenFile = __DIR__ . '/../storage/saby_token.json';
    if (is_file($tokenFile)) {
        $t = json_decode((string)file_get_contents($tokenFile), true) ?: [];
        $sabySession = (string)($t['session'] ?? '');
    }
}

if ($sabySession === '' && $action !== 'log_get') {
    jsonOut(['ok' => false, 'error' => 'SABY не подключён. Авторизуйтесь во вкладке "Подключение".'], 401);
}

// -------------------------------------------------------------
// action: etrn_list — полный список ЭТрН кабинета (вкл. «в работе»)
// -------------------------------------------------------------

if ($action === 'log_get') {

    require_once __DIR__ . '/../includes/applog.php';

    $bytes = max(1000, min(200000, (int)($_GET['bytes'] ?? 20000)));

    jsonOut([
        'ok'      => true,
        'log'     => appLogTail($bytes),
        'channels' => appLogChannels(),
        'maxBytes' => appLogMaxBytes(),
        'size'    => is_file(appLogFilePath()) ? filesize(appLogFilePath()) : 0,
    ]);
}

if ($action === 'smartprocess_list') {

    // Список смарт-процессов — через вебхук (от имени админа): у некоторых пользователей
    // crm.type.list из браузера даёт ACCESS_DENIED
    $file = __DIR__ . '/../storage/settings.json';
    $settings = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $hook = rtrim((string)($settings['bitrixWebhook'] ?? ''), '/');

    if ($hook === '') {
        jsonOut(['ok' => false, 'error' => 'Не настроен вебхук Bitrix24'], 500);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => '',
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $raw = @file_get_contents($hook . '/crm.type.list.json', false, $ctx);

    if ($raw === false || $raw === '') {
        jsonOut(['ok' => false, 'error' => 'Б24 недоступен (crm.type.list)'], 502);
    }

    $r = json_decode($raw, true) ?: [];

    if (isset($r['error'])) {
        jsonOut(['ok' => false, 'error' => cleanMessage($r['error'] . ': ' . ($r['error_description'] ?? ''))], 502);
    }

    jsonOut([
        'ok'   => true,
        'types' => array_values(array_map(
            static fn ($t) => [
                'id'           => (string)($t['id'] ?? ''),
                'title'        => (string)($t['title'] ?? ''),
                'entityTypeId' => (int)($t['entityTypeId'] ?? 0),
            ],
            (array)($r['result']['types'] ?? [])
        )),
    ]);
}

if ($action === 'etrn_list') {

    try {

        $resp = sabyRequest(
            SABY_SERVICE_URL,
            'СБИС.СписокДокументов',
            [
                'Фильтр' => [
                    'Тип' => 'ConsignmentNote',
                ],
            ],
            $sabySession
        );

    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => cleanMessage($e->getMessage())], 502);
    }

    $documents = $resp['result']['Документ'] ?? [];
    if (!is_array($documents)) {
        $documents = [];
    }


    $list = [];

    foreach ($documents as $doc) {

        if (!is_array($doc)) {
            continue;
        }

        $id = (string)($doc['Идентификатор'] ?? '');
        if ($id === '') {
            continue;
        }

        $state      = $doc['Состояние'] ?? [];
        $transport  = $doc['КодПеревозки'] ?? [];
        $lastPhase  = is_array($transport) && $transport ? end($transport) : [];

        $stateCode = (string)($state['Код'] ?? '');

        // Завершённые документы (код 7) старше 1 дня — не показываем
        $cutoff = time() - 86400;
        $phaseTs = 0;
        $phaseDateRaw = (string)($lastPhase['ДатаВремя'] ?? '');
        if ($phaseDateRaw !== '') {
            $ts = DateTime::createFromFormat('d.m.Y H.i.s', $phaseDateRaw);
            $phaseTs = $ts ? $ts->getTimestamp() : 0;
        } else {
            $phaseTs = 0;
        }
        $dateTs = DateTime::createFromFormat('d.m.Y', (string)($doc['Дата'] ?? ''));
        $dateTsVal = $dateTs ? $dateTs->getTimestamp() : 0;

        // Завершён: считаем актуальным время последней фазы, если нет — дату документа
        $actualTs = $phaseTs ?: $dateTsVal;
        if ($stateCode === '7' && $actualTs > 0 && $actualTs < $cutoff) {
            continue;
        }

        $counterparty = $doc['Контрагент']['СвЮЛ'] ?? [];
        $our          = $doc['НашаОрганизация']['СвЮЛ'] ?? [];

        $innCounterparty = (string)($counterparty['ИНН'] ?? '');
        $innOur          = (string)($our['ИНН'] ?? '');
        $innOur        = OUR_INN;

        // Наша организация всегда один из двух. Партнёр — другой.
        $partnerInn = ($innOur !== $innOur) ? $innOur : $innCounterparty;

        $list[] = [
            'id'          => $id,
            'gisUid'      => (string)($doc['ГИС_УИД'] ?? ''),
            'number'      => (string)($doc['Номер'] ?? ''),
            'date'        => (string)($doc['Дата'] ?? ''),
            'title'       => (string)($doc['Название'] ?? ''),
            'direction'   => (string)($doc['Направление'] ?? ''),
            'counterpartyInn'  => $innCounterparty,
            'counterpartyName' => (string)($counterparty['Название'] ?? ''),
            'ourInn'      => $innOur,
            'ourName'     => (string)($our['Название'] ?? ''),
            'partnerInn'  => $partnerInn,
            'stateCode'   => (string)($state['Код'] ?? ''),
            'stateName'   => (string)($state['Название'] ?? ''),
            'phaseCode'   => (string)($lastPhase['Код'] ?? ''),
            'phaseName'   => (string)($lastPhase['НазваниеПерехода'] ?? ''),
            'phaseTitle'  => (string)($lastPhase['НазваниеФазы'] ?? ''),
            'phaseDate'   => (string)($lastPhase['ДатаВремя'] ?? ''),
            'cabinetUrl'  => (string)($doc['СсылкаДляНашаОрганизация'] ?? ''),
            'pdfUrl'      => (string)($doc['СсылкаНаPDF'] ?? ''),
        ];
    }

    // Свежие сверху
    usort($list, static fn (array $a, array $b): int => strcmp($b['date'] . $b['phaseDate'], $a['date'] . $a['phaseDate']));

    jsonOut([
        'ok'      => true,
        'count'   => count($list),
        'etrn'    => $list,
    ]);
}

// -------------------------------------------------------------
// action: etrn_probe — временная проба сигнатур СписокДокументов для ТН
// -------------------------------------------------------------

if ($action === 'etrn_probe') {

    $probes = [];
    $variants = [
        'ТрН прямой'        => ['Фильтр' => ['Тип' => 'ТрН']],
        'ТрН Направление'   => ['Фильтр' => ['Тип' => 'ТрН', 'Направление' => 'Входящий']],
        'ConsignmentNote'   => ['Фильтр' => ['Тип' => 'ConsignmentNote']],
        'СписокДок НомТрН'  => ['Фильтр' => ['Документ' => ['ТипДок' => 'ТрН']]],
        'Параметр+Фильтр'   => ['Параметр' => ['Фильтр' => ['Тип' => 'ТрН']]],
        'Навигация'         => ['Фильтр' => ['Тип' => 'ТрН'], 'Навигация' => ['РазмерСтраницы' => 25, 'Страница' => 0]],
    ];

    foreach ($variants as $name => $params) {
        try {
            $r = sabyRequest(SABY_SERVICE_URL, 'СБИС.СписокДокументов', $params, $sabySession);
            $docs = $r['result']['Документ'] ?? $r['result'] ?? [];
            $probes[$name] = [
                'ok'     => true,
                'count'  => is_array($docs) ? count($docs) : 0,
                'sample' => array_slice(is_array($docs) ? $docs : [], 0, 3),
            ];
        } catch (Throwable $e) {
            $probes[$name] = ['ok' => false, 'error' => cleanMessage($e->getMessage())];
        }
    }

    jsonOut(['ok' => true, 'probes' => $probes]);
}

// -------------------------------------------------------------
// action: etrn_details — титульные данные одной ЭТрН
// -------------------------------------------------------------

if ($action === 'etrn_details') {

    $docId = (string)($_GET['id'] ?? '');

    if ($docId === '' || !preg_match('/^[a-f0-9-]{36}$/i', $docId)) {
        jsonOut(['ok' => false, 'error' => 'Не указан корректный id документа.'], 400);
    }

    try {

        $resp = sabyRequest(
            SABY_SERVICE_URL,
            'СБИС.ПрочитатьДокумент',
            [
                'Документ' => [
                    'Идентификатор' => $docId,
                ],
            ],
            $sabySession
        );

    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => cleanMessage($e->getMessage())], 502);
    }

    $document = $resp['result'] ?? [];
    if (!is_array($document)) {
        $document = [];
    }

    // Ссылка на XML титула грузоотправителя (первое вложение без is_sign)
    $fileUrl = '';
    $fileAttId = '';

    foreach ($document['Вложение'] ?? [] as $att) {
        $url = (string)($att['Файл']['Ссылка'] ?? '');
        if ($url !== '' && strpos($url, 'is_sign=true') === false) {
            $fileUrl = $url;
            $fileAttId = (string)($att['Файл']['Идентификатор'] ?? '');
            break;
        }
    }

    // Качаем XML с реальной сессией SABY
    $fields = [];
    $parseError = '';

    if ($fileUrl !== '') {
        try {
            $fields = parseEtrnTitleXml($fileUrl, $sabySession);
        } catch (Throwable $e) {
            $parseError = cleanMessage($e->getMessage());
        }
    }

    jsonOut([
        'ok'         => true,
        'id'         => $docId,
        'attachment' => $fileAttId,
        'fields'     => $fields,
        'parseError' => $parseError,
    ]);
}

jsonOut(['ok' => false, 'error' => 'Неизвестное действие.'], 400);


// =========================================================
// Разбор формализованного XML титула ЭТрН
// =========================================================

/**
 * Скачивает XML вложения (с реальной SABY-сессией) и извлекает
 * поля титула грузоотправителя: перевозчик, водители, груз, адреса.
 */
function parseEtrnTitleXml(string $url, string $sabySession): array
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "X-SBISSessionID: {$sabySession}\r\nUser-Agent: Saby-Etrn-Bitrix24-Integration/1.0\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $xml = @file_get_contents($url, false, $context);

    if ($xml === false) {
        throw new RuntimeException('Не удалось скачать XML вложения: ' . cleanMessage((string)(error_get_last()['message'] ?? '')));
    }

    // --- отладка: полный XML в файл (для разбора структуры) ---
    @file_put_contents(
        sys_get_temp_dir() . '/saby_title_dump.xml',
        $xml
    );

    // Кодировку НЕ конвертируем вручную: в XML заявлена WINDOWS-1251,
    // DOMDocument сам переведёт в UTF-8 (iconv). Ручная mb-конвертация ломала строку.

    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        throw new RuntimeException('XML вложения не разобран.');
    }

    // --- отладка: полный XML в файл (для разбора структуры) ---
    @file_put_contents(
        sys_get_temp_dir() . '/saby_title_dump.xml',
        $xml
    );

    // XPath-выборка полей титула
    $xp = new DOMXPath($doc);

    $q = static function (string $query) use ($xp): string {
        $n = $xp->query($query)?->item(0);
        return $n ? trim($n->nodeValue ?? '') : '';
    };

    $qa = static function (string $query, string $attr) use ($xp): string {
        $n = $xp->query($query)?->item(0);
        return $n ? trim($n->attributes?->getNamedItem($attr)?->nodeValue ?? '') : '';
    };

    // Водитель
    $fioFamily = $qa('//СвВодит/ФИО', 'Фамилия');
    $fioName   = $qa('//СвВодит/ФИО', 'Имя');
    $fioPatr   = $qa('//СвВодит/ФИО', 'Отчество');
    $driverName = implode(' ', array_filter([$fioFamily, $fioName, $fioPatr]));

    // Перевозчик: ЮЛ или ИП
    $carrierName = '';
    $carrierInn  = '';
    $carrierExtra = '';

    $nЮЛ = $xp->query('//СвПер/ИдСв/СвЮЛУч')?->item(0);
    $nИП = $xp->query('//СвПер/ИдСв/СвИП')?->item(0);

    if ($nЮЛ) {
        $carrierName = $nЮЛ->attributes?->getNamedItem('НаимОрг')?->nodeValue ?? '';
        $carrierInn  = $nЮЛ->attributes?->getNamedItem('ИННЮЛ')?->nodeValue ?? '';
        $carrierExtra .= 'КПП: ' . ($nЮЛ->attributes?->getNamedItem('КПП')?->nodeValue ?? '') . '; ';
    } elseif ($nИП) {
        $fam = $nИП->getElementsByTagName('ФИО')->item(0)?->attributes;
        $carrierName = implode(' ', array_filter([
            $fam?->getNamedItem('Фамилия')?->nodeValue,
            $fam?->getNamedItem('Имя')?->nodeValue,
            $fam?->getNamedItem('Отчество')?->nodeValue,
        ]));
        $carrierName .= ' (ИП)';
        $carrierInn  = $nИП->attributes?->getNamedItem('ИННФЛ')?->nodeValue ?? '';
        $carrierExtra .= 'ОГРНИП: ' . ($nИП->attributes?->getNamedItem('ОГРНИП')?->nodeValue ?? '') . '; ';
    }

    $carrierPhone = $q('//СвПер/Контакт/Тлф');
    $carrierAddr  = $qa('//СвПер/Адрес/АдресИнф', 'АдрТекст');
    $carrierExtra .= 'ИНН: ' . $carrierInn . ($carrierAddr ? '; адрес: ' . $carrierAddr : '') . ($carrierPhone ? '; тел.: ' . $carrierPhone : '');

    // Груз: несколько строк ОпГруз — товары через запятую, масса/объём/места суммируются
    $goodsList   = [];
    $weightSum   = 0.0;
    $sizeSum     = 0.0;
    $qtySum      = 0.0;

    foreach ($xp->query('//СвГруз/ОпГруз') as $row) {

        $name = trim($row->attributes?->getNamedItem('НаимГруз')?->nodeValue ?? '');
        if ($name !== '') {
            $goodsList[] = $name;
        }

        $m = $row->getElementsByTagName('ПлМасГруз')->item(0)?->attributes?->getNamedItem('МасБрутЗнач')?->nodeValue ?? '';
        $v = $row->attributes?->getNamedItem('Объем')?->nodeValue ?? '';
        $c = $row->attributes?->getNamedItem('КолМестГр')?->nodeValue ?? '';

        $weightSum += (float)str_replace(',', '.', $m);
        $sizeSum   += (float)str_replace(',', '.', $v);
        $qtySum    += (float)str_replace(',', '.', $c);
    }

    $fmtNum = static function (float $x): string {
        $s = rtrim(rtrim(number_format($x, 3, '.', ''), '0'), '.');
        return $s === '' ? '' : $s;
    };

    $fields = [
        'carrierName'  => $carrierName,
        'carrierInn'   => $carrierInn,
        'carrierPhone' => $carrierPhone,
        'carrierRequisites' => trim($carrierExtra, '; '),
        'driverName'   => $driverName,
        'driverPhone'  => $q('//СвВодит/Тлф'),
        'dep'          => $qa('//СвПогруз/ФАдресПогр/АдресИнф', 'АдрТекст'),
        'dest'         => $qa('//СвГП/АдресДостГр/АдресИнф', 'АдрТекст'),
        'goods'        => implode(', ', $goodsList),
        'weight'       => $fmtNum($weightSum),
        'size'         => $fmtNum($sizeSum),
        'quantity'     => $fmtNum($qtySum),
    ];

    return $fields;
}