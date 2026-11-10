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
const COMPONENT_ID = 'ff-lazy';
const CSRF_TOKEN = 'lazy-csrf';

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

class FakeIntersectionObserver {
    static instances = [];
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.observed = [];
        this.disconnected = false;
        FakeIntersectionObserver.instances.push(this);
    }
    observe(el) { this.observed.push(el); }
    unobserve(el) { this.observed = this.observed.filter((e) => e !== el); }
    disconnect() { this.disconnected = true; this.observed = []; }
    trigger(entries) { this.callback(entries, this); }
}

function bootLazy({ timers, fetch, document, withObserver = true }) {
    FakeIntersectionObserver.instances = [];
    const runtime = loadRuntime();
    const window = new FakeElement('window');

    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot({ loaded: false })));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);
    root.setAttribute('data-firefiber-lazy', 'visible');
    root.setAttribute('data-firefiber-lazy-action', 'firefiberLoad');
    root.innerHTML = '<p>Deferred panel loading…</p>';
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
        IntersectionObserver: withObserver ? FakeIntersectionObserver : undefined,
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    return { runtime, root, window };
}

beforeEach(() => { FakeMutationObserver.instances = []; });
afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') globalThis.Firefiber.stop();
    delete globalThis.Firefiber;
});

test('lazy components load via the built-in action when they become visible', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root } = bootLazy({ timers, fetch, document });

    const loaded = new Promise((resolve) => root.addEventListener('firefiber:loaded', (e) => resolve(e.detail)));
    assert.equal(FakeIntersectionObserver.instances.length, 1);
    assert.equal(FakeIntersectionObserver.instances[0].observed.length, 1);

    // No request until the observer reports visibility.
    assert.equal(fetch.calls.length, 0);
    FakeIntersectionObserver.instances[0].trigger([{ isIntersecting: true, intersectionRatio: 1 }]);
    await waitForCalls(fetch, 1);

    const body = JSON.parse(fetch.calls[0].options.body);
    assert.equal(body.action, 'firefiberLoad');
    assert.deepEqual(body.payload, {});

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberLoad',
        result: { loaded: true }, loaded: true,
        snapshot: makeSnapshot({ loaded: true }, 'loaded'),
        html: '<p>Panel content ready</p>',
    }));
    const detail = await loaded;
    assert.equal(detail.ok, true);
    assert.equal(FakeIntersectionObserver.instances[0].disconnected, true);
});

test('lazy loading stops permanently on a 404 response', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root } = bootLazy({ timers, fetch, document, withObserver: false });

    const loaderror = new Promise((resolve) => root.addEventListener('firefiber:loaderror', (e) => resolve(e.detail)));

    // Without an observer the runtime attempts an idle load.
    timers.advance(0);
    await waitForCalls(fetch, 1);

    fetch.release(0, responseFor({ status: 'error', error: 'no', errorCode: 6 }, 404));
    await loaderror;
    await tick();

    // 404 means the component does not support lazy loading: no retries.
    timers.advance(10000);
    await tick();
    assert.equal(fetch.calls.length, 1);
});

test('lazy loading retries transient failures with backoff and succeeds', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root } = bootLazy({ timers, fetch, document, withObserver: false });

    timers.advance(0);
    await waitForCalls(fetch, 1);
    fetch.release(0, responseFor({ status: 'error', error: 'boom', errorCode: 4 }, 500));
    await tick();
    await tick();

    // Attempt 1 failed → backoff 500ms.
    timers.advance(499);
    await tick();
    assert.equal(fetch.calls.length, 1);
    timers.advance(1);
    await waitForCalls(fetch, 2);

    fetch.release(1, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberLoad',
        result: { loaded: true }, loaded: true,
        snapshot: makeSnapshot({ loaded: true }, 'loaded'),
        html: '<p>ready</p>',
    }));
    await new Promise((resolve) => root.addEventListener('firefiber:loaded', resolve));
    assert.equal(fetch.calls.length, 2);
});

test('Firefiber.loadLazy() manually triggers a lazy load', async () => {
    const timers = manualTimers();
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { runtime } = bootLazy({ timers, fetch, document });

    // Not intersecting yet — manual trigger forces the load.
    const promise = runtime.loadLazy(COMPONENT_ID);
    await waitForCalls(fetch, 1);

    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberLoad',
        result: { loaded: true }, loaded: true,
        snapshot: makeSnapshot({ loaded: true }, 'loaded'),
        html: '<p>ready</p>',
    }));

    const detail = await promise;
    await tick();
    assert.equal(detail.ok, true);
    assert.equal(detail.data.loaded, true);
});
