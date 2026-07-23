'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { FakeElement, createClock, createStorage } = require('./helpers');

function posDom() {
	const root = new FakeElement('div');
	root.setAttribute('id', 'sqtwc-payment');
	root.setAttribute('data-order-id', '99');
	root.setAttribute('data-order-key', 'order-key');
	const button = new FakeElement('button');
	button.setAttribute('id', 'sqtwc-pos-open');
	button.setAttribute('data-sqtwc-action', 'pos-open');
	const status = new FakeElement('div');
	status.setAttribute('id', 'sqtwc-status');
	const panel = new FakeElement('div');
	panel.setAttribute('id', 'sqtwc-log-panel');
	panel.setAttribute('hidden', 'hidden');
	const toggle = new FakeElement('button');
	toggle.setAttribute('data-sqtwc-action', 'log-toggle');
	const body = new FakeElement('div');
	body.className = 'sqtwc-payment__log-body';
	body.setAttribute('hidden', 'hidden');
	const log = new FakeElement('textarea');
	log.setAttribute('id', 'sqtwc-log');
	body.appendChild(log);
	panel.appendChild(toggle);
	panel.appendChild(body);
	root.appendChild(button);
	root.appendChild(status);
	root.appendChild(panel);
	return { root: root, button: button, status: status, log: log };
}

function config() {
	return {
		collectionMethod: 'pos_app',
		posApplicationId: 'sq0idp-AbCdEf1234567890_-xyZA',
		posCallbackUrl: 'https://store.test/wp-json/sqtwc/v1/pos-callback',
		posLocationId: 'LOC',
		amount: 1234,
		currency: 'USD',
		orderId: 99,
		orderKey: 'order-key',
		note: 'Order #99',
		skipReceipt: true,
		environment: 'production',
		strings: {
			posOpening: 'Opening Square Point of Sale…',
			posHandoffFailed: 'Square Point of Sale did not open.',
			posUnsupported: 'Use a supported mobile device.',
			posProductionRequired: 'Production required.',
			posCanceled: 'Payment was canceled.',
			posNotLoggedIn: 'Sign in to Square.',
			posNoNetwork: 'No network.',
			posOffline: 'Offline payment needs verification.',
			posError: 'Payment was not completed: %s',
			posMissingConfig: 'Missing application ID or location.',
			posPartial: 'Partial payment recorded; order on hold.'
		}
	};
}

test('builds exact Android Square POS intent URL', () => {
	const cfg = config();
	const state = payment.buildPosState(cfg);
	assert.equal(state, '{"o":99,"k":"order-key"}');
	assert.equal(
		payment.buildAndroidPosUrl(Object.assign({}, cfg, { state: state, fallbackUrl: 'https://store.test/checkout/order-pay/99/' })),
		'intent:#Intent;action=com.squareup.pos.action.CHARGE;package=com.squareup;S.browser_fallback_url=https%3A%2F%2Fstore.test%2Fcheckout%2Forder-pay%2F99%2F;S.com.squareup.pos.WEB_CALLBACK_URI=https%3A%2F%2Fstore.test%2Fwp-json%2Fsqtwc%2Fv1%2Fpos-callback;S.com.squareup.pos.CLIENT_ID=sq0idp-AbCdEf1234567890_-xyZA;S.com.squareup.pos.API_VERSION=v2.0;i.com.squareup.pos.TOTAL_AMOUNT=1234;S.com.squareup.pos.CURRENCY_CODE=USD;S.com.squareup.pos.TENDER_TYPES=com.squareup.pos.TENDER_CARD;S.com.squareup.pos.LOCATION_ID=LOC;S.com.squareup.pos.REQUEST_METADATA=%7B%22o%22%3A99%2C%22k%22%3A%22order-key%22%7D;S.com.squareup.pos.NOTE=Order%20%2399;l.com.squareup.pos.AUTO_RETURN_TIMEOUT_MS=3200;end'
	);
});

test('android fallback defaults to the callback URL when no page URL is known', () => {
	const cfg = config();
	const url = payment.buildAndroidPosUrl(Object.assign({}, cfg, { state: payment.buildPosState(cfg) }));
	assert.ok(url.indexOf('S.browser_fallback_url=https%3A%2F%2Fstore.test%2Fwp-json%2Fsqtwc%2Fv1%2Fpos-callback') !== -1);
});

