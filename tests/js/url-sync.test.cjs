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
const COMPONENT_ID = 'ff-query';
const CSRF_TOKEN = 'query-csrf';
const HMAC_KEY = 'query-test-key';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function makeSnapshot(componentId, state, signature = 'sig') {
    return {
        componentId,
        state: { ...state },
        timestamp: Math.floor(Date.now() / 1000),
        protocolVersion: '3.1',
        signature,
    };
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

        return new Promise((resolve) => {
            pending.push({ url, options, resolve, released: false });
        });
    };

    fetch.calls = calls;
    fetch.pending = pending;
    fetch.release = (index, response) => {
        pending[index].released = true;
        pending[index].resolve(response);
    };
    fetch.releaseOthers = (exceptIndex, response) => {
        for (let index = 0; index < pending.length; index++) {
            if (index !== exceptIndex && !pending[index].released) {
                pending[index].released = true;
                pending[index].resolve(response);
            }
        }
    };

    return fetch;
}

async function waitForActionCall(fetch, action, timeoutMs = 1500) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        for (let index = 0; index < fetch.calls.length; index++) {
            try {
                const body = JSON.parse(fetch.calls[index].options.body);
                if (body.action === action) {
                    return { index, call: fetch.calls[index], body };
                }
            } catch (error) {
                // Ignore non-action requests (e.g. SPA navigation GETs).
            }
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    throw new Error(`Timed out waiting for action ${action} (saw ${fetch.calls.length} calls)`);
}

function manualTimers() {
    let nextId = 1;
    const timers = new Map();

    return {
        setTimeout(fn, delay) {
            const id = nextId++;
            timers.set(id, { fn, at: Math.max(0, Number(delay) || 0) });
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        advance(ms) {
            for (const timer of timers.values()) {
                timer.at -= ms;
            }

            let fired = true;

            while (fired) {
                fired = false;
                let bestId = null;
                let bestAt = Infinity;

                for (const [id, timer] of timers) {
                    if (timer.at <= 0 && timer.at <= bestAt) {
                        bestAt = timer.at;
                        bestId = id;
                    }
                }

                if (bestId !== null) {
                    const timer = timers.get(bestId);
                    timers.delete(bestId);
                    timer.fn();
                    fired = true;
                }
            }
        },
        pendingCount() {
            return timers.size;
        },
    };
}

function componentRoot(document, querySync, initialState = { page: 1, q: '' }) {
    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot(COMPONENT_ID, initialState)));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);
    root.setAttribute('data-firefiber-query-sync', JSON.stringify(querySync));
    return root;
}

async function waitForCalls(fetch, count, timeoutMs = 1500) {
    const deadline = Date.now() + timeoutMs;

    while (fetch.calls.length < count) {
        if (Date.now() > deadline) {
            throw new Error(`Timed out waiting for ${count} fetch calls (saw ${fetch.calls.length})`);
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    FakeMutationObserver.instances = [];
});

afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') {
        globalThis.Firefiber.stop();
    }

    delete globalThis.Firefiber;
});

test('URL sync writes state on interaction and settles one history entry per burst', async () => {
    const runtime = loadRuntime();
    const timers = manualTimers();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const location = {
        href: 'https://firefiber.test/table',
        origin: 'https://firefiber.test',
        pathname: '/table',
        search: '',
        hash: '',
    };
    const history = {
        replaces: [],
        pushes: [],
        replaceState(_state, _title, url) {
            this.replaces.push(url);
            this.sync(url);
        },
        pushState(_state, _title, url) {
            this.pushes.push(url);
            this.sync(url);
        },
        sync(url) {
            location.href = url;
            const q = String(url).indexOf('?');
            const h = String(url).indexOf('#');
            const end = h === -1 ? String(url).length : h;
            location.search = q === -1 ? '' : String(url).slice(q, end);
        },
    };
    const fetch = deferredFetch();

    const root = componentRoot(document, {
        page: { param: 'page', type: 'int', multiple: false },
        q: { param: 'q', type: 'string', multiple: false },
    });
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'bump');
    root.append(button);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window,
        location,
        history,
        fetch,
        setTimeout: timers.setTimeout.bind(timers),
        clearTimeout: timers.clearTimeout.bind(timers),
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });
    await waitForCalls(fetch, 1);

    fetch.release(0, responseFor({
        status: 'ok',
        componentId: COMPONENT_ID,
        action: 'bump',
        result: {},
        snapshot: makeSnapshot(COMPONENT_ID, { page: 3, q: 'hello' }, 'fresh'),
    }));
    await tick();
    await tick();

    // The URL updates immediately without adding a history entry.
    assert.equal(history.replaces.length >= 1, true);
    assert.match(String(history.replaces[history.replaces.length - 1]), /[?&]page=3/);
    assert.match(String(history.replaces[history.replaces.length - 1]), /[?&]q=hello/);
    assert.equal(history.pushes.length, 0);
    assert.match(location.search, /page=3/);

    // After the interaction settles, one history entry is recorded.
    timers.advance(700);
    assert.equal(history.pushes.length, 1);
    assert.match(String(history.pushes[0]), /page=3/);
});

