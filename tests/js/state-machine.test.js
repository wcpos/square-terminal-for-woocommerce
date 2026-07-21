'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const payment = require('../../assets/js/payment.js');
const { FakeElement, setup, flush } = require('./helpers');

function hidden(elm) {
	return elm.hasAttribute('hidden');
}

test('admin actions send the current environment, token, and location', async () => {
	const elements = {
		'sqtwc-create-device-code': new FakeElement('button'),
		'sqtwc-admin-status': new FakeElement('div'),
		'woocommerce_sqtwc_environment': new FakeElement('select'),
		'woocommerce_sqtwc_sandbox_access_token': new FakeElement('input'),
		'woocommerce_sqtwc_production_access_token': new FakeElement('input'),
		'woocommerce_sqtwc_location_id': new FakeElement('input')
	};
	elements.woocommerce_sqtwc_environment.value = 'production';
	elements.woocommerce_sqtwc_sandbox_access_token.value = 'sandbox-token';
	elements.woocommerce_sqtwc_production_access_token.value = 'current-token';
	elements.woocommerce_sqtwc_location_id.value = 'NEW';
	let request;
	const context = {
		window: {
			sqtwcAdmin: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'nonce',
				strings: {}
			}
		},
		document: {
			readyState: 'complete',
			getElementById: function (id) { return elements[id] || null; }
		},
		fetch: function (url, options) {
			request = { url: url, options: options };
			return Promise.resolve({
				ok: true,
				json: function () { return Promise.resolve({ success: true, code: 'PAIR-ME' }); }
			});
		},
		Promise: Promise,
		String: String,
		encodeURIComponent: encodeURIComponent
	};
	const script = fs.readFileSync(path.join(__dirname, '../../assets/js/admin.js'), 'utf8');
	vm.runInNewContext(script, context);

	elements['sqtwc-create-device-code'].listeners.click[0]();
	await flush();
	const body = new URLSearchParams(request.options.body);

	assert.equal(body.get('environment'), 'production');
	assert.equal(body.get('access_token'), 'current-token');
	assert.equal(body.get('location_id'), 'NEW');
});

test('idle: start enabled, cancel hidden, check enabled, device unlocked', () => {
	const ctx = setup(payment);
	const e = ctx.els;
	assert.equal(e.start.disabled, false);
	assert.equal(hidden(e.cancel), true);
	assert.equal(e.check.disabled, false);
	assert.equal(e.select.disabled, false);
});

test('creating: start disabled, cancel hidden, check disabled, device locked', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();

	const e = ctx.els;
	assert.equal(ctx.controller.state.name, payment.STATES.CREATING);
	assert.equal(e.start.disabled, true);
	assert.equal(hidden(e.cancel), true);
	assert.equal(e.check.disabled, true);
	assert.equal(e.select.disabled, true);
});

test('polling: start disabled, cancel visible+enabled, check enabled, device locked', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();

	const e = ctx.els;
	assert.equal(ctx.controller.state.name, payment.STATES.POLLING);
	assert.equal(e.start.disabled, true);
	assert.equal(hidden(e.cancel), false);
	assert.equal(e.cancel.disabled, false);
	assert.equal(e.check.disabled, false);
	assert.equal(e.select.disabled, true);
});

test('cancel in flight: all controls disabled, cancel disabled', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();

	ctx.controller.cancel();
	await flush();

	const e = ctx.els;
	assert.equal(ctx.controller.state.name, payment.STATES.CANCELLING);
	assert.equal(e.start.disabled, true);
	assert.equal(e.cancel.disabled, true);
	assert.equal(e.check.disabled, true);
});

test('final (completed): start becomes retry+enabled, cancel hidden, device unlocked', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();
	ctx.clock.runNext();
	await flush();
	ctx.fetch.settle({ status: 'COMPLETED', continue_polling: false });
	await flush();

	const e = ctx.els;
	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
	assert.equal(e.start.disabled, false);
	assert.equal(e.start.textContent, 'Retry Payment');
	assert.equal(hidden(e.cancel), true);
	assert.equal(e.select.disabled, false);
});

test('start is refused without a selected device', () => {
	const ctx = setup(payment);
	ctx.els.select.value = '';
	ctx.controller.start();
	assert.equal(ctx.fetch.callCount(), 0);
	assert.equal(ctx.els.status.textContent, 'Please choose a terminal first.');
});

test('every mutation disables its trigger while in flight (no duplicate create)', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	// A second start while creating must be a no-op (button-state is the guard client-side).
	ctx.controller.start();
	await flush();
	assert.equal(ctx.fetch.calls.filter((c) => c.action === 'sqtwc_create_terminal_checkout').length, 1);
});

test('cancel that resolves COMPLETED redirects instead of showing cancelled', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();

	ctx.controller.cancel();
	await flush();
	// The payment completed during cancellation: a payment, not a cancel.
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'COMPLETED' }, redirect_url: '/thank-you' });
	await flush();

	assert.deepEqual(ctx.navigations, ['/thank-you']);
	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
});

test('cancel that stays non-final keeps polling and reveals detach', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_offline';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();

	ctx.controller.cancel();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'CANCEL_REQUESTED' } });
	await flush();

	assert.equal(ctx.controller.state.name, payment.STATES.POLLING, 'keeps watching after a cancel request');
	assert.equal(ctx.controller.state.detachAvailable, true);
	assert.equal(hidden(ctx.els.detach), false);
});

test('detach releases the payment and returns to idle', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_offline';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();
	ctx.controller.cancel();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'CANCEL_REQUESTED' } });
	await flush();

	ctx.controller.detach();
	await flush();
	assert.equal(ctx.fetch.lastCall().action, 'sqtwc_detach_terminal_checkout');
	ctx.fetch.settle({ status: 200 });
	await flush();

	assert.equal(ctx.controller.state.name, payment.STATES.IDLE);
	assert.equal(hidden(ctx.els.detach), true);
	assert.equal(ctx.els.status.textContent, 'Payment released.');
});
