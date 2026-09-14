'use strict';

// =========================================================
// Global state
// =========================================================

let smartProcesses = [];


// =========================================================
// Storage keys — в state.js
// =========================================================


// =========================================================
// DOM
// =========================================================

const smartProcess =
    document.getElementById('smartProcess');

const smartProcessInfo =
    document.getElementById('smartProcessInfo');

const smartProcessError =
    document.getElementById('smartProcessError');

const smartProcessLoading =
    document.getElementById('smartProcessLoading');

const fieldsCard =
    document.getElementById('fieldsCard');

const fieldsTable =
    document.getElementById('fieldsTable');

const fieldsError =
    document.getElementById('fieldsError');

const fieldsLoading =
    document.getElementById('fieldsLoading');

const mappingCard =
    document.getElementById('mappingCard');

const mappingTable =
    document.getElementById('mappingTable');

const mappingSummary =
    document.getElementById('mappingSummary');

const mappingResult =
    document.getElementById('mappingResult');

const etrnIdInput =
    document.getElementById('etrnId');


// =========================================================
// SABY logical schema
// =========================================================

const sabySchema = [
    {
        key: 'sender',
        title: 'Отправитель',
        description: 'Отправитель груза'
    },
    {
        key: 'recipient',
        title: 'Получатель',
        description: 'Получатель груза'
    },
    {
        key: 'departure',
        title: 'Место отправления',
        description: 'Адрес отправления'
    },
    {
        key: 'destination',
        title: 'Место назначения',
        description: 'Адрес назначения'
    },
    {
        key: 'transport_company',
        title: 'Перевозчик',
        description: 'Организация-перевозчик'
    },
    {
        key: 'manager_phone',
        title: 'Телефон менеджера перевозчика',
        description: 'Контактный телефон'
    },
    {
        key: 'driver_name',
        title: 'ФИО водителя',
        description: 'Водитель'
    },
    {
        key: 'driver_phone',
        title: 'Телефон водителя',
        description: 'Телефон водителя'
    },
    {
        key: 'goods',
        title: 'Товары',
        description: 'Описание груза'
    },
    {
        key: 'weight',
        title: 'Масса, кг',
        description: 'Масса груза'
    },
    {
        key: 'volume',
        title: 'Объем, м³',
        description: 'Объем груза'
    },
    {
        key: 'places',
        title: 'Количество мест',
        description: 'Количество грузовых мест'
    }
];


// =========================================================
// Default mapping
// =========================================================

const defaultMapping = {
    sender: 'ufCrm19Sender',
    recipient: 'ufCrm19Recepient',
    departure: 'ufCrm19Dep',
    destination: 'ufCrm19Dest',
    transport_company: 'ufCrm19TransportCompany',
    manager_phone: 'ufCrm19ManagerPhone',
    driver_name: 'ufCrm19DriverName',
    driver_phone: 'ufCrm19DriverPhone',
    goods: 'ufCrm19Goods',
    weight: 'ufCrm19Weight',
    volume: 'ufCrm19Size',
    places: 'ufCrm19Qantity'
};


// =========================================================
// Tabs
// =========================================================

function initTabs() {

    const tabButtons =
        document.querySelectorAll(
            '[data-tab]'
        );

    const tabPanels =
        document.querySelectorAll(
            '[data-tab-panel]'
        );

    if (!tabButtons.length) {
        return;
    }

    function activateTab(
        tabName,
        save = true
    ) {

        let found = false;

        tabButtons.forEach(
            function(button) {

                const active =
                    button.dataset.tab === tabName;

                button.classList.toggle(
                    'active',
                    active
                );

                if (active) {
                    found = true;
                }
            }
        );

        tabPanels.forEach(
            function(panel) {

                const active =
                    panel.dataset.tabPanel === tabName;

                panel.classList.toggle(
                    'active',
                    active
                );
            }
        );

        if (!found) {

            tabName =
                tabButtons[0].dataset.tab;

            tabButtons.forEach(
                function(button) {

                    button.classList.toggle(
                        'active',
                        button.dataset.tab === tabName
                    );
                }
            );

            tabPanels.forEach(
                function(panel) {

                    panel.classList.toggle(
                        'active',
                        panel.dataset.tabPanel === tabName
                    );
                }
            );
        }

        if (save) {

            try {

                localStorage.setItem(
                    STORAGE_ACTIVE_TAB,
                    tabName
                );

            } catch (error) {

                console.warn(
                    'Не удалось сохранить активную вкладку.',
                    error
                );
            }
        }
    }


    tabButtons.forEach(
        function(button) {

            button.addEventListener(
                'click',
                function() {

                    activateTab(
                        button.dataset.tab
                    );

                }
            );
        }
    );


    let savedTab = '';

    try {

        savedTab =
            localStorage.getItem(
                STORAGE_ACTIVE_TAB
            ) || '';

    } catch (error) {
        savedTab = '';
    }


    activateTab(
        savedTab || tabButtons[0].dataset.tab,
        false
    );
}


