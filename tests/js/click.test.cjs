'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeMutationObserver,
    waitForEvent,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');
const HMAC_KEY = 'phase-4.2-click-test-key';
const COMPONENT_ID = 'ff-click';
const CSRF_TOKEN = 'csrf-token';
const ENDPOINT = 'https://firefiber.test/firefiber';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function boot(runtime, document) {
    return runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
    });
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

function successPayload(componentId = COMPONENT_ID, count = 2, signature = 'fresh-snapshot') {
    return {
        status: 'ok',
        componentId,
        action: 'increment',
        result: { previous: count - 1, count },
        snapshot: makeSnapshot(componentId, count, signature),
    };
}

function responseFor(payload, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
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

function dispatchClick(element, target = element) {
    const state = {
        prevented: 0,
        stopped: 0,
    };
    const event = {
        type: 'click',
        target,
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    };

    element.dispatchEvent(event);

    return state;
}

function expectedRequestSignature(body) {
    return crypto.createHmac('sha256', HMAC_KEY).update(body).digest('base64');
}

function setup(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = document.createElement('div');
    const button = document.createElement('button');
    const snapshot = options.snapshot || makeSnapshot();

    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot));
    root.append(button);
    document.body.append(root);

    button.setAttribute('fiber:click', options.action || 'increment');

    if (options.modifiers) {
        button.setAttribute('fiber:click', `${options.action || 'increment'}.${options.modifiers}`);
    }

    if (options.attributes) {
        for (const [name, value] of Object.entries(options.attributes)) {
            button.setAttribute(name, value);
        }
    }

    const fetch = options.fetch || captureFetch(() => responseFor(successPayload()));
    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        hmacKey: HMAC_KEY,
        protocolVersion: '3.1',
        fetch,
        ...(options.config || {}),
    });
    boot(runtime, document);

    return {
        runtime,
        document,
        root,
        button,
        fetch,
        snapshot,
    };
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

test('fiber:click dispatches exactly one valid action request only after a click', async () => {
    const { button, fetch } = setup();

    assert.equal(fetch.calls.length, 0, 'Boot must not issue a request');

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    const event = await success;

    assert.equal(fetch.calls.length, 1);
    assert.equal(event.detail.status, 200);
    assert.equal(event.detail.data.result.count, 2);

    const request = fetch.calls[0];
    assert.equal(request.url, ENDPOINT);
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.headers['X-Firefiber-Target'], '1');
    assert.equal(request.options.headers['X-Csrf-Token'], CSRF_TOKEN);
    assert.equal(
        request.options.headers['X-Firefiber-Signature'],
        expectedRequestSignature(request.options.body),
    );

    const body = JSON.parse(request.options.body);
    assert.equal(body.protocolVersion, '3.1');
    assert.equal(body.type, 'handleAction');
    assert.match(body.requestId, /^ff-req-/);
    assert.equal(body.componentId, COMPONENT_ID);
    assert.equal(body.action, 'increment');
    assert.deepEqual(body.payload, {});
    assert.equal(body.csrfToken, CSRF_TOKEN);
    assert.equal(body.signature, 'signed-snapshot');
    assert.equal(body.snapshot.componentId, COMPONENT_ID);
    assert.equal(body.snapshot.state.count, 1);
    assert.equal(body.snapshot.signature, 'signed-snapshot');

    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(fetch.calls.length, 1, 'A single click must produce exactly one request');
});

test('fiber:click sends the component class and updates bound state after success', async () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = document.createElement('div');
    const output = document.createElement('span');
    const button = document.createElement('button');

    output.textContent = '1';
    output.setAttribute('data-firefiber-bind', 'count');
    button.setAttribute('fiber:click', 'increment');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-component-name', 'App\\Firefiber\\Components\\Counter');
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot()));
    root.append(output, button);
    document.body.append(root);

    const fetch = captureFetch(() => responseFor(successPayload()));
    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        hmacKey: HMAC_KEY,
        protocolVersion: '3.1',
        fetch,
    });
    boot(runtime, document);

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await success;

    const body = JSON.parse(fetch.calls[0].options.body);
    assert.equal(body.componentName, 'App\\Firefiber\\Components\\Counter');
    assert.equal(body.componentClass, 'App\\Firefiber\\Components\\Counter');
    assert.equal(String(output.textContent), '2');
    assert.equal(JSON.parse(root.getAttribute('data-firefiber-snapshot')).state.count, 2);
});

