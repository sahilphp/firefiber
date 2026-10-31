'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeElement,
    FakeMutationObserver,
    waitForEvent,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');
const COMPONENT_ID = 'ff-keydown';
const CSRF_TOKEN = 'keydown-csrf';
const HMAC_KEY = 'phase-4.10-keydown-test-key';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function makeSnapshot(componentId = COMPONENT_ID, signature = 'signed-snapshot') {
    return {
        componentId,
        state: { count: 1 },
        timestamp: Math.floor(Date.now() / 1000),
        protocolVersion: '3.1',
        signature,
    };
}

function successPayload(componentId = COMPONENT_ID) {
    return {
        status: 'ok',
        componentId,
        action: 'saveSearch',
        result: { count: 2 },
        snapshot: makeSnapshot(componentId, 'fresh-snapshot'),
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

function captureFetch(handler) {
    const calls = [];
    const fetch = (url, options) => {
        calls.push({ url, options });

        return Promise.resolve(handler(calls.length, url, options));
    };
    fetch.calls = calls;

    return fetch;
}

function deferredFetch(factory) {
    const calls = [];
    const pending = [];
    const fetch = (url, options) => {
        calls.push({ url, options });

        return new Promise((resolve, reject) => {
            pending.push({ url, options, resolve, reject });
        });
    };

    fetch.calls = calls;
    fetch.pending = pending;
    fetch.releaseAction = (action, response) => {
        const entry = pending.find((candidate) => {
            if (candidate.released) {
                return false;
            }

            try {
                return JSON.parse(candidate.options.body).action === action;
            } catch (error) {
                return false;
            }
        });

        if (!entry) {
            throw new Error(`No pending request for action ${action}`);
        }

        entry.released = true;
        entry.resolve(response === undefined ? factory(entry.url) : response);
    };

    return fetch;
}

async function waitForCalls(fetch, count, timeoutMs = 1000) {
    const deadline = Date.now() + timeoutMs;

    while (fetch.calls.length < count) {
        if (Date.now() > deadline) {
            throw new Error(`Timed out waiting for ${count} fetch calls (saw ${fetch.calls.length})`);
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function componentRoot(document) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot()));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

function makeKeyTarget(document, attributeName, action, options = {}) {
    const element = document.createElement(options.tagName || 'div');
    element.setAttribute(attributeName, action);

    if (options.target) {
        element.setAttribute('tabindex', '0');
    }

    if (options.confirm !== undefined) {
        element.setAttribute('fiber:confirm', options.confirm);
    }

    return element;
}

function keydown(element, key, options = {}) {
    const state = {
        prevented: 0,
        stopped: 0,
    };
    const event = {
        type: 'keydown',
        target: options.target || element,
        key,
        keyCode: options.keyCode,
        ctrlKey: options.ctrlKey === true,
        altKey: options.altKey === true,
        shiftKey: options.shiftKey === true,
        metaKey: options.metaKey === true,
        isComposing: options.isComposing === true,
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    };

    element.dispatchEvent(event);

    return { state, event };
}

function setUpRuntime(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const window = new FakeElement('window');

    window.confirmCalls = [];
    window.confirmResult = options.confirmResult === undefined ? true : options.confirmResult;
    window.confirm = function confirm(message) {
        this.confirmCalls.push(message);

        return this.confirmResult;
    };

    const location = {
        href: 'https://firefiber.test/start',
        origin: 'https://firefiber.test',
        assign() {},
    };
    const history = {
        entries: [],
        pushState(state, title, url) {
            this.entries.push(url);
        },
    };
    const fetch = options.fetch || captureFetch(() => responseFor(successPayload()));
    const parseHTML = () => null;

    const built = options.build(document);

    runtime.configure({
        componentId: COMPONENT_ID,
        snapshot: makeSnapshot(),
        endpoint: 'https://firefiber.test/firefiber',
        csrfToken: CSRF_TOKEN,
        hmacKey: HMAC_KEY,
        protocolVersion: '3.1',
        fetch,
        window,
        location,
        history,
        parseHTML,
    });
    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window,
        location,
        history,
        parseHTML,
        fetch,
    });

    return { runtime, document, window, location, history, fetch, ...built };
}

beforeEach(() => {
    FakeMutationObserver.instances = [];
});

afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') {
        globalThis.Firefiber.stop();
    }

    delete globalThis.Firefiber;
});

