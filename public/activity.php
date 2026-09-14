<?php

declare(strict_types=1);

/**
 * Активити бизнес-процесса: создание ЭТрН в SABY.
 *
 * Б24 отправляет POST на этот URL при запуске активити в бизнес-процессе.
 * Ответ (синхронный):
 *   {"result": {"sabyId": "...", "status": "created"}}
 *   или {"result": {"sabyId": "", "status": "error", "error": "..."}}
 */

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/saby_create.php';
require_once __DIR__ . '/../includes/b24_resolve.php';
require_once __DIR__ . '/../includes/applog.php';

header('Content-Type: application/json; charset=utf-8');

function actOut(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// b24_resolve.php использует b24Call — алиас на локальную функцию
function b24Call(string $webhook, string $method, array $params = []): array
{
    return actB24Call($webhook, $method, $params);
}

// -------------------------------------------------------------
// Bitrix24 REST через вебхук (копия b24Call из cron_sync.php)
// -------------------------------------------------------------

function actB24Call(string $webhook, string $method, array $params = []): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json; charset=utf-8\r\n",
            'content'       => json_encode($params, JSON_UNESCAPED_UNICODE),
            'timeout'       => 60,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $raw = @file_get_contents(rtrim($webhook, '/') . '/' . $method, false, $ctx);

    if ($raw === false) {
        throw new RuntimeException('Ошибка HTTP-запроса к Б24.');
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Б24 вернул некорректный JSON.');
    }

    if (isset($decoded['error'])) {
        throw new RuntimeException('Б24 ' . $method . ': ' . (string)($decoded['error_description'] ?? $decoded['error']));
    }

    return $decoded;
}

// -------------------------------------------------------------
// Только POST
// -------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    actOut(['result' => ['sabyId' => '', 'status' => 'error', 'error' => 'Только POST.']]);
}

// -------------------------------------------------------------
// Читаем вход от Б24 (JSON или form-data)
// Б24 шлёт form-urlencoded, параметры БП — JSON-строкой в поле "properties"
// -------------------------------------------------------------

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

// Формат Б24: properties = JSON-строка с параметрами активити
if (isset($data['properties']) && is_string($data['properties'])) {
    $props = json_decode((string)$data['properties'], true);

    if (is_array($props)) {
        $data = array_merge($data, $props);
    }
} elseif (isset($data['properties']) && is_array($data['properties'])) {
    $data = array_merge($data, $data['properties']);
}

// Токен бизнес-процесса (для bizproc.activity.send, если понадобится асинхронно)
$bpToken = (string)($data['token'] ?? '');

// Поля активити (задаются при регистрации)
$input = [
    'number'         => (string)($data['number'] ?? ''),
    'date'           => (string)($data['date'] ?? ''),
    'receiverInn'    => (string)($data['receiverInn'] ?? ''),
    'receiverName'   => (string)($data['receiverName'] ?? ''),
    'carrierInn'     => (string)($data['carrierInn'] ?? ''),
    'carrierName'    => (string)($data['carrierName'] ?? ''),
    'carrierPhone'   => (string)($data['carrierPhone'] ?? ''),
    'driverName'     => (string)($data['driverName'] ?? ''),
    'driverPhone'    => (string)($data['driverPhone'] ?? ''),
    'goods'          => (string)($data['goods'] ?? ''),
    'weight'         => (string)($data['weight'] ?? ''),
    'size'           => (string)($data['size'] ?? ''),
    'quantity'       => (string)($data['quantity'] ?? ''),
    'dep'            => (string)($data['dep'] ?? ''),
    'dest'           => (string)($data['dest'] ?? ''),
    'externalId'     => (string)($data['externalId'] ?? ''),
    'bpDocumentId'   => (string)($data['document_id'] ?? '') . '_' . (string)($data['document_id2'] ?? ''),
];

