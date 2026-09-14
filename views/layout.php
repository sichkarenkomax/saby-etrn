<?php
/**
 * Каркас страницы: <head>, уведомления, вкладки.
 * Требует переменные из auth.php: $sabySuccess, $sabyError.
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>

    <meta charset="UTF-8">

    <title>SABY ЭТрН</title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <script src="//api.bitrix24.com/api/v1/"></script>

    <link rel="stylesheet" href="../assets/css/app.css">


</head>

<body>

<div class="container">

    <h1>SABY → Bitrix24 — ЭТрН</h1>


    <!-- =====================================================
         GLOBAL NOTIFICATIONS
         ===================================================== -->

    <?php if ($sabySuccess !== ''): ?>

        <div class="status success">
            <span class="status-title">Готово:</span>
            <span class="status-subtitle">
                <?= h(cleanMessage($sabySuccess)) ?>
            </span>
        </div>

    <?php endif; ?>


    <?php if ($sabyError !== ''): ?>

        <div class="status error">
            <span class="status-title">Ошибка:</span>
            <span class="status-subtitle">
                <?= h(cleanMessage($sabyError)) ?>
            </span>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         TABS
         ===================================================== -->

    <div class="tabs">

        <button
            type="button"
            class="tab-button"
            data-tab="connection"
        >
            Подключение
        </button>

        <button
            type="button"
            class="tab-button"
            data-tab="sync"
        >
            Сопоставление ЭТрН
        </button>

        <button
            type="button"
            class="tab-button"
            data-tab="mapping"
        >
            Сопоставление полей
        </button>

        <button
            type="button"
            class="tab-button"
            data-tab="diagnostics"
        >
            Диагностика
        </button>

    </div>