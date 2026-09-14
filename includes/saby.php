<?php

declare(strict_types=1);

/**
 * JSON-RPC запрос к SABY без cURL.
 */
function sabyRequest(
    string $url,
    string $method,
    array $params = [],
    ?string $sessionId = null
): array {
    $requestId = random_int(100000, 999999);

    $payload = [
        'jsonrpc' => '2.0',
        'method'  => $method,
        'params'  => $params,
        'id'      => $requestId,
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(
            'Не удалось сформировать JSON-запрос SABY.'
        );
    }

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'User-Agent: Saby-Etrn-Bitrix24-Integration/1.0',
        'Content-Length: ' . strlen($json),
    ];

    if ($sessionId !== null && $sessionId !== '') {
        $headers[] = 'X-SBISSessionID: ' . $sessionId;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();

        throw new RuntimeException(
            'Ошибка HTTP-запроса SABY: '
            . cleanMessage($error['message'] ?? 'неизвестная ошибка')
        );
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'SABY вернул некорректный JSON: '
            . cleanMessage($response)
        );
    }

    if (isset($decoded['error'])) {
        $error = $decoded['error'];

        $message = '';

        if (isset($error['message'])) {
            $message .= (string)$error['message'];
        }

        if (isset($error['details'])) {
            if ($message !== '') {
                $message .= ' ';
            }

            $message .= (string)$error['details'];
        }

        if ($message === '') {
            $message = 'Неизвестная ошибка SABY.';
        }

        throw new RuntimeException(cleanMessage($message));
    }

    return $decoded;
}