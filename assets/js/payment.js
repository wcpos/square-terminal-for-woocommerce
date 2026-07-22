/**
 * Square Terminal for WooCommerce — cashier payment frontend.
 *
 * Dependency-free vanilla JS. A single controller drives one #sqtwc-payment
 * container: it owns the polling loop, the button state machine, cancel /
 * detach recovery, reload resume, and the optional cashier debug log.
 *
 * Testability: `createController(env)` takes every side-effecting dependency
 * (document, fetch, timers, storage, location, clock) so the loop and state
 * machine can be exercised deterministically under node:test. In a browser the
 * bottom IIFE boots real controllers against real globals.
 *
 * Every dynamic string comes from the localized `sqtwcPayment.strings` object;
 * no English is hardcoded here. All DOM text is written via textContent.
 */
(function () {
	'use strict';

	var STATES = {
		IDLE: 'idle',
		CREATING: 'creating',
		POLLING: 'polling',
		CANCELLING: 'cancelling',
		FINAL: 'final'
	};

	var FINAL_SQUARE_STATUSES = { COMPLETED: true, CANCELED: true, CANCELLED: true };

	/**
	 * Default poll tuning, overridden by localized config.
	 */
	var DEFAULT_POLL = {
		cadenceMs: 2000,
		backoffStartMs: 2000,
		backoffCapMs: 15000,
		unstableAfter: 3,
		deadlineMs: 330000
	};

	/**
	 * Create a payment controller bound to one container element.
	 *
	 * @param {Object} env Injected dependencies.
	 *   env.root         {Element}  the #sqtwc-payment container.
	 *   env.config       {Object}   localized sqtwcPayment data.
	 *   env.doc          {Document} document (for createElement).
	 *   env.fetch        {Function} fetch implementation.
	 *   env.setTimeout   {Function} timer scheduler.
	 *   env.clearTimeout {Function} timer canceller.
	 *   env.now          {Function} () => epoch ms.
	 *   env.sessionStorage {Storage} optional.
	 *   env.localStorage {Storage} optional.
	 *   env.navigate     {Function} (url) => void, for redirects.
	 *   env.sendBeacon   {Function} optional (url, body) => bool.
	 * @return {Object} controller
	 */
	function createController(env) {
		var root = env.root;
		var config = env.config || {};
		if (config.collectionMethod === 'pos_app') {
			return createPosController(env);
		}
		var doc = env.doc || (root && root.ownerDocument) || (typeof document !== 'undefined' ? document : null);
		var fetchImpl = env.fetch;
		var setTimeoutImpl = env.setTimeout;
		var clearTimeoutImpl = env.clearTimeout;
		var now = env.now || function () { return Date.now(); };
		var sessionStore = env.sessionStorage || null;
		var localStore = env.localStorage || null;
		var navigate = env.navigate || function () {};
		var sendBeacon = env.sendBeacon || null;

		var strings = config.strings || {};
		var actions = config.actions || {};
		var poll = mergePoll(config.poll);

		var els = {
			deviceSelect: root.querySelector('#sqtwc-device-id'),
			deviceManual: root.querySelector('#sqtwc-device-id-manual'),
			deviceManualLabel: root.querySelector('.sqtwc-payment__device-manual-label'),
			start: root.querySelector('[data-sqtwc-action="start"]'),
			cancel: root.querySelector('[data-sqtwc-action="cancel"]'),
			check: root.querySelector('[data-sqtwc-action="check"]'),
			detach: root.querySelector('[data-sqtwc-action="detach"]'),
			status: root.querySelector('#sqtwc-status'),
			logPanel: root.querySelector('#sqtwc-log-panel'),
			logToggle: root.querySelector('[data-sqtwc-action="log-toggle"]'),
			logBody: root.querySelector('.sqtwc-payment__log-body'),
			logOutput: root.querySelector('#sqtwc-log'),
			logCopy: root.querySelector('[data-sqtwc-action="log-copy"]'),
			logClear: root.querySelector('[data-sqtwc-action="log-clear"]')
		};

		var orderId = root.getAttribute('data-order-id') || '';

		var state = {
			name: STATES.IDLE,
			deviceId: '',
			checkoutId: '',
			attemptId: '',
			pollSeq: 0,
			pollTimer: null,
			consecutiveErrors: 0,
			errorDelayMs: poll.cadenceMs,
			deadlineAt: 0,
			deadlineHandled: false,
			cancelRequested: false,
			detachAvailable: false,
			logLines: []
		};

		var controller = {
			state: state,
			els: els,
			// Exposed for tests + delegation.
			handleAction: handleAction,
			start: startPayment,
			cancel: cancelPayment,
			detach: detachPayment,
			checkStatus: checkStatusManual,
			hasActiveAttempt: hasActiveAttempt,
			beaconCancel: beaconCancel,
			getSelectedDevice: getSelectedDevice,
			render: render
		};

		init();

		return controller;

		// ---- Setup -------------------------------------------------------

		function init() {
			populateDevices();
			restoreLog();
			bindLogToggleInitial();

			var resume = root.getAttribute('data-resume') === '1';
			if (resume) {
				resumeAttempt();
			} else {
				setState(STATES.IDLE);
				if (!getSelectedDevice() && strings.statusChooseDevice) {
					setStatus(strings.statusChooseDevice, 'info', false);
				}
			}
		}

		function populateDevices() {
			var devices = config.devices || [];
			if (els.deviceSelect) {
				// Rebuild options from localized data via createElement/textContent
				// only — never HTML interpolation of device names.
				clearChildren(els.deviceSelect);

				var placeholder = createOption('', strings.devicePlaceholder || '');
				placeholder.disabled = true;
				placeholder.selected = true;
				els.deviceSelect.appendChild(placeholder);

				for (var i = 0; i < devices.length; i++) {
					var d = devices[i] || {};
					els.deviceSelect.appendChild(createOption(String(d.id || ''), String(d.label || d.id || '')));
				}
			}

			var hasDevices = devices.length > 0;
			// Sandbox / paired list: use the dropdown. Empty production list:
			// fall back to the temporary manual-entry field.
			showEl(els.deviceSelect, hasDevices);
			showEl(els.deviceManual, !hasDevices);
			showEl(els.deviceManualLabel, !hasDevices);

			restoreLastDevice(devices, hasDevices);
		}

		function restoreLastDevice(devices, hasDevices) {
			var resumeDevice = root.getAttribute('data-device-id') || '';
			var last = resumeDevice || readStore(localStore, deviceStoreKey());

			if (!last) {
				return;
			}

			if (hasDevices && els.deviceSelect) {
				// Restore only if still present in the list — never auto-select
				// a device the saved default no longer matches.
				var present = false;
				for (var i = 0; i < devices.length; i++) {
					if (devices[i] && String(devices[i].id) === String(last)) {
						present = true;
						break;
					}
				}
				if (present) {
					els.deviceSelect.value = last;
					state.deviceId = last;
				}
			} else if (els.deviceManual) {
				els.deviceManual.value = last;
				state.deviceId = last;
			}
		}

		function resumeAttempt() {
			state.checkoutId = root.getAttribute('data-checkout-id') || '';
			state.attemptId = root.getAttribute('data-attempt-id') || '';
			state.deviceId = root.getAttribute('data-device-id') || state.deviceId;
			log('info', 'Resuming in-flight payment after reload');
			setState(STATES.POLLING);
			setStatus(strings.statusInProgress, 'info', true);
			beginPolling(true);
		}

		// ---- Action delegation ------------------------------------------

		function handleAction(action) {
			switch (action) {
				case 'start':
					startPayment();
					break;
				case 'cancel':
					cancelPayment();
					break;
				case 'check':
					checkStatusManual();
					break;
				case 'detach':
					detachPayment();
					break;
				case 'log-toggle':
					toggleLog();
					break;
				case 'log-copy':
					copyLog();
					break;
				case 'log-clear':
					clearLog();
					break;
				default:
					break;
			}
		}

		// ---- Create -----------------------------------------------------

		function startPayment() {
			if (state.name !== STATES.IDLE && state.name !== STATES.FINAL) {
				return;
			}

			var device = getSelectedDevice();
			if (!device) {
				setStatus(strings.errorNoDevice, 'error', false);
				return;
			}

			state.deviceId = device;
			writeStore(localStore, deviceStoreKey(), device);

			resetAttempt();
			setState(STATES.CREATING);
			setStatus(strings.statusCreating, 'info', true);
			log('info', 'Start payment requested (device ' + device + ')');

			request(actions.create, { device_id: device }).then(function (res) {
				if (!res.ok) {
					failCreate(res);
					return;
				}
				var checkout = extractCheckout(res.body);
				state.checkoutId = checkout.id || '';
				state.attemptId = checkout.attempt_id || checkout.attemptId || state.attemptId;
				log('info', 'Terminal checkout created: ' + (state.checkoutId || '(unknown)'));
				setState(STATES.POLLING);
				applyReconciled(toReconciled(res.body, checkout), false);
				if (state.name === STATES.POLLING) {
					beginPolling(true);
				}
			});
		}

		function failCreate(res) {
			setState(STATES.FINAL);
			setStatus(errorMessage(res), 'error', false);
			log('error', 'Create failed: ' + errorMessage(res));
		}

		// ---- Cancel -----------------------------------------------------

		function cancelPayment() {
			if (state.name !== STATES.POLLING) {
				return;
			}

			state.cancelRequested = true;
			stopPolling();
			setState(STATES.CANCELLING);
			setStatus(strings.statusCancelling, 'warning', true);
			log('info', 'Cancel requested for checkout ' + state.checkoutId);

			request(actions.cancel, {
				checkout_id: state.checkoutId,
				device_id: state.deviceId
			}).then(function (res) {
				if (!res.ok) {
					// Transport / inconclusive: never treat a sent cancel as done.
					// Keep watching and offer detach.
					log('warning', 'Cancel inconclusive: ' + errorMessage(res));
					state.detachAvailable = true;
					setState(STATES.POLLING);
					setStatus(strings.detachHint, 'warning', true);
					beginPolling(true);
					return;
				}

				var checkout = extractCheckout(res.body);
				var reconciled = toReconciled(res.body, checkout);
				if (isFinalStatus(reconciled.status)) {
					applyReconciled(reconciled, true);
					return;
				}

				// CANCEL_REQUESTED or still pending: cancel is a request, not a
				// result. Resume polling until it resolves; reveal detach.
				log('info', 'Cancel acknowledged (' + reconciled.status + '); still watching');
				state.detachAvailable = true;
				setState(STATES.POLLING);
				setStatus(reconciled.message || strings.statusCancelling, 'warning', true);
				beginPolling(true);
			});
		}

		// ---- Detach (release stuck / offline terminal) ------------------

		function detachPayment() {
			if (!state.detachAvailable) {
				return;
			}

			stopPolling();
			setState(STATES.CANCELLING);
			setStatus(strings.statusCancelling, 'warning', true);
			log('warning', 'Releasing unresponsive terminal for checkout ' + state.checkoutId);

			request(actions.detach, {
				checkout_id: state.checkoutId,
				device_id: state.deviceId
			}).then(function (res) {
				if (!res.ok) {
					setStatus(errorMessage(res), 'error', false);
					log('error', 'Detach failed: ' + errorMessage(res));
					// Leave detach available so the cashier can retry.
					state.detachAvailable = true;
					setState(STATES.POLLING);
					render();
					return;
				}

				log('info', 'Payment released');
				state.checkoutId = '';
				state.attemptId = '';
				state.detachAvailable = false;
				state.cancelRequested = false;
				setState(STATES.IDLE);
				setStatus(strings.statusReleased, 'warning', false);
			});
		}

		// ---- Manual check -----------------------------------------------

		function checkStatusManual() {
			if (state.name === STATES.CREATING || state.name === STATES.CANCELLING) {
				return;
			}
			if (!state.checkoutId) {
				return;
			}

			disable(els.check, true);
			setStatus(strings.statusCheckingNow, 'info', true);
			log('info', 'Manual status check');

			requestStatus(true).then(function (res) {
				disable(els.check, false);
				if (!res.ok) {
					setStatus(errorMessage(res), 'error', false);
					return;
				}
				applyReconciled(toStatusReconciled(res.body), state.name === STATES.CANCELLING);
			});
		}

		// ---- Polling loop -----------------------------------------------

		function beginPolling(immediate) {
			state.pollSeq += 1;
			var seq = state.pollSeq;
			state.consecutiveErrors = 0;
			state.errorDelayMs = poll.cadenceMs;
			if (!state.deadlineAt) {
				state.deadlineAt = now() + poll.deadlineMs;
			}
			scheduleTick(seq, immediate ? 0 : poll.cadenceMs);
		}

		function scheduleTick(seq, delay) {
			clearPollTimer();
			state.pollTimer = setTimeoutImpl(function () {
				pollTick(seq);
			}, delay);
		}

		function pollTick(seq) {
			if (seq !== state.pollSeq) {
				return; // Superseded session.
			}

			if (!state.deadlineHandled && now() >= state.deadlineAt) {
				handleDeadline(seq);
				return;
			}

			requestStatus(false).then(function (res) {
				if (seq !== state.pollSeq) {
					return; // Stale response from a superseded session — drop it.
				}

				if (!res.ok) {
					onPollTransportError(seq);
					return;
				}

				state.consecutiveErrors = 0;
				state.errorDelayMs = poll.cadenceMs;

				var reconciled = toStatusReconciled(res.body);
				applyReconciled(reconciled, state.name === STATES.CANCELLING);

				if (state.name === STATES.POLLING && reconciled.continuePolling) {
					scheduleTick(seq, poll.cadenceMs);
				}
			});
		}

		function onPollTransportError(seq) {
			state.consecutiveErrors += 1;
			if (state.consecutiveErrors >= poll.unstableAfter) {
				setStatus(strings.connectionUnstable, 'warning', true);
				log('warning', 'Connection unstable (' + state.consecutiveErrors + ' consecutive errors)');
			}

			var exp = poll.backoffStartMs * Math.pow(2, state.consecutiveErrors - 1);
			state.errorDelayMs = Math.min(exp, poll.backoffCapMs);
			scheduleTick(seq, state.errorDelayMs);
		}

		function handleDeadline(seq) {
			state.deadlineHandled = true;
			log('warning', 'Deadline reached; issuing one fresh status read before cancel');
			setStatus(strings.statusCheckingNow, 'warning', true);

			// One forced fresh read; only if still non-final do we cancel.
			requestStatus(true).then(function (res) {
				if (seq !== state.pollSeq) {
					return;
				}
				if (res.ok) {
					var reconciled = toStatusReconciled(res.body);
					applyReconciled(reconciled, false);
					if (isFinalStatus(reconciled.status) || !reconciled.continuePolling) {
						return; // Payment resolved at the boundary — no cancel.
					}
				}
				// Still non-final (or the read failed): auto-cancel and inform.
				log('warning', 'Terminal did not respond in time; cancelling');
				setStatus(strings.statusTimeout, 'warning', false);
				autoCancelAfterTimeout();
			});
		}

		function autoCancelAfterTimeout() {
			setState(STATES.CANCELLING);
			request(actions.cancel, {
				checkout_id: state.checkoutId,
				device_id: state.deviceId
			}).then(function (res) {
				if (!res.ok) {
					state.detachAvailable = true;
					setState(STATES.POLLING);
					setStatus(strings.detachHint, 'warning', false);
					stopPolling();
					render();
					return;
				}
				var checkout = extractCheckout(res.body);
				var reconciled = toReconciled(res.body, checkout);
				if (isFinalStatus(reconciled.status)) {
					applyReconciled(reconciled, true);
				} else {
					state.detachAvailable = true;
					setState(STATES.POLLING);
					setStatus(strings.detachHint, 'warning', false);
					stopPolling();
					render();
				}
			});
		}

		function stopPolling() {
			state.pollSeq += 1; // Invalidate any in-flight tick/response.
			clearPollTimer();
		}

		function clearPollTimer() {
			if (state.pollTimer !== null && clearTimeoutImpl) {
				clearTimeoutImpl(state.pollTimer);
			}
			state.pollTimer = null;
		}

		// ---- Reconciliation / status application ------------------------

		function applyReconciled(reconciled, wasCancelling) {
			if (reconciled.redirectUrl) {
				stopPolling();
				setState(STATES.FINAL);
				setStatus(reconciled.message || strings.statusCompleted, 'success', false);
				log('info', 'Redirecting to receipt');
				navigate(reconciled.redirectUrl);
				return;
			}

			var status = reconciled.status;

			if (status === 'COMPLETED') {
				stopPolling();
				setState(STATES.FINAL);
				setStatus(reconciled.message || strings.statusCompleted, 'success', false);
				log('info', 'Payment completed');
				return;
			}

			if (status === 'CANCELED' || status === 'CANCELLED') {
				stopPolling();
				resetAttempt();
				setState(STATES.FINAL);
				setStatus(reconciled.message || strings.statusCancelled, 'warning', false);
				log('info', 'Payment cancelled');
				return;
			}

			if (!reconciled.continuePolling) {
				// Idle / no active attempt server-side.
				stopPolling();
				setState(STATES.IDLE);
				if (reconciled.message) {
					setStatus(reconciled.message, 'info', false);
				}
				return;
			}

			// Non-final: keep the panel busy with a cashier-facing message.
			var severity = wasCancelling || state.cancelRequested ? 'warning' : 'info';
			setStatus(reconciled.message || statusText(status), severity, true);
		}

		// ---- Networking -------------------------------------------------

		function request(action, extra) {
			var body = buildBody(action, extra);
			return fetchImpl(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			}).then(parseResponse, function () {
				return { ok: false, code: 0, body: null, network: true };
			});
		}

		function requestStatus(force) {
			return request(actions.status, force ? { force: '1' } : {});
		}

		function parseResponse(resp) {
			// Contract note: create/cancel put an HTTP-style numeric `status` in
			// the body; the status endpoint puts a Square status *string* there.
			// A numeric body `status` therefore signals a create/cancel result
			// whose >=400 value means failure; a string leaves ok to the HTTP
			// layer. We code defensively as the server side is built in parallel.
			var httpOk = !!resp && resp.ok !== false;
			var textFn = resp && typeof resp.text === 'function' ? resp.text() : Promise.resolve('');
			return Promise.resolve(textFn).then(function (text) {
				var parsed = safeJson(text);
				var bodyCode = parsed && typeof parsed.status === 'number' ? parsed.status : null;
				var ok = httpOk;
				if (bodyCode !== null && bodyCode >= 400) {
					ok = false;
				}
				if (parsed && parsed.error && bodyCode === null && !httpOk) {
					ok = false;
				}
				var code = bodyCode !== null
					? bodyCode
					: (resp && typeof resp.status === 'number' ? resp.status : (httpOk ? 200 : 0));
				return { ok: ok, code: code, body: parsed };
			});
		}

		function buildBody(action, extra) {
			var params = [];
			pushParam(params, 'action', action);
			pushParam(params, 'order_id', orderId);
			pushParam(params, '_wpnonce', config.nonce);
			pushParam(params, 'order_key', root.getAttribute('data-order-key') || '');
			pushParam(params, 'payment_request_token', root.getAttribute('data-payment-token') || '');
			if (extra) {
				for (var k in extra) {
					if (Object.prototype.hasOwnProperty.call(extra, k)) {
						pushParam(params, k, extra[k]);
					}
				}
			}
			return params.join('&');
		}

		// ---- Button state machine ---------------------------------------

		function setState(name) {
			state.name = name;
			render();
		}

		function render() {
			var s = state.name;
			var deviceLocked = s === STATES.CREATING || s === STATES.POLLING || s === STATES.CANCELLING;

			// Start: enabled in idle/final, disabled otherwise.
			disable(els.start, !(s === STATES.IDLE || s === STATES.FINAL));
			if (els.start) {
				setText(els.start, s === STATES.FINAL ? (strings.retryPayment || strings.startPayment) : strings.startPayment);
			}

			// Cancel: visible+enabled only while polling.
			showEl(els.cancel, s === STATES.POLLING);
			disable(els.cancel, s !== STATES.POLLING);

			// Check status: enabled in idle/polling/final, disabled mid-mutation.
			disable(els.check, s === STATES.CREATING || s === STATES.CANCELLING);

			// Detach: only after a cancel flow fails to conclude.
			showEl(els.detach, state.detachAvailable && (s === STATES.POLLING || s === STATES.CANCELLING));
			disable(els.detach, s === STATES.CANCELLING || !state.detachAvailable);

			// Device selector: locked while an attempt is active.
			disable(els.deviceSelect, deviceLocked);
			disable(els.deviceManual, deviceLocked);
		}

		// ---- Status region ----------------------------------------------

		function setStatus(message, severity, busy) {
			if (!els.status) {
				return;
			}
			setText(els.status, message || '');
			els.status.className = 'sqtwc-payment__status sqtwc-status--' + (severity || 'info') + (busy ? ' is-busy' : '');
		}

		function statusText(status) {
			switch (status) {
				case 'PENDING':
					return strings.statusWaiting;
				case 'IN_PROGRESS':
					return strings.statusInProgress;
				case 'CANCEL_REQUESTED':
					return strings.statusCancelling;
				default:
					return strings.statusInProgress;
			}
		}

		// ---- Cashier debug log ------------------------------------------

		function log(level, message) {
			if (!els.logOutput) {
				return;
			}
			var line = timestamp() + ' [' + String(level).toUpperCase() + '] ' + message;
			state.logLines.push(line);
			if (state.logLines.length > 50) {
				state.logLines = state.logLines.slice(state.logLines.length - 50);
			}
			renderLog();
			writeStore(sessionStore, logStoreKey(), state.logLines.join('\n'));
		}

		function renderLog() {
			if (els.logOutput) {
				els.logOutput.value = state.logLines.join('\n');
			}
		}

		function restoreLog() {
			if (!els.logOutput) {
				return;
			}
			var stored = readStore(sessionStore, logStoreKey());
			if (stored) {
				state.logLines = stored.split('\n').slice(-50);
				renderLog();
			}
		}

		function bindLogToggleInitial() {
			if (els.logPanel) {
				showEl(els.logPanel, true);
			}
		}

		function toggleLog() {
			if (!els.logBody || !els.logToggle) {
				return;
			}
			var hidden = els.logBody.hasAttribute('hidden');
			showEl(els.logBody, hidden);
			els.logToggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
			setText(els.logToggle, hidden ? strings.logHide : strings.logShow);
		}

		function copyLog() {
			if (!els.logOutput) {
				return;
			}
			var text = els.logOutput.value;
			var nav = typeof navigator !== 'undefined' ? navigator : null;
			if (nav && nav.clipboard && nav.clipboard.writeText) {
				nav.clipboard.writeText(text);
			} else if (els.logOutput.select) {
				els.logOutput.select();
				if (doc && doc.execCommand) {
					doc.execCommand('copy');
				}
			}
		}

		function clearLog() {
			state.logLines = [];
			renderLog();
			removeStore(sessionStore, logStoreKey());
		}

		// ---- Helpers ----------------------------------------------------

		function getSelectedDevice() {
			if (els.deviceSelect && !els.deviceSelect.hasAttribute('hidden')) {
				return (els.deviceSelect.value || '').trim();
			}
			if (els.deviceManual) {
				return (els.deviceManual.value || '').trim();
			}
			return '';
		}

		function hasActiveAttempt() {
			return (state.name === STATES.POLLING || state.name === STATES.CANCELLING || state.name === STATES.CREATING) && !!state.checkoutId;
		}

		function beaconCancel() {
			if (!sendBeacon || !hasActiveAttempt()) {
				return;
			}
			try {
				sendBeacon(config.ajaxUrl, buildBody(actions.cancel, {
					checkout_id: state.checkoutId,
					device_id: state.deviceId
				}));
			} catch (e) {
				// Best-effort only.
			}
		}

		function resetAttempt() {
			state.checkoutId = '';
			state.attemptId = '';
			state.consecutiveErrors = 0;
			state.errorDelayMs = poll.cadenceMs;
			state.deadlineAt = 0;
			state.deadlineHandled = false;
			state.cancelRequested = false;
			state.detachAvailable = false;
		}

		function extractCheckout(body) {
			if (!body) {
				return {};
			}
			return body.checkout || body.terminal_checkout || {};
		}

		function toReconciled(body, checkout) {
			return {
				status: (checkout && checkout.status) || (body && body.status_text) || '',
				message: body && body.cashier_message ? body.cashier_message : '',
				continuePolling: !isFinalStatus((checkout && checkout.status) || ''),
				redirectUrl: body && body.redirect_url ? body.redirect_url : ''
			};
		}

		function toStatusReconciled(body) {
			body = body || {};
			var status = typeof body.status === 'string' ? body.status : '';
			var cont = body.continue_polling;
			if (typeof cont === 'undefined') {
				cont = !isFinalStatus(status);
			}
			return {
				status: status,
				message: body.cashier_message || '',
				continuePolling: !!cont,
				redirectUrl: body.redirect_url || ''
			};
		}

		function errorMessage(res) {
			if (res && res.body && res.body.cashier_message) {
				return res.body.cashier_message;
			}
			if (res && res.body && res.body.error) {
				return res.body.error;
			}
			if (res && res.network) {
				return strings.errorNetwork || strings.errorGeneric;
			}
			return strings.errorGeneric;
		}

		function deviceStoreKey() {
			return 'sqtwc_last_device';
		}

		function logStoreKey() {
			return 'sqtwc_log_' + orderId;
		}

		function createOption(value, label) {
			var opt = doc.createElement('option');
			opt.value = value;
			setText(opt, label);
			return opt;
		}
	}

	// ---- Square Point of Sale app handoff -------------------------------

	function buildPosState(cfg) {
		return JSON.stringify({ o: Number(cfg.orderId), k: String(cfg.orderKey || '') });
	}

	function buildAndroidPosUrl(cfg) {
		var parts = [
			'intent:#Intent',
			'action=com.squareup.pos.action.CHARGE',
			'package=com.squareup',
			'S.browser_fallback_url=' + encodeURIComponent(cfg.posCallbackUrl),
			'S.com.squareup.pos.WEB_CALLBACK_URI=' + encodeURIComponent(cfg.posCallbackUrl),
			'S.com.squareup.pos.CLIENT_ID=' + encodeURIComponent(cfg.posApplicationId),
			'S.com.squareup.pos.API_VERSION=v2.0',
			'i.com.squareup.pos.TOTAL_AMOUNT=' + Number(cfg.amount),
			'S.com.squareup.pos.CURRENCY_CODE=' + encodeURIComponent(cfg.currency),
			'S.com.squareup.pos.TENDER_TYPES=com.squareup.pos.TENDER_CARD'
		];
		if (cfg.posLocationId) {
			parts.push('S.com.squareup.pos.LOCATION_ID=' + encodeURIComponent(cfg.posLocationId));
		}
		parts.push('S.com.squareup.pos.REQUEST_METADATA=' + encodeURIComponent(cfg.state));
		if (cfg.note) {
			parts.push('S.com.squareup.pos.NOTE=' + encodeURIComponent(cfg.note));
		}
		parts.push('i.com.squareup.pos.AUTO_RETURN_TIMEOUT_MS=3200', 'end');
		return parts.join(';');
	}

	function buildIosPosUrl(cfg) {
		var data = {
			amount_money: { amount: Number(cfg.amount), currency_code: String(cfg.currency) },
			callback_url: String(cfg.posCallbackUrl),
			client_id: String(cfg.posApplicationId),
			version: '1.3'
		};
		if (cfg.posLocationId) {
			data.location_id = String(cfg.posLocationId);
		}
		data.state = String(cfg.state);
		if (cfg.note) {
			data.notes = String(cfg.note);
		}
		data.options = {
			supported_tender_types: ['CREDIT_CARD'],
			auto_return: true,
			skip_receipt: !!cfg.skipReceipt
		};
		return 'square-commerce-v1://payment/create?data=' + encodeURIComponent(JSON.stringify(data));
	}

	function detectPosPlatform(userAgent, maxTouchPoints) {
		userAgent = String(userAgent || '');
		if (/Android/i.test(userAgent)) {
			return 'android';
		}
		if (/iPhone|iPad|iPod/i.test(userAgent) || (/Macintosh/i.test(userAgent) && Number(maxTouchPoints) > 1)) {
			return 'ios';
		}
		return 'unsupported';
	}

	function createPosController(env) {
		var root = env.root;
		var config = env.config || {};
		var strings = config.strings || {};
		var button = root.querySelector('[data-sqtwc-action="pos-open"]');
		var status = root.querySelector('#sqtwc-status');
		var userAgent = env.userAgent !== undefined ? env.userAgent : (typeof navigator !== 'undefined' ? navigator.userAgent : '');
		var maxTouchPoints = env.maxTouchPoints !== undefined ? env.maxTouchPoints : (typeof navigator !== 'undefined' ? navigator.maxTouchPoints : 0);
		var locationRef = env.location || (typeof window !== 'undefined' ? window.location : { search: '' });
		var navigate = env.navigate || function () {};
		var platform = detectPosPlatform(userAgent, maxTouchPoints);

		var controller = {
			handleAction: function (action) {
				if (action !== 'pos-open' || !button || button.disabled) {
					return;
				}
				setPosStatus(strings.posOpening);
				button.disabled = true;
				var cfg = copyObject(config);
				cfg.state = buildPosState(config);
				navigate(platform === 'android' ? buildAndroidPosUrl(cfg) : buildIosPosUrl(cfg));
			},
			beaconCancel: function () { return false; }
		};

		initPos();
		return controller;

		function initPos() {
			if (config.environment !== 'production') {
				disable(button, true);
				setPosStatus(strings.posProductionRequired);
				return;
			}

			var params = parseQuery(locationRef.search || '');
			if (params.sqtwc_pos_result === 'offline') {
				disable(button, false);
				setPosStatus(strings.posOffline);
				return;
			}
			if (params.sqtwc_pos_result === 'error') {
				disable(button, false);
				setPosStatus(posErrorMessage(params.sqtwc_pos_code || ''));
				return;
			}
			if (platform === 'unsupported') {
				disable(button, true);
				setPosStatus(strings.posUnsupported);
			}
		}

		function posErrorMessage(code) {
			if (code === 'payment_canceled') { return strings.posCanceled; }
			if (code === 'not_logged_in') { return strings.posNotLoggedIn; }
			if (code === 'no_network') { return strings.posNoNetwork; }
			return String(strings.posError || '').replace('%s', code);
		}

		function setPosStatus(message) {
			if (status) {
				setText(status, message || '');
			}
		}
	}

	function parseQuery(search) {
		var params = {};
		String(search || '').replace(/^\?/, '').split('&').forEach(function (part) {
			if (!part) { return; }
			var pair = part.split('=');
			params[decodeURIComponent(pair[0])] = decodeURIComponent((pair.slice(1).join('=') || '').replace(/\+/g, ' '));
		});
		return params;
	}

	function copyObject(source) {
		var copy = {};
		for (var key in source) {
			if (Object.prototype.hasOwnProperty.call(source, key)) {
				copy[key] = source[key];
			}
		}
		return copy;
	}

	// ---- Module-level DOM/util helpers ----------------------------------

	function isFinalStatus(status) {
		return !!FINAL_SQUARE_STATUSES[status];
	}

	function mergePoll(cfg) {
		cfg = cfg || {};
		return {
			cadenceMs: numOr(cfg.cadenceMs, DEFAULT_POLL.cadenceMs),
			backoffStartMs: numOr(cfg.backoffStartMs, DEFAULT_POLL.backoffStartMs),
			backoffCapMs: numOr(cfg.backoffCapMs, DEFAULT_POLL.backoffCapMs),
			unstableAfter: numOr(cfg.unstableAfter, DEFAULT_POLL.unstableAfter),
			deadlineMs: numOr(cfg.deadlineMs, DEFAULT_POLL.deadlineMs)
		};
	}

	function numOr(value, fallback) {
		var n = Number(value);
		return isFinite(n) && n > 0 ? n : fallback;
	}

	function setText(el, text) {
		if (el) {
			el.textContent = text == null ? '' : String(text);
		}
	}

	function disable(el, isDisabled) {
		if (el) {
			el.disabled = !!isDisabled;
		}
	}

	function showEl(el, show) {
		if (!el) {
			return;
		}
		if (show) {
			el.removeAttribute('hidden');
		} else {
			el.setAttribute('hidden', 'hidden');
		}
	}

	function clearChildren(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
	}

	function pushParam(params, key, value) {
		if (value === undefined || value === null || value === '') {
			return;
		}
		params.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
	}

	function safeJson(text) {
		if (!text) {
			return null;
		}
		try {
			return JSON.parse(text);
		} catch (e) {
			return null;
		}
	}

	function readStore(store, key) {
		if (!store) {
			return '';
		}
		try {
			return store.getItem(key) || '';
		} catch (e) {
			return '';
		}
	}

	function writeStore(store, key, value) {
		if (!store) {
			return;
		}
		try {
			store.setItem(key, value);
		} catch (e) {
			// Ignore quota / privacy-mode failures.
		}
	}

	function removeStore(store, key) {
		if (!store) {
			return;
		}
		try {
			store.removeItem(key);
		} catch (e) {
			// Ignore.
		}
	}

	function timestamp() {
		return new Date().toISOString();
	}

	// ---- Browser boot ---------------------------------------------------

	/**
	 * Boot controllers for every un-bound container and wire global listeners.
	 *
	 * @param {Object} [globalEnv] Optional overrides (used in tests).
	 * @return {Array} controllers
	 */
	function boot(globalEnv) {
		globalEnv = globalEnv || {};
		var win = globalEnv.window || (typeof window !== 'undefined' ? window : null);
		var docRef = globalEnv.document || (win && win.document) || (typeof document !== 'undefined' ? document : null);
		if (!docRef) {
			return [];
		}

		var config = globalEnv.config || (win && win.sqtwcPayment) || {};
		var controllers = [];
		var containers = docRef.querySelectorAll('#sqtwc-payment, .sqtwc-payment');

		for (var i = 0; i < containers.length; i++) {
			var root = containers[i];
			if (root.__sqtwcBound) {
				continue; // Dedupe bindings across updated_checkout re-inits.
			}
			root.__sqtwcBound = true;

			var controller = createController({
				root: root,
				config: config,
				doc: docRef,
				fetch: (win && win.fetch) ? win.fetch.bind(win) : (typeof fetch !== 'undefined' ? fetch : null),
				setTimeout: (win && win.setTimeout) ? win.setTimeout.bind(win) : setTimeout,
				clearTimeout: (win && win.clearTimeout) ? win.clearTimeout.bind(win) : clearTimeout,
				now: function () { return Date.now(); },
				sessionStorage: safeStorage(win, 'sessionStorage'),
				localStorage: safeStorage(win, 'localStorage'),
				navigate: function (url) { if (win && win.location) { win.location.assign(url); } },
				sendBeacon: (win && win.navigator && win.navigator.sendBeacon) ? win.navigator.sendBeacon.bind(win.navigator) : null,
				userAgent: win && win.navigator ? win.navigator.userAgent : '',
				maxTouchPoints: win && win.navigator ? win.navigator.maxTouchPoints : 0,
				location: win && win.location ? win.location : { search: '' }
			});

			controllers.push(controller);

			// Event delegation: one click + one change handler per container.
			root.addEventListener('click', makeClickHandler(controller));
		}

		// Best-effort cancel of active attempts when the page is dismissed.
		if (win && win.addEventListener && controllers.length) {
			win.addEventListener('pagehide', function () {
				for (var j = 0; j < controllers.length; j++) {
					controllers[j].beaconCancel();
				}
			});
		}

		return controllers;
	}

	function makeClickHandler(controller) {
		// The listener is attached to the container root, so any matched
		// action element is guaranteed to belong to this controller.
		return function (event) {
			var target = event.target;
			if (!target || !target.closest) {
				return;
			}
			var actionEl = target.closest('[data-sqtwc-action]');
			if (actionEl) {
				controller.handleAction(actionEl.getAttribute('data-sqtwc-action'));
			}
		};
	}

	function safeStorage(win, name) {
		try {
			return win && win[name] ? win[name] : null;
		} catch (e) {
			return null;
		}
	}

	// ---- Exports / auto-boot --------------------------------------------

	var api = {
		createController: createController,
		boot: boot,
		STATES: STATES,
		isFinalStatus: isFinalStatus,
		buildPosState: buildPosState,
		buildAndroidPosUrl: buildAndroidPosUrl,
		buildIosPosUrl: buildIosPosUrl,
		detectPosPlatform: detectPosPlatform
	};

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}

	if (typeof window !== 'undefined' && typeof document !== 'undefined') {
		var run = function () { boot(); };
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', run);
		} else {
			run();
		}
		// Re-bind after WooCommerce replaces the checkout DOM.
		if (window.jQuery && document.body) {
			window.jQuery(document.body).on('updated_checkout', function () { boot(); });
		} else if (document.body && document.body.addEventListener) {
			document.body.addEventListener('updated_checkout', function () { boot(); });
		}
	}
}());
