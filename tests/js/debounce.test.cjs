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
const COMPONENT_ID = 'ff-debounce';
const CSRF_TOKEN = 'debounce-csrf';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;
    return require(runtimePath);
}

function responseFor(payload, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { 'Content-Type': 'application/json' },
        text: async () => JSON.stringify(payload),
    };
}

function deferredFetch() {
    const calls = [];
    const pending = [];
    const fetch = (url, options) => {
        calls.push({ url, options });
        return new Promise((resolve) => pending.push({ url, options, resolve, released: false }));
    };
    fetch.calls = calls;
    fetch.pending = pending;
    fetch.release = (index, response) => { pending[index].released = true; pending[index].resolve(response); };
    return fetch;
}

function manualTimers() {
    let nextId = 1;
    const timers = new Map();
    return {
        setTimeout(fn, delay) { const id = nextId++; timers.set(id, { fn, at: Math.max(0, Number(delay) || 0) }); return id; },
        clearTimeout(id) { timers.delete(id); },
        advance(ms) {
            for (const timer of timers.values()) timer.at -= ms;
            let fired = true;
            while (fired) {
                fired = false;
                let bestId = null, bestAt = Infinity;
                for (const [id, timer] of timers) {
                    if (timer.at <= 0 && timer.at <= bestAt) { bestAt = timer.at; bestId = id; }
                }
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

beforeEach(() => { FakeMutationObserver.instances = []; });
afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') globalThis.Firefiber.stop();
    delete globalThis.Firefiber;
});

test('fiber:model debounce coalesces rapid input into one server round trip', async () => {
    const runtime = loadRuntime();
    const timers = manualTimers();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const fetch = deferredFetch();

    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify({
        componentId: COMPONENT_ID, state: { search: '' }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature: 'sig',
    }));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    const input = document.createElement('input');
    input.setAttribute('fiber:model', 'search');
    input.setAttribute('data-firefiber-debounce', '300');
    input.value = '';
    root.append(input);
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

    // Rapid edits: each one should cancel the previous timer.
    input.value = 'h';
    input.dispatchEvent({ type: 'input', target: input });
    timers.advance(100);
    input.value = 'he';
    input.dispatchEvent({ type: 'input', target: input });
    timers.advance(100);
    input.value = 'hel';
    input.dispatchEvent({ type: 'input', target: input });

    // Still inside the debounce window: no request yet.
    assert.equal(fetch.calls.length, 0);

    timers.advance(300);
    await waitForCalls(fetch, 1);

    // Exactly one coalesced request carrying the final value.
    assert.equal(fetch.calls.length, 1);
    const body = JSON.parse(fetch.calls[0].options.body);
    assert.equal(body.action, 'updateModel');
    assert.equal(body.payload.search, 'hel');

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'updateModel',
        result: { updated: ['search'] },
        snapshot: { componentId: COMPONENT_ID, state: { search: 'hel' }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature: 'fresh' },
    }));
    await tick();
});

test('fiber:model without debounce dispatches immediately', async () => {
    const runtime = loadRuntime();
    const timers = manualTimers();
    const document = new FakeDocument();
    const fetch = deferredFetch();

    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify({
        componentId: COMPONENT_ID, state: { name: '' }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature: 'sig',
    }));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    const input = document.createElement('input');
    input.setAttribute('fiber:model', 'name');
    input.value = 'A';
    root.append(input);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window: new FakeElement('window'),
        location: { href: 'https://firefiber.test/', origin: 'https://firefiber.test', pathname: '/', search: '', hash: '' },
        history: { replaceState() {}, pushState() {} },
        fetch,
        setTimeout: timers.setTimeout.bind(timers),
        clearTimeout: timers.clearTimeout.bind(timers),
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    input.dispatchEvent({ type: 'input', target: input });
    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1);

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'updateModel', result: {},
        snapshot: { componentId: COMPONENT_ID, state: { name: 'A' }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature: 'fresh' },
    }));
    await tick();
});
