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
const COMPONENT_ID = 'ff-change';
const CSRF_TOKEN = 'change-csrf';
const HMAC_KEY = 'phase-4.9-change-test-key';

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
        action: 'syncField',
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

function componentRoot(document, id = COMPONENT_ID) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', id);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot(id)));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

function makeInput(document, name, type, value, options = {}) {
    const input = document.createElement('input');
    input.setAttribute('type', type);
    input.setAttribute('name', name);

    if (value !== undefined) {
        input.setAttribute('value', value);
    }

    if (options.checked) {
        input.setAttribute('checked', 'checked');
    }

    if (options.disabled) {
        input.setAttribute('disabled', 'disabled');
    }

    if (options.change !== false) {
        input.setAttribute('fiber:change', options.change || 'syncField');
    }

    if (options.confirm !== undefined) {
        input.setAttribute('fiber:confirm', options.confirm);
    }

    return input;
}

function selectWithOptions(document, name, values, selected, multiple) {
    const select = document.createElement('select');
    select.setAttribute('name', name);

    if (multiple) {
        select.setAttribute('multiple', 'multiple');
    }

    for (const value of values) {
        const option = document.createElement('option');
        option.setAttribute('value', value);

        if (selected.includes(value)) {
            option.setAttribute('selected', 'selected');
        }

        select.append(option);
    }

    return select;
}

function dispatch(element) {
    const state = {
        prevented: 0,
        stopped: 0,
    };
    const event = {
        type: 'change',
        target: element,
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

function payloadOf(request) {
    return JSON.parse(request.options.body).payload;
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

test('fiber:change dispatches a text input change with its name and value', async () => {
    let input = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com', { change: 'syncEmail' });
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
    assert.equal(JSON.parse(fetch.calls[0].options.body).action, 'syncEmail');
    assert.deepEqual(payloadOf(fetch.calls[0]), { email: 'ada@example.com' });
});

test('fiber:change keeps repeated checkbox names as an array', async () => {
    let first = null;
    let second = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            first = makeInput(doc, 'roles', 'checkbox', 'admin', { checked: true });
            second = makeInput(doc, 'roles', 'checkbox', 'editor', { checked: true });
            root.append(first, second);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(first, 'firefiber:success');
    dispatch(first);
    await success;
    await tick();

    assert.deepEqual(payloadOf(fetch.calls[0]), { roles: ['admin', 'editor'] });
});

test('fiber:change reports unchecked checkboxes as an empty selection', async () => {
    let input = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'notify', 'checkbox', 'yes');
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input);
    await success;
    await tick();

    assert.deepEqual(payloadOf(fetch.calls[0]), { notify: [] });
});

test('fiber:change submits only the checked radio value', async () => {
    let basic = null;
    let pro = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            basic = makeInput(doc, 'plan', 'radio', 'basic');
            pro = makeInput(doc, 'plan', 'radio', 'pro', { checked: true });
            root.append(basic, pro);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(pro, 'firefiber:success');
    dispatch(pro);
    await success;
    await tick();

    assert.deepEqual(payloadOf(fetch.calls[0]), { plan: 'pro' });
});

test('fiber:change submits the selected value of a single select', async () => {
    let select = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            select = selectWithOptions(doc, 'size', ['small', 'medium', 'large'], ['medium']);
            select.setAttribute('fiber:change', 'syncSize');
            root.append(select);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(select, 'firefiber:success');
    dispatch(select);
    await success;
    await tick();

    assert.deepEqual(payloadOf(fetch.calls[0]), { size: 'medium' });
});

test('fiber:change submits every selected value of a multiple select', async () => {
    let select = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            select = selectWithOptions(doc, 'tags', ['one', 'two', 'three'], ['one', 'three'], true);
            select.setAttribute('fiber:change', 'syncTags');
            root.append(select);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(select, 'firefiber:success');
    dispatch(select);
    await success;
    await tick();

    assert.deepEqual(payloadOf(fetch.calls[0]), { tags: ['one', 'three'] });
});

test('fiber:change ignores disabled controls', async () => {
    let disabled = null;
    let enabled = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            disabled = makeInput(doc, 'locked', 'text', 'nope', { disabled: true });
            enabled = makeInput(doc, 'email', 'text', 'ada@example.com');
            root.append(disabled, enabled);
            doc.body.append(root);

            return {};
        },
    });

    dispatch(disabled);
    await tick();

    assert.equal(fetch.calls.length, 0);

    const success = waitForEvent(enabled, 'firefiber:success');
    dispatch(enabled);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
});

test('fiber:change supports confirmation for accepted and cancelled changes', async () => {
    let input = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com', { confirm: 'Sync email?' });
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const cancelled = dispatch(input);
    await tick();

    assert.deepEqual(window.confirmCalls, ['Sync email?']);
    assert.equal(fetch.calls.length, 0);
    assert.equal(cancelled.state.prevented, 1);

    window.confirmResult = true;

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input);
    await success;
    await tick();

    assert.equal(window.confirmCalls.length, 2);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:change preserves .once and does not consume it on cancel', async () => {
    let input = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com', {
                change: 'syncField.once',
                confirm: '',
            });
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    assert.equal(input.listenerCount('change'), 1);

    dispatch(input);
    await tick();

    assert.equal(input.listenerCount('change'), 1, 'Cancelled confirmation must keep .once');
    assert.equal(fetch.calls.length, 0);

    window.confirmResult = true;

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
    assert.equal(input.listenerCount('change'), 0, '.once must remove the listener');
});

