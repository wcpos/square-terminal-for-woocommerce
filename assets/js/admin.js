(function () {
	'use strict';

	// Action names are also asserted by tests/includes/AdminUiTest.php.
	window.sqtwcAdminActions = {
		create_device_code: function () { return { action: 'sqtwc_create_device_code' }; },
		validate_settings: function () { return { action: 'sqtwc_validate_settings' }; }
	};

	var config = window.sqtwcAdmin || {};
	var strings = config.strings || {};

	function encodeParam(key, value) {
		return encodeURIComponent(key) + '=' + encodeURIComponent(value == null ? '' : String(value));
	}

	function setStatus(el, message, isError) {
		if (!el) {
			return;
		}
		el.textContent = message;
		el.className = 'sqtwc-admin__status' + (isError ? ' sqtwc-admin__status--error' : '');
	}

	function messageFor(body) {
		if (body && typeof body.cashier_message === 'string' && body.cashier_message !== '') {
			return body.cashier_message;
		}
		return strings.requestError || 'The request could not be completed.';
	}

	function post(action) {
		var body = [
			encodeParam('action', action),
			encodeParam('_wpnonce', config.nonce)
		].join('&');

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body
		}).then(function (response) {
			return response.json().catch(function () { return null; }).then(function (parsed) {
				return { ok: response.ok, body: parsed };
			});
		});
	}

	function bind(buttonId, action, onSuccess) {
		var button = document.getElementById(buttonId);
		if (!button) {
			return;
		}

		button.addEventListener('click', function () {
			var status = document.getElementById('sqtwc-admin-status');
			button.disabled = true;
			setStatus(status, strings.working || 'Working…', false);

			post(action).then(function (result) {
				if (result.ok && result.body && result.body.success) {
					setStatus(status, onSuccess(result.body), false);
				} else {
					setStatus(status, messageFor(result.body), true);
				}
			}).catch(function () {
				setStatus(status, strings.requestError || 'The request could not be completed.', true);
			}).then(function () {
				button.disabled = false;
			});
		});
	}

	function init() {
		bind('sqtwc-create-device-code', 'sqtwc_create_device_code', function (body) {
			var template = strings.pairingCode || 'Pairing code: %s';
			return template.replace('%s', String(body.code || ''));
		});
		bind('sqtwc-validate-settings', 'sqtwc_validate_settings', function () {
			return strings.settingsOk || 'Square credentials and location verified.';
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
