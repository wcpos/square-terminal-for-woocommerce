'use strict';

/**
 * Zero-dependency test helpers for assets/js/payment.js.
 *
 * Provides a minimal fake DOM (only the selectors the controller uses), a
 * manual clock that records scheduled delays, and a deferred fetch mock so
 * every promise settlement is driven explicitly by the test.
 */

// ---- Fake DOM -----------------------------------------------------------

class FakeElement {
	constructor(tagName) {
		this.tagName = String(tagName || 'div').toLowerCase();
		this.attributes = {};
		this.children = [];
		this.parentNode = null;
		this.listeners = {};
		this.textContent = '';
		this.value = '';
		this.disabled = false;
		this.className = '';
		this.selected = false;
	}

	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}

	getAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
	}

	removeAttribute(name) {
		delete this.attributes[name];
	}

	hasAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name);
	}

	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}

	removeChild(child) {
		var i = this.children.indexOf(child);
		if (i !== -1) {
			this.children.splice(i, 1);
			child.parentNode = null;
		}
		return child;
	}

	get firstChild() {
		return this.children.length ? this.children[0] : null;
	}

	addEventListener(type, handler) {
		(this.listeners[type] = this.listeners[type] || []).push(handler);
	}

	select() {
		// no-op for copy flows
	}

	matches(selector) {
		selector = selector.trim();
		if (selector.charAt(0) === '#') {
			return this.getAttribute('id') === selector.slice(1);
		}
		if (selector.charAt(0) === '.') {
			return (this.className || '').split(/\s+/).indexOf(selector.slice(1)) !== -1;
		}
		var attr = selector.match(/^\[([^=\]]+)="([^"]*)"\]$/);
		if (attr) {
			return this.getAttribute(attr[1]) === attr[2];
		}
		return this.tagName === selector.toLowerCase();
	}

	querySelector(selector) {
		var all = this.querySelectorAll(selector);
		return all.length ? all[0] : null;
	}

	querySelectorAll(selector) {
		var selectors = selector.split(',').map(function (s) { return s.trim(); });
		var out = [];
		var walk = function (node) {
			for (var i = 0; i < node.children.length; i++) {
				var child = node.children[i];
				for (var j = 0; j < selectors.length; j++) {
					if (child.matches(selectors[j])) {
						out.push(child);
						break;
					}
				}
				walk(child);
			}
		};
		walk(this);
		return out;
	}
}

function el(tag, attrs) {
	var node = new FakeElement(tag);
	if (attrs) {
		for (var k in attrs) {
			if (Object.prototype.hasOwnProperty.call(attrs, k)) {
				node.setAttribute(k, attrs[k]);
			}
		}
	}
	return node;
}

var fakeDocument = {
	createElement: function (tag) { return new FakeElement(tag); }
};

/**
 * Build a #sqtwc-payment DOM tree mirroring Gateway::render_payment_ui.
 *
 * @param {Object} opts { resume, checkoutId, deviceId, status, orderId, orderKey, debug }
 * @return {Object} { root, els }
 */
