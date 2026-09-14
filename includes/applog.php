<?php

declare(strict_types=1);

/**
 * Журнал приложения (storage/app.log) с ротацией по размеру.
 * Лимит — из настроек (settings.json → log.maxBytes), по умолчанию 5 Мб.
 */

require_once __DIR__ . '/helpers.php';

const APP_LOG_CHANNEL = 'app';

function appLogFilePath(): string
{
    return __DIR__ . '/../storage/app.log';
}

function appLogMaxBytes(): int
{
    $settingsFile = __DIR__ . '/../storage/settings.json';

    if (is_file($settingsFile)) {
        $s = json_decode((string)file_get_contents($settingsFile), true) ?: [];
        $max = (int)($s['log']['maxBytes'] ?? 0);

        if ($max > 0) {
            return $max;
        }
    }

    return 5 * 1024 * 1024; // 5 Мб по умолчанию
}

/**
 * Каналы журнала (settings.log.channels): какие события писать.
 * Доступные каналы: interval, sync, activity, notify.
 */
function appLogChannels(): array
{
    $settingsFile = __DIR__ . '/../storage/settings.json';

    $defaults = ['interval' => true, 'sync' => true, 'activity' => true, 'notify' => true];

    if (is_file($settingsFile)) {
        $s = json_decode((string)file_get_contents($settingsFile), true) ?: [];
        $ch = $s['log']['channels'] ?? null;

        if (is_array($ch)) {
            foreach ($defaults as $k => $v) {
                if (array_key_exists($k, $ch)) {
                    $defaults[$k] = (bool)$ch[$k];
                }
            }
        }
    }

    return $defaults;
}

/**
 * Запись в журнал. $context — доп. данные (массив), добавляются JSON-ом.
 * $channel — категория события (interval/sync/activity/notify), фильтруется настройками.
 */
function appLog(string $level, string $message, array $context = [], string $channel = 'sync'): void
{
    $channels = appLogChannels();

    if (!($channels[$channel] ?? true)) {
        return; // канал выключен в настройках
    }

    $file = appLogFilePath();
    $max  = appLogMaxBytes();

    // Ротация: если превышен лимит — начинаем файл заново
    if (is_file($file) && filesize($file) >= $max) {
        @unlink($file);
    }

    $line = sprintf(
        "[%s] [%s] %s%s\n",
        date('d.m.Y H:i:s'),
        $level,
        $message,
        $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Последние $bytes байт журнала (для отображения в Диагностике).
 */
function appLogTail(int $bytes = 20000): string
{
    $file = appLogFilePath();

    if (!is_file($file)) {
        return 'Журнал пуст (записей ещё нет).';
    }

    $size = filesize($file);
    $start = max(0, $size - $bytes);

    $fh = fopen($file, 'rb');
    fseek($fh, $start);
    $data = (string)fread($fh, $bytes);
    fclose($fh);

    if ($start > 0) {
        // срезаем первую (обрезанную) строку
        $nl = strpos($data, "\n");
        if ($nl !== false) {
            $data = substr($data, $nl + 1);
        }
    }

    // Новые события сверху
    $lines = explode("\n", $data);
    if (end($lines) === '') {
        array_pop($lines);
    }
    $lines = array_reverse($lines);

    return implode("\n", $lines);
}