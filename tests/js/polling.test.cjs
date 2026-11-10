'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeElement,
    FakeMutationObserver,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');
const COMPONENT_ID = 'ff-poll';
const CSRF_TOKEN = 'poll-csrf';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;
    return require(runtimePath);
}

function makeSnapshot(state, signature = 'sig') {
    return { componentId: COMPONENT_ID, state: { ...state }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature };
}

function responseFor(payload, status = 200) {
    return { ok: status >= 200 && status < 300, status, headers: { 'Content-Type': 'application/json' }, text: async () => JSON.stringify(payload) };
}

function deferredFetch() {
    const calls = [];
    const pending = [];
    const fetch = (url, options) => {
        calls.push({ url, options });
        return new Promise((resolve) => pending.push({ url, options, resolve }));
    };
    fetch.calls = calls;
    fetch.pending = pending;
    fetch.release = (index, response) => pending[index].resolve(response);
    return fetch;
}

function manualTimers() {
    let nextId = 1;
    const timers = new Map();
    return {
        setTimeout(fn, delay) { const id = nextId++; timers.set(id, { fn, at: Math.max(0, Number(delay) || 0) }); return id; },
        clearTimeout(id) { timers.delete(id); },
        advance(ms) {
            for (const t of timers.values()) t.at -= ms;
            let fired = true;
            while (fired) {
                fired = false; let bestId = null, bestAt = Infinity;
                for (const [id, t] of timers) if (t.at <= 0 && t.at <= bestAt) { bestAt = t.at; bestId = id; }
                if (bestId !== null) { const t = timers.get(bestId); timers.delete(bestId); t.fn(); fired = true; }
            }
        },
        pendingCount() { return timers.size; },
    };
}

async function waitForCalls(fetch, count, timeoutMs = 1500) {
    const deadline = Date.now() + timeoutMs;
    while (fetch.calls.length < count) {
        if (Date.now() > deadline) throw new Error(`Timed out waiting for ${count} fetch calls (saw ${fetch.calls.length})`);
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function bootPoller({ timers, fetch, document, pausedHidden = '1', ticks = 0 }) {
    const runtime = loadRuntime();
    const window = new FakeElement('window');
    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot({ ticks })));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);
    root.setAttribute('data-firefiber-poll', '1000');
    root.setAttribute('data-firefiber-poll-paused-hidden', pausedHidden);
    root.innerHTML = '<span data-firefiber-bind="ticks">0</span>';
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window,
        location: { href: 'https://firefiber.test/', origin: 'https://firefiber.test', pathname: '/', search: '', hash: '' },
        history: { replaceState() {}, pushState() {} },
        fetch,
        setTimeout: timers.setTimeout.bind(timers),
        clearTimeout: timers.clearTimeout.bind(timers),
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    return { runtime, root, window, document };
}

beforeEach(() => { FakeMutationObserver.instances = []; });
afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') globalThis.Firefiber.stop();
    delete globalThis.Firefiber;
});

test('polling dispatches silently at the configured interval and stops when the server says so', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root } = bootPoller({ timers, fetch, document });

    const polls = [];
    root.addEventListener('firefiber:poll', (e) => polls.push(e.detail));
    const stopped = new Promise((resolve) => root.addEventListener('firefiber:pollstopped', (e) => resolve(e.detail)));

    assert.equal(fetch.calls.length, 0);
    timers.advance(1000);
    await waitForCalls(fetch, 1);

    const firstBody = JSON.parse(fetch.calls[0].options.body);
    assert.equal(firstBody.action, 'firefiberPoll');
    assert.deepEqual(firstBody.payload, {});

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: true,
        snapshot: makeSnapshot({ ticks: 1 }, 's1'),
        html: '<span data-firefiber-bind="ticks">1</span>',
    }));
    await tick();
    await tick();
    assert.equal(polls.length, 1);

    timers.advance(1000);
    await waitForCalls(fetch, 2);
    fetch.release(1, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: false,
        snapshot: makeSnapshot({ ticks: 2 }, 's2'),
        html: '<span data-firefiber-bind="ticks">2</span>',
    }));
    await stopped;
    await tick();

    // After the server stops polling, no further requests are scheduled.
    timers.advance(5000);
    await tick();
    assert.equal(fetch.calls.length, 2);
});

test('polling backs off on errors and recovers on the next success', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root } = bootPoller({ timers, fetch, document });

    const errors = [];
    root.addEventListener('firefiber:pollerror', (e) => errors.push(e.detail));

    timers.advance(1000);
    await waitForCalls(fetch, 1);
    fetch.release(0, responseFor({ status: 'error', error: 'boom', errorCode: 4 }, 500));
    await tick();
    await tick();
    assert.equal(errors.length, 1);

    // First failure backs off to 2000ms (interval * 2^1).
    timers.advance(1999);
    await tick();
    assert.equal(fetch.calls.length, 1);
    timers.advance(1);
    await waitForCalls(fetch, 2);

    fetch.release(1, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: true,
        snapshot: makeSnapshot({ ticks: 1 }, 's1'),
    }));
    await tick();
    await tick();

    // A success resets the backoff to the normal interval.
    timers.advance(1000);
    await waitForCalls(fetch, 3);
    assert.equal(fetch.calls.length, 3);

    fetch.release(2, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: false,
        snapshot: makeSnapshot({ ticks: 2 }, 's2'),
    }));
    await tick();
});

test('polling suspends while the document is hidden and resumes when visible', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    bootPoller({ timers, fetch, document, pausedHidden: '1' });

    // Hide before the first tick: no request should fire.
    document.setVisibility(true);
    timers.advance(5000);
    await tick();
    assert.equal(fetch.calls.length, 0);

    // Becoming visible schedules an immediate poll (delay 0).
    document.setVisibility(false);
    timers.advance(0);
    await waitForCalls(fetch, 1);

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: false,
        snapshot: makeSnapshot({ ticks: 1 }, 's1'),
    }));
    await tick();
});

test('polling does not suspend when paused-when-hidden is disabled', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    document.hidden = true;
    bootPoller({ timers, fetch, document, pausedHidden: '0' });

    timers.advance(1000);
    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1);

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberPoll',
        result: { polled: true }, polling: false,
        snapshot: makeSnapshot({ ticks: 1 }, 's1'),
    }));
    await tick();
});