test('fiber:click suppresses duplicate submissions while the same action is pending', async () => {
    let resolveFetch;
    let markFetchStarted;
    const fetchStarted = new Promise((resolve) => {
        markFetchStarted = resolve;
    });
    const fetch = captureFetch((callNumber) => {
        if (callNumber === 1) {
            markFetchStarted();

            return new Promise((resolve) => {
                resolveFetch = resolve;
            });
        }

        return Promise.resolve(responseFor(successPayload()));
    });
    const { button } = setup({ fetch });

    const firstSuccess = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    dispatchClick(button);
    dispatchClick(button);
    await fetchStarted;

    assert.equal(fetch.calls.length, 1, 'Pending action must not submit again');

    resolveFetch(responseFor(successPayload()));
    await firstSuccess;
    await new Promise((resolve) => setTimeout(resolve, 0));

    const secondSuccess = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await secondSuccess;

    assert.equal(fetch.calls.length, 2, 'A new click after completion may submit again');
    assert.equal(JSON.parse(fetch.calls[1].options.body).snapshot.state.count, 2);
});

test('fiber:click supports .prevent and .stop modifiers', async () => {
    const { button, fetch } = setup({ modifiers: 'prevent.stop' });
    const success = waitForEvent(button, 'firefiber:success');
    const eventState = dispatchClick(button);

    await success;

    assert.equal(eventState.prevented, 1);
    assert.equal(eventState.stopped, 1);
    assert.equal(fetch.calls.length, 1);
});

test('fiber:click supports the .once modifier', async () => {
    const { button, fetch } = setup({ modifiers: 'once' });
    const success = waitForEvent(button, 'firefiber:success');

    dispatchClick(button);
    assert.equal(button.listenerCount('click'), 0, '.once must remove the listener');
    dispatchClick(button);
    await success;
    await new Promise((resolve) => setTimeout(resolve, 0));

    dispatchClick(button);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(fetch.calls.length, 1);
});

test('fiber:click supports the .self modifier', async () => {
    const { button, document, fetch } = setup({ modifiers: 'self' });
    const child = document.createElement('span');
    button.append(child);

    dispatchClick(button, child);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(fetch.calls.length, 0, 'Clicks from descendants must not dispatch with .self');

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button, button);
    await success;

    assert.equal(fetch.calls.length, 1);
});

test('success rotates the signed snapshot for the next action', async () => {
    const fetch = captureFetch((callNumber) => responseFor(successPayload(COMPONENT_ID, callNumber + 1)));
    const { button } = setup({ fetch });

    const firstSuccess = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await firstSuccess;

    const secondSuccess = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await secondSuccess;

    assert.equal(fetch.calls.length, 2);

    const secondBody = JSON.parse(fetch.calls[1].options.body);
    assert.equal(secondBody.snapshot.state.count, 2);
    assert.equal(secondBody.snapshot.signature, 'fresh-snapshot');
});

test('validation errors are surfaced without retrying', async () => {
    const fetch = captureFetch(() => responseFor({
        status: 'error',
        error: 'The value is invalid',
        errorCode: 1,
        errors: { count: ['The count is invalid'] },
    }, 422));
    const { button } = setup({ fetch });
    const validation = waitForEvent(button, 'firefiber:validation-error');

    dispatchClick(button);
    const event = await validation;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(event.detail.status, 422);
    assert.equal(event.detail.errorCode, 1);
    assert.equal(fetch.calls.length, 1, 'Validation errors must not be retried');
});

