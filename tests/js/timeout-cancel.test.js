'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { setup, flush } = require('./helpers');

async function enterPollingAtDeadline(ctx, device) {
	ctx.els.select.value = device || 'dev_offline';
	ctx.controller.start();
	await flush();
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'PENDING' } });
	await flush();
	// Force the wall-clock deadline to have passed.
	ctx.controller.state.deadlineAt = ctx.clock.now();
}

test('deadline issues ONE forced read before any cancel', async () => {
	const ctx = setup(payment);
	await enterPollingAtDeadline(ctx);

	// The tick that observes the deadline must fire the forced read.
	ctx.clock.runNext();
	await flush();

	const forced = ctx.fetch.lastCall();
	assert.equal(forced.action, 'sqtwc_get_terminal_status');
	assert.equal(forced.params.force, '1', 'deadline read must bypass the throttle');
	// No cancel has been issued yet — the read must resolve first.
	assert.equal(ctx.fetch.calls.filter((c) => c.action === 'sqtwc_cancel_terminal_checkout').length, 0);
});

test('forced read that is still non-final then triggers cancel (correct ordering)', async () => {
	const ctx = setup(payment);
	await enterPollingAtDeadline(ctx);

	ctx.clock.runNext();
	await flush();
	// Forced read still pending.
	ctx.fetch.settle({ status: 'PENDING', continue_polling: true });
	await flush();

	// Only now is cancel issued.
	assert.equal(ctx.fetch.lastCall().action, 'sqtwc_cancel_terminal_checkout');
	const order = ctx.fetch.calls.map((c) => c.action);
	const forcedIdx = order.lastIndexOf('sqtwc_get_terminal_status');
	const cancelIdx = order.lastIndexOf('sqtwc_cancel_terminal_checkout');
	assert.ok(forcedIdx < cancelIdx, 'forced read must precede cancel');
});

test('forced read that resolves COMPLETED at the boundary wins — no cancel', async () => {
	const ctx = setup(payment);
	await enterPollingAtDeadline(ctx);

	ctx.clock.runNext();
	await flush();
	// The payment completed right at the deadline boundary.
	ctx.fetch.settle({ status: 'COMPLETED', continue_polling: false });
	await flush();

	assert.equal(ctx.fetch.calls.filter((c) => c.action === 'sqtwc_cancel_terminal_checkout').length, 0);
	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
	assert.equal(ctx.els.status.textContent, 'Payment complete.');
});

test('timeout auto-cancel that stays stuck offers detach', async () => {
	const ctx = setup(payment);
	await enterPollingAtDeadline(ctx);

	ctx.clock.runNext();
	await flush();
	ctx.fetch.settle({ status: 'PENDING', continue_polling: true }); // forced read
	await flush();
	// The auto-cancel itself resolves still non-final (offline terminal).
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk', status: 'CANCEL_REQUESTED' } });
	await flush();

	assert.equal(ctx.controller.state.detachAvailable, true);
	assert.equal(ctx.els.detach.hasAttribute('hidden'), false);
});

test('deadline is only handled once', async () => {
	const ctx = setup(payment);
	await enterPollingAtDeadline(ctx);

	ctx.clock.runNext();
	await flush();
	assert.equal(ctx.controller.state.deadlineHandled, true);

	const statusReadsAfterDeadline = ctx.fetch.calls.filter((c) => c.action === 'sqtwc_get_terminal_status' && c.params.force === '1').length;
	assert.equal(statusReadsAfterDeadline, 1, 'exactly one forced read at the deadline');
});
