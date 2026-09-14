'use strict';

// =========================================================
// Synchronization: просмотр статусов ЭТрН из SABY + сопоставление с Б24
//
// Ключ сопоставления: пара ИНН (отправитель + получатель).
// В SABY: ourInn (наша организация) + partnerInn (контрагент).
// В Б24: ИНН компаний ufCrm19Sender + ufCrm19Recepient.
// Авто-матчинг только для карточек без ufCrm19SabyId.
// Неоднозначные → "Несопоставленные" + уведомление ответственному.
// =========================================================

// sync.js (загружается раньше) уже объявил: syncButton, syncLoading, syncError, syncData, syncRawJson.
// Повторные const здесь вызывали бы SyntaxError "already been declared" — не дублируем.

const matchButton = document.getElementById('matchButton');
const matchLoading = document.getElementById('matchLoading');
const matchError = document.getElementById('matchError');
const matchData = document.getElementById('matchData');

// =========================================================
// Match state
// =========================================================

const OUR_INN = '0000000000'; // ИНН нашей организации — ЗАМЕНИТЬ при развёртывании

let notifySkipIds = []; // SABY ID с подавленным уведомлением (settings.notify.skipIds)

function loadNotifySkipIds(callback) {
    fetch('api.php?action=settings_get', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            notifySkipIds = (data.ok && data.settings.notify && data.settings.notify.skipIds) || [];
            if (callback) { callback(); }
        })
        .catch(function() {
            if (callback) { callback(); }
        });
}

function toggleNotifySkip(etrnId, muted) {

    const apply = function(list) {
        const i = list.indexOf(etrnId);
        if (muted && i === -1) { list.push(etrnId); }
        if (!muted && i !== -1) { list.splice(i, 1); }
        return list;
    };

    fetch('api.php?action=settings_get', { method: 'GET', credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const s = data.ok ? data.settings : {};
            s.notify = s.notify || { responsibleId: 0, onFail: true, onUnmatched: true };
            s.notify.skipIds = apply((s.notify.skipIds || []).slice());
            return fetch('api.php?action=settings_set', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(s)
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) { notifySkipIds = data.settings.notify.skipIds; }
        })
        .catch(function() { /* молча: чекбокс восстановится при перезагрузке */ });
}

let sabyEtrn = [];      // ЭТрН из SABY
let b24Items = [];      // элементы смарт-процесса Б24
let companiesCache = {}; // companyId -> {inn, title}
let currentEntityTypeId = 0;
let lastUnmatched = []; // несопоставленные последнего прогона (для «Создать карточку»)

// =========================================================
// Load ETRN statuses (read-only, из SABY)
// =========================================================

function loadEtrnStatuses() {
    hideElement(syncError);
    hideElement(syncData);

    showLoading(syncLoading, true);

    fetch('api.php?action=etrn_list', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json().then(function(data) {
                return { status: response.status, data: data };
            });
        })
        .then(function(result) {

            showLoading(syncLoading, false);

            if (!result.data.ok) {
                showError(syncError, result.data.error || 'Ошибка запроса.');
                return;
            }

            renderSyncList(result.data.etrn || []);

        })
        .catch(function(error) {
            showLoading(syncLoading, false);
            showError(syncError, 'Ошибка сети: ' + cleanMessage(error.message));
        });
}

// =========================================================
// Match: SABY ЭТрН ↔ Б24 карточки
// =========================================================

function startMatch() {
    hideElement(matchError);
    hideElement(matchData);

    if (!appState.entityTypeId) {
        showError(matchError, 'Сначала выберите смарт-процесс во вкладке ЭТрН.');
        return;
    }

    currentEntityTypeId = appState.entityTypeId;

    showLoading(matchLoading, true);

    // 1. Берём ЭТрН из SABY
    fetch('api.php?action=etrn_list', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json().then(function(data) {
                if (!data.ok) {
                    throw new Error(data.error || 'Ошибка SABY');
                }
                return data.etrn || [];
            });
        })
        .then(function(etrnList) {
            sabyEtrn = etrnList;
            // 2. Загружаем карточки Б24 (без SABY ID)
            return loadBitrixItems();
        })
        .then(function(items) {
            // Фильтр карточек из настроек: отфильтрованные нигде не участвуют
            b24Items = applyCardFilter(items || []);
            // Кэш ИНН компаний, затем матчинг
            loadCompaniesForItems(b24Items, function() {
                loadNotifySkipIds(function() {
                    showLoading(matchLoading, false);
                    runMatching();
                });
            });
        })
        .catch(function(error) {
            showLoading(matchLoading, false);
            showError(matchError, cleanMessage(error.message));
        });
}

// ---------------------------------------------------------
// Загрузка элементов смарт-процесса из Б24
// ---------------------------------------------------------