// =========================================================
// Save smart process
// =========================================================

function saveSelectedSmartProcess(
    entityTypeId
) {

    if (entityTypeId) {

        storageSet(
            STORAGE_SMART_PROCESS,
            String(entityTypeId)
        );

    } else {

        storageRemove(STORAGE_SMART_PROCESS);
    }
}


// =========================================================
// Get saved smart process
// =========================================================

function getSavedSmartProcess() {

    return Number(
        storageGet(STORAGE_SMART_PROCESS) || 0
    );
}


// =========================================================
// Save ETRN ID
// =========================================================

function saveEtrnId() {

    if (!etrnIdInput) {
        return;
    }

    const value =
        String(etrnIdInput.value || '').trim();

    setAppStateEtrnId(value);

    if (value) {

        storageSet(
            STORAGE_ETRN_ID,
            value
        );

    } else {

        storageRemove(STORAGE_ETRN_ID);
    }
}


// =========================================================
// Restore ETRN ID
// =========================================================

function restoreEtrnId() {

    if (!etrnIdInput) {
        return;
    }

    const saved =
        storageGet(STORAGE_ETRN_ID);

    if (saved) {
        etrnIdInput.value = saved;

        setAppStateEtrnId(saved);
    }
}


// =========================================================
// Bitrix24 init
// =========================================================

function initBitrix24() {

    if (typeof BX24 === 'undefined') {

        showError(
            smartProcessError,
            'BX24 не найден. Проверьте подключение api.bitrix24.com/api/v1/.'
        );

        return;
    }


    BX24.init(
        function() {

            loadSmartProcesses();

        }
    );
}


// =========================================================
// Load smart processes
// =========================================================

function loadSmartProcesses() {

    showLoading(
        smartProcessLoading,
        true
    );

    hideElement(
        smartProcessError
    );


    fetch('api.php?action=smartprocess_list', {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json().then(function(data) {
                return { status: response.status, data: data };
            });
        })
        .then(function(res) {

            showLoading(
                smartProcessLoading,
                false
            );

            if (!res.data.ok) {
                showError(
                    smartProcessError,
                    res.data.error || ('Ошибка ' + res.status)
                );
                return;
            }

            const types = Array.isArray(res.data.types) ? res.data.types : [];

            smartProcesses = types;

            smartProcess.innerHTML =
                '<option value="">' +
                'Выберите смарт-процесс' +
                '</option>';

            types
                .slice()
                .sort(
                    function(a, b) {

                        return String(
                            a.title ?? ''
                        ).localeCompare(
                            String(
                                b.title ?? ''
                            ),
                            'ru'
                        );

                    }
                )
                .forEach(
                    function(type) {

                        const option =
                            document.createElement(
                                'option'
                            );

                        option.value = String(type.entityTypeId);
                        option.textContent = String(type.title) + ' [' + String(type.entityTypeId) + ']';
                        option.dataset.typeId = String(type.id);
                        option.dataset.title = String(type.title);
                        option.dataset.entityTypeId = String(type.entityTypeId);

                        smartProcess.appendChild(
                            option
                        );

                    }
                );

            // Восстановление сохранённого выбора (как было в BX24-версии)
            const savedEntityTypeId = getSavedSmartProcess();

            const savedExists =
                savedEntityTypeId > 0 &&
                types.some(
                    function(t) {
                        return Number(t.entityTypeId) === savedEntityTypeId;
                    }
                );

            if (savedExists) {
                smartProcess.value = String(savedEntityTypeId);
                smartProcess.dispatchEvent(new Event('change'));
            } else if (savedEntityTypeId > 0) {
                saveSelectedSmartProcess(0);
                setAppStateEntityTypeId(0);
            }

        })
        .catch(function(e) {
            showLoading(smartProcessLoading, false);
            showError(smartProcessError, cleanMessage(e.message || String(e)));
        });
}


// =========================================================
// Smart process selection
// =========================================================

