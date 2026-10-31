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
const COMPONENT_ID = 'ff-confirm';
const CSRF_TOKEN = 'confirm-csrf';
const HMAC_KEY = 'phase-4.8-confirm-test-key';

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
        action: 'increment',
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

function componentRoot(document) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot()));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

function makeEvent(type, element) {
    const state = {
        prevented: 0,
        stopped: 0,
    };

    return {
        state,
        event: {
            type,
            target: element,
            preventDefault() {
                state.prevented++;
            },
            stopPropagation() {
                state.stopped++;
            },
        },
    };
}

function dispatch(element, type) {
    const built = makeEvent(type, element);
    element.dispatchEvent(built.event);

    return { state: built.state, event: built.event };
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

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

test('fiber:confirm accepts a click action with the default message', async () => {
    let button = null;

    const { window, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    const { state } = dispatch(button, 'click');
    await success;
    await tick();

    assert.deepEqual(window.confirmCalls, ['Are you sure?']);
    assert.equal(fetch.calls.length, 1, 'Confirmed click sends exactly one request');
    assert.equal(state.prevented, 0);
    assert.equal(state.stopped, 0);
});

test('fiber:confirm cancels a click action without sending a request', async () => {
    let button = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    const { state } = dispatch(button, 'click');
    await tick();

    assert.equal(window.confirmCalls.length, 1);
    assert.equal(fetch.calls.length, 0, 'Cancelled click must not send a request');
    assert.equal(state.prevented, 1, 'Cancelling prevents the browser default');
});

test('fiber:confirm uses the custom message from the attribute value', async () => {
    let button = null;

    const { window } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            button.setAttribute('fiber:confirm', 'Delete this post?');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    dispatch(button, 'click');
    await tick();

    assert.deepEqual(window.confirmCalls, ['Delete this post?']);
});

test('fiber:confirm accepts a form submission', async () => {
    let form = null;

    const { window, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            form.setAttribute('fiber:confirm', 'Save this profile?');
            root.append(form);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(form, 'firefiber:success');
    dispatch(form, 'submit');
    await success;
    await tick();

    assert.deepEqual(window.confirmCalls, ['Save this profile?']);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:confirm cancels a form submission', async () => {
    let form = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            form.setAttribute('fiber:confirm', 'Save this profile?');
            root.append(form);
            doc.body.append(root);

            return {};
        },
    });

    const { state } = dispatch(form, 'submit');
    await tick();

    assert.equal(window.confirmCalls.length, 1);
    assert.equal(fetch.calls.length, 0);
    assert.equal(state.prevented, 1);
});

test('fiber:confirm preserves .prevent and .stop modifiers on confirmed clicks', async () => {
    let preventedButton = null;
    let stoppedButton = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            preventedButton = doc.createElement('button');
            preventedButton.setAttribute('fiber:click', 'increment.prevent');
            preventedButton.setAttribute('fiber:confirm', '');
            stoppedButton = doc.createElement('button');
            stoppedButton.setAttribute('fiber:click', 'increment.stop');
            stoppedButton.setAttribute('fiber:confirm', '');
            root.append(preventedButton, stoppedButton);
            doc.body.append(root);

            return {};
        },
    });

    const preventedSuccess = waitForEvent(preventedButton, 'firefiber:success');
    const prevented = dispatch(preventedButton, 'click');
    await preventedSuccess;

    assert.equal(prevented.state.prevented, 1);
    assert.equal(prevented.state.stopped, 0);

    const stoppedSuccess = waitForEvent(stoppedButton, 'firefiber:success');
    const stopped = dispatch(stoppedButton, 'click');
    await stoppedSuccess;

    assert.equal(stopped.state.prevented, 0);
    assert.equal(stopped.state.stopped, 1);
    assert.equal(fetch.calls.length, 2);
});