test('fiber:keydown dispatches only for the exact configured key', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Escape');
    await tick();

    assert.equal(fetch.calls.length, 0, 'Unrelated keys must not dispatch');

    const success = waitForEvent(element, 'firefiber:success');
    keydown(element, 'Enter');
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
    assert.equal(JSON.parse(fetch.calls[0].options.body).action, 'saveSearch');
});

test('fiber:keydown matches named navigation and editing keys', async () => {
    const cases = [
        ['fiber:keydown.escape', 'Escape'],
        ['fiber:keydown.tab', 'Tab'],
        ['fiber:keydown.arrowup', 'ArrowUp'],
        ['fiber:keydown.arrowdown', 'ArrowDown'],
        ['fiber:keydown.space', ' '],
        ['fiber:keydown.esc', 'Escape'],
    ];

    for (const [attribute, key] of cases) {
        let element = null;

        const { fetch } = setUpRuntime({
            build(doc) {
                const root = componentRoot(doc);
                element = makeKeyTarget(doc, attribute, 'saveSearch');
                root.append(element);
                doc.body.append(root);

                return {};
            },
        });

        keydown(element, key);
        await waitForCalls(fetch, 1);

        assert.equal(fetch.calls.length, 1, `${attribute} must match ${key}`);
    }
});

test('fiber:keydown requires exact ctrl, alt, shift and meta combinations', async () => {
    const cases = [
        ['fiber:keydown.enter.ctrl', { ctrlKey: true }],
        ['fiber:keydown.enter.alt', { altKey: true }],
        ['fiber:keydown.enter.shift', { shiftKey: true }],
        ['fiber:keydown.enter.meta', { metaKey: true }],
    ];

    for (const [attribute, modifiers] of cases) {
        let element = null;

        const first = setUpRuntime({
            build(doc) {
                const root = componentRoot(doc);
                element = makeKeyTarget(doc, attribute, 'saveSearch');
                root.append(element);
                doc.body.append(root);

                return {};
            },
        });

        keydown(element, 'Enter');
        await tick();

        assert.equal(first.fetch.calls.length, 0, `${attribute} must not fire without its modifier`);

        element = null;
        const second = setUpRuntime({
            build(doc) {
                const root = componentRoot(doc);
                element = makeKeyTarget(doc, attribute, 'saveSearch');
                root.append(element);
                doc.body.append(root);

                return {};
            },
        });

        keydown(element, 'Enter', modifiers);
        await waitForCalls(second.fetch, 1);

        assert.equal(second.fetch.calls.length, 1, `${attribute} must fire with its modifier`);

        const extraKey = ['ctrlKey', 'altKey', 'shiftKey', 'metaKey'].find((name) => modifiers[name] !== true);
        const extra = { ...modifiers, [extraKey]: true };
        keydown(element, 'Enter', extra);
        await tick();

        assert.equal(second.fetch.calls.length, 1, `${attribute} must ignore extra modifiers`);
    }
});

test('fiber:keydown supports several modifiers together', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter.ctrl.shift', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter', { ctrlKey: true });
    await tick();

    assert.equal(fetch.calls.length, 0);

    keydown(element, 'Enter', { ctrlKey: true, shiftKey: true });
    await waitForCalls(fetch, 1);

    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown preserves .prevent and .stop modifiers', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter.prevent.stop', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(element, 'firefiber:success');
    const { state } = keydown(element, 'Enter');
    await success;
    await tick();

    assert.equal(state.prevented, 1);
    assert.equal(state.stopped, 1);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown supports .once without consuming it on cancel', async () => {
    let element = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter.once', 'saveSearch', {
                confirm: 'Save search?',
            });
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter');
    await tick();

    assert.equal(element.listenerCount('keydown'), 1, 'Cancelled confirmation keeps .once');
    assert.equal(fetch.calls.length, 0);

    window.confirmResult = true;

    const success = waitForEvent(element, 'firefiber:success');
    keydown(element, 'Enter');
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
    assert.equal(element.listenerCount('keydown'), 0);
});

