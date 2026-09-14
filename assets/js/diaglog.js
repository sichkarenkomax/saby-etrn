'use strict';

// =========================================================
// Журнал синхронизации (вкладка Диагностика)
// =========================================================

function refreshAppLog() {

    const view = document.getElementById('logView');
    const meta = document.getElementById('logMeta');

    if (!view) {
        return;
    }

    view.textContent = 'Загрузка...';

    fetch('../public/api.php?action=log_get&bytes=60000', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {

            if (!data.ok) {
                view.textContent = 'Ошибка: ' + (data.error || 'неизвестно');
                return;
            }

            view.textContent = data.log || '(пусто)';

            const mb = (data.maxBytes / (1024 * 1024)).toFixed(1);
            const curMb = (data.size / (1024 * 1024)).toFixed(2);

            if (meta) {
                meta.textContent = 'Размер: ' + curMb + ' Мб из ' + mb + ' Мб. ' +
                    (data.size === 0 ? 'Записей пока нет — ждём первый запуск cron.' : '');
            }

            const limitInput = document.getElementById('logLimitMb');
            if (limitInput) {
                limitInput.value = Math.round(data.maxBytes / (1024 * 1024));
            }

            const ch = data.channels || {};
            const chMap = { logChInterval: 'interval', logChSync: 'sync', logChActivity: 'activity', logChNotify: 'notify' };
            Object.keys(chMap).forEach(function(id) {
                const cb = document.getElementById(id);
                if (cb) {
                    cb.checked = ch[chMap[id]] !== false;
                }
            });
        })
        .catch(function(e) {
            view.textContent = 'Ошибка сети: ' + e;
        });
}

function saveLogLimit() {

    const limitInput = document.getElementById('logLimitMb');
    const meta = document.getElementById('logMeta');

    if (!limitInput) {
        return;
    }

    const maxBytes = Math.round(Number(limitInput.value) * 1024 * 1024);

    // Забираем текущие настройки и обновляем только log.maxBytes
    fetch('../public/api.php?action=settings_get', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {

            const s = (data.settings || {});
            s.log = {
                maxBytes: maxBytes,
                channels: readLogChannels()
            };

            return fetch('../public/api.php?action=settings_set', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(s)
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {

            if (meta) {
                meta.textContent = data.ok
                    ? '✓ Лимит сохранён (' + (maxBytes / (1024 * 1024)) + ' Мб).'
                    : 'Ошибка сохранения: ' + (data.error || '');
            }

            refreshAppLog();
        })
        .catch(function(e) {
            if (meta) {
                meta.textContent = 'Ошибка сети: ' + e;
            }
        });
}

// Мгновенное сохранение категорий журнала при клике по флажку
function saveLogChannels() {

    fetch('api.php?action=settings_get', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const s = data.ok ? data.settings : {};
            s.log = s.log || { maxBytes: 5 * 1024 * 1024 };
            s.log.channels = readLogChannels();

            return fetch('api.php?action=settings_set', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(s)
            }).then(function(r2) { return r2.json(); });
        })
        .then(function(data) {
            const meta = document.getElementById('logMeta');
            if (meta) {
                meta.textContent = (data && data.ok)
                    ? '✓ Категории сохранены. Новые записи отключённых категорий не пишутся (уже записанные строки остаются).'
                    : 'Ошибка сохранения: ' + ((data && data.error) || '');
            }
        })
        .catch(function(e) {
            const meta = document.getElementById('logMeta');
            if (meta) {
                meta.textContent = 'Ошибка сети: ' + e;
            }
        });
}

function readLogChannels() {
    return {
        interval: document.getElementById('logChInterval') ? document.getElementById('logChInterval').checked : true,
        sync: document.getElementById('logChSync') ? document.getElementById('logChSync').checked : true,
        activity: document.getElementById('logChActivity') ? document.getElementById('logChActivity').checked : true,
        notify: document.getElementById('logChNotify') ? document.getElementById('logChNotify').checked : true
    };
}

['logChInterval', 'logChSync', 'logChActivity', 'logChNotify'].forEach(function(id) {
    const cb = document.getElementById(id);
    if (cb) {
        cb.addEventListener('change', saveLogChannels);
    }
});

if (document.getElementById('logRefresh')) {
    document.getElementById('logRefresh').addEventListener('click', refreshAppLog);
}

if (document.getElementById('logSave')) {
    document.getElementById('logSave').addEventListener('click', saveLogLimit);
}