test('fiber:confirm preserves .once on confirmed clicks', async () => {
    let button = null;

    const { window, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment.once');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    dispatch(button, 'click');
    await success;
    await tick();

    assert.equal(button.listenerCount('click'), 0, '.once must remove the listener');
    assert.equal(window.confirmCalls.length, 1);

    dispatch(button, 'click');
    await tick();

    assert.equal(window.confirmCalls.length, 1, 'No listener means no second dialog');
    assert.equal(fetch.calls.length, 1);
});

test('fiber:confirm does not consume .once when the user cancels', async () => {
    let button = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment.once');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    dispatch(button, 'click');
    await tick();

    assert.equal(button.listenerCount('click'), 1, 'A cancelled confirmation keeps the listener');
    assert.equal(fetch.calls.length, 0);

    window.confirmResult = true;

    const success = waitForEvent(button, 'firefiber:success');
    dispatch(button, 'click');
    await success;
    await tick();

    assert.equal(window.confirmCalls.length, 2);
    assert.equal(fetch.calls.length, 1);
    assert.equal(button.listenerCount('click'), 0);
});

test('fiber:confirm preserves .once on form submissions after acceptance', async () => {
    let form = null;

    const { window, fetch } = setUpRuntime({
        confirmResult: false,
        build(doc) {
            const root = componentRoot(doc);
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile.once');
            form.setAttribute('fiber:confirm', '');
            root.append(form);
            doc.body.append(root);

            return {};
        },
    });

    assert.equal(form.listenerCount('submit'), 1);

    dispatch(form, 'submit');
    await tick();

    assert.equal(form.listenerCount('submit'), 1, 'Cancelling must not consume .once');
    assert.equal(fetch.calls.length, 0);

    window.confirmResult = true;

    const success = waitForEvent(form, 'firefiber:success');
    dispatch(form, 'submit');
    await success;
    await tick();

    assert.equal(fetch.calls.length, 1);
    assert.equal(form.listenerCount('submit'), 0);
});

test('fiber:confirm shows one dialog per event and dispatches once', async () => {
    let button = null;

    const { window, fetch } = setUpRuntime({
        fetch: captureFetch(() => new Promise((resolve) => {
            setTimeout(() => resolve(responseFor(successPayload())), 5);
        })),
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    const built = makeEvent('click', button);
    const success = waitForEvent(button, 'firefiber:success');
    button.dispatchEvent(built.event);
    button.dispatchEvent(built.event);
    await success;
    await tick();

    assert.equal(window.confirmCalls.length, 1, 'The same event must not open two dialogs');
    assert.equal(fetch.calls.length, 1, 'The pending action must not be dispatched twice');
});

test('fiber:confirm does not intercept elements without a Firefiber action', async () => {
    let button = null;
    let form = null;

    const { window, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:confirm', '');
            form = doc.createElement('form');
            root.append(button, form);
            doc.body.append(root);

            return {};
        },
    });

    const buttonState = dispatch(button, 'click');
    const formState = dispatch(form, 'submit');
    await tick();

    assert.equal(window.confirmCalls.length, 0);
    assert.equal(fetch.calls.length, 0);
    assert.equal(buttonState.state.prevented, 0);
    assert.equal(formState.state.prevented, 0);
});

test('fiber:confirm cleans up on element removal and runtime stop', async () => {
    let button = null;

    const { runtime, window, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            button.setAttribute('fiber:confirm', '');
            root.append(button);
            doc.body.append(root);

            return {};
        },
    });

    assert.equal(button.listenerCount('click'), 1);

    const root = button.parentNode;
    root.removeChild(button);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [button], addedNodes: [] }]);

    assert.equal(button.listenerCount('click'), 0);

    dispatch(button, 'click');
    await tick();

    assert.equal(window.confirmCalls.length, 0);
    assert.equal(fetch.calls.length, 0);

    const second = setUpRuntime({
        build(doc) {
            const holder = componentRoot(doc);
            const anchor = doc.createElement('button');
            anchor.setAttribute('fiber:click', 'increment');
            anchor.setAttribute('fiber:confirm', '');
            holder.append(anchor);
            doc.body.append(holder);

            return { anchor };
        },
    });

    second.runtime.stop();
    second.runtime.stop();

    assert.equal(second.anchor.listenerCount('click'), 0);

    dispatch(second.anchor, 'click');
    await tick();

    assert.equal(second.window.confirmCalls.length, 0);
    assert.equal(second.fetch.calls.length, 0);
});