// Фильтр карточек из настроек (стадии + поля)
function applyCardFilter(items) {

    const f = (appSettings && appSettings.filter) || { mode: 'all' };

    if (!f || f.mode !== 'custom') {
        return items;
    }

    return items.filter(function(item) {

        // Стадии
        const stages = (f.stages || []).map(String);

        if (stages.length) {
            const stage = String(item.stageId || '');
            const inList = stages.indexOf(stage) !== -1;
            const include = (f.stageMode || 'include') === 'include';

            if (include && !inList) {
                return false;
            }
            if (!include && inList) {
                return false;
            }
        }

        // Поля
        const fields = f.fields || [];

        for (let i = 0; i < fields.length; i++) {

            const flt = fields[i];

            if (!flt.field) {
                continue;
            }

            let actual = item[flt.field];
            actual = Array.isArray(actual) ? actual.join('|') : (actual === null || actual === undefined ? '' : String(actual));
            actual = actual.trim();

            const val = String(flt.value || '');
            let ok = true;

            if (flt.op === 'eq') { ok = actual === val; }
            else if (flt.op === 'ne') { ok = actual !== val; }
            else if (flt.op === 'contains') { ok = val === '' || actual.toLowerCase().indexOf(val.toLowerCase()) !== -1; }
            else if (flt.op === 'empty') { ok = actual === ''; }
            else if (flt.op === 'notempty') { ok = actual !== ''; }

            if (!ok) {
                return false;
            }
        }

        return true;
    });
}

function loadBitrixItems() {

    return new Promise(function(resolve, reject) {

        BX24.callMethod(
            'crm.item.list',
            {
                entityTypeId: currentEntityTypeId,
                select: [
                    'id', 'title',
                    'ufCrm19Sender', 'ufCrm19Recepient',
                    'ufCrm19TransportCompany',
                    'ufCrm19SabyId', 'ufCrm19SabyStatus',
                    'ufCrm19SyncStatus'
                ]
            },
            function(response) {

                if (response.error()) {
                    reject(new Error(String(response.error())));
                    return;
                }

                const data = response.data() || {};
                // Берём ВСЕ карточки: сопоставленные пойдут на обновление,
                // свободные — в кандидаты автопар
                const items = (data.items || []);
                const next = response.next && typeof response.next === 'function'
                    ? response.next()
                    : null;

                if (next && items.length) {
                    collectNext(next, items, resolve, reject);
                } else {
                    resolve(items);
                }
            }
        );
    });
}

function collectNext(next, collected, resolve, reject) {

    next(function(response) {

        if (response.error()) {
            reject(new Error(String(response.error())));
            return;
        }

        const data = response.data() || {};
        const items = (data.items || []);

        const all = collected.concat(items);

        const next2 = response.next && typeof response.next === 'function'
            ? response.next()
            : null;

        if (next2 && items.length) {
            collectNext(next2, all, resolve, reject);
        } else {
            resolve(all);
        }
    });
}
// ---------------------------------------------------------
// Сопоставление: пара ИНН (отправитель + получатель)
// ---------------------------------------------------------

function runMatching() {

    const pairs = [];      // уверенные пары
    const unmatched = [];  // неоднозначные (несколько кандидатов)
    const usedItems = new Set();  // карточки, занятые парами в этом прогоне

    // Карточки с уже записанным SABY ID: map sabyId -> item (для обновления пар)
    const boundBySabyId = {};
    b24Items.forEach(function(item) {
        const sid = String(item.ufCrm19SabyId || '').trim();
        if (sid !== '' && sid) {
            boundBySabyId[sid] = item;
        }
    });

    sabyEtrn.forEach(function(etrn) {

        // 1. ЭТрН уже привязана к карточке → пара на обновление
        const bound = boundBySabyId[etrn.id];
        if (bound) {
            pairs.push({ etrn: etrn, item: bound });
            return;
        }

        const sabyPair = [etrn.ourInn, etrn.partnerInn]
            .filter(function(v) { return v; })
            .sort()
            .join('|');

        if (!sabyPair) {
            unmatched.push({ etrn: etrn, reason: 'В SABY не хватает ИНН участников' });
            return;
        }

        // 2. Свободные карточки (без SABY ID) — кандидаты на привязку
        const candidates = b24Items.filter(function(item) {

            // Карточка уже привязана — не кандидат
            if (String(item.ufCrm19SabyId || '').trim() !== '') {
                return false;
            }

            // Уже занята другой парой в этом прогоне
            const key = String(item.id);
            if (usedItems.has(key)) {
                return false;
            }

            const senderId = String(item.ufCrm19Sender || '').replace(/[^0-9]/g, '');
            const recvId = String(item.ufCrm19Recepient || '').replace(/[^0-9]/g, '');

            const senderInn = companiesCache[senderId]?.inn || '';
            const recvInn = companiesCache[recvId]?.inn || '';

            const b24Pair = [senderInn, recvInn]
                .filter(function(v) { return v; })
                .sort()
                .join('|');

            if (!b24Pair) {
                return false;
            }

            return b24Pair === sabyPair;
        });

        if (candidates.length === 1) {
            usedItems.add(String(candidates[0].id));
            pairs.push({ etrn: etrn, item: candidates[0] });
        } else if (candidates.length === 0) {
            unmatched.push({ etrn: etrn, item: null, reason: 'В Б24 нет карточки с такой парой ИНН' });
        } else {
            unmatched.push({ etrn: etrn, item: null, candidates: candidates, reason: 'Несколько карточек-кандидатов' });
        }
    });

    // Автопары записываем в карточки сразу, потом рендерим
    bindPairsAuto(pairs, function() {
        renderMatch(pairs, unmatched);

        // После записи автопар заполняем поля из титулов ЭТрН
        if (pairs.length) {
            fillAllPairs(pairs);
        }
    });
}

