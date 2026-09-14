'use strict';

// =========================================================
// Synchronization: только read-only просмотр статусов ЭТрН из SABY
// =========================================================

const syncButton = document.getElementById('syncButton');
const syncLoading = document.getElementById('syncLoading');
const syncError = document.getElementById('syncError');
const syncData = document.getElementById('syncData');
const syncRawJson = document.getElementById('syncRawJson');

// =========================================================
// Load ETRN statuses
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
// Render list
// =========================================================

function renderSyncList(etrnList) {

    if (!Array.isArray(etrnList) || !etrnList.length) {
        syncData.innerHTML =
            '<div class="status info">' +
            '<span class="status-title">ЭТрН не найдены</span>' +
            '<span class="status-subtitle">За последний месяц транспортных накладных нет.</span>' +
            '</div>';

        syncData.classList.remove('hidden');
        return;
    }

    let html = '';

    etrnList.forEach(function(item) {

        const phase = item.phaseName
            ? escapeHtml(item.phaseName) + ' (' + escapeHtml(item.phaseTitle) + ')'
            : '—';

        const pdf = item.pdfUrl
            ? ' <a href="' + escapeHtml(item.pdfUrl) + '" target="_blank">PDF</a>'
            : '';

        const cabinet = item.cabinetUrl
            ? ' <a href="' + escapeHtml(item.cabinetUrl) + '" target="_blank">Кабинет</a>'
            : '';

        html +=
            '<div class="sync-row">' +
                '<div class="sync-main">' +
                    '<div class="sync-title">' +
                        escapeHtml(item.title) +
                    '</div>' +
                    '<div class="sync-meta">' +
                        '<span>№ ' + escapeHtml(item.number) + '</span>' +
                        '<span>от ' + escapeHtml(item.date) + '</span>' +
                        '<span>' + escapeHtml(item.direction) + '</span>' +
                        '<span>ИНН: ' + escapeHtml(item.counterpartyInn) + '</span>' +
                        '<span>' + escapeHtml(item.counterpartyName) + '</span>' +
                        (cabinet) + (pdf) +
                    '</div>' +
                    '<div class="sync-events">' +
                        buildEventsHtml(item.events) +
                    '</div>' +
                '</div>' +
                '<div class="sync-badges">' +
                    '<span class="badge blue">' +
                        'Статус: ' + escapeHtml(item.stateName || item.stateCode) +
                    '</span>' +
                    '<span class="badge green">' +
                        'Перевозка: ' + phase +
                    '</span>' +
                    (item.phaseDate
                        ? '<span class="badge">' + escapeHtml(item.phaseDate) + '</span>'
                        : '') +
                '</div>' +
            '</div>';
    });

    syncData.innerHTML = html;
    syncData.classList.remove('hidden');

    if (syncRawJson) {
        syncRawJson.textContent = JSON.stringify(etrnList, null, 2);
    }
}

function buildEventsHtml(events) {

    if (!Array.isArray(events) || !events.length) {
        return '';
    }

    return events
        .map(function(ev) {
            return '<div class="sync-event">' +
                escapeHtml(ev.time) + ' — ' +
                escapeHtml(ev.name) +
                (ev.action ? ' / ' + escapeHtml(ev.action) : '') +
                (ev.state ? ' [' + escapeHtml(ev.state) + ']' : '') +
            '</div>';
        })
        .join('');
}

// =========================================================
// Events
// =========================================================

if (syncButton) {
    syncButton.addEventListener('click', loadEtrnStatuses);
}