smartProcess.addEventListener(
    'change',
    function() {

        const entityTypeId =
            Number(
                smartProcess.value
            );


        setAppStateEntityTypeId(
            entityTypeId || 0
        );


        // Сохраняем выбор
        saveSelectedSmartProcess(
            entityTypeId
        );


        hideElement(fieldsCard);
        hideElement(mappingCard);
        hideElement(fieldsError);
        hideElement(smartProcessError);
        hideElement(etrnError);


        if (!entityTypeId) {

            smartProcessInfo.classList.add(
                'hidden'
            );

            return;
        }


        const selected =
            smartProcesses.find(
                function(item) {

                    return Number(
                        item.entityTypeId
                    ) === entityTypeId;

                }
            );


        if (selected) {

            smartProcessInfo.classList.remove(
                'hidden'
            );


            smartProcessInfo.innerHTML =

                '<span class="badge blue">' +
                'ID: ' +
                escapeHtml(
                    selected.id
                ) +
                '</span>' +

                '<span class="badge blue">' +
                'entityTypeId: ' +
                escapeHtml(
                    selected.entityTypeId
                ) +
                '</span>' +

                '<span class="badge green">' +
                escapeHtml(
                    selected.title
                ) +
                '</span>';
        }


        loadFields(
            entityTypeId
        );

    }
);


// =========================================================
// Fields
// =========================================================

function loadFields(
    entityTypeId
) {

    showLoading(
        fieldsLoading,
        true
    );

    fieldsCard.classList.remove(
        'hidden'
    );

    hideElement(
        fieldsError
    );


    BX24.callMethod(
        'crm.item.fields',
        {
            entityTypeId:
                entityTypeId
        },
        function(response) {

            showLoading(
                fieldsLoading,
                false
            );


            if (response.error()) {

                showError(
                    fieldsError,
                    response.error()
                );

                return;
            }


            const result =
                response.data();


            const fields =
                result?.fields ??
                result ??
                {};


            if (
                typeof fields !== 'object' ||
                Array.isArray(fields)
            ) {

                showError(
                    fieldsError,
                    'Bitrix24 вернул некорректную структуру fields.'
                );

                return;
            }


            setAppStateFields(
                fields
            );


            renderFields(
                fields
            );


            renderMapping(
                fields
            );

        }
    );
}


// =========================================================
// Render fields
// =========================================================

function renderFields(
    fields
) {

    const keys =
        Object.keys(fields);


    if (!keys.length) {

        fieldsTable.innerHTML =
            '<div class="status info">' +
            '<span class="status-title">' +
            'Поля:' +
            '</span>' +
            '<span class="status-subtitle">' +
            'не найдены.' +
            '</span>' +
            '</div>';

        return;
    }


    let html = '';


    html += '<table>';

    html += '<thead>';

    html += '<tr>';

    html += '<th>Код</th>';

    html += '<th>Название</th>';

    html += '<th>Тип</th>';

    html += '<th>Свойства</th>';

    html += '</tr>';

    html += '</thead>';

    html += '<tbody>';


    keys.forEach(
        function(code) {

            const field =
                fields[code] || {};


            const badges = [];


            if (field.isRequired) {

                badges.push(
                    '<span class="badge red">' +
                    'required' +
                    '</span>'
                );
            }


            if (field.isReadOnly) {

                badges.push(
                    '<span class="badge">' +
                    'readOnly' +
                    '</span>'
                );
            }


            if (field.isImmutable) {

                badges.push(
                    '<span class="badge">' +
                    'immutable' +
                    '</span>'
                );
            }


            if (field.isMultiple) {

                badges.push(
                    '<span class="badge">' +
                    'multiple' +
                    '</span>'
                );
            }


            if (field.isDynamic) {

                badges.push(
                    '<span class="badge blue">' +
                    'dynamic' +
                    '</span>'
                );
            }


            html += '<tr>';


            html +=
                '<td class="value">' +
                '<code>' +
                escapeHtml(code) +
                '</code>' +
                '</td>';


            html +=
                '<td class="value">' +
                escapeHtml(
                    field.title ?? ''
                ) +
                '</td>';


            html +=
                '<td>' +
                escapeHtml(
                    field.type ?? ''
                ) +
                '</td>';


            html +=
                '<td>' +
                badges.join(' ') +
                '</td>';


            html += '</tr>';

        }
    );


    html += '</tbody>';

    html += '</table>';


    fieldsTable.innerHTML =
        html;
}


// =========================================================
// Mapping
// =========================================================

