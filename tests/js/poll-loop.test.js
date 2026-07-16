'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const payment = require('../../assets/js/payment.js');
const { setup, flush } = require('./helpers');

async function startAndEnterPolling(ctx) {
	ctx.els.select.value = 'dev_success';
	ctx.controller.start();
	await flush();
	// create -> PENDING
	ctx.fetch.settle({ status: 200, checkout: { id: 'chk_1', status: 'PENDING' } });
	await flush();
	assert.equal(ctx.controller.state.name, payment.STATES.POLLING);
}

test('healthy poll uses the 2s cadence', async () => {
	const ctx = setup(payment);
	await startAndEnterPolling(ctx);

	// Immediate first tick.
	ctx.clock.runNext();
	await flush();
	assert.equal(ctx.fetch.lastCall().action, 'sqtwc_get_terminal_status');
	ctx.fetch.settle({ status: 'PENDING', continue_polling: true });
	await flush();

	// Next tick scheduled at the healthy cadence.
	assert.equal(ctx.clock.lastDelay(), 2000);
});

test('transport errors back off 2->4->8->15 (capped) and flag unstable', async () => {
	const ctx = setup(payment);
	await startAndEnterPolling(ctx);

	const observed = [];
	// Run the immediate tick, then fail repeatedly and record the backoff.
	for (let i = 0; i < 5; i++) {
		ctx.clock.runNext();
		await flush();
		ctx.fetch.fail();
		await flush();
		observed.push(ctx.clock.lastDelay());
	}

	assert.deepEqual(observed, [2000, 4000, 8000, 15000, 15000]);
	// After 3 consecutive failures the cashier sees the unstable message.
	assert.equal(ctx.els.status.textContent, 'Connection unstable…');
});

test('a recovered response resets the backoff to cadence', async () => {
	const ctx = setup(payment);
	await startAndEnterPolling(ctx);

	ctx.clock.runNext();
	await flush();
	ctx.fetch.fail();
	await flush();
	assert.equal(ctx.clock.lastDelay(), 2000);

	ctx.clock.runNext();
	await flush();
	ctx.fetch.fail();
	await flush();
	assert.equal(ctx.clock.lastDelay(), 4000);

	// Recover.
	ctx.clock.runNext();
	await flush();
	ctx.fetch.settle({ status: 'IN_PROGRESS', continue_polling: true });
	await flush();
	assert.equal(ctx.clock.lastDelay(), 2000);
});

test('stale responses from a superseded session are dropped', async () => {
	const ctx = setup(payment);
	await startAndEnterPolling(ctx);

	ctx.clock.runNext();
	await flush();
	// A newer session supersedes this one before the response lands.
	ctx.controller.state.pollSeq += 1;

	// The late response claims COMPLETED, which would normally finalize/redirect.
	ctx.fetch.settle({ status: 'COMPLETED', continue_polling: false, redirect_url: '/thanks' });
	await flush();

	assert.equal(ctx.controller.state.name, payment.STATES.POLLING, 'state must not change');
	assert.equal(ctx.navigations.length, 0, 'stale COMPLETED must not redirect');
});

test('stop polling on continue_polling:false', async () => {
	const ctx = setup(payment);
	await startAndEnterPolling(ctx);

	ctx.clock.runNext();
	await flush();
	const pendingBefore = ctx.clock.pending();
	ctx.fetch.settle({ status: 'CANCELED', continue_polling: false });
	await flush();

	assert.equal(ctx.controller.state.name, payment.STATES.FINAL);
	// No further tick should be scheduled.
	assert.ok(ctx.clock.pending() <= pendingBefore - 1 || ctx.clock.pending() === 0);
});
