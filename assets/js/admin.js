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
		// Square reports Handhelds and other hardware here too; show the type so
		// nothing is presented as a Terminal when it isn't one.
		if (device.type) { parts.push(device.type); }
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
		renderSection(
			container,
			strings.accountTitle || 'Other Terminals',
			account,
			// A failed informational lookup is reported as such, never as "none".
			body && body.account_error ? body.account_error : (strings.noneAtAll || 'None found.'),
			account.length ? strings.accountNote : ''
		);

		var template = strings.readersFound || 'Found %1$d paired and %2$d other Terminal(s).';
		return template.replace('%1$d', String(paired.length)).replace('%2$d', String(account.length));
	}

	/**
	 * Keep the Connect link pointed at the environment currently on screen.
	 *
	 * Connect is a link, so an unsaved dropdown change would otherwise be
	 * ignored and someone selecting Production would authorize the sandbox
	 * application. The server validates and persists whatever arrives here.
	 */
	function trackEnvironment() {
		var select = document.getElementById('woocommerce_sqtwc_environment');
		var link = document.getElementById('sqtwc-connect-link');
		if (!select || !link) {
			return;
		}

		var base = link.getAttribute('href');

		function sync() {
			link.setAttribute('href', base + '&environment=' + encodeURIComponent(select.value));

			// The label has to move with the link. Updating only the URL would
			// leave the button reading "sandbox" while starting a production
			// authorization — worse than the bug this whole thing fixes.
			if (strings.connectLabel) {
				link.textContent = strings.connectLabel.replace('%s', select.value);
			}
		}

		select.addEventListener('change', sync);
		sync();
	}

	/**
	 * Copy the webhook URL, so it never has to be selected out of a narrow box.
	 */
	function bindCopyWebhook() {
		var button = document.getElementById('sqtwc-copy-webhook');
		var input = document.getElementById('sqtwc-webhook-url');
		if (!button || !input) {
			return;
		}

		var original = button.textContent;

		button.addEventListener('click', function () {
			input.select();

			function done() {
				button.textContent = button.getAttribute('data-copied') || 'Copied';
				window.setTimeout(function () { button.textContent = original; }, 2000);
			}

			// execCommand is the fallback: the async Clipboard API needs a secure
			// context, and plenty of WordPress admins are served over plain http.
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(input.value).then(done, function () {
					try { document.execCommand('copy'); done(); } catch (e) {}
				});
				return;
			}

			try { document.execCommand('copy'); done(); } catch (e) {}
		});
	}

	function init() {
		trackEnvironment();
		bindCopyWebhook();
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
