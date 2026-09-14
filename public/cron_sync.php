<?php

declare(strict_types=1);

/**
 * Автосинхронизация ЭТрН SABY → Б24 (для cron).
 *
 * Вызывается внешним планировщиком (crontab каждые 5 минут):
 *   cron_sync.php?secret=<storage/cron_secret.txt>
 *
 * Сам решает по settings.json: включена ли автосинхронизация,
 * прошёл ли интервал с последнего прогона, какие карточки фильтруются.
 * Логика идентична кнопке «Сопоставить и обновить» в приложении:
 * автопары по ИНН-паре, обновление статусов/полей, таймлайн-комментарии.
 *
 * Б24 доступ через входящий вебхук (bitrixWebhook в настройках).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/saby.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/applog.php';

header('Content-Type: application/json; charset=utf-8');

function cronOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$storageDir = __DIR__ . '/../storage';

function b24Notify(string $webhook, int $userId, string $title, string $message): void
{
    try {
        b24Call($webhook, 'im.notify', [
            'USER_ID'      => $userId,
            'NOTIFY_TITLE' => $title,
            'MESSAGE'      => $message,
        ]);
    } catch (Throwable $e) {
        appLog('WARN', 'Уведомление не отправлено: ' . cleanMessage($e->getMessage()), [], 'notify');
    }
}

// -------------------------------------------------------------
// Секрет
// -------------------------------------------------------------

$secretFile = $storageDir . '/cron_secret.txt';

if (!is_file($secretFile)) {
    cronOut(['ok' => false, 'error' => 'Секрет cron не создан. Откройте приложение (вкладка Подключение), чтобы сгенерировать.'], 403);
}

$secret = trim((string)file_get_contents($secretFile));

if (!hash_equals($secret, (string)($_GET['secret'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Неверный секрет.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------
// Настройки
// -------------------------------------------------------------

$settingsFile = $storageDir . '/settings.json';

$settings = is_file($settingsFile)
    ? (json_decode((string)file_get_contents($settingsFile), true) ?: [])
    : [];

$autoSync  = $settings['autoSync'] ?? ['enabled' => false, 'intervalMin' => 15];
$filter    = $settings['filter'] ?? ['mode' => 'all'];
$webhook   = (string)($settings['bitrixWebhook'] ?? '');

if (empty($autoSync['enabled'])) {
    appLog('INFO', 'Пропуск: автосинхронизация выключена в настройках.', [], 'sync');
    cronOut(['ok' => true, 'result' => 'Автосинхронизация выключена.', 'skipped' => true]);
}

if ($webhook === '') {
    appLog('ERROR', 'Не задан URL вебхука Б24 (bitrixWebhook в настройках).', [], 'sync');
    cronOut(['ok' => false, 'error' => 'Не задан URL вебхука Б24 (bitrixWebhook в настройках).']);
}

// Интервал: пропускаем, если с последнего прогона прошло меньше
$lockFile     = $storageDir . '/cron_last_run.txt';
$intervalMin  = max(5, min(1440, (int)($autoSync['intervalMin'] ?? 15)));
$lastRun      = is_file($lockFile) ? (int)file_get_contents($lockFile) : 0;
$now          = time();

if ($now - $lastRun < $intervalMin * 60) {
    appLog('INFO', 'Пропуск: интервал не истёк.', ['сек_прошло' => $now - $lastRun, 'интервал_сек' => $intervalMin * 60], 'interval');
    cronOut([
        'ok'      => true,
        'skipped' => true,
        'reason'  => 'Интервал ещё не прошёл (' . ($now - $lastRun) . 'с из ' . ($intervalMin * 60) . 'с)',
    ]);
}

@file_put_contents($lockFile, (string)$now);

appLog('INFO', 'Старт автосинхронизации.', ['intervalMin' => $intervalMin], 'sync');

// Перехват фатальных ошибок в журнал (cron молчал при падениях)
register_shutdown_function(static function () {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        appLog('FATAL', 'Фатальная ошибка cron_sync: ' . $e['message'] . ' в ' . $e['file'] . ':' . $e['line'], [], 'sync');
    }
});

// -------------------------------------------------------------
// SABY-токен
// -------------------------------------------------------------

$tokenFile = $storageDir . '/saby_token.json';

if (!is_file($tokenFile)) {
    appLog('ERROR', 'SABY-токен отсутствует. Авторизуйтесь в приложении.', [], 'sync');
    $ntfUid = (int)($settings['notify']['responsibleId'] ?? 0);
    if ($ntfUid > 0 && !empty($settings['notify']['onAuthFail'] ?? true)) {
        b24Notify($webhook, $ntfUid, 'SABY ЭТрН: авторизация SABY отвалилась', 'SABY-токен отсутствует. Открой приложение и выполните вход во вкладке «Подключение».');
    }
    cronOut(['ok' => false, 'error' => 'SABY-токен отсутствует. Авторизуйтесь в приложении.'], 401);
}

$tokenData = json_decode((string)file_get_contents($tokenFile), true) ?: [];
$sabySession = (string)($tokenData['session'] ?? '');

if ($sabySession === '') {
    appLog('ERROR', 'SABY-токен пуст. Авторизуйтесь в приложении.', [], 'sync');
    $ntfUid = (int)($settings['notify']['responsibleId'] ?? 0);
    if ($ntfUid > 0 && !empty($settings['notify']['onAuthFail'] ?? true)) {
        b24Notify($webhook, $ntfUid, 'SABY ЭТрН: авторизация SABY отвалилась', 'SABY-токен пуст. Открой приложение и выполните вход во вкладке «Подключение».');
    }
    cronOut(['ok' => false, 'error' => 'SABY-токен пуст. Авторизуйтесь в приложении.'], 401);
}

// -------------------------------------------------------------
// Авто-релогин при сбросе сессии
// -------------------------------------------------------------

function sabyAutoRelogin(): string
{
    $creds = sabyCreds();

    if (empty($creds['login']) || empty($creds['password'])) {
        return '';
    }

    $r = sabyRequest(
        SABY_AUTH_URL,
        'СБИС.Аутентифицировать',
        [
            'Логин'  => $creds['login'],
            'Пароль' => $creds['password'],
        ]
    );

    $newSession = '';

    if (is_string($r['result'] ?? null)) {
        $newSession = (string)$r['result'];
    } elseif (is_string($r['result']['SessionID'] ?? null)) {
        $newSession = (string)$r['result']['SessionID'];
    }

    if ($newSession !== '') {
        saveSabyTokenFile($newSession, (string)($creds['login'] ?? ''));
        appLog('INFO', 'Авто-релогин SABY выполнен.', [], 'sync');
        return $newSession;
    }

    appLog('ERROR', 'Авто-релогин SABY не удался.', [], 'sync');
    return '';
}

// Ошибка связана с авторизацией/сессией SABY?
function isSabyAuthError(string $msg): bool
{
    foreach (['не авторизован', 'авториз', 'сессия', 'session', 'токен пуст', 'логин', 'пароль'] as $needle) {
        if (stripos($msg, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function sabyCallWithRelogin(string $method, array $params, string &$sabySession): array
{
    try {
        return sabyRequest(SABY_SERVICE_URL, $method, $params, $sabySession);
    } catch (Throwable $e) {

        $msg = cleanMessage($e->getMessage());

        // Признаки сброса сессии — пробуем авто-релогин
        if (stripos($msg, 'не авторизован') !== false
            || stripos($msg, 'session') !== false
            || stripos($msg, 'сессия') !== false
            || stripos($msg, 'авториз')) {

            $newSession = sabyAutoRelogin();

            if ($newSession !== '') {
                appLog('INFO', 'Сессия SABY была сброшена, перелогинились и повторили запрос.', ['метод' => $method], 'sync');
                $sabySession = $newSession;
                return sabyRequest(SABY_SERVICE_URL, $method, $params, $sabySession);
            }
        }

        throw $e;
    }
}

// -------------------------------------------------------------
// Bitrix24 helper (REST через вебхук)
// -------------------------------------------------------------

function b24Call(string $webhook, string $method, array $params = []): array
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
        throw new RuntimeException('Ошибка HTTP-запроса к Б24: ' . cleanMessage((string)(error_get_last()['message'] ?? '')));
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

function passesFilter(array $item, array $filter): bool
{
    $mode = $filter['mode'] ?? 'all';

    if ($mode === 'all') {
        return true;
    }

    // Стадии
    $stages = array_map('strval', (array)($filter['stages'] ?? []));

    if ($stages) {
        $stage = (string)($item['stageId'] ?? '');
        $in    = in_array($stage, $stages, true);
        $mode2 = ($filter['stageMode'] ?? 'include') === 'include';

        if ($mode2 === 'include' && !$in) {
            return false;
        }
        if ($mode2 === 'exclude' && $in) {
            return false;
        }
    }

    // Поля
    foreach ((array)($filter['fields'] ?? []) as $f) {

        $field = (string)($f['field'] ?? '');
        $op    = (string)($f['op'] ?? '');
        $val   = (string)($f['value'] ?? '');

        if ($field === '') {
            continue;
        }

        $actual = is_array($item[$field] ?? null) ? implode('|', $item[$field]) : (string)($item[$field] ?? '');
        $actual = trim($actual);

        $ok = match ($op) {
            'eq'       => $actual === $val,
            'ne'       => $actual !== $val,
            'contains' => $val === '' ? true : (mb_stripos($actual, $val) !== false),
            'empty'    => $actual === '',
            'notempty' => $actual !== '',
            default    => true,
        };

        if (!$ok) {
            return false;
        }
    }

    return true;
}

// -------------------------------------------------------------
// 1. Список ЭТрН из SABY
// -------------------------------------------------------------

try {
    $resp = sabyCallWithRelogin(
        'СБИС.СписокДокументов',
        ['Фильтр' => ['Тип' => 'ConsignmentNote']],
        $sabySession
    );
} catch (Throwable $e) {
    $errMsg = cleanMessage($e->getMessage());
    appLog('ERROR', 'SABY: ' . $errMsg, ['метод' => 'СписокДокументов'], 'sync');
    $ntfUid = (int)($settings['notify']['responsibleId'] ?? 0);

    if ($ntfUid > 0 && isSabyAuthError($errMsg)) {
        // Отдельный тип: авторизация SABY отвалилась
        if (!empty($settings['notify']['onAuthFail'] ?? true)) {
            b24Notify($webhook, $ntfUid, 'SABY ЭТрН: авторизация SABY отвалилась', 'Не удалось авторизоваться в SABY (' . $errMsg . '). Открой приложение и выполните вход во вкладке «Подключение».');
        }
    } elseif ($ntfUid > 0 && !empty($settings['notify']['onFail'])) {
        b24Notify($webhook, $ntfUid, 'SABY ЭТрН: синхронизация не удалась', 'Ошибка SABY (СписокДокументов): ' . $errMsg);
    }
    cronOut(['ok' => false, 'error' => 'SABY: ' . $errMsg], 502);
}

$documents = $resp['result']['Документ'] ?? [];
if (!is_array($documents)) {
    $documents = [];
}

$sabyById = [];

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
    $counterparty = $doc['Контрагент']['СвЮЛ'] ?? [];
    $our          = $doc['НашаОрганизация']['СвЮЛ'] ?? [];

    // Время актуальности: последняя фаза, иначе дата документа (для отсева завершённых)
    $phaseTs = 0;
    $phaseDateRaw = (string)($lastPhase['ДатаВремя'] ?? '');
    if ($phaseDateRaw !== '') {
        $ts = DateTime::createFromFormat('d.m.Y H.i.s', $phaseDateRaw);
        $phaseTs = $ts ? $ts->getTimestamp() : 0;
    }
    $dateTs = DateTime::createFromFormat('d.m.Y', (string)($doc['Дата'] ?? ''));
    $actualTs = $phaseTs ?: ($dateTs ? $dateTs->getTimestamp() : 0);

    $innOur  = (string)($our['ИНН'] ?? '');
    $innCp   = (string)($counterparty['ИНН'] ?? '');

    $sabyById[$id] = [
        'id'         => $id,
        'actualTs'   => $actualTs,
        'number'     => (string)($doc['Номер'] ?? ''),
        'date'       => (string)($doc['Дата'] ?? ''),
        'stateCode'  => (string)($state['Код'] ?? ''),
        'stateName'  => (string)($state['Название'] ?? ''),
        'phaseName'  => (string)($lastPhase['НазваниеПерехода'] ?? ''),
        'phaseCode'  => (string)($lastPhase['Код'] ?? ''),
        'cabinetUrl' => (string)($doc['СсылкаДляНашаОрганизация'] ?? ''),
        'ourInn'     => $innOur,
        'partnerInn' => ($innOur !== OUR_INN) ? $innOur : $innCp,
    ];
}

// -------------------------------------------------------------
// 2. Карточки Б24 (все, с фильтром)
// -------------------------------------------------------------

$itemMapBySabyId = [];
$freeItems = [];

try {

    $start = 0;

    while (true) {

        $r = b24Call($webhook, 'crm.item.list', [
            'entityTypeId' => 1072,
            'start'        => $start,
        ]);

        $items = $r['result']['items'] ?? [];

        if (!$items) {
            break;
        }

        foreach ($items as $it) {

            if (!passesFilter($it, $filter)) {
                continue;
            }

            $sid = trim((string)($it['ufCrm19SabyId'] ?? ''));

            if ($sid !== '') {
                $itemMapBySabyId[$sid] = $it;
            } else {
                $freeItems[] = $it;
            }
        }

        if (count($items) < 50) {
            break;
        }

        $start += 50;
    }

} catch (Throwable $e) {
    cronOut(['ok' => false, 'error' => 'Б24: ' . cleanMessage($e->getMessage())], 502);
}

// -------------------------------------------------------------
// 3. Матчинг: автопары для свободных карточек по ИНН-паре
// -------------------------------------------------------------

// ИНН компаний карточек (реквизиты)
function getInnByCompany(string $webhook, $companyId): string
{
    static $cache = [];

    $key = (string)$companyId;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $r = b24Call($webhook, 'crm.requisite.list', [
            'filter' => ['ENTITY_TYPE_ID' => 4, 'ENTITY_ID' => (int)$companyId],
        ]);
        $reqs = $r['result'] ?? [];
        $first = is_array($reqs) ? ($reqs[0] ?? []) : [];
        $inn = (string)($first['RQ_INN'] ?? '');
    } catch (Throwable $e) {
        $inn = '';
    }

    $cache[$key] = $inn;

    return $inn;
}

$bound = 0;
$updated = 0;
$errors = [];
$justBoundIds = [];   // SABY ID, привязанные в этом прогоне

// ИНН-пара: сортированный список
function innPair(string ...$inns): string
{
    $arr = array_values(array_filter(array_map('trim', $inns), 'strlen'));
    sort($arr);

    return implode('|', $arr);
}

// Кэш ИНН компаний
$innCache = [];

foreach ($sabyById as $etrn) {

    // Уже привязана
    if (isset($itemMapBySabyId[$etrn['id']])) {
        continue;
    }

    $sabyPair = innPair($etrn['ourInn'], $etrn['partnerInn']);

    if ($sabyPair === '') {
        continue;
    }

    foreach ($freeItems as $idx => $it) {

        if (in_array((int)$it['id'], $usedItems, true)) {
            continue;
        }

        $senderId = (string)($it['ufCrm19Sender'] ?? '');
        $recvId   = (string)($it['ufCrm19Recepient'] ?? '');

        $senderKey = preg_replace('/[^0-9]/', '', $senderId);
        $recvKey   = preg_replace('/[^0-9]/', '', $recvId);

        if (!isset($innCache[$senderKey])) {
            $innCache[$senderKey] = getInnByCompany($webhook, $senderKey);
        }
        if (!isset($innCache[$recvKey])) {
            $innCache[$recvKey] = getInnByCompany($webhook, $recvKey);
        }

        $b24Pair = innPair($innCache[$senderKey], $innCache[$recvKey]);

        if ($b24Pair === '' || $b24Pair !== $sabyPair) {
            continue;
        }

        // Привязываем
        try {
            b24Call($webhook, 'crm.item.update', [
                'entityTypeId' => 1072,
                'id'           => (int)$it['id'],
                'fields'       => [
                    'ufCrm19SabyId'     => $etrn['id'],
                    'ufCrm19SabyNumber' => $etrn['number'],
                    'ufCrm19SabyStatus' => trim($etrn['phaseName'] . ' — ' . $etrn['stateName'], ' —'),
                    'ufCrm19SabyUrl'    => $etrn['cabinetUrl'],
                    'ufCrm19SyncStatus' => 'auto',
                    'ufCrm19SyncDate'   => date('Y-m-d H:i:s'),
                ],
            ]);
            $bound++;
            $justBoundIds[] = $etrn['id'];
            $usedItems[] = (int)$it['id'];
            unset($freeItems[$idx]);
        } catch (Throwable $e) {
            $errors[] = 'Привязка #' . $it['id'] . ': ' . cleanMessage($e->getMessage());
        }

        break;
    }
}

// -------------------------------------------------------------
// 3a. Несопоставленные ЭТрН (нет карточки в Б24)
// -------------------------------------------------------------

$unmatched = [];

foreach ($sabyById as $etrn) {
    if (isset($itemMapBySabyId[$etrn['id']]) || in_array($etrn['id'], $justBoundIds, true)) {
        continue;
    }

    // Завершённые (7) и аннулированные/удалённые (19/20/22) старше 1 дня — не «несопоставленные»
    // (та же логика, что в ui etrn_list): они уже не требуют карточки
    $sc = (string)$etrn['stateCode'];
    if (in_array($sc, ['7', '19', '20', '22'], true)) {
        $actualTs = (int)($etrn['actualTs'] ?? 0);
        if ($actualTs > 0 && $actualTs < time() - 86400) {
            continue;
        }
    }

    $unmatched[] = $etrn;
}

// -------------------------------------------------------------
// 4. Обновление статусов привязанных карточек
// -------------------------------------------------------------

foreach ($sabyById as $etrn) {

    $it = $itemMapBySabyId[$etrn['id']] ?? null;

    if (!$it) {
        continue;
    }

    $newStatus = trim($etrn['phaseName'] . ' — ' . $etrn['stateName'], ' —');
    $oldStatus = trim((string)($it['ufCrm19SabyStatus'] ?? ''));
    $newSync   = ($etrn['stateCode'] === '7') ? 'done' : 'auto';

    $fields = [];

    if ($newStatus !== '' && $newStatus !== $oldStatus) {
        $fields['ufCrm19SabyStatus'] = $newStatus;
    }

    if ($etrn['stateCode'] === '7' && trim((string)($it['ufCrm19SyncStatus'] ?? '')) !== 'done') {
        $fields['ufCrm19SyncStatus'] = 'done';
    }

    if (!$fields) {
        continue;
    }

    $fields['ufCrm19SyncDate'] = date('Y-m-d H:i:s');

    try {

        b24Call($webhook, 'crm.item.update', [
            'entityTypeId' => 1072,
            'id'           => (int)$it['id'],
            'fields'       => $fields,
        ]);
        $updated++;

        // Таймлайн
        b24Call($webhook, 'crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_TYPE' => 'DYNAMIC_1072',
                'ENTITY_ID'   => (int)$it['id'],
                'POST_TITLE'  => 'SABY ЭТрН: автосинхронизация',
                'COMMENT'     => 'Статус: ' . $newStatus,
            ],
        ]);

    } catch (Throwable $e) {
        $errors[] = 'Обновление #' . $it['id'] . ': ' . cleanMessage($e->getMessage());
    }
}

appLog(
    $errors ? 'WARN' : 'INFO',
    'Финал автосинхронизации.',
    [
        'sabyDocs' => count($sabyById),
        'bound'    => $bound,
        'updated'  => $updated,
        'errors'   => $errors,
    ],
    'sync'
);

// -------------------------------------------------------------
// 5. Уведомления ответственному (im.notify через вебхук)
// -------------------------------------------------------------

$notify = $settings['notify'] ?? [];
$notifyUserId = (int)($notify['responsibleId'] ?? 0);

if ($notifyUserId > 0) {

    // а) Синхронизация не удалась
    if (!empty($notify['onFail']) && $errors) {
        b24Notify($webhook, $notifyUserId, 'SABY ЭТрН: синхронизация с ошибками',
            'Автосинхронизация завершена с ошибками (' . count($errors) . "):\n"
            . implode("\n", array_slice($errors, 0, 10)));
    }

    // б) Несопоставленные ЭТрН (анти-спам: только при изменении списка)
    if (!empty($notify['onUnmatched']) && $unmatched) {

        // Подавленные пользователем («не уведомлять об этой ЭТрН»)
        $skipIds = array_map('strval', (array)($notify['skipIds'] ?? []));
        $unmatched = array_values(array_filter($unmatched, static fn ($e) => !in_array((string)$e['id'], $skipIds, true)));

        // «Только если начат рабочий день»
        if ($unmatched && !empty($notify['workdayOnly'])) {

            $workdayOpen = true; // по умолчанию — уведомляем

            try {
                $tm = b24Call($webhook, 'timeman.status', ['USER_ID' => $notifyUserId]);
                $status = (string)($tm['result']['STATUS'] ?? '');

                // OPEN/OPENED — день начат; CLOSED/PAUSED — не работает
                if ($status !== '' && $status !== 'OPEN' && $status !== 'OPENED') {
                    $workdayOpen = false;
                }
            } catch (Throwable $e) {
                // Метод недоступен (нет права timeman) — не блокируем уведомления
                appLog('INFO', 'timeman.status недоступен: ' . cleanMessage($e->getMessage()), [], 'notify');
            }

            if (!$workdayOpen) {
                appLog('INFO', 'Пропуск уведомления о несопоставленных: рабочий день не начат.', [], 'notify');
                $unmatched = []; // не отправляем в этом прогоне
            }
        }

        $lines = array_map(static function (array $e) {
            $num = $e['number'] !== '' ? '№ ' . $e['number'] : 'без номера';
            return '• ТН ' . $num . ' от ' . $e['date'] . ' — ИНН контрагента ' . ($e['partnerInn'] ?: '—');
        }, $unmatched);
        $hash = md5(implode("\n", $lines));
        $hashFile = $storageDir . '/notify_unmatched_hash.txt';

        $lastHash = is_file($hashFile) ? trim((string)file_get_contents($hashFile)) : '';

        if ($hash !== $lastHash) {
            b24Notify($webhook, $notifyUserId, 'SABY ЭТрН: несопоставленные ЭТрН',
                'Найдены ЭТрН без карточки в Битрикс (' . count($unmatched) . "):\n"
                . implode("\n", array_slice($lines, 0, 10))
                . "\n\nОткройте приложение для сопоставления.");
            @file_put_contents($hashFile, $hash);
        }
    }
}

cronOut([
    'ok'      => true,
    'sabyDocs' => count($sabyById),
    'bound'   => $bound,
    'updated' => $updated,
    'errors'  => $errors,
]);