// Последовательное заполнение полей всех автопар из SABY
function fillAllPairs(pairs) {

    let i = 0;

    const next = function() {

        if (i >= pairs.length) {
            return;
        }

        const p = pairs[i++];

        fetch('api.php?action=etrn_details&id=' + encodeURIComponent(p.etrn.id), {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then(function(r) { return r.json(); })
            .then(function(d) {

                if (!d.ok) {
                    next();
                    return;
                }

                applySabyFieldsToItem(p.item.id, d.fields || {}, next, p.etrn);
            })
            .catch(function() {
                next();
            });
    };

    next();
}

/**
 * Записывает поля титула в карточку Б24 с таймлайн-комментарием
 * только по изменённым/пустым полям.
 * callback(changed: bool) — изменилось ли хоть что-то.
 * Вход: etrnListItem (опц.) — данные списка ЭТрН для статуса/фазы/ссылок.
 */
function applySabyFieldsToItem(itemId, f, callback, etrnListItem) {

    BX24.callMethod(
        'crm.item.get',
        { entityTypeId: currentEntityTypeId, id: itemId },
        function(resp) {

            if (resp.error()) {
                callback(false);
                return;
            }

            const item = (resp.data().item || resp.data() || {});
            const toUpdate = {};
            const changes = [];

            // Адресные поля Б24 хранят 'текст|;|<ID адреса>'. Сравниваем и пишем только чистый текст;
            // если текст совпадает (хвост |;|ID отличается) — поле не трогаем, чтобы не создавать лишние записи.
            const ADDRESS_FIELDS = new Set(['ufCrm19Dep', 'ufCrm19Dest']);

            const clean = function(v) {
                const s = String(v || '');
                return s.split('|;|')[0].trim();
            };

            TITLE_FIELDS.forEach(function(pair) {

                const fieldName = pair[0];
                const newVal = normalizeVal(pair[1], f[pair[1]]);

                if (ADDRESS_FIELDS.has(fieldName)) {

                    const oldClean = clean(item[fieldName]);
                    const newClean = clean(newVal);

                    if (newClean === '' || oldClean === newClean) {
                        return; // совпадает (или пусто в SABY) — не пишем и не в таймлайн
                    }

                    toUpdate[fieldName] = newClean;
                    changes.push({ label: pair[2], from: oldClean, to: newClean });
                    return;
                }

                const oldVal = normalizeVal(pair[1], item[fieldName]);

                if (newVal === '' || oldVal === newVal) {
                    return;
                }

                toUpdate[fieldName] = newVal;
                changes.push({ label: pair[2], from: oldVal, to: newVal });
            });

            // Статус/фаза из списка ЭТрН (меняются при движении груза)
            if (etrnListItem) {

                const stateName = etrnListItem.stateName || '';
                const phase = etrnListItem.phaseName || '';

                const oldStatus = normalizeVal('x', item.ufCrm19SabyStatus);
                const newStatus = [phase, stateName].filter(Boolean).join(' — ');

                if (newStatus && oldStatus !== newStatus) {
                    toUpdate.ufCrm19SabyStatus = newStatus;
                    changes.push({ label: 'Статус SABY', from: oldStatus, to: newStatus });
                }

                const oldSyncStatus = normalizeVal('x', item.ufCrm19SyncStatus);
                if (etrnListItem.stateCode === '7' && oldSyncStatus !== 'done') {
                    toUpdate.ufCrm19SyncStatus = 'done';
                }
            }

            // Перевозчик
            const finishWrite = function() {

                if (!Object.keys(toUpdate).length) {
                    callback(false);
                    return;
                }

                toUpdate.ufCrm19SyncDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

                BX24.callMethod(
                    'crm.item.update',
                    {
                        entityTypeId: currentEntityTypeId,
                        id: itemId,
                        fields: toUpdate
                    },
                    function(r2) {

                        if (r2.error() || !changes.length) {
                            callback(false);
                            return;
                        }

                        // Таймлайн-комментарий: только изменённые поля
                        BX24.callMethod(
                            'crm.timeline.comment.add',
                            {
                                fields: {
                                    ENTITY_TYPE: 'DYNAMIC_' + currentEntityTypeId,
                                    ENTITY_ID: itemId,
                                    POST_TITLE: 'SABY ЭТрН: обновление полей',
                                    COMMENT: changes.map(function(c) {
                                        return c.label + ': ' + c.to +
                                            (c.from !== '' ? ' (было: ' + c.from + ')' : '');
                                    }).join('\n')
                                }
                            },
                            function() {
                                callback(true);
                            }
                        );
                    }
                );
            };

            const carrierInn = f.carrierInn || '';

            if (carrierInn) {
                findCompanyByInn(carrierInn, function(found) {
                    if (found) {
                        toUpdate.ufCrm19TransportCompany = found;
                        toUpdate.ufCrm19Comment = '';
                    } else {
                        toUpdate.ufCrm19Comment =
                            'Перевозчик из ЭТрН (нет в Б24): ' +
                            (f.carrierName || 'не указан') + '. ' +
                            (f.carrierRequisites || '');
                    }
                    finishWrite();
                });
            } else {
                toUpdate.ufCrm19Comment =
                    'Перевозчик из ЭТрН (нет в Б24): ' +
                    (f.carrierName || 'не указан') + '. ' +
                    (f.carrierRequisites || '');
                finishWrite();
            }
        });
}

// Запись автопар в карточки Б24 (идемпотентно: тот же SABY ID не перезаписываем)
function bindPairsAuto(pairs, callback) {

    const toBind = pairs.filter(function(p) {
        return String(p.item.ufCrm19SabyId || '') !== String(p.etrn.id);
    });

    if (!toBind.length) {
        callback();
        return;
    }

    let done = 0;

    toBind.forEach(function(p) {

        BX24.callMethod(
            'crm.item.update',
            {
                entityTypeId: currentEntityTypeId,
                id: p.item.id,
                fields: {
                    ufCrm19SabyId: p.etrn.id,
                    ufCrm19SabyNumber: p.etrn.number,
                    ufCrm19SabyStatus: p.etrn.stateName || '',
                    ufCrm19SabyUrl: p.etrn.cabinetUrl || '',
                    ufCrm19SyncStatus: 'auto',
                    ufCrm19SyncDate: new Date().toISOString().slice(0, 19).replace('T', ' ')
                }
            },
            function(response) {

                if (response.error()) {
                    showError(matchError, 'Ошибка записи автопары #' + p.item.id + ': ' + String(response.error()));
                } else {
                    p.item.ufCrm19SabyId = String(p.etrn.id);
                }

                done++;

                if (done >= toBind.length) {
                    callback();
                }
            }
        );
    });
}

// Повторный прогон матчинга удалён: теперь кэш ИНН грузится ДО матчинга (в startMatch).

// ---------------------------------------------------------
// Кэш ИНН компаний Б24 (ИНН в реквизитах: crm.requisite.list)
// ---------------------------------------------------------

function loadCompaniesForItems(items, callback) {

    const ids = new Set();

    items.forEach(function(item) {
        [item.ufCrm19Sender, item.ufCrm19Recepient].forEach(function(v) {
            const id = String(v || '').replace(/[^0-9]/g, '');
            if (id && !companiesCache[id]) {
                ids.add(id);
            }
        });
    });

    if (!ids.size) {
        callback();
        return;
    }

    const idList = Array.from(ids);
    let done = 0;

    idList.forEach(function(companyId) {

        BX24.callMethod(
            'crm.requisite.list',
            {
                filter: {
                    ENTITY_TYPE_ID: 4,
                    ENTITY_ID: companyId
                }
            },
            function(response) {

                let inn = '';
                let title = '';

                if (!response.error()) {
                    let reqs = response.data() || [];
                    reqs = Array.isArray(reqs.requisites)
                        ? reqs.requisites
                        : (Array.isArray(reqs) ? reqs : []);

                    const r = reqs[0] || {};

                    inn = r.RQ_INN ?? '';
                    title = r.RQ_COMPANY_NAME ?? r.RQ_NAME ?? '';
                }

                companiesCache[companyId] = {
                    inn: String(inn),
                    title: String(title)
                };

                done++;

                if (done >= idList.length) {
                    callback();
                }
            }
        );
    });
}

// ---------------------------------------------------------
// Рендер сопоставления
// ---------------------------------------------------------

function renderMatch(pairs, unmatched) {

    let html = '';

    // --- Уверенные пары ---
    html += '<h3>Автосопоставление</h3>';

    if (pairs.length) {
        html += '<div class="actions" style="margin-bottom:8px;"><button type="button" id="refreshAllBtn" class="success">Обновить все</button></div>';
    }

    if (!pairs.length) {
        html += '<div class="small">Автоматических пар не найдено.</div>';
    } else {
        html += '<div class="match-list">';
        pairs.forEach(function(p) {

            const stParts = [p.etrn.phaseName, p.etrn.stateName].filter(Boolean).join(' — ');

            html +=
                '<div class="sync-row">' +
                    '<div class="sync-main">' +
                        '<div class="sync-title">' + escapeHtml(p.etrn.title) + '</div>' +
                        '<div class="sync-meta">' +
                            '<span>SABY: № ' + escapeHtml(p.etrn.number) + '</span>' +
                            '<span>от ' + escapeHtml(p.etrn.date) + '</span>' +
                            '<span>' + escapeHtml(p.etrn.partnerInn) + '</span>' +
                            (stParts ? '<span class="badge blue">' + escapeHtml(stParts) + '</span>' : '') +
                        '</div>' +
                        '<div class="sync-meta">' +
                            '<span>Б24: #' + escapeHtml(p.item.id) + '</span>' +
                            '<span>' + escapeHtml(p.item.title || '') + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="sync-badges">' +
                        '<button type="button" class="bind-btn refresh-btn" ' +
                            'data-etrn="' + escapeHtml(p.etrn.id) + '" ' +
                            'data-item="' + escapeHtml(p.item.id) + '">' +
                            'Обновить из SABY' +
                        '</button>' +
                    '</div>' +
                '</div>';
        });
        html += '</div>';
    }

    // --- Несопоставленные ---
    html += '<h3 style="margin-top:18px;">Несопоставленные</h3>';

    if (!unmatched.length) {
        html += '<div class="small">Нет неоднозначных или непарных ЭТрН.</div>';
    } else {
        html += '<div class="match-list">';
        unmatched.forEach(function(u, ui) {

            let extra = '';

            if (u.candidates && u.candidates.length > 1) {
                extra = '<div class="small">Кандидатов: ' + u.candidates.length + '.</div>';

                // Ручная привязка к одному из кандидатов
                extra += '<div class="bind-controls">';
                u.candidates.forEach(function(c) {
                    extra +=
                        '<button type="button" class="bind-btn" ' +
                            'data-etrn="' + escapeHtml(u.etrn.id) + '" ' +
                            'data-item="' + escapeHtml(c.id) + '">' +
                            'Привязать: #' + escapeHtml(c.id) + ' ' + escapeHtml(c.title || '') +
                        '</button>';
                });
                extra += '</div>';
            } else {
                // Кандидатов нет или один — всегда даём выбор из всех карточек Б24
                extra = '<div class="small">' + escapeHtml(u.reason || '') + '</div>';
                extra +=
                    '<div class="bind-controls">' +
                        '<select class="bind-select" data-etrn="' + escapeHtml(u.etrn.id) + '">' +
                            '<option value="">— выберите карточку Б24 —</option>' +
                            b24Items.map(function(c) {
                                const inn = companiesCache[String(c.ufCrm19Sender || '').replace(/[^0-9]/g, '')]?.inn || '';
                                const inn2 = companiesCache[String(c.ufCrm19Recepient || '').replace(/[^0-9]/g, '')]?.inn || '';
                                return '<option value="' + escapeHtml(c.id) + '">' +
                                    '#' + escapeHtml(c.id) + ' ' + escapeHtml(c.title || '') +
                                    (inn || inn2 ? ' [' + escapeHtml(inn + (inn2 ? ' / ' + inn2 : '')) + ']' : '') +
                                    '</option>';
                            }).join('') +
                        '</select>' +
                        '<button type="button" class="bind-btn bind-from-select">' +
                            'Привязать выбранную' +
                        '</button>' +
                    '</div>';
            }

            html +=
                '<div class="sync-row">' +
                    '<div class="sync-main">' +
                        '<div class="sync-title">' + escapeHtml(u.etrn.title) + '</div>' +
                        '<div class="sync-meta">' +
                            '<span>SABY: № ' + escapeHtml(u.etrn.number) + '</span>' +
                            '<span>' + escapeHtml(u.etrn.partnerInn) + '</span>' +
                        '</div>' +
                        extra +
                        '<div class="bind-controls" style="margin-top:6px;">' +
                            '<button type="button" class="small-btn create-card-btn" data-etrn-idx="' + ui + '">Создать карточку в Б24</button>' +
                            '<span class="small create-card-status" style="margin-left:8px;"></span>' +
                        '</div>' +
                        '<label style="display:flex; align-items:center; gap:8px; margin-top:6px; font-size:12px;">' +
                            '<input type="checkbox" class="mute-unmatched-cb" data-etrn-id="' + escapeHtml(u.etrn.id) + '"' +
                                (notifySkipIds.indexOf(u.etrn.id) !== -1 ? ' checked' : '') +
                                ' style="margin:0; width:auto; flex:0 0 auto;">' +
                            '<span>Не уведомлять об этой ЭТрН</span>' +
                        '</label>' +
                    '</div>' +
                '</div>';
        });
        html += '</div>';
    }

    matchData.innerHTML = html;
    matchData.classList.remove('hidden');

    // «Не уведомлять об этой ЭТрН»
    matchData.querySelectorAll('.mute-unmatched-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            toggleNotifySkip(cb.getAttribute('data-etrn-id'), cb.checked);
        });
    });

    // Создание карточки Б24 из несопоставленной ЭТрН
    matchData.querySelectorAll('.create-card-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            createCardForEtrn(parseInt(btn.getAttribute('data-etrn-idx'), 10), btn);
        });
    });

    // Обработчики кнопок привязки и обновления — строго ПОСЛЕ innerHTML
    matchData.querySelectorAll('.bind-btn, .refresh-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {

            // Обновление уже привязанной пары: заполняем поля из титулов
            if (btn.classList.contains('refresh-btn')) {
                fillItemFromSaby(
                    btn.getAttribute('data-etrn'),
                    btn.getAttribute('data-item'),
                    btn,
                    true
                );
                return;
            }

            // Кнопка рядом с select — берём значение из него
            const wrap = btn.closest('.bind-controls');
            const select = wrap ? wrap.querySelector('.bind-select') : null;

            if (select) {
                if (!select.value) {
                    return;
                }
                bindEtrnToItem(select.getAttribute('data-etrn'), select.value, btn);
                return;
            }

            bindEtrnToItem(btn.getAttribute('data-etrn'), btn.getAttribute('data-item'), btn);
        });
    });

    // Кнопка "Обновить все"
    const refreshAllBtn = matchData.querySelector('#refreshAllBtn');

    if (refreshAllBtn) {
        refreshAllBtn.addEventListener('click', function() {
            refreshAllMatched();
        });
    }

    // Сохраняем несопоставленных для кнопки "Создать карточку"
    lastUnmatched = unmatched;
}

