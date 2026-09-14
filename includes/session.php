<?php

declare(strict_types=1);

/**
 * PHP-сессия приложения и параметры embedded app Bitrix24.
 */

if (!isset($_SESSION['SABY_ETRN'])) {
    $_SESSION['SABY_ETRN'] = [];
}

$appSession =& $_SESSION['SABY_ETRN'];

if (isset($_GET['DOMAIN'])) {
    $appSession['DOMAIN'] = (string)$_GET['DOMAIN'];
}

if (isset($_GET['PROTOCOL'])) {
    $appSession['PROTOCOL'] = (string)$_GET['PROTOCOL'];
}

if (isset($_GET['LANG'])) {
    $appSession['LANG'] = (string)$_GET['LANG'];
}

if (isset($_GET['APP_SID'])) {
    $appSession['APP_SID'] = (string)$_GET['APP_SID'];
}