test('fiber:change suppresses a duplicate dispatch of the same event', async () => {
    let input = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com');
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const state = {
        prevented: 0,
        stopped: 0,
    };
    const event = {
        type: 'change',
        target: input,
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    };

    const success = waitForEvent(input, 'firefiber:success');
    input.dispatchEvent(event);
    input.dispatchEvent(event);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1, 'The same change event must dispatch only once');
});

test('fiber:change preserves .prevent and .stop modifiers', async () => {
    let input = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com', {
                change: 'syncField.prevent.stop',
            });
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(input, 'firefiber:success');
    const { state } = dispatch(input);
    await success;
    await tick();

    assert.equal(state.prevented, 1);
    assert.equal(state.stopped, 1);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:change integrates with fiber:loading and fiber:target', async () => {
    let input = null;
    let anyLoading = null;
    let matching = null;
    let other = null;

    const { fetch } = setUpRuntime({
        fetch: deferredFetch(() => responseFor(successPayload())),
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com', { change: 'syncEmail' });
            anyLoading = doc.createElement('div');
            anyLoading.setAttribute('fiber:loading', '');
            anyLoading.setAttribute('hidden', 'hidden');
            matching = doc.createElement('div');
            matching.setAttribute('fiber:loading', '');
            matching.setAttribute('fiber:target', 'syncEmail');
            matching.setAttribute('hidden', 'hidden');
            other = doc.createElement('div');
            other.setAttribute('fiber:loading', '');
            other.setAttribute('fiber:target', 'otherAction');
            other.setAttribute('hidden', 'hidden');
            root.append(input, anyLoading, matching, other);
            doc.body.append(root);

            return {};
        },
    });

    dispatch(input);

    assert.equal(anyLoading.getAttribute('hidden'), null, 'Untargeted loading must activate');
    assert.equal(matching.getAttribute('hidden'), null, 'Matching target must activate');
    assert.equal(other.getAttribute('hidden'), 'hidden', 'Non-matching target must stay hidden');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('syncEmail');
    await tick();

    assert.equal(anyLoading.getAttribute('hidden'), 'hidden');
    assert.equal(matching.getAttribute('hidden'), 'hidden');
    assert.equal(other.getAttribute('hidden'), 'hidden');
});

test('fiber:change stays isolated between component roots', async () => {
    let inputA = null;
    let inputB = null;
    let loadingA = null;
    let loadingB = null;

    const { fetch } = setUpRuntime({
        fetch: deferredFetch(() => responseFor(successPayload())),
        build(doc) {
            const rootA = componentRoot(doc, 'ff-change-a');
            inputA = makeInput(doc, 'email', 'text', 'a@example.com');
            loadingA = doc.createElement('div');
            loadingA.setAttribute('fiber:loading', '');
            loadingA.setAttribute('hidden', 'hidden');
            rootA.append(inputA, loadingA);

            const rootB = componentRoot(doc, 'ff-change-b');
            inputB = makeInput(doc, 'email', 'text', 'b@example.com');
            loadingB = doc.createElement('div');
            loadingB.setAttribute('fiber:loading', '');
            loadingB.setAttribute('hidden', 'hidden');
            rootB.append(inputB, loadingB);

            doc.body.append(rootA, rootB);

            return {};
        },
    });

    dispatch(inputA);

    assert.equal(loadingA.getAttribute('hidden'), null);
    assert.equal(loadingB.getAttribute('hidden'), 'hidden');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('syncField');
    await tick();

    assert.equal(loadingA.getAttribute('hidden'), 'hidden');
});

test('fiber:change cleans up on element removal and runtime stop', async () => {
    let input = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = makeInput(doc, 'email', 'text', 'ada@example.com');
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    assert.equal(input.listenerCount('change'), 1);

    const root = input.parentNode;
    root.removeChild(input);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [input], addedNodes: [] }]);

    assert.equal(input.listenerCount('change'), 0);

    dispatch(input);
    await tick();
    assert.equal(fetch.calls.length, 0);

    const second = setUpRuntime({
        build(doc) {
            const holder = componentRoot(doc);
            const control = makeInput(doc, 'email', 'text', 'grace@example.com');
            holder.append(control);
            doc.body.append(holder);

            return { control };
        },
    });

    second.runtime.stop();
    second.runtime.stop();

    assert.equal(second.control.listenerCount('change'), 0);

    dispatch(second.control);
    await tick();

    assert.equal(second.fetch.calls.length, 0);
});

test('controls without fiber:change keep normal behavior', async () => {
    let input = null;

    const { document, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            input = doc.createElement('input');
            input.setAttribute('type', 'text');
            input.setAttribute('name', 'email');
            input.setAttribute('value', 'ada@example.com');
            root.append(input);
            doc.body.append(root);

            return {};
        },
    });

    const { state } = dispatch(input);
    await tick();

    assert.equal(input.listenerCount('change'), 0);
    assert.equal(state.prevented, 0);
    assert.equal(fetch.calls.length, 0);
    assert.equal(document.querySelectorAll('input').length, 1);
});