// =========================================================
// Массовое обновление всех сопоставленных пар из SABY
// =========================================================

function refreshAllMatched() {

    hideElement(matchError);
    hideElement(matchNotice);

    const btn = document.getElementById('refreshAllBtn');

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Обновление...';
    }

    let i = 0;
    let updated = 0;

    // Собираем пары из DOM: etrnId → ищем данные списка sabyEtrn
    const rows = [];
    matchData.querySelectorAll('.refresh-btn').forEach(function(b) {
        const etrnId = b.getAttribute('data-etrn');
        const etrnData = sabyEtrn.find(function(e) { return e.id === etrnId; }) || null;
        rows.push({
            etrnId: etrnId,
            itemId: b.getAttribute('data-item'),
            etrnData: etrnData,
            button: b
        });
    });

    const next = function() {

        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Обновить все (' + updated + ')';
        }

        if (i >= rows.length) {
            startMatchSilent();
            return;
        }

        const row = rows[i++];

        fetch('api.php?action=etrn_details&id=' + encodeURIComponent(row.etrnId), {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then(function(r) { return r.json(); })
            .then(function(d) {

                if (!d.ok) {
                    next();
                    return;
                }

                applySabyFieldsToItem(row.item.id, d.fields || {}, function(changed) {
                    if (changed) {
                        updated++;
                    }
                    next();
                }, row.etrnData);
            })
            .catch(function() {
                next();
            });
    };

    next();
}

