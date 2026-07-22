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
		bindCopyUrl('sqtwc-copy-webhook', 'sqtwc-webhook-url');
		bindCopyUrl('sqtwc-copy-pos-callback', 'sqtwc-pos-callback-url');
	}

	function bindCopyUrl(buttonId, inputId) {
		var button = document.getElementById(buttonId);
		var input = document.getElementById(inputId);
		if (!button || !input) {
			return;
		}

		var original = button.textContent;

		button.addEventListener('click', function () {
			input.select();

			function flash(text) {
				button.textContent = text;
				window.setTimeout(function () { button.textContent = original; }, 2500);
			}

			function copied() { flash(button.getAttribute('data-copied') || 'Copied'); }

			// The input stays selected, so a failure leaves the merchant able to
			// copy by hand — but the button must not claim success either way.
			function failed() { flash(button.getAttribute('data-failed') || 'Press Ctrl+C'); }

			// execCommand returns false when the browser blocks legacy copying,
			// without throwing, so its result has to be checked rather than
			// assumed. The async Clipboard API needs a secure context and plenty
			// of WordPress admins are served over plain http.
			function legacyCopy() {
				try {
					return document.execCommand('copy') === true;
				} catch (e) {
					return false;
				}
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(input.value).then(copied, function () {
					if (legacyCopy()) { copied(); } else { failed(); }
				});
				return;
			}

			if (legacyCopy()) { copied(); } else { failed(); }
		});
	}

	/** Settings rows grouped by the device that uses them. */
	var modeRows = {
		terminal: ['section_terminal', 'terminal_pairing', 'webhook_status', 'collect_signature'],
		pos_app: ['pos_application_id']
	};

	function trackDeviceMode() {
		var radios = document.querySelectorAll('input[name="woocommerce_sqtwc_collection_method"]');

		function sync() {
			var selected = 'terminal';
			var mode;
			var i;

			for (i = 0; i < radios.length; i++) {
				if (radios[i].checked) { selected = radios[i].value; }
			}
			for (mode in modeRows) {
				if (Object.prototype.hasOwnProperty.call(modeRows, mode)) {
					for (i = 0; i < modeRows[mode].length; i++) {
						var field = document.getElementById('woocommerce_sqtwc_' + modeRows[mode][i]);
						var row = field ? field.closest('tr') : null;
						if (field) { (row || field).style.display = mode === selected ? '' : 'none'; }
						if (field && !row && field.nextElementSibling && field.nextElementSibling.tagName === 'P') {
							field.nextElementSibling.style.display = mode === selected ? '' : 'none';
						}
					}
				}
			}
		}

		for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', sync); }
		sync();
	}

	/** Keep the checklist's test-mode banner honest before the form is saved. */
	function trackSandboxNotice() {
		var select = document.getElementById('woocommerce_sqtwc_environment');
		var notice = document.getElementById('sqtwc-setup-sandbox-notice');
		if (!select || !notice) { return; }

		function sync() {
			notice.style.display = select.value === 'sandbox' ? '' : 'none';
		}

		select.addEventListener('change', sync);
		sync();
	}

	function validateApplicationId() {
		var input = document.getElementById('woocommerce_sqtwc_pos_application_id');
		var status = document.getElementById('sqtwc-pos-application-status');
		if (!input || !status) { return; }

		function sync() {
			var value = input.value.trim();
			status.className = 'sqtwc-setup__input-status';
			if (!value) { status.textContent = ''; return; }
			if (/^sq0idp-[\w-]{8,}$/.test(value)) {
				status.textContent = strings.applicationIdValid || '✓ That looks right';
				status.className += ' sqtwc-setup__input-status--ok';
			} else if (value.indexOf('sandbox-') === 0) {
				status.textContent = strings.applicationIdSandbox || 'That\'s the test ID — you need the one starting with sq0idp-';
				status.className += ' sqtwc-setup__input-status--warning';
			} else {
				status.textContent = strings.applicationIdInvalid || 'Application IDs start with sq0idp-';
				status.className += ' sqtwc-setup__input-status--muted';
			}
		}

		input.addEventListener('input', sync);
		sync();
	}

	function init() {
		trackEnvironment();
		trackSandboxNotice();
		trackDeviceMode();
		validateApplicationId();
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
