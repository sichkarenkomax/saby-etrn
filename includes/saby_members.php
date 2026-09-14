<?php

declare(strict_types=1);

/**
 * Идентификаторы участников ЭДО в SABY (формат "2BE...").
 * Получаются через СБИС.ИнформацияОКонтрагенте, кэшируются в storage/saby_member_ids.json.
 */

require_once __DIR__ . '/saby.php';

function sabyMemberIdsCachePath(): string
{
    return __DIR__ . '/../storage/saby_member_ids.json';
}

/**
 * Возвращает идентификатор участника ЭДО по ИНН/КПП.
 * @return string «2BE...» или '' при ошибке
 */
function sabyMemberId(string $sabySession, string $inn, string $kpp): string
{
    $cache = is_file(sabyMemberIdsCachePath())
        ? (json_decode((string)file_get_contents(sabyMemberIdsCachePath()), true) ?: [])
        : [];

    $key = $inn . '_' . $kpp;

    if (!empty($cache[$key])) {
        return (string)$cache[$key];
    }

    try {
        $resp = sabyRequest(SABY_SERVICE_URL, 'СБИС.ИнформацияОКонтрагенте', [
            'Участник' => ['СвЮЛ' => ['ИНН' => $inn, 'КПП' => $kpp]],
        ], $sabySession);
    } catch (Throwable $e) {
        return '';
    }

    $id = (string)($resp['result']['Идентификатор'] ?? '');

    if ($id !== '') {
        $cache[$key] = $id;
        @file_put_contents(sabyMemberIdsCachePath(), json_encode($cache, JSON_UNESCAPED_UNICODE));
    }

    return $id;
}