// =========================================================
// Events
// =========================================================

if (matchButton) {
    matchButton.addEventListener('click', startMatch);
}

// =========================================================
// Ручная привязка: запись SABY ID в карточку Б24
// =========================================================

function bindEtrnToItem(etrnId, itemId, button) {

    if (!currentEntityTypeId) {
        return;
    }

    button.disabled = true;
    button.textContent = 'Привязка...';

    BX24.callMethod(
        'crm.item.update',
        {
            entityTypeId: currentEntityTypeId,
            id: itemId,
            fields: {
                ufCrm19SabyId: etrnId,
                ufCrm19SyncStatus: 'manual',
                ufCrm19SyncDate: new Date().toISOString().slice(0, 19).replace('T', ' ')
            }
        },
        function(response) {

            if (response.error()) {
                button.disabled = false;
                button.textContent = 'Привязать';
                showError(matchError, 'Ошибка записи в Б24: ' + String(response.error()));
                return;
            }

            // Убираем из локальных данных и перерисовываем
            b24Items = b24Items.filter(function(i) {
                return String(i.id) !== String(itemId);
            });

            startMatchSilent();
        }
    );
}

// Тихий повторный матчинг (без лоадера) — после ручной привязки
function startMatchSilent() {

    fetch('api.php?action=etrn_list', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {

            if (!data.ok) {
                return;
            }

            sabyEtrn = data.etrn || [];
            loadBitrixItems()
                .then(function(items) {
                    b24Items = items;
                    runMatching();
                });
        });
}

