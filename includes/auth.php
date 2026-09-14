<?php

declare(strict_types=1);

/**
 * Авторизация SABY (login/logout) и текущее состояние подключения.
 * Требует: $appSession из session.php.
 */

// Файл токена SABY для cron_sync.php
function sabyTokenFilePath(): string
{
    return __DIR__ . '/../storage/saby_token.json';
}

function saveSabyTokenFile(string $sessionId, string $login): void
{
    $dir = dirname(sabyTokenFilePath());

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    @file_put_contents(
        sabyTokenFilePath(),
        json_encode([
            'session' => $sessionId,
            'login'   => $login,
            'time'    => date('c'),
        ], JSON_UNESCAPED_UNICODE)
    );

    @chmod(sabyTokenFilePath(), 0600);
}

// Публичный секрет для cron URL (генерируется один раз)
function cronSecret(): string
{
    $file = __DIR__ . '/../storage/cron_secret.txt';

    if (is_file($file)) {
        return trim((string)file_get_contents($file));
    }

    $secret = bin2hex(random_bytes(16));
    @file_put_contents($file, $secret);
    @chmod($file, 0600);

    return $secret;
}

// Файл сохранённых кредов SABY (для авто-релогина при сбросе сессии)
function sabyCredsFilePath(): string
{
    return __DIR__ . '/../storage/saby_creds.json';
}

// Сохранить логин/пароль (вызывается при логине с установленной галкой)
function saveSabyCreds(string $login, string $password): void
{
    $dir = dirname(sabyCredsFilePath());

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    @file_put_contents(
        sabyCredsFilePath(),
        json_encode(['login' => $login, 'password' => $password], JSON_UNESCAPED_UNICODE)
    );

    @chmod(sabyCredsFilePath(), 0600);
}

function sabyCreds(): array
{
    $file = sabyCredsFilePath();

    if (!is_file($file)) {
        return [];
    }

    return json_decode((string)file_get_contents($file), true) ?: [];
}

// Удалить сохранённые креды (logout)
function deleteSabyCreds(): void
{
    @unlink(sabyCredsFilePath());
}

$sabySuccess = '';
$sabyError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'saby_login') {

        $login    = trim((string)($_POST['saby_login'] ?? ''));
        $password = (string)($_POST['saby_password'] ?? '');

        if ($login === '') {
            $sabyError = 'Введите логин SABY.';
        } elseif ($password === '') {
            $sabyError = 'Введите пароль SABY.';
        } else {

            try {

                $result = sabyRequest(
                    SABY_AUTH_URL,
                    'СБИС.Аутентифицировать',
                    [
                        'Логин'   => $login,
                        'Пароль'  => $password,
                    ]
                );

                /**
                 * В зависимости от ответа SABY результат
                 * может находиться непосредственно в result
                 * или иметь дополнительные поля.
                 */
                $sessionId = '';

                if (isset($result['result']) && is_string($result['result'])) {
                    $sessionId = $result['result'];
                } elseif (
                    isset($result['result']['ИдентификаторСессии'])
                    && is_string($result['result']['ИдентификаторСессии'])
                ) {
                    $sessionId = $result['result']['ИдентификаторСессии'];
                } elseif (
                    isset($result['result']['SessionID'])
                    && is_string($result['result']['SessionID'])
                ) {
                    $sessionId = $result['result']['SessionID'];
                }

                if ($sessionId === '') {
                    throw new RuntimeException(
                        'SABY не вернул идентификатор сессии.'
                    );
                }

                $appSession['SABY_API_SESSION'] = $sessionId;
                $appSession['SABY_LOGIN']       = $login;

                // Токен в файл для cron_sync.php (chmod 600)
                saveSabyTokenFile($sessionId, $login);

                // Логин/пароль — только если пользователь разрешил автосинхронизацию
                if (!empty($_POST['saby_remember'])) {
                    saveSabyCreds($login, $password);
                } else {
                    deleteSabyCreds();
                }

                $sabySuccess = 'Авторизация SABY выполнена. Сессия сохранена.';

            } catch (Throwable $e) {

                $sabyError = 'Ошибка авторизации SABY: '
                    . cleanMessage($e->getMessage());
            }
        }
    }

    // -----------------------------------------------------
    // SABY logout
    // -----------------------------------------------------

    if ($action === 'saby_logout') {

        unset(
            $appSession['SABY_API_SESSION'],
            $appSession['SABY_LOGIN']
        );

        // Удаляем файл токена
        @unlink(sabyTokenFilePath());

        // Удаляем сохранённые креды
        deleteSabyCreds();

        $sabySuccess = 'Сессия SABY удалена.';
    }
}

// ---------------------------------------------------------
// Current state
// ---------------------------------------------------------

$sabySession = (string)($appSession['SABY_API_SESSION'] ?? '');
$sabyLogin   = (string)($appSession['SABY_LOGIN'] ?? '');

$bitrixDomain = (string)($appSession['DOMAIN'] ?? '');
$appSid       = (string)($appSession['APP_SID'] ?? '');

// Общая сессия: подключён, если есть личная сессия ИЛИ общий токен (saby_token.json)
if ($sabySession === '') {
    $t = json_decode((string)@file_get_contents(sabyTokenFilePath()), true) ?: [];
    $sabySession = (string)($t['session'] ?? '');
    $sabyLogin   = $sabyLogin !== '' ? $sabyLogin : (string)($t['login'] ?? '');
}

$sabyConnected = $sabySession !== '';