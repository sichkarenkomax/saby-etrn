'use strict';

// =========================================================
// Helpers
// =========================================================

function cleanMessage(value) {
    return String(value ?? '')
        .replace(/\r\n/g, ' ')
        .replace(/\r/g, ' ')
        .replace(/\n/g, ' ')
        .replace(/\t/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function showError(element, message) {
    if (!element) {
        return;
    }

    element.classList.remove('hidden');

    element.innerHTML =
        '<span class="status-title">Ошибка:</span>' +
        '<span class="status-subtitle">' +
        escapeHtml(cleanMessage(message)) +
        '</span>';
}

function hideElement(element) {
    if (element) {
        element.classList.add('hidden');
    }
}

function showLoading(element, visible) {
    if (!element) {
        return;
    }

    element.style.display = visible ? 'block' : 'none';
}