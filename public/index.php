<?php

declare(strict_types=1);

/**
 * SABY ЭТрН — Bitrix24 embedded app.
 *
 * Точка входа: только сборка.
 * Конфиг → helpers → SABY-клиент → сессия → авторизация → views.
 */

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/saby.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../views/layout.php';
require_once __DIR__ . '/../views/connection.php';
require_once __DIR__ . '/../views/etrn.php';
require_once __DIR__ . '/../views/participants.php';
require_once __DIR__ . '/../views/mapping.php';
require_once __DIR__ . '/../views/sync.php';
require_once __DIR__ . '/../views/diagnostics.php';