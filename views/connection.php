<?php
/**
 * Вкладка "Подключение": SABY auth + Bitrix24 embedded-параметры.
 * Требует: $sabyConnected, $sabyLogin, $sabySession, $bitrixDomain, $appSid, $appSession.
 */
?>

    <section
        class="tab-panel"
        data-tab-panel="connection"
    >

        <div class="grid">

            <!-- SABY -->

            <div class="card">

                <h2>SABY</h2>

                <?php if ($sabyConnected): ?>

                    <div class="status success">

                        <span class="status-title">
                            SABY подключён
                        </span>

                        <span class="status-subtitle">
                            Активная сессия SABY сохранена
                            в PHP-сессии приложения.
                        </span>

                    </div>


                    <div class="field">

                        <label>Логин</label>

                        <input
                            type="text"
                            value="<?= h($sabyLogin) ?>"
                            readonly
                        >

                    </div>


                    <div class="field">

                        <label>Сессия</label>

                        <input
                            type="text"
                            value="<?= h(maskSecret($sabySession)) ?>"
                            readonly
                        >

                    </div>


                    <form method="post">

                        <input
                            type="hidden"
                            name="action"
                            value="saby_logout"
                        >

                        <button
                            type="submit"
                            class="danger"
                        >
                            Отключить SABY
                        </button>

                    </form>

                <?php else: ?>

                    <div class="status info">

                        <span class="status-title">
                            SABY не подключён
                        </span>

                        <span class="status-subtitle">
                            Выполните авторизацию для работы
                            с ЭТрН.
                        </span>

                    </div>


                    <form method="post">

                        <input
                            type="hidden"
                            name="action"
                            value="saby_login"
                        >


                        <div class="field">

                            <label for="saby_login">
                                Логин SABY
                            </label>

                            <input
                                id="saby_login"
                                name="saby_login"
                                type="text"
                                autocomplete="username"
                                required
                            >

                        </div>


                        <div class="field">

                            <label for="saby_password">
                                Пароль SABY
                            </label>

                            <input
                                id="saby_password"
                                name="saby_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >

                        </div>


                        <div class="form-row" style="margin-bottom:10px;">

                            <label style="display:flex; align-items:center; gap:6px;">
                                <input
                                    type="checkbox"
                                    name="saby_remember"
                                    value="1"
                                    style="width:auto; margin:0;"
                                    checked
                                >
                                <span>Сохранить логин/пароль для автосинхронизации (авто-релогин при сбросе сессии)</span>
                            </label>

                        </div>


                        <button type="submit">
                            Авторизоваться в SABY
                        </button>

                    </form>

                <?php endif; ?>

            </div>


            <!-- BITRIX24 -->

            <div class="card">

                <h2>Bitrix24</h2>


                <div class="field">

                    <label>Домен</label>

                    <input
                        type="text"
                        value="<?= h($bitrixDomain) ?>"
                        readonly
                    >

                </div>


                <div class="field">

                    <label>Протокол</label>

                    <input
                        type="text"
                        value="<?= h($appSession['PROTOCOL'] ?? '') ?>"
                        readonly
                    >

                </div>


                <div class="field">

                    <label>Язык</label>

                    <input
                        type="text"
                        value="<?= h($appSession['LANG'] ?? '') ?>"
                        readonly
                    >

                </div>


                <div class="field">

                    <label>APP_SID</label>

                    <input
                        type="text"
                        value="<?= h(maskSecret($appSid)) ?>"
                        readonly
                    >

                </div>

            </div>

        </div>


        <div class="card">

            <h2>Сопоставление</h2>


            <!-- Smart process -->

            <div class="field">

                <label for="smartProcess">
                    Смарт-процесс Bitrix24
                </label>

                <select id="smartProcess">

                    <option value="">
                        Загрузка...
                    </option>

                </select>

            </div>


            <div
                id="smartProcessError"
                class="status error hidden"
            ></div>


            <div
                id="smartProcessLoading"
                class="loading"
            >
                Загрузка смарт-процессов...
            </div>


            <div
                id="smartProcessInfo"
                class="process-info hidden"
            ></div>

        </div>

        <div class="card">

            <h2>Настройки</h2>


            <div
                id="settingsOk"
                class="status success hidden"
            ></div>


            <div
                id="settingsError"
                class="status error hidden"
            ></div>


            <div id="settingsBlock"></div>

        </div>

        <div class="card">

            <h2>Активити для бизнес-процессов</h2>


            <div class="small">
                Регистрирует действие "SABY: создать ЭТрН" — доступно
                в дизайнере бизнес-процессов смарт-процесса ЭТрН.
                Вход: реквизиты получателя, перевозчик, водитель, груз.
                Выход: ID документа в SABY.
            </div>


            <div class="actions" style="margin-top:12px;">

                <button
                    id="registerActivity"
                    type="button"
                    class="success"
                >
                    Зарегистрировать активити
                </button>

            </div>


            <div
                id="activityStatus"
                class="small"
                style="margin-top:8px;"
            ></div>

        </div>

    </section>

<script>
    window.SABY_ACTIVITY_URL = '<?= h('https://' . preg_replace('/:\d+$/', '', ($_SERVER['HTTP_HOST'] ?? 'portal.example.com')) . '/local/saby-etrn/public/activity.php') ?>';
</script>

<script>
    window.SABY_CRON_URL = '<?= h('https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.example.com') . '/local/saby-etrn/public/cron_sync.php?secret=' . cronSecret()) ?>';
</script>