test('popstate sends URL values through the built-in query action and reconciles', async () => {
    const runtime = loadRuntime();
    const timers = manualTimers();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const location = {
        href: 'https://firefiber.test/table?page=3&q=hello',
        origin: 'https://firefiber.test',
        pathname: '/table',
        search: '?page=3&q=hello',
        hash: '',
    };
    const history = {
        replaces: [],
        pushes: [],
        replaceState(_state, _title, url) { this.replaces.push(url); this.sync(url); },
        pushState(_state, _title, url) { this.pushes.push(url); this.sync(url); },
        sync(url) {
            location.href = url;
            const q = String(url).indexOf('?');
            const h = String(url).indexOf('#');
            const end = h === -1 ? String(url).length : h;
            location.search = q === -1 ? '' : String(url).slice(q, end);
        },
    };
    const fetch = deferredFetch();

    const root = componentRoot(document, {
        page: { param: 'page', type: 'int', multiple: false },
        q: { param: 'q', type: 'string', multiple: false },
    }, { page: 3, q: 'hello' });
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'seed');
    root.append(button);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window,
        location,
        history,
        fetch,
        setTimeout: timers.setTimeout.bind(timers),
        clearTimeout: timers.clearTimeout.bind(timers),
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    // Seed the runtime's authoritative snapshot map.
    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });
    await waitForCalls(fetch, 1);
    fetch.release(0, responseFor({
        status: 'ok',
        componentId: COMPONENT_ID,
        action: 'seed',
        result: {},
        snapshot: makeSnapshot(COMPONENT_ID, { page: 3, q: 'hello' }, 'seed'),
    }));
    await tick();
    await tick();
    timers.advance(1000);
    await tick();

    // Simulate back navigation: the browser changed the URL, runtime must react.
    location.search = '?page=1&q=hello';
    location.href = 'https://firefiber.test/table?page=1&q=hello';
    window.dispatchEvent({ type: 'popstate' });

    // SPA navigation also listens for popstate; ignore its GET fetches.
    const { index, body } = await waitForActionCall(fetch, 'firefiberQuery');
    assert.deepEqual(body.payload, { page: '1' });
    fetch.releaseOthers(index, { ok: false, status: 404, headers: { 'Content-Type': 'text/html' }, text: async () => '' });

    fetch.release(index, responseFor({
        status: 'ok',
        componentId: COMPONENT_ID,
        action: 'firefiberQuery',
        result: { applied: ['page'], rejected: [] },
        snapshot: makeSnapshot(COMPONENT_ID, { page: 1, q: 'hello' }, 'after-pop'),
        html: '<section>page 1</section>',
    }));
    await tick();
    await tick();

    // The URL is reconciled with the server-accepted state.
    assert.match(location.search, /page=1/);
});

test('popstate sends null when a synchronized parameter disappears', async () => {
    const runtime = loadRuntime();
    const timers = manualTimers();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const location = {
        href: 'https://firefiber.test/table?page=3',
        origin: 'https://firefiber.test',
        pathname: '/table',
        search: '?page=3',
        hash: '',
    };
    const history = {
        replaces: [],
        pushes: [],
        replaceState(_state, _title, url) { this.replaces.push(url); this.sync(url); },
        pushState(_state, _title, url) { this.pushes.push(url); this.sync(url); },
        sync(url) {
            location.href = url;
            const q = String(url).indexOf('?');
            location.search = q === -1 ? '' : String(url).slice(q);
        },
    };
    const fetch = deferredFetch();

    const root = componentRoot(document, {
        page: { param: 'page', type: 'int', multiple: false },
    }, { page: 3 });
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'seed');
    root.append(button);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window,
        location,
        history,
        fetch,
        setTimeout: timers.setTimeout.bind(timers),
        clearTimeout: timers.clearTimeout.bind(timers),
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    button.dispatchEvent({ type: 'click', target: button, preventDefault() {}, stopPropagation() {} });
    await waitForCalls(fetch, 1);
    fetch.release(0, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'seed', result: {},
        snapshot: makeSnapshot(COMPONENT_ID, { page: 3 }, 'seed'),
    }));
    await tick();
    await tick();

    // The URL parameter was removed: the server must reset the key.
    location.search = '';
    location.href = 'https://firefiber.test/table';
    window.dispatchEvent({ type: 'popstate' });

    const { index, body } = await waitForActionCall(fetch, 'firefiberQuery');
    assert.deepEqual(body.payload, { page: null });
    fetch.releaseOthers(index, { ok: false, status: 404, headers: { 'Content-Type': 'text/html' }, text: async () => '' });

    fetch.release(index, responseFor({
        status: 'ok', componentId: COMPONENT_ID, action: 'firefiberQuery',
        result: { applied: ['page'], rejected: [] },
        snapshot: makeSnapshot(COMPONENT_ID, { page: 1 }, 'reset'),
    }));
    await tick();
    await tick();
});