function renderMapping(
    fields
) {

    mappingCard.classList.remove(
        'hidden'
    );


    let html = '';


    html +=
        '<table class="mapping-table">';

    html += '<thead>';

    html += '<tr>';

    html += '<th>SABY</th>';

    html += '<th>Описание</th>';

    html += '<th>Поле Bitrix24</th>';

    html += '</tr>';

    html += '</thead>';

    html += '<tbody>';


    sabySchema.forEach(
        function(sabyField) {

            html += '<tr>';


            html +=
                '<td>' +
                '<strong>' +
                escapeHtml(
                    sabyField.title
                ) +
                '</strong>' +
                '<br>' +
                '<span class="small">' +
                escapeHtml(
                    sabyField.key
                ) +
                '</span>' +
                '</td>';


            html +=
                '<td>' +
                escapeHtml(
                    sabyField.description
                ) +
                '</td>';


            html += '<td>';


            html +=
                '<select ' +
                'data-saby-field="' +
                escapeHtml(
                    sabyField.key
                ) +
                '">';


            html +=
                '<option value="">' +
                '— не сопоставлено —' +
                '</option>';


            Object.keys(fields)
                .forEach(
                    function(code) {

                        const field =
                            fields[code] || {};


                        if (field.isReadOnly) {
                            return;
                        }


                        const selected =
                            defaultMapping[
                                sabyField.key
                            ] === code
                                ? ' selected'
                                : '';


                        html +=
                            '<option value="' +
                            escapeHtml(code) +
                            '"' +
                            selected +
                            '>' +
                            escapeHtml(
                                field.title
                                    ? field.title +
                                      ' [' +
                                      code +
                                      ']'
                                    : code
                            ) +
                            '</option>';

                    }
                );


            html += '</select>';

            html += '</td>';

            html += '</tr>';

        }
    );


    html += '</tbody>';

    html += '</table>';


    mappingTable.innerHTML =
        html;


    updateMappingSummary();


    mappingTable
        .querySelectorAll(
            'select'
        )
        .forEach(
            function(select) {

                select.addEventListener(
                    'change',
                    updateMappingSummary
                );

            }
        );
}


// =========================================================
// Mapping summary
// =========================================================

function getCurrentMapping() {

    const result = {};


    mappingTable
        .querySelectorAll(
            'select[data-saby-field]'
        )
        .forEach(
            function(select) {

                result[
                    select.dataset.sabyField
                ] =
                    select.value;

            }
        );


    return result;
}


function updateMappingSummary() {

    if (!mappingTable) {
        return;
    }


    const mapping =
        getCurrentMapping();


    const total =
        Object.keys(
            mapping
        ).length;


    const mapped =
        Object.values(
            mapping
        )
        .filter(
            function(value) {

                return value !== '';

            }
        )
        .length;


    mappingSummary.innerHTML =
        '<strong>Сопоставлено:</strong> ' +
        mapped +
        ' из ' +
        total;
}


// =========================================================
// Check mapping
// =========================================================

const checkMappingButton =
    document.getElementById(
        'checkMappingButton'
    );


if (checkMappingButton) {

    checkMappingButton.addEventListener(
        'click',
        function() {

            const mapping =
                getCurrentMapping();


            const used = {};

            let duplicate =
                false;


            Object.keys(mapping)
                .forEach(
                    function(key) {

                        const value =
                            mapping[key];


                        if (!value) {
                            return;
                        }


                        if (used[value]) {
                            duplicate = true;
                        }


                        used[value] = true;

                    }
                );


            mappingResult.classList.remove(
                'hidden'
            );


            if (duplicate) {

                mappingResult.className =
                    'status error';


                mappingResult.innerHTML =
                    '<span class="status-title">' +
                    'Проверка:' +
                    '</span>' +
                    '<span class="status-subtitle">' +
                    'одно поле Bitrix24 используется несколько раз.' +
                    '</span>';

                return;
            }


            mappingResult.className =
                'status success';


            mappingResult.innerHTML =
                '<span class="status-title">' +
                'Проверка:' +
                '</span>' +
                '<span class="status-subtitle">' +
                'сопоставление не содержит дублирующихся полей.' +
                '</span>';

        }
    );
}


// =========================================================
// Save ETRN ID when changed
// =========================================================

if (etrnIdInput) {

    etrnIdInput.addEventListener(
        'input',
        saveEtrnId
    );
}


// =========================================================
// Start
// =========================================================

initTabs();

restoreEtrnId();

initBitrix24();