test('fiber:keydown supports .self', async () => {
    let element = null;
    let child = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter.self', 'saveSearch');
            child = doc.createElement('span');
            element.append(child);
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter', { target: child });
    await tick();

    assert.equal(fetch.calls.length, 0, '.self must ignore descendant targets');

    const success = waitForEvent(element, 'firefiber:success');
    keydown(element, 'Enter');
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown ignores keys from interactive descendants', async () => {
    let element = null;
    let input = null;
    let span = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            input = doc.createElement('input');
            span = doc.createElement('span');
            element.append(input, span);
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter', { target: input });
    await tick();

    assert.equal(fetch.calls.length, 0, 'Keys inside interactive controls belong to that control');

    const success = waitForEvent(element, 'firefiber:success');
    keydown(element, 'Enter', { target: span });
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown supports confirmation for accepted and cancelled keys', async () => {
    let element = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch', {
                confirm: 'Save search?',
            });
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    const cancelled = keydown(element, 'Enter');
    await tick();

    assert.deepEqual(window.confirmCalls, ['Save search?']);
    assert.equal(fetch.calls.length, 0);
    assert.equal(cancelled.state.prevented, 1);

    window.confirmResult = true;

    const success = waitForEvent(element, 'firefiber:success');
    keydown(element, 'Enter');
    await success;
    await tick();

    assert.equal(window.confirmCalls.length, 2);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown integrates with fiber:loading and fiber:target', async () => {
    let element = null;
    let anyLoading = null;
    let matching = null;
    let other = null;

    const { fetch } = setUpRuntime({
        fetch: deferredFetch(() => responseFor(successPayload())),
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            anyLoading = doc.createElement('div');
            anyLoading.setAttribute('fiber:loading', '');
            anyLoading.setAttribute('hidden', 'hidden');
            matching = doc.createElement('div');
            matching.setAttribute('fiber:loading', '');
            matching.setAttribute('fiber:target', 'saveSearch');
            matching.setAttribute('hidden', 'hidden');
            other = doc.createElement('div');
            other.setAttribute('fiber:loading', '');
            other.setAttribute('fiber:target', 'otherAction');
            other.setAttribute('hidden', 'hidden');
            root.append(element, anyLoading, matching, other);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter');

    assert.equal(anyLoading.getAttribute('hidden'), null);
    assert.equal(matching.getAttribute('hidden'), null);
    assert.equal(other.getAttribute('hidden'), 'hidden');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('saveSearch');
    await tick();

    assert.equal(anyLoading.getAttribute('hidden'), 'hidden');
    assert.equal(matching.getAttribute('hidden'), 'hidden');
});

test('fiber:keydown suppresses a duplicate dispatch of the same event', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    const state = {
        prevented: 0,
        stopped: 0,
    };
    const event = {
        type: 'keydown',
        target: element,
        key: 'Enter',
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    };

    const success = waitForEvent(element, 'firefiber:success');
    element.dispatchEvent(event);
    element.dispatchEvent(event);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
});

test('fiber:keydown ignores disabled controls', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = doc.createElement('input');
            element.setAttribute('type', 'text');
            element.setAttribute('name', 'query');
            element.setAttribute('disabled', 'disabled');
            element.setAttribute('fiber:keydown.enter', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    keydown(element, 'Enter');
    await tick();

    assert.equal(fetch.calls.length, 0);
});

test('fiber:keydown cleans up on element removal and runtime stop', async () => {
    let element = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    assert.equal(element.listenerCount('keydown'), 1);

    const root = element.parentNode;
    root.removeChild(element);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [element], addedNodes: [] }]);

    assert.equal(element.listenerCount('keydown'), 0);

    keydown(element, 'Enter');
    await tick();
    assert.equal(fetch.calls.length, 0);

    const second = setUpRuntime({
        build(doc) {
            const holder = componentRoot(doc);
            const control = makeKeyTarget(doc, 'fiber:keydown.enter', 'saveSearch');
            holder.append(control);
            doc.body.append(holder);

            return { control };
        },
    });

    second.runtime.stop();
    second.runtime.stop();

    assert.equal(second.control.listenerCount('keydown'), 0);

    keydown(second.control, 'Enter');
    await tick();

    assert.equal(second.fetch.calls.length, 0);
});

test('elements without fiber:keydown keep normal keyboard behavior', async () => {
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            element = doc.createElement('div');
            root.append(element);
            doc.body.append(root);

            return {};
        },
    });

    const { state } = keydown(element, 'Enter');
    await tick();

    assert.equal(element.listenerCount('keydown'), 0);
    assert.equal(state.prevented, 0);
    assert.equal(fetch.calls.length, 0);
});
