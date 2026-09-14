'use strict';

// =========================================================
// EТрН
// =========================================================

const etrnId = document.getElementById('etrnId');
const loadEtrnButton = document.getElementById('loadEtrnButton');

const etrnLoading = document.getElementById('etrnLoading');
const etrnError = document.getElementById('etrnError');

const etrnData = document.getElementById('etrnData');
const etrnDataTable = document.getElementById('etrnDataTable');
const etrnRawJson = document.getElementById('etrnRawJson');

// =========================================================
// Load EТрН
// =========================================================

function loadEtrn() {
    hideElement(etrnError);
    hideElement(etrnData);

    const id = Number(
        etrnId?.value || 0
    );

    const entityTypeId =
        appState.entityTypeId;

    if (!id) {
        showError(
            etrnError,
            'Введите ID ЭТрН.'
        );
        return;
    }

    if (!entityTypeId) {
        showError(
            etrnError,
            'Сначала выберите смарт-процесс.'
        );
        return;
    }

    showLoading(etrnLoading, true);

    BX24.callMethod(
        'crm.item.get',
        {
            entityTypeId: entityTypeId,
            id: id
        },
        function(response) {
            showLoading(etrnLoading, false);

            if (response.error()) {
                showError(
                    etrnError,
                    response.error()
                );
                return;
            }

            const result = response.data() || {};
            const item = result.item ?? result;

            if (
                !item ||
                typeof item !== 'object'
            ) {
                showError(
                    etrnError,
                    'Bitrix24 не вернул данные ЭТрН.'
                );
                return;
            }

            renderEtrnData(item);
        }
    );
}

// =========================================================
// Render EТрН
// =========================================================

function renderEtrnData(item) {
    const fields = [
        ['ID', 'id'],
        ['Название', 'title'],
        ['Отправитель', 'ufCrm19Sender'],
        ['Получатель', 'ufCrm19Recepient'],
        ['Перевозчик', 'ufCrm19TransportCompany'],
        ['Телефон менеджера', 'ufCrm19ManagerPhone'],
        ['ФИО водителя', 'ufCrm19DriverName'],
        ['Телефон водителя', 'ufCrm19DriverPhone'],
        ['Товары', 'ufCrm19Goods'],
        ['Масса, кг', 'ufCrm19Weight'],
        ['Объем, м³', 'ufCrm19Size'],
        ['Количество мест', 'ufCrm19Qantity'],
        ['Место отправления', 'ufCrm19Dep'],
        ['Место назначения', 'ufCrm19Dest'],
        ['SABY ID', 'ufCrm19SabyId'],
        ['SABY статус', 'ufCrm19SabyStatus'],
        ['SABY номер', 'ufCrm19SabyNumber'],
        ['SABY URL', 'ufCrm19SabyUrl'],
        ['Статус синхронизации', 'ufCrm19SyncStatus']
    ];

    etrnDataTable.innerHTML = '';

    fields.forEach(function([title, key]) {
        const value = item[key];

        const row = document.createElement('div');

        row.className = 'etrn-row';

        row.innerHTML =
            '<div class="etrn-label">' +
                escapeHtml(title) +
            '</div>' +
            '<div class="etrn-value">' +
                escapeHtml(formatEtrnValue(value)) +
            '</div>';

        etrnDataTable.appendChild(row);
    });

    if (etrnRawJson) {
        etrnRawJson.textContent =
            JSON.stringify(
                item,
                null,
                2
            );
    }

    etrnData.classList.remove('hidden');
}

// =========================================================
// Format value
// =========================================================

function formatEtrnValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    if (Array.isArray(value)) {
        return value
            .map(function(item) {
                if (
                    item !== null &&
                    typeof item === 'object'
                ) {
                    return JSON.stringify(item);
                }

                return String(item ?? '');
            })
            .join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

// =========================================================
// Events
// =========================================================

if (loadEtrnButton) {
    loadEtrnButton.addEventListener(
        'click',
        loadEtrn
    );
}