function buildDom(opts) {
	opts = opts || {};
	var root = el('div', {
		id: 'sqtwc-payment',
		'data-order-id': opts.orderId || '99'
	});
	root.className = 'sqtwc-payment';
	if (opts.orderKey) {
		root.setAttribute('data-order-key', opts.orderKey);
	}
	if (opts.resume) {
		root.setAttribute('data-resume', '1');
		root.setAttribute('data-checkout-id', opts.checkoutId || 'chk_resume');
		root.setAttribute('data-attempt-id', opts.attemptId || 'att_resume');
		root.setAttribute('data-device-id', opts.deviceId || 'dev_resume');
		root.setAttribute('data-status', opts.status || 'IN_PROGRESS');
	}

	var select = el('select', { id: 'sqtwc-device-id' });
	var manual = el('input', { id: 'sqtwc-device-id-manual' });
	manual.setAttribute('hidden', 'hidden');
	var manualLabel = el('label', { class: 'sqtwc-payment__device-manual-label' });
	manualLabel.className = 'sqtwc-payment__device-manual-label';
	manualLabel.setAttribute('hidden', 'hidden');

	var start = el('button', { 'data-sqtwc-action': 'start' });
	start.setAttribute('id', 'sqtwc-start-payment');
	var cancel = el('button', { 'data-sqtwc-action': 'cancel' });
	cancel.setAttribute('id', 'sqtwc-cancel-payment');
	cancel.setAttribute('hidden', 'hidden');
	var check = el('button', { 'data-sqtwc-action': 'check' });
	check.setAttribute('id', 'sqtwc-check-status');
	var detach = el('button', { 'data-sqtwc-action': 'detach' });
	detach.setAttribute('id', 'sqtwc-detach-payment');
	detach.setAttribute('hidden', 'hidden');

	var status = el('div', { id: 'sqtwc-status' });

	var deviceField = el('div');
	deviceField.appendChild(select);
	deviceField.appendChild(manualLabel);
	deviceField.appendChild(manual);
	root.appendChild(deviceField);

	var actions = el('div');
	actions.appendChild(start);
	actions.appendChild(cancel);
	actions.appendChild(check);
	actions.appendChild(detach);
	root.appendChild(actions);
	root.appendChild(status);

	if (opts.debug) {
		var panel = el('div', { id: 'sqtwc-log-panel' });
		panel.setAttribute('hidden', 'hidden');
		var toggle = el('button', { 'data-sqtwc-action': 'log-toggle' });
		var body = el('div', { class: 'sqtwc-payment__log-body' });
		body.className = 'sqtwc-payment__log-body';
		body.setAttribute('hidden', 'hidden');
		var output = el('textarea', { id: 'sqtwc-log' });
		var copy = el('button', { 'data-sqtwc-action': 'log-copy' });
		var clear = el('button', { 'data-sqtwc-action': 'log-clear' });
		body.appendChild(output);
		body.appendChild(copy);
		body.appendChild(clear);
		panel.appendChild(toggle);
		panel.appendChild(body);
		root.appendChild(panel);
	}

	return {
		root: root,
		els: {
			select: select, manual: manual, manualLabel: manualLabel,
			start: start, cancel: cancel, check: check, detach: detach,
			status: status
		}
	};
}

// ---- Manual clock -------------------------------------------------------

function createClock() {
	var seq = 0;
	var timers = {};
	var current = 0;
	var delays = [];

	return {
		delays: delays,
		setTimeout: function (fn, delay) {
			var id = ++seq;
			delay = delay || 0;
			timers[id] = { fn: fn, at: current + delay, delay: delay };
			delays.push(delay);
			return id;
		},
		clearTimeout: function (id) { delete timers[id]; },
		now: function () { return current; },
		setNow: function (t) { current = t; },
		advance: function (t) { current += t; },
		pending: function () { return Object.keys(timers).length; },
		lastDelay: function () { return delays.length ? delays[delays.length - 1] : null; },
		// Run the earliest-scheduled timer, advancing the clock to its time.
		runNext: function () {
			var ids = Object.keys(timers);
			if (!ids.length) {
				return false;
			}
			ids.sort(function (a, b) { return timers[a].at - timers[b].at; });
			var id = ids[0];
			var t = timers[id];
			current = Math.max(current, t.at);
			delete timers[id];
			t.fn();
			return true;
		}
	};
}

// ---- Deferred fetch mock ------------------------------------------------

function createFetch() {
	var calls = [];
	var outstanding = [];

	function parseParams(body) {
		var out = {};
		String(body || '').split('&').forEach(function (pair) {
			if (!pair) { return; }
			var kv = pair.split('=');
			out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
		});
		return out;
	}

	var impl = function (url, options) {
		var params = parseParams(options && options.body);
		var record = { url: url, options: options, params: params, action: params.action };
		calls.push(record);
		var deferred = {};
		var promise = new Promise(function (resolve, reject) {
			deferred.resolve = resolve;
			deferred.reject = reject;
		});
		deferred.record = record;
		outstanding.push(deferred);
		return promise;
	};

	return {
		impl: impl,
		calls: calls,
		outstanding: outstanding,
		callCount: function () { return calls.length; },
		lastCall: function () { return calls[calls.length - 1]; },
		// Settle the earliest outstanding request with a JSON body.
		settle: function (body, httpOpts) {
			var d = outstanding.shift();
			if (!d) { throw new Error('no outstanding fetch to settle'); }
			httpOpts = httpOpts || {};
			var status = typeof httpOpts.httpStatus === 'number' ? httpOpts.httpStatus : 200;
			d.resolve({
				ok: status < 400,
				status: status,
				text: function () { return Promise.resolve(JSON.stringify(body)); }
			});
		},
		// Reject the earliest outstanding request (transport failure).
		fail: function () {
			var d = outstanding.shift();
			if (!d) { throw new Error('no outstanding fetch to fail'); }
			d.reject(new Error('network'));
		},
		outstandingCount: function () { return outstanding.length; }
	};
}

