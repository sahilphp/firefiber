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
const COMPONENT_ID = 'ff-loading';
const CSRF_TOKEN = 'loading-csrf';
const HMAC_KEY = 'phase-4.6-loading-test-key';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function makeSnapshot(componentId = COMPONENT_ID, count = 1, signature = 'signed-snapshot') {
    return {
        componentId,
        state: { count },
        timestamp: Math.floor(Date.now() / 1000),
        protocolVersion: '3.1',
        signature,
    };
}

function successPayload(componentId = COMPONENT_ID) {
    return {
        status: 'ok',
        componentId,
        action: 'increment',
        result: { count: 2 },
        snapshot: makeSnapshot(componentId, 2, 'fresh-snapshot'),
    };
}

function responseFor(payload, status = 200, contentType = 'application/json') {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { 'Content-Type': contentType },
        text: async () => JSON.stringify(payload),
    };
}

function htmlResponse(text, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
        text: async () => text,
    };
}

/** Fetch stub whose responses are released explicitly by each test. */
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
    fetch.release = (index, response) => {
        const entry = pending[index];

        entry.released = true;
        entry.resolve(response === undefined ? factory(entry.url) : response);
    };
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

function makePage(markupKey, title = '') {
    const document = new FakeDocument();
    document.title = title;

    const page = document.createElement('div');
    page.setAttribute('data-page', markupKey);
    document.body.append(page);

    return document;
}

function makeIndicator(document, name, options = {}) {
    const element = document.createElement('div');
    element.setAttribute('name', name);

    if (options.modifier) {
        element.setAttribute(`fiber:loading.${options.modifier}`, options.value || '');
    } else {
        element.setAttribute('fiber:loading', options.value || '');
    }

    if (options.className) {
        element.setAttribute('class', options.className);
    }

    if (options.hidden !== false) {
        element.setAttribute('hidden', 'hidden');
    }

    return element;
}

function click(element) {
    element.dispatchEvent({
        type: 'click',
        target: element,
        button: 0,
        preventDefault() {},
        stopPropagation() {},
    });
}

function setUpRuntime(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const location = {
        href: 'https://firefiber.test/start',
        origin: 'https://firefiber.test',
        assigns: [],
        assign(url) {
            this.assigns.push(url);
        },
    };
    const history = {
        entries: [],
        pushState(state, title, url) {
            this.entries.push(url);
        },
    };
    const snapshot = makeSnapshot();
    const fetch = options.fetch || deferredFetch(() => responseFor(successPayload()));
    const pages = options.pages || { 'page:dashboard': makePage('dashboard', 'Dashboard') };
    const parseHTML = (html) => pages[html] || null;

    const build = options.build || ((document) => ({ document }));

    // Build the markup before booting so the initial scan binds it.
    const built = build(document);

    runtime.configure({
        componentId: COMPONENT_ID,
        snapshot,
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

    return {
        runtime,
        document,
        window,
        location,
        history,
        fetch,
        snapshot,
        ...built,
    };
}

/** Create the component root wrapper used by action-based tests. */
function componentRoot(document) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot()));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

