'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { setup, flush } = require('./helpers');

test('resume from reload starts polling immediately without re-creating', async () => {
	const ctx = setup(payment, {
		dom: { resume: true, checkoutId: 'chk_open', deviceId: 'dev_success', status: 'IN_PROGRESS' }
	});

	// Enters polling on construction.
	assert.equal(ctx.controller.state.name, payment.STATES.POLLING);
	assert.equal(ctx.controller.state.checkoutId, 'chk_open');

	// The immediate resume tick issues a status read, never a create.
	ctx.clock.runNext();
	await flush();
	assert.equal(ctx.fetch.lastCall().action, 'sqtwc_get_terminal_status');
	assert.equal(ctx.fetch.calls.filter((c) => c.action === 'sqtwc_create_terminal_checkout').length, 0);

	// Cancel is visible because we resumed into an active attempt.
	assert.equal(ctx.els.cancel.hasAttribute('hidden'), false);
});

test('resume completes the order and redirects when the poll returns paid', async () => {
	const ctx = setup(payment, {
		dom: { resume: true, checkoutId: 'chk_open', deviceId: 'dev_success' }
	});

	ctx.clock.runNext();
	await flush();
	ctx.fetch.settle({ status: 'COMPLETED', continue_polling: false, redirect_url: '/order-received/99' });
	await flush();

	assert.deepEqual(ctx.navigations, ['/order-received/99']);
	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
});

test('a redirect_url on any status navigates rather than resubmitting', async () => {
	const ctx = setup(payment, {
		dom: { resume: true, checkoutId: 'chk_open' }
	});

	ctx.clock.runNext();
	await flush();
	ctx.fetch.settle({ status: 'IN_PROGRESS', continue_polling: true, redirect_url: '/already-paid' });
	await flush();

	assert.deepEqual(ctx.navigations, ['/already-paid']);
});

test('pagehide beacon best-effort cancels an active resumed attempt', async () => {
	const ctx = setup(payment, {
		dom: { resume: true, checkoutId: 'chk_open', deviceId: 'dev_success' }
	});

	ctx.controller.beaconCancel();
	assert.equal(ctx.beacons.length, 1);
	assert.ok(ctx.beacons[0].body.indexOf('action=sqtwc_cancel_terminal_checkout') !== -1);
	assert.ok(ctx.beacons[0].body.indexOf('checkout_id=chk_open') !== -1);
});

test('no beacon is sent when there is no active attempt', () => {
	const ctx = setup(payment);
	ctx.controller.beaconCancel();
	assert.equal(ctx.beacons.length, 0);
});
