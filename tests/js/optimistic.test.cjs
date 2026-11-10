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
const COMPONENT_ID = 'ff-opt';
const CSRF_TOKEN = 'opt-csrf';

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
    fetch.releaseAction = (action, response) => {
        const entry = pending.find((p) => !p.released && JSON.parse(p.options.body).action === action);
        if (!entry) throw new Error(`No pending request for action ${action}`);
        entry.released = true;
        entry.resolve(response);
    };
    return fetch;
}

async function waitForCalls(fetch, count, timeoutMs = 1500) {
    const deadline = Date.now() + timeoutMs;
    while (fetch.calls.length < count) {
        if (Date.now() > deadline) throw new Error(`Timed out waiting for ${count} fetch calls (saw ${fetch.calls.length})`);
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function bootOptimistic(fetch, document) {
    const runtime = loadRuntime();
    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot({ active: false })));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);
    root.setAttribute('data-firefiber-optimistic', JSON.stringify({ toggle: { active: true } }));

    const value = document.createElement('span');
    value.setAttribute('data-firefiber-bind', 'active');
    value.textContent = 'false';
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'toggle');
    root.append(value, button);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window: new FakeElement('window'),
        location: { href: 'https://firefiber.test/', origin: 'https://firefiber.test', pathname: '/', search: '', hash: '' },
        history: { replaceState() {}, pushState() {} },
        fetch,
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    return { runtime, root, button, value };
}

beforeEach(() => { FakeMutationObserver.instances = []; });
afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') globalThis.Firefiber.stop();
    delete globalThis.Firefiber;
});

test('optimistic actions patch the DOM immediately and commit on server success', async () => {
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root, button, value } = bootOptimistic(fetch, document);

    // Action events are dispatched on the triggering element.
    const optimistic = new Promise((resolve) => button.addEventListener('firefiber:optimistic', (e) => resolve(e.detail)));
    const success = new Promise((resolve) => button.addEventListener('firefiber:success', (e) => resolve(e.detail)));

    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });

    // DOM-only: patched immediately, but the signed snapshot attribute stays authoritative.
    const opt = await optimistic;
    assert.equal(opt.action, 'toggle');
    assert.deepEqual(opt.patch, { active: true });
    assert.equal(value.textContent, 'true');
    const attrSnapshot = JSON.parse(root.getAttribute('data-firefiber-snapshot'));
    assert.equal(attrSnapshot.state.active, false);

    await waitForCalls(fetch, 1);
    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'toggle', result: {},
        snapshot: makeSnapshot({ active: true }, 'server'),
    }));
    const detail = await success;
    assert.equal(detail.ok, true);
    assert.equal(value.textContent, 'true');
});

test('optimistic actions roll back on server failure', async () => {
    const fetch = deferredFetch();
    const document = new FakeDocument();
    const { root, button, value } = bootOptimistic(fetch, document);

    const rollback = new Promise((resolve) => button.addEventListener('firefiber:rollback', (e) => resolve(e.detail)));
    const failure = new Promise((resolve) => button.addEventListener('firefiber:error', (e) => resolve(e.detail)));

    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });
    await waitForCalls(fetch, 1);
    assert.equal(value.textContent, 'true');

    fetch.release(0, responseFor({ status: 'error', error: 'denied', errorCode: 4 }, 403));
    const rb = await rollback;
    await failure;

    assert.equal(rb.action, 'toggle');
    assert.equal(value.textContent, 'false');
});

test('optimistic rollback restores state when the fetch rejects', async () => {
    const calls = [];
    const fetch = (url, options) => {
        calls.push({ url, options });
        return Promise.reject(new Error('offline'));
    };
    fetch.calls = calls;

    const document = new FakeDocument();
    const { root, button, value } = bootOptimistic(fetch, document);

    const rollback = new Promise((resolve) => button.addEventListener('firefiber:rollback', (e) => resolve(e.detail)));
    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });
    await rollback;
    await tick();

    assert.equal(value.textContent, 'false');
});