/** Wait until the async request signing has reached the fetch stub. */
async function waitForCalls(fetch, count, timeoutMs = 1000) {
    const deadline = Date.now() + timeoutMs;

    while (fetch.calls.length < count) {
        if (Date.now() > deadline) {
            throw new Error(`Timed out waiting for ${count} fetch calls (saw ${fetch.calls.length})`);
        }

        await new Promise((resolve) => setTimeout(resolve, 0));
    }
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

test('fiber:loading stays inactive before any request', async () => {
    let indicator = null;

    const { document, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            const button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
    assert.equal(fetch.calls.length, 0);
});

test('fiber:loading activates during a click request and restores afterwards', async () => {
    let indicator = null;
    let button = null;

    const { document, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), null, 'Loading must activate synchronously');

    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1);

    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading activates during a submit request', async () => {
    let indicator = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicator, form);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(form, 'firefiber:success');
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });

    assert.equal(indicator.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1);

    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading activates during a model request without dedupe flicker', async () => {
    let indicator = null;
    let input = null;

    const { document, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            input = doc.createElement('input');
            input.setAttribute('type', 'text');
            input.setAttribute('name', 'email');
            input.setAttribute('fiber:model', 'email');
            input.setAttribute('value', 'ada@example.com');
            root.append(indicator, input);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(input, 'firefiber:success');
    input.dispatchEvent({ type: 'input', target: input, preventDefault() {}, stopPropagation() {} });
    input.dispatchEvent({ type: 'change', target: input, preventDefault() {}, stopPropagation() {} });

    assert.equal(indicator.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1, 'Duplicate input/change must not send twice');

    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading activates during navigation and restores afterwards', async () => {
    let indicator = null;
    let anchor = null;

    const { window, fetch, document } = setUpRuntime({
        fetch: deferredFetch(() => htmlResponse('page:dashboard')),
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            anchor = doc.createElement('a');
            anchor.setAttribute('href', '/dashboard');
            anchor.setAttribute('fiber:navigate', '');
            root.append(indicator, anchor);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(window, 'firefiber:navigate-success');
    click(anchor);

    assert.equal(indicator.getAttribute('hidden'), null, 'Navigation must activate loading synchronously');

    await waitForCalls(fetch, 1);
    assert.equal(fetch.calls.length, 1);

    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
    assert.equal(document.title, 'Dashboard');
});

test('fiber:loading restores after a validation error', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        fetch: deferredFetch(() => responseFor({
            status: 'error',
            error: 'The value is invalid',
            errorCode: 1,
            errors: { count: ['The count is invalid'] },
        }, 422)),
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const validation = waitForEvent(button, 'firefiber:validation-error');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await validation;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading restores after a server error', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        fetch: deferredFetch(() => responseFor({ status: 'error', error: 'boom' }, 500)),
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const failure = waitForEvent(button, 'firefiber:error');
    click(button);
    await waitForCalls(fetch, 1);
    fetch.release(0);
    await failure;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading restores after a failed navigation fallback', async () => {
    let indicator = null;
    let anchor = null;

    const { window, location } = setUpRuntime({
        fetch: () => Promise.reject(new Error('network down')),
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            anchor = doc.createElement('a');
            anchor.setAttribute('href', '/dashboard');
            anchor.setAttribute('fiber:navigate', '');
            root.append(indicator, anchor);
            doc.body.append(root);

            return {};
        },
    });

    const failure = waitForEvent(window, 'firefiber:navigate-error');
    click(anchor);

    assert.equal(indicator.getAttribute('hidden'), null);

    await failure;
    await tick();

    assert.deepEqual(location.assigns, ['https://firefiber.test/dashboard']);
    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading stays active while overlapping requests are pending', async () => {
    let indicator = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicator, button, form);
            doc.body.append(root);

            return {};
        },
    });

    const firstSuccess = waitForEvent(button, 'firefiber:success');
    const secondSuccess = waitForEvent(form, 'firefiber:success');

    click(button);
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });

    await waitForCalls(fetch, 2);
    assert.equal(fetch.calls.length, 2, 'Both distinct actions must be in flight');
    assert.equal(indicator.getAttribute('hidden'), null);

    fetch.releaseAction('increment');
    await firstSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), null, 'One pending request must keep the indicator active');

    fetch.releaseAction('saveProfile');
    await secondSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:loading is scoped to the nearest component root', async () => {
    let indicatorA = null;
    let indicatorB = null;
    let buttonA = null;

    const { document } = setUpRuntime({
        build(doc) {
            const rootA = componentRoot(doc);
            indicatorA = makeIndicator(doc, 'spinner-a');
            buttonA = doc.createElement('button');
            buttonA.setAttribute('fiber:click', 'increment');
            rootA.append(indicatorA, buttonA);

            const rootB = componentRoot(doc);
            indicatorB = makeIndicator(doc, 'spinner-b');
            const buttonB = doc.createElement('button');
            buttonB.setAttribute('fiber:click', 'increment');
            rootB.append(indicatorB, buttonB);

            doc.body.append(rootA, rootB);

            return {};
        },
    });

    click(buttonA);

    assert.equal(indicatorA.getAttribute('hidden'), null);
    assert.equal(indicatorB.getAttribute('hidden'), 'hidden', 'Other components must not react');
});

