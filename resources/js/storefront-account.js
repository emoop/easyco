// The storefront's account pages script (ACC-3a): login, register, forgot / reset password, verify, email-change confirm and the /account placeholder.
// Plain JavaScript, no framework. It drives the EXISTING stateful account API (/api/account/...) through api() of storefront-cart.js, so the session + CSRF flow
// (GET /sanctum/csrf-cookie, X-XSRF-TOKEN, one retry on a 419) is the cart's own: ONE implementation.
//
// The pages are server-rendered shells (resources/views/storefront/account). Their texts come as data attributes of [data-sf-account-i18n] (no inline script).
// The tokens of the mail links (reset, verify, email confirm) are read from the ADDRESS and sent in the POST body: the page HTML never holds them.
// Nothing here says whether an email has an account: register, forgot and resend show ONE answer whatever the API said (the API itself answers the same).
// Everything that comes from the API is put on the page with textContent, never as markup.
import { api } from './storefront-cart.js';

var root = document.querySelector('[data-sf-account]');

if (root) {
    start(root);
}

function start(root) {
    var page = root.getAttribute('data-sf-account');
    var i18n = root.querySelector('[data-sf-account-i18n]');
    var status = root.querySelector('[data-sf-account-status]');
    var form = root.querySelector('[data-sf-account-form]');
    var loginUrl = i18n.getAttribute('data-login-url') || '/account/login';
    var accountUrl = i18n.getAttribute('data-account-url') || '/account';
    var working = false;

    function t(key, vars) {
        var raw = i18n.getAttribute('data-m-' + key.replace(/_/g, '-'));
        var text = raw === null ? key : raw;

        if (vars) {
            Object.keys(vars).forEach(function (name) {
                text = text.split(':' + name).join(String(vars[name]));
            });
        }

        return text;
    }

    function say(text, kind) {
        status.textContent = text;
        status.setAttribute('data-state', kind || 'info');
        status.hidden = false;
    }

    function clearStatus() {
        status.textContent = '';
        status.hidden = true;
    }

    function clearErrors(scope) {
        scope.querySelectorAll('[data-sf-error-for]').forEach(function (node) {
            node.textContent = '';
            node.hidden = true;
        });
        scope.querySelectorAll('[aria-invalid]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
        });
    }

    /** one message under one field; false when the page has no such field */
    function fieldError(scope, name, text) {
        var node = scope.querySelector('[data-sf-error-for="' + name + '"]');
        var input = scope.querySelector('[name="' + name + '"]');

        if (! node) {
            return false;
        }

        node.textContent = text;
        node.hidden = false;

        if (input) {
            input.setAttribute('aria-invalid', 'true');
        }

        return true;
    }

    /** the API's 422 `errors` object under its fields; a field this page has not goes to the status line */
    function fieldErrors(scope, errors) {
        var first = null;

        Object.keys(errors || {}).forEach(function (name) {
            var text = Array.isArray(errors[name]) ? String(errors[name][0]) : String(errors[name]);

            if (! fieldError(scope, name, text)) {
                say(text, 'error');
            }

            first = first || scope.querySelector('[name="' + name + '"]');
        });

        if (first) {
            first.focus();
        }
    }

    function busy(scope, on) {
        working = on;
        scope.querySelectorAll('[data-sf-account-submit]').forEach(function (button) {
            button.disabled = on;
        });
    }

    /** "try again in N min" from Retry-After (seconds); a missing header is the general sentence */
    function tooMany(result) {
        var seconds = parseInt(result.retryAfter, 10);

        say(Number.isFinite(seconds) && seconds > 0 ? t('too_many', { minutes: Math.max(1, Math.ceil(seconds / 60)) }) : t('too_many_soon'), 'error');
    }

    /** the answers every page treats the same way; true = handled */
    function common(scope, result) {
        if (result === null || result.status >= 500 || result.status === 404 || result.status === 419) {
            say(t('generic_error'), 'error');

            return true;
        }

        if (result.status === 429) {
            tooMany(result);

            return true;
        }

        if (result.status === 422 && result.data && result.data.errors) {
            fieldErrors(scope, result.data.errors);

            return true;
        }

        return false;
    }

    /** a same-origin RELATIVE path only: one `/`, never `//`, no backslash, no space or control character (the server applies the same rule: SafeRedirect) */
    function safePath(value) {
        if (typeof value !== 'string' || value === '' || value.length > 2000 || value.charAt(0) !== '/' || value.charAt(1) === '/' || value.charAt(1) === '\\' || /[\\\u0000- \u007f]/.test(value)) {
            return accountUrl;
        }

        return value;
    }

    function query(name) {
        return new URLSearchParams(window.location.search).get(name) || '';
    }

    function call(method, path, body) {
        return api(method, path, body).catch(function () {
            return null;
        });
    }

    /** runs one submit: no double submit, the button disabled while the request runs */
    function onSubmit(scope, handler) {
        scope.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (working) {
                return;
            }

            clearStatus();
            clearErrors(scope);
            busy(scope, true);
            var keepBusy = false;

            try {
                keepBusy = (await handler()) === true;
            } finally {
                if (! keepBusy) {
                    busy(scope, false);
                }
            }
        });
    }

    function value(scope, name) {
        var input = scope.querySelector('[name="' + name + '"]');

        return input ? input.value : '';
    }

    /** a finished page: the form(s) go, the message stays */
    function finish(text, kind, hide, show) {
        (hide || []).forEach(function (selector) {
            root.querySelectorAll(selector).forEach(function (node) {
                node.hidden = true;
            });
        });
        (show || []).forEach(function (selector) {
            root.querySelectorAll(selector).forEach(function (node) {
                node.hidden = false;
            });
        });
        say(text, kind);
    }

    // ---- the show / hide button of every password field ----------------------------------------------------------------------------------------------------------
    root.querySelectorAll('[data-sf-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('aria-controls'));
            var show = input.type === 'password';

            input.type = show ? 'text' : 'password';
            button.textContent = button.getAttribute(show ? 'data-hide' : 'data-show');
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    });

    // ---- login ---------------------------------------------------------------------------------------------------------------------------------------------------
    if (page === 'login') {
        var resend = root.querySelector('[data-sf-account-resend]');

        onSubmit(form, async function () {
            var email = value(form, 'email').trim();
            var password = value(form, 'password');

            if (email === '' || password === '') {
                if (email === '') {
                    fieldError(form, 'email', t('email_required'));
                }

                if (password === '') {
                    fieldError(form, 'password', t('password_required'));
                }

                return false;
            }

            var result = await call('POST', '/api/account/login', { email: email, password: password, remember: form.querySelector('[name="remember"]').checked });

            if (result !== null && result.status === 200) {
                window.location.assign(safePath(root.getAttribute('data-redirect')));

                return true;
            }

            if (result !== null && result.status === 401) {
                say(t('login_invalid'), 'error');

                return false;
            }

            if (result !== null && result.status === 403 && result.data && result.data.code === 'email_unverified') {
                say(t('login_unverified'), 'error');
                resend.hidden = false;

                return false;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });

        resend.querySelector('[data-sf-account-resend-button]').addEventListener('click', async function (event) {
            var button = event.currentTarget;
            var email = value(form, 'email').trim();

            if (working || email === '') {
                return;
            }

            working = true;
            button.disabled = true;
            var result = await call('POST', '/api/account/verify/resend', { email: email });
            working = false;
            button.disabled = false;

            if (result !== null && result.status === 429) {
                tooMany(result);
            } else {
                say(t('resend_done'), 'info');
            }
        });
    }

    // ---- register ------------------------------------------------------------------------------------------------------------------------------------------------
    if (page === 'register') {
        onSubmit(form, async function () {
            var email = value(form, 'email').trim();
            var password = value(form, 'password');
            var result = await call('POST', '/api/account/register', { email: email, password: password, password_confirmation: password });

            if (result !== null && result.status === 202) {
                finish(t('register_done', { email: email }), 'success', ['[data-sf-account-form]', '.sf-account-links']);

                return true;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });
    }

    // ---- forgot password -----------------------------------------------------------------------------------------------------------------------------------------
    if (page === 'forgot') {
        onSubmit(form, async function () {
            var result = await call('POST', '/api/account/password/forgot', { email: value(form, 'email').trim() });

            if (result !== null && result.status === 202) {
                finish(t('forgot_done'), 'success', ['[data-sf-account-form]', '.sf-account-intro']);

                return true;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });
    }

    // ---- reset password (the link of the mail: ?token=…&email=…) -------------------------------------------------------------------------------------------------
    if (page === 'reset') {
        var resetToken = query('token');
        var resetEmail = query('email');
        var resetInvalid = function () {
            finish(t('reset_invalid'), 'error', ['[data-sf-account-form]'], ['[data-sf-account-invalid-links]']);
        };

        if (resetToken === '' || resetEmail === '') {
            resetInvalid();
        }

        onSubmit(form, async function () {
            var password = value(form, 'password');

            if (password !== value(form, 'password_confirmation')) {
                fieldError(form, 'password_confirmation', t('password_mismatch'));

                return false;
            }

            var result = await call('POST', '/api/account/password/reset', { email: resetEmail, token: resetToken, password: password, password_confirmation: password });

            if (result !== null && result.status === 200) {
                window.location.assign(loginUrl + '?notice=password-reset');

                return true;
            }

            if (result !== null && result.status === 422 && ! (result.data && result.data.errors)) {
                resetInvalid();

                return false;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });
    }

    // ---- verify the email (the link of the mail: ?id=…&expires=…&signature=…): a GET changes nothing, the button POSTs ----------------------------------------------
    if (page === 'verify') {
        var resendForm = root.querySelector('[data-sf-account-resend-form]');
        var verifyInvalid = function () {
            finish(t('verify_invalid'), 'error', ['[data-sf-account-form]', '[data-sf-account-intro]'], ['[data-sf-account-resend-form]']);
        };

        if (query('id') === '' || query('expires') === '' || query('signature') === '') {
            verifyInvalid();
        }

        onSubmit(form, async function () {
            var result = await call('POST', '/api/account/verify', { id: query('id'), expires: query('expires'), signature: query('signature') });

            if (result !== null && result.status === 200) {
                finish(t('verify_done'), 'success', ['[data-sf-account-form]', '[data-sf-account-intro]'], ['[data-sf-account-done-links]']);

                return true;
            }

            if (result !== null && result.status === 422) {
                verifyInvalid();

                return false;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });

        onSubmit(resendForm, async function () {
            var result = await call('POST', '/api/account/verify/resend', { email: value(resendForm, 'email').trim() });

            if (result !== null && result.status === 429) {
                tooMany(result);

                return false;
            }

            if (result !== null && result.status === 422 && result.data && result.data.errors) {
                fieldErrors(resendForm, result.data.errors);

                return false;
            }

            say(t('resend_done'), 'info');

            return false;
        });
    }

    // ---- confirm the new email (the link of the mail: ?id=…&token=…&expires=…&signature=…) ----------------------------------------------------------------------------
    if (page === 'email-confirm') {
        var confirmInvalid = function () {
            finish(t('confirm_invalid'), 'error', ['[data-sf-account-form]', '[data-sf-account-intro]'], ['[data-sf-account-invalid-links]']);
        };

        if (query('id') === '' || query('token') === '' || query('expires') === '' || query('signature') === '') {
            confirmInvalid();
        }

        onSubmit(form, async function () {
            var result = await call('POST', '/api/account/email/confirm', { id: query('id'), token: query('token'), expires: query('expires'), signature: query('signature') });

            if (result !== null && result.status === 200) {
                finish(t('confirm_done'), 'success', ['[data-sf-account-form]', '[data-sf-account-intro]'], ['[data-sf-account-done-links]']);

                return true;
            }

            if (result !== null && result.status === 422) {
                confirmInvalid();

                return false;
            }

            common(form, result) || say(t('generic_error'), 'error');

            return false;
        });
    }

    // ---- the account area (ACC-3a placeholder, ACC-3b-1 pages): sign out, from the menu AND from the dashboard tile (every form binds the same handler) ---------------------
    if (page === 'home') {
        root.querySelectorAll('[data-sf-account-logout-form]').forEach(function (logoutForm) {
            onSubmit(logoutForm, async function () {
                var result = await call('POST', '/api/account/logout');

                // 204 = signed out; 401 = the session was already gone: either way the visitor is a guest now
                if (result !== null && (result.status === 204 || result.status === 401)) {
                    window.location.assign(loginUrl);

                    return true;
                }

                say(t('generic_error'), 'error');

                return false;
            });
        });
    }
}
