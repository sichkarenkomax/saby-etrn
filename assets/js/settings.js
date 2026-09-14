'use strict';

// =========================================================
// Настройки: фильтр карточек (стадии + поля), автосинхронизация
// =========================================================

let appSettings = null;
let stageList = [];      // [{STATUS_ID, NAME}]
let fieldList = [];      // [{name, title}]

function loadSettings(callback) {

    fetch('api.php?action=settings_get', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            appSettings = data.ok ? data.settings : {
                filter: { mode: 'all', stages: [], stageMode: 'include', fields: [] },
                autoSync: { enabled: false, intervalMin: 15 },
                bitrixWebhook: ''
            };
            if (callback) { callback(); }
        })
        .catch(function() {
            appSettings = {
                filter: { mode: 'all', stages: [], stageMode: 'include', fields: [] },
                autoSync: { enabled: false, intervalMin: 15 },
                bitrixWebhook: ''
            };
            if (callback) { callback(); }
        });
}

function saveSettings(settings, callback) {

    fetch('api.php?action=settings_set', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(settings)
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {

            const err = document.getElementById('settingsError');

            if (!data.ok) {
                if (err) {
                    err.textContent = 'Ошибка сохранения: ' + (data.error || '');
                    err.classList.remove('hidden');
                }
                return;
            }

            appSettings = data.settings;

            if (err) {
                err.classList.add('hidden');
            }

            const ok = document.getElementById('settingsOk');
            if (ok) {
                ok.textContent = 'Настройки сохранены.';
                ok.classList.remove('hidden');
                setTimeout(function() { ok.classList.add('hidden'); }, 3000);
            }

            if (callback) { callback(); }
        })
        .catch(function(e) {

            const err = document.getElementById('settingsError');

            if (err) {
                err.textContent = 'Ошибка сети: ' + cleanMessage(e.message);
                err.classList.remove('hidden');
            }
        });
}

// ---------------------------------------------------------
// Мгновенное сохранение autoSync (интервал / вкл-выкл)
// ---------------------------------------------------------

function saveAutoSync(intervalMin, onSaved, enabled) {

    fetch('api.php?action=settings_get', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const s = data.ok ? data.settings : {};
            s.autoSync = s.autoSync || { enabled: false, intervalMin: 15 };

            if (intervalMin !== null && intervalMin !== undefined) {
                s.autoSync.intervalMin = parseInt(intervalMin, 10) || 15;
            }
            if (enabled !== undefined && enabled !== null) {
                s.autoSync.enabled = enabled;
            }

            return fetch('api.php?action=settings_set', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(s)
            }).then(function(r2) { return r2.json(); });
        })
        .then(function(data) {
            if (data && data.ok) {
                appSettings = data.settings;
                const a = appSettings.autoSync || {};
                if (onSaved) { onSaved(a.intervalMin); }
            } else {
                const err = document.getElementById('settingsError');
                if (err) {
                    err.textContent = 'Ошибка сохранения: ' + ((data && data.error) || '');
                    err.classList.remove('hidden');
                }
            }
        })
        .catch(function(e) {
            const err = document.getElementById('settingsError');
            if (err) {
                err.textContent = 'Ошибка сети: ' + cleanMessage(e.message);
                err.classList.remove('hidden');
            }
        });
}

// ---------------------------------------------------------
// Загрузка стадий и полей смарт-процесса (для редактора фильтра)
// ---------------------------------------------------------

