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
		var environmentField = document.getElementById('woocommerce_sqtwc_environment');
		var environment = environmentField ? environmentField.value : '';
		var tokenField = document.getElementById('woocommerce_sqtwc_' + environment + '_access_token');
		var locationField = document.getElementById('woocommerce_sqtwc_location_id');
		var body = [
			encodeParam('action', action),
			encodeParam('_wpnonce', config.nonce),
			encodeParam('environment', environment),
			encodeParam('access_token', tokenField ? tokenField.value : ''),
			encodeParam('location_id', locationField ? locationField.value : '')
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

	function clear(el) {
		while (el && el.firstChild) {
			el.removeChild(el.firstChild);
		}
	}

	function textNode(tag, text, className) {
		var el = document.createElement(tag);
		// textContent only — device names come from Square and are never
		// interpolated into markup.
		el.textContent = text;
		if (className) {
			el.className = className;
		}
		return el;
	}

	function describeDevice(device) {
		var parts = [];
		if (device.label) { parts.push(device.label); }
		if (device.name && !device.label) { parts.push(device.name); }
		if (device.model) { parts.push(device.model); }
		if (device.status) { parts.push(device.status); }
		if (device.id) { parts.push(device.id); }
		return parts.join(' — ');
	}

	function renderSection(container, title, devices, emptyText, note) {
		container.appendChild(textNode('h4', title));
		if (!devices.length) {
			container.appendChild(textNode('p', emptyText, 'description'));
			return;
		}
		var list = document.createElement('ul');
		for (var i = 0; i < devices.length; i++) {
			list.appendChild(textNode('li', describeDevice(devices[i] || {})));
		}
		container.appendChild(list);
		if (note) {
			container.appendChild(textNode('p', note, 'description'));
		}
	}

	function renderReaders(body) {
		var container = document.getElementById('sqtwc-reader-list');
		if (!container) {
			return '';
		}
		var paired = (body && body.paired) || [];
		var account = (body && body.account) || [];

		clear(container);
		renderSection(container, strings.pairedTitle || 'Paired', paired, strings.nonePaired || 'None paired.');
		renderSection(container, strings.accountTitle || 'Other Terminals', account, strings.noneAtAll || 'None found.', strings.accountNote);

		var template = strings.readersFound || 'Found %1$d paired and %2$d other Terminal(s).';
		return template.replace('%1$d', String(paired.length)).replace('%2$d', String(account.length));
	}

	function init() {
		bind('sqtwc-check-readers', 'sqtwc_list_devices', renderReaders);
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
