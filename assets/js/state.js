'use strict';

// =========================================================
// Shared app state (etrn.js, participants.js, app.js, sync.js)
// =========================================================

const appState = {
    etrnId: 0,
    entityTypeId: 0,
    fields: {}
};

// ---------------------------------------------------------
// Storage keys
// ---------------------------------------------------------

const STORAGE_SMART_PROCESS =
    'saby_etrn_selected_entity_type_id';

const STORAGE_ETRN_ID =
    'saby_etrn_id';

const STORAGE_ACTIVE_TAB =
    'saby_etrn_active_tab';

// ---------------------------------------------------------
// Getters / setters
// ---------------------------------------------------------

function setAppStateEtrnId(value) {
    appState.etrnId = Number(value) || 0;
}

function setAppStateEntityTypeId(value) {
    appState.entityTypeId = Number(value) || 0;
}

function setAppStateFields(fields) {
    appState.fields = fields || {};
}

// ---------------------------------------------------------
// localStorage helpers
// ---------------------------------------------------------

function storageGet(key) {
    try {
        return localStorage.getItem(key) || '';
    } catch (error) {
        return '';
    }
}

function storageSet(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (error) {
        console.warn(
            'Не удалось сохранить ' + key + '.',
            error
        );
    }
}

function storageRemove(key) {
    try {
        localStorage.removeItem(key);
    } catch (error) {
        console.warn(
            'Не удалось удалить ' + key + '.',
            error
        );
    }
}