function loadStagesAndFields() {

    // Ждём BX24.init (appState.entityTypeId появляется после него)
    const tryLoadStages = function(attempts) {

        if (!appState.entityTypeId) {
            if (attempts > 0) {
                setTimeout(function() { tryLoadStages(attempts - 1); }, 300);
            }
            return;
        }

        BX24.callMethod(
            'crm.status.list',
            {},
            function(resp) {

                if (!resp.error()) {
                    const all = resp.data() || [];
                    const prefix = 'DT' + appState.entityTypeId + '_';

                    stageList = all
                        .filter(function(s) {
                            return String(s.STATUS_ID || '').indexOf(prefix) === 0;
                        })
                        .map(function(s) {
                            return { STATUS_ID: s.STATUS_ID, NAME: s.NAME };
                        });
                }

                renderStageOptions();
            }
        );
    };

    tryLoadStages(100);

    // Поля: crm.item.fields — ждём BX24.init (appState.entityTypeId появляется после него)
    const tryLoadFields = function(attempts) {

        if (!appState.entityTypeId) {
            if (attempts > 0) {
                setTimeout(function() { tryLoadFields(attempts - 1); }, 500);
            } else {
                const dbg = document.getElementById('filterDebug');
                if (dbg) {
                    dbg.textContent = 'Поля не загрузились: entityTypeId не задан за 50с. Проверьте, выбран ли смарт-процесс, и обновите страницу (Ctrl+F5).';
                    dbg.classList.remove('hidden');
                }
            }
            return;
        }

        const dbg = document.getElementById('filterDebug');
        if (dbg) {
            dbg.textContent = 'Загрузка полей... (entityTypeId=' + appState.entityTypeId + ')';
            dbg.classList.remove('hidden');
        }

        BX24.callMethod(
            'crm.item.fields',
            { entityTypeId: appState.entityTypeId },
            function(resp) {

                if (resp.error()) {
                    const dbg = document.getElementById('filterDebug');
                    if (dbg) {
                        dbg.textContent = 'Ошибка загрузки полей: ' + String(resp.error());
                        dbg.classList.remove('hidden');
                    }
                    return;
                }

                const raw = resp.data() || {};
                const fields = (raw.fields || raw || {});

                // fieldList: name, title, type — тип нужен для спискового рендера
                fieldList = Object.keys(fields).map(function(k) {
                    return {
                        name: k,
                        title: (fields[k] || {}).title || k,
                        type: (fields[k] || {}).type || 'string'
                    };
                });

                const dbg = document.getElementById('filterDebug');
                if (dbg) {
                    dbg.classList.add('hidden');
                }

                // Варианты значений для списковых полей, затем рендер
                loadFieldVariants(function() {
                    renderFieldRows();
                });
            }
        );
    };

    // Варианты значений для списковых полей фильтра
    function loadFieldVariants(callback) {

        const crmFields = fieldList.filter(function(f) { return f.type === 'crm'; });
        const statusFields = fieldList.filter(function(f) { return f.type === 'crm_status'; });

        const done = function() {

            // Для crm_status (стадии) — варианты из загруженного stageList
            statusFields.forEach(function(f) {
                f.variants = stageList.map(function(s) {
                    return { value: s.STATUS_ID, label: s.NAME };
                });
            });

            if (callback) { callback(); }
        };

        if (!crmFields.length) {
            done();
            return;
        }

        // Компании: crm.company.list (по страницам)
        const companies = [];

        const nextPage = function(start) {

            BX24.callMethod(
                'crm.company.list',
                { select: ['ID', 'TITLE'], start: start },
                function(resp) {

                    const more = (resp.data() || []);

                    for (let i = 0; i < more.length; i++) {
                        companies.push({ value: String(more[i].ID), label: more[i].TITLE || ('#' + more[i].ID) });
                    }

                    const next = (typeof resp.next === 'function') ? resp.next() : null;

                    if (next && more.length >= 50) {
                        nextPage(start + 50);
                        return;
                    }

                    crmFields.forEach(function(f) {
                        f.variants = companies;
                    });

                    done();
                }
            );
        };

        nextPage(0);
    }

    tryLoadFields(20);
}

// ---------------------------------------------------------
// Рендер настроек
// ---------------------------------------------------------