// ---- Storage + flush ----------------------------------------------------

function createStorage() {
	var data = {};
	return {
		getItem: function (k) { return Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null; },
		setItem: function (k, v) { data[k] = String(v); },
		removeItem: function (k) { delete data[k]; },
		_data: data
	};
}

async function flush(times) {
	times = times || 8;
	for (var i = 0; i < times; i++) {
		await Promise.resolve();
	}
}

// ---- Config -------------------------------------------------------------

function makeConfig(overrides) {
	var cfg = {
		ajaxUrl: 'https://store.test/wp-admin/admin-ajax.php',
		nonce: 'nonce123',
		actions: {
			create: 'sqtwc_create_terminal_checkout',
			cancel: 'sqtwc_cancel_terminal_checkout',
			status: 'sqtwc_get_terminal_status',
			detach: 'sqtwc_detach_terminal_checkout'
		},
		environment: 'sandbox',
		devices: [
			{ id: 'dev_success', label: 'Sandbox: Success' },
			{ id: 'dev_offline', label: 'Sandbox: Offline' }
		],
		defaultDeviceId: '',
		debugLog: false,
		poll: {
			cadenceMs: 2000,
			backoffStartMs: 2000,
			backoffCapMs: 15000,
			unstableAfter: 3,
			deadlineMs: 330000
		},
		strings: {
			statusChooseDevice: 'Choose a terminal to begin.',
			statusCreating: 'Sending payment to the terminal…',
			statusWaiting: 'Waiting for card…',
			statusInProgress: 'Payment in progress…',
			statusCancelling: 'Cancelling…',
			statusCancelled: 'Payment cancelled.',
			statusCompleted: 'Payment complete.',
			statusTimeout: 'Terminal did not respond in time.',
			statusCheckingNow: 'Checking payment status…',
			statusReleased: 'Payment released.',
			connectionUnstable: 'Connection unstable…',
			errorGeneric: 'Something went wrong.',
			errorNoDevice: 'Please choose a terminal first.',
			errorNetwork: 'Could not reach the store.',
			detachHint: 'Terminal not responding.',
			startPayment: 'Start Payment',
			retryPayment: 'Retry Payment',
			cancelPayment: 'Cancel Payment',
			checkStatus: 'Check Status',
			detachPayment: 'Release this payment',
			devicePlaceholder: 'Choose a terminal…',
			logShow: 'Show debug log',
			logHide: 'Hide debug log'
		}
	};
	if (overrides) {
		for (var k in overrides) {
			if (Object.prototype.hasOwnProperty.call(overrides, k)) {
				cfg[k] = overrides[k];
			}
		}
	}
	return cfg;
}

/**
 * Build a controller wired to fake deps, returning everything a test needs.
 */
function setup(payment, opts) {
	opts = opts || {};
	var dom = buildDom(opts.dom || {});
	var clock = createClock();
	var fetchMock = createFetch();
	var localStore = createStorage();
	var sessionStore = createStorage();
	var navigations = [];
	var beacons = [];

	var controller = payment.createController({
		root: dom.root,
		config: makeConfig(opts.config),
		doc: fakeDocument,
		fetch: fetchMock.impl,
		setTimeout: clock.setTimeout,
		clearTimeout: clock.clearTimeout,
		now: clock.now,
		localStorage: localStore,
		sessionStorage: sessionStore,
		navigate: function (url) { navigations.push(url); },
		sendBeacon: function (url, body) { beacons.push({ url: url, body: body }); return true; }
	});

	return {
		controller: controller,
		dom: dom,
		els: dom.els,
		clock: clock,
		fetch: fetchMock,
		localStore: localStore,
		sessionStore: sessionStore,
		navigations: navigations,
		beacons: beacons
	};
}

module.exports = {
	FakeElement: FakeElement,
	fakeDocument: fakeDocument,
	buildDom: buildDom,
	createClock: createClock,
	createFetch: createFetch,
	createStorage: createStorage,
	makeConfig: makeConfig,
	setup: setup,
	flush: flush
};
