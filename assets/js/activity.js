'use strict';

// =========================================================
// Регистрация активити "Создать ЭТрН в SABY" в Б24
// =========================================================

const ACTIVITY_CODE = 'saby_etrn-create';

function registerSabyActivity() {

    const handlerUrl = (window.SABY_ACTIVITY_URL || '');

    if (!handlerUrl) {
        const el = document.getElementById('activityStatus');
        if (el) { el.textContent = 'URL активити не задан.'; }
        return;
    }

    const btn = document.getElementById('registerActivity');

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Регистрация...';
    }

    BX24.callMethod(
        'bizproc.activity.add',
        {
            CODE: ACTIVITY_CODE,
            HANDLER: handlerUrl,
            NAME: 'SABY: создать ЭТрН',
            DESCRIPTION: 'Создаёт транспортную накладную в SABY от имени грузоотправителя (наша организация) и возвращает ID документа.',
            USE_SUBSCRIPTION: 'N',
            FILTER: {},
            PROPERTIES: {
                number:              { Name: 'Номер документа', Type: 'string', Required: 'N', Options: null },
                date:                { Name: 'Дата (ДД.ММ.ГГГГ)', Type: 'string', Required: 'N', Options: null },
                receiverCompanyId:   { Name: 'ID компании-получателя', Type: 'string', Required: 'Y', Options: null },
                carrierCompanyId:    { Name: 'ID компании-перевозчика', Type: 'string', Required: 'N', Options: null },
                carrierPhone:        { Name: 'Телефон перевозчика (если не брать из Б24)', Type: 'string', Required: 'N', Options: null },
                driverName:          { Name: 'ФИО водителя', Type: 'string', Required: 'Y', Options: null },
                driverPhone:         { Name: 'Телефон водителя', Type: 'string', Required: 'N', Options: null },
                goods:               { Name: 'Товары (через запятую)', Type: 'string', Required: 'Y', Options: null },
                weight:              { Name: 'Масса, кг', Type: 'string', Required: 'Y', Options: null },
                size:                { Name: 'Объём, м.куб.', Type: 'string', Required: 'N', Options: null },
                quantity:            { Name: 'Количество мест', Type: 'string', Required: 'Y', Options: null },
                dep:                 { Name: 'Место отправления', Type: 'string', Required: 'Y', Options: null },
                dest:                { Name: 'Место назначения', Type: 'string', Required: 'Y', Options: null }
            },
            RETURN_PROPERTIES: {
                sabyId:     { Name: 'ID документа в SABY', Type: 'string' },
                sabyUrl:    { Name: 'Ссылка на документ', Type: 'string' },
                status:     { Name: 'Статус (created/error)', Type: 'string' },
                error:      { Name: 'Текст ошибки', Type: 'string' }
            },
            DOCUMENT_TYPE: ['crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Dynamic', 'DYNAMIC_1072'],
            AUTH_USER_ID: 1
        },
        function(resp) {

            const el = document.getElementById('activityStatus');
            const btn = document.getElementById('registerActivity');

            if (!el) {
                return;
            }

            if (resp.error() && String(resp.error()).indexOf('ACTIVITY_ALREADY_INSTALLED') !== -1) {

                // Уже установлено — обновляем: удаляем и регистрируем заново
                el.textContent = 'Активити уже есть — обновляю...';

                BX24.callMethod(
                    'bizproc.activity.delete',
                    { CODE: ACTIVITY_CODE, HANDLER: handlerUrl },
                    function(delResp) {

                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Зарегистрировать активити';
                        }

                        if (delResp.error()) {
                            el.textContent = 'Не удалось удалить старую версию: ' + String(delResp.error());
                            return;
                        }

                        el.textContent = 'Старая версия удалена. Нажмите «Зарегистрировать активити» ещё раз.';
                    }
                );

                return;
            }

            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Зарегистрировать активити';
            }

            if (resp.error()) {
                el.textContent = 'Ошибка регистрации: ' + String(resp.error());
                return;
            }

            el.textContent = '✓ Активити зарегистрировано. В бизнес-процессах ищите "SABY: Создать ЭТрН".';
        }
    );
}

if (document.getElementById('registerActivity')) {
    document.getElementById('registerActivity').addEventListener('click', registerSabyActivity);
}