function renderSettingsUI(cronUrl) {

    const el = document.getElementById('settingsBlock');

    if (!el || !appSettings) {
        return;
    }

    const f = appSettings.filter || {};
    const a = appSettings.autoSync || {};
    const n = appSettings.notify || {};

    el.innerHTML =
        '<div style="margin:0 0 10px; padding-bottom:6px; border-bottom:1px solid #e0e3e8; font-size:13px; font-weight:600; color:#555; text-transform:uppercase; letter-spacing:.5px;">Фильтры</div>' +
        '<div class="form-row">' +
            '<label>Фильтр карточек</label>' +
            '<select id="fltMode">' +
                '<option value="all"' + (f.mode !== 'custom' ? ' selected' : '') + '>Все карточки</option>' +
                '<option value="custom"' + (f.mode === 'custom' ? ' selected' : '') + '>Настраиваемый фильтр</option>' +
            '</select>' +
        '</div>' +

        '<div id="fltCustom" class="' + (f.mode === 'custom' ? '' : 'hidden') + '">' +

            '<div class="form-row" style="margin-top:10px;">' +
                '<label>Стадии</label>' +
                '<select id="fltStageMode">' +
                    '<option value="include"' + ((f.stageMode || 'include') === 'include' ? ' selected' : '') + '>Показывать только выбранные</option>' +
                    '<option value="exclude"' + ((f.stageMode || 'include') === 'exclude' ? ' selected' : '') + '>Не показывать выбранные</option>' +
                '</select>' +
                '<div id="fltStages" style="margin-top:6px; max-height:180px; overflow:auto; border:1px solid #ddd; padding:8px;">Загрузка стадий...</div>' +
            '</div>' +

            '<div class="form-row" style="margin-top:10px;">' +
                '<label>Условия по полям (И)</label>' +
                '<div id="fltFields"></div>' +
                '<button type="button" id="fltAddField" class="small-btn" style="margin-top:6px;">+ условие</button>' +
                '<div id="filterDebug" class="small hidden" style="color:#a33; margin-top:4px;"></div>' +
            '</div>' +

        '</div>' +

        '<div style="margin:18px 0 10px; padding-bottom:6px; border-bottom:1px solid #e0e3e8; font-size:13px; font-weight:600; color:#555; text-transform:uppercase; letter-spacing:.5px;">Автосинхронизация</div>' +
        '<div class="form-row" id="bxWebhookRow" style="display:none;">' +
            '<label>Вебхук Bitrix24 (REST) — только для администратора</label>' +
            '<input type="text" id="bxWebhook" placeholder="(задан; вставь новый URL, чтобы заменить)" value="">' +
            '<div class="small" style="margin-top:4px;">Виден только администраторам Б24. Значение не показывается — при замене вставь новый URL целиком.</div>' +
        '</div>' +
            '<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="asEnabled" style="margin:0; width:auto; flex:0 0 auto;"' + (a.enabled ? ' checked' : '') + '> Включена</label>' +
            '<select id="asInterval" style="margin-left:10px;">' +
                [5, 15, 30, 60, 120].map(function(m) {
                    return '<option value="' + m + '"' + (a.intervalMin === m ? ' selected' : '') + '>' + m + ' мин</option>';
                }).join('') +
            '</select>' +
            '<div class="small" style="margin-top:4px;">Периодичность запуска на коробочном портале. Изменения применяются после «Сохранить» — crontab трогать не нужно.</div>' +
        '</div>' +

        '<div class="form-row" style="margin-top:10px;">' +
            '<label>Cron: установка и статус</label>' +
            '<div>' +
                '<button type="button" id="cronInstall" class="success">Установить</button> ' +
                '<button type="button" id="cronRemove" class="danger">Удалить</button>' +
            '</div>' +
            '<div id="cronStatus" class="small" style="margin-top:6px;">Проверка...</div>' +
        '</div>' +

        '<div style="margin:18px 0 10px; padding-bottom:6px; border-bottom:1px solid #e0e3e8; font-size:13px; font-weight:600; color:#555; text-transform:uppercase; letter-spacing:.5px;">Уведомления</div>' +
        '<div class="form-row">' +
            '<label>Ответственный, которому отправляются уведомления</label>' +
            '<div style="margin-top:4px;">' +
                '<select id="ntfResponsible" style="max-width:320px;">' +
                    '<option value="0">— не выбран —</option>' +
                '</select>' +
            '</div>' +
            '<div style="margin-top:6px;">' +
                '<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="ntfOnFail" style="margin:0; width:auto; flex:0 0 auto;"' + ((n.onFail !== false) ? ' checked' : '') + '> Синхронизация не удалась</label>' +
            '</div>' +
            '<div style="margin-top:6px;">' +
                '<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="ntfOnAuthFail" style="margin:0; width:auto; flex:0 0 auto;"' + ((n.onAuthFail !== false) ? ' checked' : '') + '> Авторизация SABY отвалилась</label>' +
            '</div>' +
            '<div style="margin-top:6px;">' +
                '<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="ntfOnUnmatched" style="margin:0; width:auto; flex:0 0 auto;"' + ((n.onUnmatched !== false) ? ' checked' : '') + '> Найдены несопоставленные ЭТрН</label>' +
            '</div>' +
            '<div style="margin-top:6px;">' +
                '<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="ntfWorkdayOnly" style="margin:0; width:auto; flex:0 0 auto;"' + ((n.workdayOnly) ? ' checked' : '') + '> Уведомлять о несопоставленных, только если начат рабочий день ответственного</label>' +
            '</div>' +
            '<div id="ntfHint" class="small hidden" style="color:#a33; margin-top:4px;"></div>' +
        '</div>' +

        '<div class="actions" style="margin-top:12px;">' +
            '<button type="button" id="settingsSave" class="success">Сохранить настройки</button>' +
        '</div>';

    renderStageOptions();
    renderFieldRows();
    checkCronStatus();
    loadNotifyUsers();

    el.querySelector('#fltMode').onchange = function() {
        document.getElementById('fltCustom').classList.toggle(
            'hidden',
            el.querySelector('#fltMode').value !== 'custom'
        );
    };

    el.querySelector('#fltAddField').onclick = addFieldRow;

    el.querySelector('#cronInstall').onclick = function() { manageCron('cron_install'); };
    el.querySelector('#cronRemove').onclick = function() { manageCron('cron_remove'); };

    // Интервал и «Включена» сохраняются сразу при смене (независимо от кнопки Сохранить)
    el.querySelector('#asInterval').onchange = function() {
        saveAutoSync(this.value, function(saved) {
            const ok = document.getElementById('settingsOk');
            if (ok) {
                ok.textContent = 'Интервал сохранён: ' + saved + ' мин.';
                ok.classList.remove('hidden');
                setTimeout(function() { ok.classList.add('hidden'); }, 3000);
            }
        });
    };

    el.querySelector('#asEnabled').onchange = function() {
        saveAutoSync(null, null, this.checked);
    };

    // Вебхук виден только администраторам Б24
    const showWebhookRow = function(isAdmin) {
        const row = el.querySelector('#bxWebhookRow');
        if (row && !isAdmin) {
            row.parentNode.removeChild(row);
        }
    };
    if (typeof BX24 !== 'undefined' && typeof BX24.isAdmin === 'function') {
        BX24.isAdmin(showWebhookRow);
    } else {
        showWebhookRow(false);
    }

    el.querySelector('#settingsSave').onclick = function() {

        const stages = [];
        el.querySelectorAll('#fltStages input:checked').forEach(function(cb) {
            stages.push(cb.value);
        });

        const fields = [];
        el.querySelectorAll('#fltFields .fld-row').forEach(function(row) {

            const field = row.querySelector('.fld-name').value;
            const op = row.querySelector('.fld-op').value;
            const value = row.querySelector('.fld-value').value;

            if (field) {
                fields.push({ field: field, op: op, value: value });
            }
        });

        const bxWhEl = el.querySelector('#bxWebhook');
        const bxWhVal = bxWhEl ? bxWhEl.value.trim() : '';
        const savePayload = {};
        if (bxWhVal !== '') { savePayload.bitrixWebhook = bxWhVal; }
        saveSettings(Object.assign(savePayload, {
            filter: {
                mode: el.querySelector('#fltMode').value,
                stageMode: el.querySelector('#fltStageMode').value,
                stages: stages,
                fields: fields
            },
            autoSync: {
                enabled: el.querySelector('#asEnabled').checked,
                intervalMin: parseInt(el.querySelector('#asInterval').value, 10) || 15
            },
            notify: {
                responsibleId: parseInt(el.querySelector('#ntfResponsible').value, 10) || 0,
                onFail: el.querySelector('#ntfOnFail').checked,
                onAuthFail: el.querySelector('#ntfOnAuthFail').checked,
                onUnmatched: el.querySelector('#ntfOnUnmatched').checked,
                workdayOnly: el.querySelector('#ntfWorkdayOnly').checked
            }
        }));
    };
}