test('builds iOS payload with v1.3 card-only auto-return options', () => {
	const cfg = config();
	const url = payment.buildIosPosUrl(Object.assign({}, cfg, { state: payment.buildPosState(cfg) }));
	assert.equal(url.slice(0, 'square-commerce-v1://payment/create?data='.length), 'square-commerce-v1://payment/create?data=');
	const data = JSON.parse(decodeURIComponent(url.split('data=')[1]));
	assert.deepEqual(data.amount_money, { amount: 1234, currency_code: 'USD' });
	assert.equal(data.version, '1.3');
	assert.equal(data.state, '{"o":99,"k":"order-key"}');
	assert.deepEqual(data.options.supported_tender_types, ['CREDIT_CARD']);
	assert.equal(data.options.auto_return, true);
	assert.equal(data.options.skip_receipt, true);
});

test('detects Android and iOS while rejecting desktop', () => {
	assert.equal(payment.detectPosPlatform('Mozilla Android', 0), 'android');
	assert.equal(payment.detectPosPlatform('Mozilla iPhone', 0), 'ios');
	assert.equal(payment.detectPosPlatform('Mozilla Macintosh', 2), 'ios');
	assert.equal(payment.detectPosPlatform('Mozilla Windows', 0), 'unsupported');
});

test('error return shows localized reason and re-enables button', () => {
	const dom = posDom();
	payment.createController({ root: dom.root, config: config(), userAgent: 'Android', maxTouchPoints: 0, location: { search: '?sqtwc_pos_result=error&sqtwc_pos_code=no_network' }, navigate: function () {} });
	assert.equal(dom.status.textContent, 'No network.');
	assert.equal(dom.button.disabled, false);
});

test('partial return is terminal: button stays disabled', () => {
	const dom = posDom();
	payment.createController({ root: dom.root, config: config(), userAgent: 'Android', maxTouchPoints: 0, location: { search: '?sqtwc_pos_result=partial' }, navigate: function () {} });
	assert.equal(dom.status.textContent, 'Partial payment recorded; order on hold.');
	assert.equal(dom.button.disabled, true);
});

test('missing application ID disables handoff with config message', () => {
	const dom = posDom();
	const cfg = config();
	cfg.posApplicationId = '';
	payment.createController({ root: dom.root, config: cfg, userAgent: 'Android', maxTouchPoints: 0, location: { search: '' }, navigate: function () {} });
	assert.equal(dom.status.textContent, 'Missing application ID or location.');
	assert.equal(dom.button.disabled, true);
});

test('desktop shows unsupported message and disables handoff', () => {
	const dom = posDom();
	payment.createController({ root: dom.root, config: config(), userAgent: 'Windows', maxTouchPoints: 0, location: { search: '' }, navigate: function () {} });
	assert.equal(dom.status.textContent, 'Use a supported mobile device.');
	assert.equal(dom.button.disabled, true);
});

test('visible page reports a failed handoff after 2500ms and re-enables the button', () => {
	const dom = posDom();
	const clock = createClock();
	payment.createController({
		root: dom.root,
		config: config(),
		doc: { hidden: false, addEventListener: function () {} },
		userAgent: 'Android',
		location: { search: '', href: 'https://store.test/order-pay/99/' },
		navigate: function () {},
		setTimeout: clock.setTimeout,
		clearTimeout: clock.clearTimeout
	}).handleAction('pos-open');

	assert.equal(clock.lastDelay(), 2500);
	clock.runNext();
	assert.equal(dom.button.disabled, false);
	assert.equal(dom.status.textContent, 'Square Point of Sale did not open.');
	assert.match(dom.log.value, /\[ERROR\].*handoff failed/i);
});

test('handoff log redacts request metadata without changing the navigation URL', () => {
	['Android WebView', 'iPhone'].forEach(function (userAgent) {
		const dom = posDom();
		const navigations = [];
		const controller = payment.createController({
			root: dom.root,
			config: config(),
			doc: { hidden: false, addEventListener: function () {} },
			sessionStorage: createStorage(),
			userAgent: userAgent,
			location: { search: '?sqtwc_pos_result=error&sqtwc_pos_code=no_network', href: 'https://store.test/order-pay/99/' },
			navigate: function (url) { navigations.push(url); },
			setTimeout: function () { return 1; },
			clearTimeout: function () {}
		});

		controller.handleAction('pos-open');
		assert.match(dom.log.value, /POS platform:/);
		assert.match(dom.log.value, /sqtwc_pos_result=error.*sqtwc_pos_code=no_network/i);
		assert.match(dom.log.value, /\[redacted\]/);
		assert.doesNotMatch(dom.log.value, /order-key/);
		assert.match(navigations[0], /order-key/);
	});
});

