<?php

declare(strict_types=1);

/**
 * HTML/строковые helpers.
 */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Компактное сообщение для UI.
 * Убирает переносы строк, табы и повторяющиеся пробелы.
 */
function cleanMessage(string $value): string
{
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * Маскировка секрета.
 */
function maskSecret(string $value): string
{
    $length = mb_strlen($value);

    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return mb_substr($value, 0, 2)
        . str_repeat('*', max(4, $length - 4))
        . mb_substr($value, -2);
}