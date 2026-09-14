'use strict';

// =========================================================
// Participants
// =========================================================

const loadParticipantsButton =
    document.getElementById('loadParticipantsButton');

const participantsLoading =
    document.getElementById('participantsLoading');

const participantsError =
    document.getElementById('participantsError');

const participantsData =
    document.getElementById('participantsData');

const participantsTable =
    document.getElementById('participantsTable');

const participantsRawJson =
    document.getElementById('participantsRawJson');

// =========================================================
// Load participants
// =========================================================

function loadParticipants() {
    hideElement(participantsError);
    hideElement(participantsData);

    const id = Number(
        etrnId?.value || 0
    );

    const entityTypeId =
        appState.entityTypeId;

    if (!id) {
        showError(
            participantsError,
            'Введите ID ЭТрН.'
        );
        return;
    }

    if (!entityTypeId) {
        showError(
            participantsError,
            'Сначала выберите смарт-процесс.'
        );
        return;
    }

    showLoading(
        participantsLoading,
        true
    );

    BX24.callMethod(
        'crm.item.get',
        {
            entityTypeId: entityTypeId,
            id: id
        },
        function(response) {
            if (response.error()) {
                showLoading(
                    participantsLoading,
                    false
                );

                showError(
                    participantsError,
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
                showLoading(
                    participantsLoading,
                    false
                );

                showError(
                    participantsError,
                    'Не удалось получить данные ЭТрН.'
                );

                return;
            }

            const requests = [];

            if (item.ufCrm19Sender) {
                requests.push({
                    role: 'sender',
                    id: Number(item.ufCrm19Sender)
                });
            }

            if (item.ufCrm19Recepient) {
                requests.push({
                    role: 'recipient',
                    id: Number(item.ufCrm19Recepient)
                });
            }

            if (item.ufCrm19TransportCompany) {
                requests.push({
                    role: 'transportCompany',
                    id: Number(
                        item.ufCrm19TransportCompany
                    )
                });
            }

            if (!requests.length) {
                showLoading(
                    participantsLoading,
                    false
                );

                showError(
                    participantsError,
                    'В ЭТрН не указаны участники.'
                );

                return;
            }

            loadCompaniesSequentially(
                requests,
                0,
                []
            );
        }
    );
}

// =========================================================
// Load companies sequentially
// =========================================================

function loadCompaniesSequentially(
    requests,
    index,
    results
) {
    if (index >= requests.length) {
        showLoading(
            participantsLoading,
            false
        );

        renderParticipants(results);

        return;
    }

    const request = requests[index];

    BX24.callMethod(
        'crm.company.get',
        {
            id: request.id
        },
        function(response) {
            let company = {};
            let companyError = '';

            if (response.error()) {
                companyError = response.error();
            } else {
                const result = response.data() || {};
                company = result.company ?? result;
            }

            loadCompanyRequisites(
                request,
                company,
                companyError,
                function(participant) {
                    results.push(participant);

                    loadCompaniesSequentially(
                        requests,
                        index + 1,
                        results
                    );
                }
            );
        }
    );
}

// =========================================================
// Load company requisites
// =========================================================

function loadCompanyRequisites(
    request,
    company,
    companyError,
    callback
) {
    BX24.callMethod(
        'crm.requisite.list',
        {
            filter: {
                ENTITY_TYPE_ID: 4,
                ENTITY_ID: request.id
            }
        },
        function(response) {
            let requisites = [];
            let requisiteError = '';

            if (response.error()) {
                requisiteError = response.error();
            } else {
                const result = response.data() || {};

                requisites =
                    Array.isArray(result.requisites)
                        ? result.requisites
                        : Array.isArray(result)
                            ? result
                            : [];
            }

            callback({
                role: request.role,
                id: request.id,
                company: company,
                companyError: companyError || '',
                requisites: requisites,
                requisiteError: requisiteError
            });
        }
    );
}

// =========================================================
// Render participants
// =========================================================

function renderParticipants(items) {
    participantsTable.innerHTML = '';

    items.forEach(function(item) {
        const company = item.company || {};

        const requisite =
            Array.isArray(item.requisites) &&
            item.requisites.length
                ? item.requisites[0]
                : {};

        const inn =
            requisite.RQ_INN ??
            '';

        const kpp =
            requisite.RQ_KPP ??
            '';

        const companyName =
            requisite.RQ_COMPANY_NAME ??
            requisite.RQ_NAME ??
            company.TITLE ??
            '';

        const roleTitle =
            getParticipantRoleTitle(
                item.role
            );

        const row = document.createElement('div');

        row.className = 'participant-row';

        row.innerHTML =
            '<div class="participant-role">' +
                escapeHtml(roleTitle) +
            '</div>' +

            '<div class="participant-main">' +
                '<div class="participant-name">' +
                    escapeHtml(companyName) +
                '</div>' +

                '<div class="participant-meta">' +
                    '<span>ID: ' +
                        escapeHtml(item.id) +
                    '</span>' +

                    '<span>ИНН: ' +
                        escapeHtml(inn) +
                    '</span>' +

                    '<span>КПП: ' +
                        escapeHtml(kpp) +
                    '</span>' +

                    '<span>Реквизитов: ' +
                        escapeHtml(
                            item.requisites.length
                        ) +
                    '</span>' +
                '</div>' +

                (
                    item.companyError
                        ? '<div class="participant-error">' +
                            escapeHtml(
                                cleanMessage(
                                    item.companyError
                                )
                            ) +
                          '</div>'
                        : ''
                ) +

                (
                    item.requisiteError
                        ? '<div class="participant-error">' +
                            escapeHtml(
                                cleanMessage(
                                    item.requisiteError
                                )
                            ) +
                          '</div>'
                        : ''
                ) +

            '</div>';

        participantsTable.appendChild(row);
    });

    if (participantsRawJson) {
        participantsRawJson.textContent =
            JSON.stringify(
                items,
                null,
                2
            );
    }

    participantsData.classList.remove('hidden');
}

// =========================================================
// Participant role title
// =========================================================

function getParticipantRoleTitle(role) {
    switch (role) {
        case 'sender':
            return 'Отправитель';

        case 'recipient':
            return 'Получатель';

        case 'transportCompany':
            return 'Перевозчик';

        default:
            return role;
    }
}

// =========================================================
// Build SABY data
// =========================================================

function buildSabyData(items) {
    const result = {};

    items.forEach(function(item) {
        const company = item.company || {};

        const requisite =
            Array.isArray(item.requisites) &&
            item.requisites.length
                ? item.requisites[0]
                : {};

        result[item.role] = {
            id: item.id,

            name:
                requisite.RQ_COMPANY_NAME ??
                requisite.RQ_NAME ??
                company.TITLE ??
                '',

            fullName:
                requisite.RQ_COMPANY_FULL_NAME ??
                '',

            inn:
                requisite.RQ_INN ??
                '',

            kpp:
                requisite.RQ_KPP ??
                '',

            ogrn:
                requisite.RQ_OGRN ??
                '',

            ogrnip:
                requisite.RQ_OGRNIP ??
                '',

            director:
                requisite.RQ_DIRECTOR ??
                '',

            phone:
                company.PHONE?.[0]?.VALUE ??
                ''
        };
    });

    return result;
}

// =========================================================
// Reset
// =========================================================

function resetParticipantsSection() {
    hideElement(participantsError);
    hideElement(participantsData);

    showLoading(
        participantsLoading,
        false
    );

    if (participantsTable) {
        participantsTable.innerHTML = '';
    }

    if (participantsRawJson) {
        participantsRawJson.textContent = '';
    }
}

// =========================================================
// Events
// =========================================================

if (loadParticipantsButton) {
    loadParticipantsButton.addEventListener(
        'click',
        loadParticipants
    );
}