function renderStageOptions() {

    const box = document.getElementById('fltStages');

    if (!box) {
        return;
    }

    const selected = new Set((appSettings.filter || {}).stages || []);

    box.innerHTML = stageList.map(function(s) {
        return '<label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" style="margin:0;" value="' + escapeHtml(s.STATUS_ID) + '"' +
            (selected.has(s.STATUS_ID) ? ' checked' : '') + '> <span>' + escapeHtml(s.NAME) + '</span></label>';
    }).join('');
}

function renderFieldRows() {

    const box = document.getElementById('fltFields');

    if (!box) {
        return;
    }

    // Если строк ещё нет — берём сохранённые в настройках
    if (!box.querySelectorAll('.fld-row').length) {

        const selected = (appSettings.filter || {}).fields || [];

        selected.forEach(function(r) {
            box.appendChild(buildFieldRow(r.field, r.op, r.value));
        });
    }
    // Существующие строки НЕ пересоздаём: иначе открытый dropdown значения
    // закрывается в момент, когда асинхронно догружаются варианты (компании)
}

function buildFieldRow(field, op, value) {

    const div = document.createElement('div');
    div.className = 'fld-row';
    div.style.marginBottom = '6px';

    const name = document.createElement('select');
    name.className = 'fld-name';
    name.style.width = '40%';
    name.innerHTML = '<option value="">— поле —</option>' + fieldList.map(function(f) {
        return '<option value="' + escapeHtml(f.name) + '"' + (f.name === field ? ' selected' : '') + '>' + escapeHtml(f.title) + '</option>';
    }).join('');

    const opSel = document.createElement('select');
    opSel.className = 'fld-op';
    opSel.style.width = '25%';
    opSel.innerHTML = [
        ['eq', 'равно'], ['ne', 'не равно'], ['contains', 'содержит'], ['empty', 'пусто'], ['notempty', 'не пусто']
    ].map(function(o) {
        return '<option value="' + o[0] + '"' + (o[0] === op ? ' selected' : '') + '>' + o[1] + '</option>';
    }).join('');

    // Значение: input для текстовых, select для списковых полей
    let val;

    const currentField = fieldList.find(function(f) { return f.name === field; });
    const variants = currentField ? currentField.variants : null;

    if (variants && variants.length) {

        val = document.createElement('select');
        val.className = 'fld-value';
        val.style.width = '30%';
        val.innerHTML = '<option value="">— значение —</option>' + variants.map(function(v) {
            return '<option value="' + escapeHtml(v.value) + '"' + (v.value === value ? ' selected' : '') + '>' + escapeHtml(v.label) + '</option>';
        }).join('');

        // Для списковых полей оператор "содержит" не имеет смысла
        if (op === 'contains') {
            opSel.value = 'eq';
        }
    } else {
        val = document.createElement('input');
        val.type = 'text';
        val.className = 'fld-value';
        val.value = value || '';
        val.style.width = '30%';
    }

    // При смене поля — перерисовка значения (список/текст)
    name.onchange = function() {
        const f = fieldList.find(function(f) { return f.name === name.value; });

        let newVal;
        if (f && f.variants && f.variants.length) {
            newVal = document.createElement('select');
            newVal.className = 'fld-value';
            newVal.style.width = '30%';
            newVal.innerHTML = '<option value="">— значение —</option>' + f.variants.map(function(v) {
                return '<option value="' + escapeHtml(v.value) + '">' + escapeHtml(v.label) + '</option>';
            }).join('');
        } else {
            newVal = document.createElement('input');
            newVal.type = 'text';
            newVal.className = 'fld-value';
            newVal.style.width = '30%';
        }

        div.replaceChild(newVal, val);
        val = newVal;
    };

    const del = document.createElement('button');
    del.type = 'button';
    del.textContent = '×';
    del.className = 'small-btn';
    del.onclick = function() { div.remove(); };

    div.appendChild(name);
    div.appendChild(opSel);
    div.appendChild(val);
    div.appendChild(del);

    return div;
}