test('fiber:loading.remove hides a visible element while loading and restores it', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'content', { modifier: 'remove', hidden: false });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), 'hidden');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), null, 'Original visibility must be restored');
});

test('fiber:loading.class toggles the class and restores the original class attribute', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner base',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.match(indicator.getAttribute('class'), /(^|\s)is-loading(\s|$)/);
    assert.match(indicator.getAttribute('class'), /(^|\s)spinner(\s|$)/);

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.equal(indicator.getAttribute('class'), 'spinner base');
});

test('fiber:loading cleans up when the indicator is removed', async () => {
    let indicator = null;
    let button = null;

    const { document, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), null);

    const root = indicator.parentNode;
    root.removeChild(indicator);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [indicator], addedNodes: [] }]);

    assert.equal(indicator.getAttribute('hidden'), 'hidden', 'Removal must restore the original state');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
});

function classTokens(element) {
    const value = element.getAttribute('class');

    return value === null || value === undefined ? [] : value.split(/\s+/).filter(Boolean);
}

test('fiber:loading.class keeps classes added by other scripts while loading', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.ok(classTokens(indicator).includes('is-loading'));

    // Another script mutates the class list while the request is pending.
    indicator.setAttribute('class', 'spinner is-loading theme-updated');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.deepEqual(classTokens(indicator), ['spinner', 'theme-updated']);
});

test('fiber:loading.class keeps classes removed by other scripts removed', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner base',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    // Another script removes the original classes while loading is active.
    indicator.setAttribute('class', 'is-loading');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.deepEqual(classTokens(indicator), []);
    assert.equal(indicator.getAttribute('class'), null);
});

test('fiber:loading.class preserves a pre-existing loading class', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner is-loading',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);
    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    assert.deepEqual(classTokens(indicator), ['spinner', 'is-loading']);
});

test('fiber:loading.class stays active for overlapping requests across indicators', async () => {
    let indicatorA = null;
    let indicatorB = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicatorA = makeIndicator(doc, 'spinner-a', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner',
                hidden: false,
            });
            indicatorB = makeIndicator(doc, 'spinner-b', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicatorA, indicatorB, button, form);
            doc.body.append(root);

            return {};
        },
    });

    const firstSuccess = waitForEvent(button, 'firefiber:success');
    const secondSuccess = waitForEvent(form, 'firefiber:success');

    click(button);
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });

    await waitForCalls(fetch, 2);
    assert.equal(fetch.calls.length, 2);
    assert.ok(classTokens(indicatorA).includes('is-loading'));
    assert.ok(classTokens(indicatorB).includes('is-loading'));

    fetch.releaseAction('increment');
    await firstSuccess;
    await tick();

    assert.ok(classTokens(indicatorA).includes('is-loading'), 'One pending request keeps the class');
    assert.ok(classTokens(indicatorB).includes('is-loading'), 'One pending request keeps the class');

    fetch.releaseAction('saveProfile');
    await secondSuccess;
    await tick();

    assert.deepEqual(classTokens(indicatorA), ['spinner']);
    assert.deepEqual(classTokens(indicatorB), ['spinner']);
});

test('fiber:loading.class cleanup on element removal and repeated runtime stop', async () => {
    let indicator = null;
    let button = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner base',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.ok(classTokens(indicator).includes('is-loading'));

    const parent = indicator.parentNode;
    parent.removeChild(indicator);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [indicator], addedNodes: [] }]);

    assert.deepEqual(classTokens(indicator), ['spinner', 'base'], 'Removal must drop only the loading class');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await success;
    await tick();

    runtime.stop();
    runtime.stop();

    assert.deepEqual(classTokens(indicator), ['spinner', 'base']);
});

test('fiber:loading restores state when the runtime stops mid-request', async () => {
    let indicator = null;
    let button = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner');
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    click(button);
    assert.equal(indicator.getAttribute('hidden'), null);

    runtime.stop();

    assert.equal(indicator.getAttribute('hidden'), 'hidden', 'Stopping must restore the original state');

    await waitForCalls(fetch, 1);
    fetch.release(0);
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});
