'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { FakeElement } = require('./helpers');

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
	root.appendChild(button);
	root.appendChild(status);
	return { root: root, button: button, status: status };
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