// =========================================================
// Заполнение полей карточки из титулов ЭТрН (SABY)
// =========================================================

const TITLE_FIELDS = [
    ['ufCrm19ManagerPhone', 'carrierPhone',  'Телефон менеджера перевозчика'],
    ['ufCrm19DriverName',   'driverName',    'ФИО водителя'],
    ['ufCrm19DriverPhone',  'driverPhone',   'Телефон водителя'],
    ['ufCrm19Dep',          'dep',           'Место отправления'],
    ['ufCrm19Dest',         'dest',          'Место назначения'],
    ['ufCrm19Goods',        'goods',         'Товары'],
    ['ufCrm19Weight',       'weight',        'Масса, кг'],
    ['ufCrm19Size',         'size',          'Объем, м. куб.'],
    ['ufCrm19Qantity',      'quantity',      'Количество мест']
];

/**
 * Тянет титулы ЭТрН по SABY ID и заполняет поля карточки.
 * В таймлайн пишет ТОЛЬКО изменённые или ранее пустые поля.
 */
function fillItemFromSaby(etrnId, itemId, button, etrnListItem) {

    button.disabled = true;
    button.textContent = 'Загрузка из SABY...';

    fetch('api.php?action=etrn_details&id=' + encodeURIComponent(etrnId), {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {

            if (!data.ok) {
                button.disabled = false;
                button.textContent = 'Обновить из SABY';
                showError(matchError, 'SABY details: ' + (data.error || data.parseError || 'ошибка'));
                return;
            }

            const f = data.fields || {};

            // Читаем карточку: что уже заполнено
            BX24.callMethod(
                'crm.item.get',
                { entityTypeId: currentEntityTypeId, id: itemId },
                function(resp) {

                    if (resp.error()) {
                        button.disabled = false;
                        button.textContent = 'Обновить из SABY';
                        showError(matchError, 'Б24: ' + String(resp.error()));
                        return;
                    }

                    const item = (resp.data().item || resp.data() || {});

                    const toUpdate = {};
                    const changes = [];

                    TITLE_FIELDS.forEach(function(pair) {
                        const fieldName = pair0(pair);
                        const srcKey = pair1(pair);
                        const label = pair2(pair);

                        const newVal = normalizeVal(srcKey, f[srcKey]);
                        const oldVal = normalizeVal(srcKey, item[fieldName]);

                        // Пустое в SABY — не трогаем и не пишем
                        if (newVal === '') {
                            return;
                        }

                        // Не изменилось — в комментарий не пишем
                        if (oldVal === newVal) {
                            return;
                        }

                        toUpdate[fieldName] = newVal;
                        changes.push({
                            label: label,
                            from: oldVal,
                            to: newVal
                        });
                    });

                    // Перевозчик: ищем по ИНН в реквизитах
                    const carrierInn = f.carrierInn || '';
                    const carrierName = f.carrierName || '';
                    const finish = function() {
                        applyTitleUpdate(itemId, toUpdate, changes, button, etrnListItem);
                    };

                    if (carrierInn) {
                        findCompanyByInn(carrierInn, function(found) {
                            if (found) {
                                // Есть в Б24: привязываем + телефон менеджера
                                toUpdate.ufCrm19TransportCompany = found;
                                toUpdate.ufCrm19Comment = '';
                            } else {
                                // Нет в Б24 — реквизиты в комментарий
                                toUpdate.ufCrm19Comment =
                                    'Перевозчик из ЭТрН (нет в Б24): ' +
                                    (f.carrierName || '') + '. ' +
                                    (f.carrierRequisites || '');
                            }
                            finish();
                        });
                    } else {
                        toUpdate.ufCrm19Comment =
                            'Перевозчик из ЭТрН (нет в Б24): ' +
                            (carrierName || 'не указан') + '. ' +
                            (f.carrierRequisites || '');
                        finish();
                    }
                });
        })
        .catch(function(err) {
            button.disabled = false;
            button.textContent = 'Обновить из SABY';
            showError(matchError, 'Ошибка сети: ' + cleanMessage(err.message));
        });
}

function pair0(p) { return p[0]; }
function pair1(p) { return p[1]; }
function pair2(p) { return p[2]; }

// Приведение значений к строке (числовые поля Б24 читаются как числа)
function normalizeVal(srcKey, v) {
    if (v === null || v === undefined) {
        return '';
    }
    return String(v).trim();
}

// Поиск компании по ИНН через реквизиты
function findCompanyByInn(inn, callback) {

    BX24.callMethod(
        'crm.requisite.list',
        {
            filter: { RQ_INN: inn },
            select: ['ID', 'ENTITY_ID']
        },
        function(response) {

            if (response.error()) {
                callback(null);
                return;
            }

            let reqs = response.data() || [];
            reqs = Array.isArray(reqs.requisites)
                ? reqs.requisites
                : (Array.isArray(reqs) ? reqs : []);

            if (!reqs.length) {
                callback(null);
                return;
            }

            callback(Number(reqs[0].ENTITY_ID) || null);
        }
    );
}

// Запись полей + комментарий таймлайна
function applyTitleUpdate(itemId, toUpdate, changes, button, etrnListItem) {

    const keys = Object.keys(toUpdate);

    const finish = function() {

        button.disabled = false;

        if (etrnListItem) {
            button.textContent = 'Обновлено';
        } else {
            button.textContent = 'Обновить из SABY';
        }

        if (changes.length) {
            renderChangeNotice(changes);
            startMatchSilent();
        } else {
            startMatchSilent();
        }
    };

    if (!keys.length) {
        finish();
        return;
    }

    toUpdate.ufCrm19SyncDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

    BX24.callMethod(
        'crm.item.update',
        {
            entityTypeId: currentEntityTypeId,
            id: itemId,
            fields: toUpdate
        },
        function(response) {

            if (response.error()) {
                button.disabled = false;
                button.textContent = 'Обновить из SABY';
                showError(matchError, 'Ошибка записи в Б24: ' + String(response.error()));
                return;
            }

            // Таймлайн: только изменённые поля
            if (changes.length) {

                const lines = changes.map(function(c) {
                    return '• ' + c.label + ': ' + c.to +
                        (c.from !== '' ? ' (было: ' + c.from + ')' : '');
                });

                BX24.callMethod(
                    'crm.timeline.comment.add',
                    {
                        fields: {
                            ENTITY_TYPE: 'DYNAMIC_' + currentEntityTypeId,
                            ENTITY_ID: itemId,
                            POST_TITLE: 'SABY ЭТрН: обновление полей',
                            COMMENT: changes.map(function(c) { return c.label + ': ' + c.to; }).join('\n')
                        }
                    },
                    function() {
                        finish();
                    }
                );
            } else {
                finish();
            }
        }
    );
}

function renderChangeNotice(changes) {
    // Краткое сообщение в matchError зелёным не выйдет (error style) — используем простой alert-free вывод
    const notice = document.getElementById('matchNotice');

    if (!notice) {
        return;
    }

    notice.innerHTML = '<div class="small">Записано полей: ' + changes.length + '.</div>';
    notice.classList.remove('hidden');
}


// =========================================================
// Создание карточки Б24 из несопоставленной ЭТрН
// =========================================================

function createCardForEtrn(idx, button) {

    const etrn = lastUnmatched[idx] || null;
    const item = etrn && etrn.etrn ? etrn.etrn : null;

    const status = button.parentNode.querySelector('.create-card-status');

    if (!item || !currentEntityTypeId) {
        if (status) { status.textContent = 'Ошибка: нет данных.'; }
        return;
    }

    button.disabled = true;
    if (status) { status.textContent = 'Создание...'; }

    // Поиск компаний по ИНН (наша сторона → партнёр; стороны карточки: sender/receiver)
    // ourInn = наша организация; partnerInn = контрагент.
    // Наша организация в карточке — sender или receiver в зависимости от направления ЭТрН:
    //   Входящий  → мы = получатель (Recepient), партнёр = отправитель (Sender)
    //   Исходящий → мы = отправитель (Sender), партнёр = получатель (Recepient)
    const ourIsReceiver = (item.direction === 'Входящий');

    const partnerInn = item.partnerInn || '';

    findCompanyByInn(OUR_INN, function(ourCompanyId) {
        findCompanyByInn(partnerInn, function(partnerCompanyId) {

            const senderCompanyId  = ourIsReceiver ? partnerCompanyId : ourCompanyId;
            const receiverCompanyId = ourIsReceiver ? ourCompanyId : partnerCompanyId;

            const fields = {
                TITLE: item.title || ('ЭТрН от ' + (item.date || '')),
                ufCrm19SabyId: item.id,
                ufCrm19SabyNumber: item.number || '',
                ufCrm19SabyStatus: item.stateName || '',
                ufCrm19SabyUrl: item.cabinetUrl || '',
                ufCrm19SyncStatus: 'auto',
                ufCrm19SyncDate: new Date().toISOString().slice(0, 19).replace('T', ' ')
            };

            if (senderCompanyId) {
                fields.ufCrm19Sender = senderCompanyId;
            }
            if (receiverCompanyId) {
                fields.ufCrm19Recepient = receiverCompanyId;
            }

            BX24.callMethod(
                'crm.item.add',
                {
                    entityTypeId: currentEntityTypeId,
                    fields: fields
                },
                function(resp) {

                    if (resp.error()) {
                        if (status) { status.textContent = 'Ошибка: ' + String(resp.error()); }
                        button.disabled = false;
                        return;
                    }

                    const newId = (resp.data() || {}).id;

                    if (status) { status.textContent = '✓ Карточка создана (#' + newId + ')'; }
                    button.disabled = true;

                    // Перезагружаем список через пару секунд
                    setTimeout(function() { startMatch(); }, 1200);
                }
            );
        });
    });
}