// -------------------------------------------------------------
// Резолвинг реквизитов по ID компании Б24
// (receiverCompanyId / carrierCompanyId — приоритет над ручными полями)
// -------------------------------------------------------------

$settingsFile = __DIR__ . '/../storage/settings.json';
$webhook = '';

if (is_file($settingsFile)) {
    $s = json_decode((string)file_get_contents($settingsFile), true) ?: [];
    $webhook = (string)($s['bitrixWebhook'] ?? '');
}

$receiverCompanyId = (string)($data['receiverCompanyId'] ?? '');
$carrierCompanyId  = (string)($data['carrierCompanyId'] ?? '');

if ($webhook === '' && ($receiverCompanyId !== '' || $carrierCompanyId !== '')) {
    actOut(['result' => ['sabyId' => '', 'status' => 'error', 'error' => 'Не задан вебхук Б24 (настройки приложения).']]);
}

if ($receiverCompanyId !== '') {

    $r = b24ResolveCompany($webhook, $receiverCompanyId);

    if ($r['inn'] === '') {
        actOut(['result' => ['sabyId' => '', 'status' => 'error', 'error' => 'У компании-получателя (ID ' . $receiverCompanyId . ') не заполнен ИНН в реквизитах.']]);
    }

    $input['receiverInn']  = $r['inn'];
    $input['receiverKpp']  = $r['kpp'] ?? '';
    $input['receiverName'] = $r['name'] ?: $input['receiverName'];
}

if ($carrierCompanyId !== '') {

    $r = b24ResolveCompany($webhook, $carrierCompanyId);

    if ($r['inn'] === '') {
        actOut(['result' => ['sabyId' => '', 'status' => 'error', 'error' => 'У компании-перевозчика (ID ' . $carrierCompanyId . ') не заполнен ИНН в реквизитах.']]);
    }

    $input['carrierInn']   = $r['inn'];
    $input['carrierKpp']   = $r['kpp'] ?? '';
    $input['carrierName']  = $r['name'] ?: $input['carrierName'];
    $input['carrierPhone'] = $input['carrierPhone'] ?: $r['phone'];
}

// -------------------------------------------------------------
// SABY-сессия: из файла (крон-механизм) или PHP-сессии
// -------------------------------------------------------------

$tokenFile = __DIR__ . '/../storage/saby_token.json';
$sabySession = '';

if (is_file($tokenFile)) {
    $t = json_decode((string)file_get_contents($tokenFile), true) ?: [];
    $sabySession = (string)($t['session'] ?? '');
}

if ($sabySession === '') {
    $appSession = $_SESSION['SABY_ETRN'] ?? [];
    $sabySession = (string)($appSession['SABY_API_SESSION'] ?? '');
}

if ($sabySession === '') {
    actOut(['result' => ['sabyId' => '', 'status' => 'error', 'error' => 'SABY не подключён. Авторизуйтесь в приложении.']]);
}

// -------------------------------------------------------------
// Создание ЭТрН
// -------------------------------------------------------------

appLog('INFO', 'Активити: старт создания ЭТрН.', [
    'вход'  => $data,
    'тип'   => $_SERVER['CONTENT_TYPE'] ?? '',
]);

$resp = sabyCreateEtrn($sabySession, $input);

if (!$resp['ok']) {

    appLog('ERROR', 'Активити: SABY отклонил создание. ' . (string)$resp['error'], [
        'raw' => $resp['raw'] ?? null,
    ]);

    actOut([
        'result' => [
            'sabyId' => '',
            'status' => 'error',
            'error'  => $resp['error'],
        ],
    ]);
}

appLog('INFO', 'Активити: ЭТрН создана в SABY.', [
    'sabyId' => $resp['sabyId'],
    'number' => $resp['number'] ?? '',
]);

actOut([
    'result' => [
        'sabyId'   => $resp['sabyId'],
        'sabyUrl'  => 'https://online.sbis.ru/opendoc.html?guid=' . $resp['sabyId'],
        'status'   => 'created',
    ],
]);