test('watchdog is cancelled when the document hides or pagehide fires', () => {
	function handoff(cancelEvent) {
		const dom = posDom();
		const clock = createClock();
		const listeners = {};
		const page = { addEventListener: function (name, fn) { listeners[name] = fn; } };
		const doc = {
			hidden: false,
			defaultView: page,
			addEventListener: function (name, fn) { listeners[name] = fn; }
		};
		const controller = payment.createController({
			root: dom.root, config: config(), doc: doc, userAgent: 'Android',
			location: { search: '' }, navigate: function () {},
			setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout
		});
		controller.handleAction('pos-open');
		if (cancelEvent === 'visibilitychange') { doc.hidden = true; }
		listeners[cancelEvent]();
		return clock.pending();
	}

	assert.equal(handoff('visibilitychange'), 0);
	assert.equal(handoff('pagehide'), 0);
});

test('openExternalApp drives the top frame when the page is framed and same-origin', () => {
	const assigned = [];
	const top = { location: { href: 'https://store.test/pos', assign: (u) => assigned.push(u) } };
	const win = { top: top, location: { assign: () => { throw new Error('must not navigate the frame'); } } };
	win.self = win;

	assert.equal(payment.openExternalApp(win, null, 'intent:#Intent;end'), 'parent-frame');
	assert.deepEqual(assigned, ['intent:#Intent;end']);
});

test('openExternalApp escapes a cross-origin frame with a target=_top anchor', () => {
	const win = { get top() { throw new Error('cross-origin'); }, location: { assign: () => { throw new Error('must not navigate the frame'); } } };
	win.self = win;
	const created = [];
	const doc = {
		body: { appendChild: (el) => { created.push(el); return el; }, removeChild: () => {} },
		createElement: () => ({ click() { this.clicked = true; } })
	};

	assert.equal(payment.openExternalApp(win, doc, 'intent:#Intent;end'), 'anchor-top');
	assert.equal(created.length, 1);
	assert.equal(created[0].href, 'intent:#Intent;end');
	assert.equal(created[0].target, '_top');
	assert.equal(created[0].clicked, true);
});

test('openExternalApp navigates directly when the page is not framed', () => {
	const assigned = [];
	const win = { location: { assign: (u) => assigned.push(u) } };
	win.top = win;
	win.self = win;

	assert.equal(payment.openExternalApp(win, null, 'square-commerce-v1://payment/create'), 'top-frame');
	assert.deepEqual(assigned, ['square-commerce-v1://payment/create']);
});

test('isFramed treats an unreachable parent as framed', () => {
	assert.equal(payment.isFramed({ self: {}, get top() { throw new Error('cross-origin'); } }), true);
	const same = {};
	assert.equal(payment.isFramed({ self: same, top: same }), false);
});

test('the order-pay submit is hidden while Square is selected and restored when it is not', () => {
	const dom = posDom();
	const submit = { style: { display: '' } };
	const radio = { name: 'payment_method', value: 'sqtwc', checked: true };
	let changeHandler = null;
	const doc = {
		hidden: false,
		addEventListener: (name, fn) => { if (name === 'change') { changeHandler = fn; } },
		querySelector: (sel) => {
			if (sel === '#place_order') { return submit; }
			if (sel === 'input[name="payment_method"]:checked') { return radio.checked ? radio : null; }
			if (sel === 'input[name="payment_method"]') { return radio; }
			return null;
		}
	};
	const cfg = config();
	cfg.gatewayId = 'sqtwc';
	payment.createController({
		root: dom.root, config: cfg, doc: doc, userAgent: 'Android',
		location: { search: '', href: 'https://store.test/order-pay/99/' },
		navigate: function () {}, setTimeout: function () { return 1; }, clearTimeout: function () {}
	});

	assert.equal(submit.style.display, 'none');

	radio.checked = false;
	changeHandler({ target: { name: 'payment_method' } });
	assert.equal(submit.style.display, '');
});