test('server errors are surfaced without retrying', async () => {
    const fetch = captureFetch(() => responseFor({
        status: 'error',
        error: 'Server exploded',
    }, 500));
    const { button } = setup({ fetch });
    const failure = waitForEvent(button, 'firefiber:error');

    dispatchClick(button);
    const event = await failure;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(event.detail.status, 500);
    assert.equal(event.detail.error, 'Server exploded');
    assert.equal(fetch.calls.length, 1, 'Server errors must not be retried');
});

test('expired snapshots are surfaced without retrying or auto-refreshing', async () => {
    const fetch = captureFetch(() => responseFor({
        status: 'error',
        error: 'Snapshot expired',
        errorCode: 5,
    }, 409));
    const { button } = setup({ fetch });
    const expired = waitForEvent(button, 'firefiber:expired');

    dispatchClick(button);
    const event = await expired;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(event.detail.status, 409);
    assert.equal(event.detail.errorCode, 5);
    assert.equal(fetch.calls.length, 1, 'Expired snapshots must not trigger automatic refresh');
});

test('removal and stop clean up click listeners', async () => {
    const first = setup();
    const observer = FakeMutationObserver.instances[0];

    first.document.body.removeChild(first.root);
    observer.emit([{ removedNodes: [first.root], addedNodes: [] }]);

    assert.equal(first.button.listenerCount('click'), 0);
    dispatchClick(first.button);
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(first.fetch.calls.length, 0);

    first.runtime.stop();

    const second = setup();
    assert.equal(second.button.listenerCount('click'), 1);

    second.runtime.stop();

    assert.equal(second.button.listenerCount('click'), 0);
    dispatchClick(second.button);
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(second.fetch.calls.length, 0);
});

test('fiber:click preserves third-party click handlers and default behavior', async () => {
    const { button, fetch } = setup();
    let thirdPartyCalls = 0;
    button.addEventListener('click', () => {
        thirdPartyCalls++;
    });

    const success = waitForEvent(button, 'firefiber:success');
    const eventState = dispatchClick(button);
    await success;

    assert.equal(thirdPartyCalls, 1);
    assert.equal(eventState.prevented, 0);
    assert.equal(eventState.stopped, 0);
    assert.equal(fetch.calls.length, 1);
});

test('structured state never replaces server-rendered markup with JSON', async () => {
    const record = {
        id: 'abc',
        componentId: COMPONENT_ID,
        field: 'document',
        disk: 'local',
        path: 'firefiber/uploads/abc/abc.txt',
        storedName: 'abc.txt',
        originalName: 'notes.txt',
        mimeType: 'text/plain',
        extension: 'txt',
        size: 128,
        promoted: false,
    };

    const { document, root, button } = setup({
        fetch: captureFetch(() => responseFor({
            status: 'ok',
            componentId: COMPONENT_ID,
            action: 'increment',
            result: { count: 2 },
            snapshot: {
                componentId: COMPONENT_ID,
                state: { count: 2, uploads: [record] },
                timestamp: Math.floor(Date.now() / 1000),
                protocolVersion: '3.1',
                signature: 'fresh-snapshot',
            },
        })),
    });

    // Server-rendered markup for the collection, next to a plain scalar bind.
    const list = document.createElement('ul');
    list.setAttribute('data-firefiber-bind', 'uploads');
    const item = document.createElement('li');
    item.setAttribute('data-upload-id', 'abc');
    item.textContent = 'notes.txt';
    list.append(item);
    root.append(list);

    const scalar = document.createElement('span');
    scalar.setAttribute('data-firefiber-bind', 'count');
    scalar.textContent = '1';
    root.append(scalar);

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await success;

    // The collection keeps its own markup: a snapshot application must not
    // write the array's JSON into the element and destroy its children.
    assert.equal(list.children.length, 1, 'collection markup survives snapshot application');
    assert.equal(list.children[0], item, 'the server-rendered item is untouched');

    const dumped = typeof list.textContent === 'string' ? list.textContent : '';
    assert.equal(dumped.includes('storedName'), false, 'internal record fields must not reach the page');
    assert.equal(dumped.includes('firefiber/uploads'), false, 'storage paths must not reach the page');

    // Scalars from the same snapshot still bind normally.
    assert.equal(scalar.textContent, '2');
});