function addFieldRow() {

    const box = document.getElementById('fltFields');

    if (box) {
        box.appendChild(buildFieldRow('', 'eq', ''));
    }
}

// ---------------------------------------------------------
// Cron на коробке
// ---------------------------------------------------------

function checkCronStatus() {

    fetch('api.php?action=cron_status', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {

            const el = document.getElementById('cronStatus');

            if (!el) {
                return;
            }

            if (!d.ok) {
                el.textContent = 'Cron недоступен на этом портале: ' + (d.error || '');
                return;
            }

            el.textContent = d.installed
                ? '✓ Cron установлен (каждые 5 минут, интервал регулируется выше)'
                : 'Cron не установлен.';
        })
        .catch(function() {

            const el = document.getElementById('cronStatus');

            if (el) {
                el.textContent = 'Не удалось проверить статус cron.';
            }
        });
}

function manageCron(action) {

    const st = document.getElementById('cronStatus');

    if (st) {
        st.textContent = action === 'cron_install' ? 'Установка...' : 'Удаление...';
    }

    fetch('api.php?action=' + action, {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {

            const el = document.getElementById('cronStatus');

            if (!el) {
                return;
            }

            if (!d.ok) {
                el.textContent = 'Ошибка: ' + (d.error || '');
                return;
            }

            el.textContent = action === 'cron_install'
                ? '✓ Cron установлен (каждые 5 минут, интервал регулируется выше)'
                : 'Cron удалён.';
        })
        .catch(function(e) {

            const el = document.getElementById('cronStatus');

            if (el) {
                el.textContent = 'Ошибка сети: ' + cleanMessage(e.message);
            }
        });
}

// =========================================================
// Events
// =========================================================

// Настройки: карточка в разделе Подключение
const settingsBlockEl = document.getElementById('settingsBlock');

if (settingsBlockEl) {

    loadSettings(function() {

        // URL cron из PHP (window.SABY_CRON_URL задаётся в connection.php)
        renderSettingsUI(window.SABY_CRON_URL || '');
        loadStagesAndFields();
    });
}

function loadNotifyUsers() {

    const select = document.getElementById('ntfResponsible');

    if (!select || !appSettings) {
        return;
    }

    BX24.callMethod(
        'user.get',
        {},
        function(response) {

            if (response.error()) {
                const hint = document.getElementById('ntfHint');
                if (hint) {
                    hint.textContent = 'Не удалось получить сотрудников: ' + String(response.error());
                    hint.classList.remove('hidden');
                }
                return;
            }

            const users = response.data() || [];
            const savedId = String((appSettings.notify || {}).responsibleId || '');

            select.innerHTML = '<option value="0">— не выбран —</option>' + users.map(function(u) {
                const fio = [u.LAST_NAME, u.NAME].filter(Boolean).join(' ');
                return '<option value="' + u.ID + '"' + (String(u.ID) === savedId ? ' selected' : '') + '>' + escapeHtml(fio) + '</option>';
            }).join('');
        }
    );
}
