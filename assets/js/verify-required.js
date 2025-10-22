(function () {
    var form = document.querySelector('.wre-verify-required');

    if (!form || typeof window.wreVerifyRequired === 'undefined') {
        return;
    }

    var button = form.querySelector('[data-wre-resend]');
    var statusEl = form.querySelector('[data-wre-status]');
    var messages = window.wreVerifyRequired.messages || {};

    function setStatus(text, type) {
        if (!statusEl) {
            return;
        }

        statusEl.textContent = text || '';
        statusEl.classList.remove('wre-verify-required__status--success', 'wre-verify-required__status--error');

        if (type) {
            statusEl.classList.add('wre-verify-required__status--' + type);
        }
    }

    function handleError(fallback) {
        var message = messages.failure || messages.error || fallback;
        setStatus(message, 'error');
    }

    if (!button) {
        return;
    }

    button.addEventListener('click', function () {
        if (!window.wreVerifyRequired.ajaxUrl || !window.wreVerifyRequired.nonce) {
            handleError('Missing AJAX configuration.');
            return;
        }

        button.disabled = true;
        setStatus(messages.sending || 'Sending…');

        var formData = new window.FormData();
        formData.append('action', 'wre_resend_verification');
        formData.append('nonce', window.wreVerifyRequired.nonce);

        window.fetch(window.wreVerifyRequired.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { success: false };
                });
            })
            .then(function (payload) {
                if (!payload) {
                    throw new Error('Empty response');
                }

                var message = payload.data && payload.data.message ? payload.data.message : '';

                if (payload.success) {
                    var successMessage = message || messages.success || '';
                    setStatus(successMessage, 'success');
                    button.disabled = false;
                    return;
                }

                if (message && message.toLowerCase().indexOf('already verified') !== -1) {
                    setStatus(messages.verified || message, 'success');
                    button.disabled = false;
                    return;
                }

                throw new Error(message || 'Request failed');
            })
            .catch(function () {
                handleError('Request failed');
                button.disabled = false;
            });
    });
})();
