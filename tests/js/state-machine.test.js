'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { setup, flush } = require('./helpers');

function hidden(elm) {
	return elm.hasAttribute('hidden');
}

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

test('retained checkoutless create failure exposes release', async () => {
	const ctx = setup(payment);
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({
		status: 502,
		cashier_message: 'Create unconfirmed.',
		detach_available: true,
		attempt_id: 'att_open',
		device_id: 'dev_success'
	});
	await flush();

	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
	assert.equal(ctx.controller.state.detachAvailable, true);
	assert.equal(hidden(ctx.els.detach), false);

	ctx.controller.detach();
	await flush();
	assert.equal(ctx.fetch.lastCall().action, 'sqtwc_detach_terminal_checkout');
	assert.equal(Object.hasOwn(ctx.fetch.lastCall().params, 'checkout_id'), false);
	assert.equal(ctx.fetch.lastCall().params.attempt_id, 'att_open');
	assert.equal(ctx.fetch.lastCall().params.device_id, 'dev_success');
});

test('checkoutless reload exposes release without polling', async () => {
	const ctx = setup(payment, {
		dom: { resume: true, checkoutId: '', attemptId: 'att_open', deviceId: 'dev_success' }
	});

	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
	assert.equal(ctx.controller.state.detachAvailable, true);
	assert.equal(hidden(ctx.els.detach), false);
	assert.equal(ctx.fetch.callCount(), 0);

	ctx.controller.detach();
	await flush();
	assert.equal(ctx.fetch.lastCall().params.attempt_id, 'att_open');
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
