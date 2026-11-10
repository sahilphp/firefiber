'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeMutationObserver,
    waitForEvent,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');
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

function snapshot(componentId, state) {
    return {
        componentId,
        state,
        timestamp: Math.floor(Date.now() / 1000),
        protocolVersion: '3.1',
        signature: `signed-${componentId}`,
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

function dispatchClick(element) {
    element.dispatchEvent({
        type: 'click',
        target: element,
        preventDefault() {},
        stopPropagation() {},
    });
}

function componentRoot(document, componentId, state) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', componentId);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot(componentId, state)));

    return root;
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

test('requests do not require or expose a client signing secret', async () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = componentRoot(document, 'ff-child', { count: 1 });
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'increment');
    root.append(button);
    document.body.append(root);

    const fetch = captureFetch(() => responseFor({
        status: 'ok',
        componentId: 'ff-child',
        action: 'increment',
        result: { count: 2 },
        snapshot: snapshot('ff-child', { count: 2 }),
    }));

    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        protocolVersion: '3.1',
        fetch,
    });
    boot(runtime, document);

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    await success;

    assert.equal(fetch.calls.length, 1);
    assert.equal(fetch.calls[0].options.headers['X-Firefiber-Signature'], undefined);
    assert.equal(fetch.calls[0].options.headers['X-Csrf-Token'], CSRF_TOKEN);
});

test('parent snapshots update the parent component root', async () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const childRoot = componentRoot(document, 'ff-child', { count: 1 });
    const button = document.createElement('button');
    button.setAttribute('fiber:click', 'increment');
    childRoot.append(button);

    const parentRoot = componentRoot(document, 'ff-parent', { last_count: 0 });
    const parentOutput = document.createElement('span');
    parentOutput.setAttribute('data-firefiber-bind', 'last_count');
    parentOutput.textContent = '0';
    parentRoot.append(parentOutput);

    document.body.append(parentRoot);
    document.body.append(childRoot);

    const fetch = captureFetch(() => responseFor({
        status: 'ok',
        componentId: 'ff-child',
        action: 'increment',
        result: { count: 2 },
        snapshot: snapshot('ff-child', { count: 2 }),
        parentSnapshots: [snapshot('ff-parent', { last_count: 2 })],
    }));

    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        protocolVersion: '3.1',
        fetch,
    });
    boot(runtime, document);

    const success = waitForEvent(button, 'firefiber:success');
    dispatchClick(button);
    const event = await success;

    assert.equal(String(parentOutput.textContent), '2');
    assert.deepEqual(JSON.parse(parentRoot.getAttribute('data-firefiber-snapshot')).state, { last_count: 2 });
    assert.equal(event.detail.data.parentSnapshots.length, 1);
});

test('validation responses populate field errors and clear them after success', async () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = componentRoot(document, 'ff-form', { email: '' });
    const email = document.createElement('input');
    email.setAttribute('name', 'email');
    email.setAttribute('fiber:model', 'email');
    email.setAttribute('value', 'not-an-email');
    const error = document.createElement('p');
    error.setAttribute('data-firefiber-error', 'email');
    error.textContent = '';
    root.append(email, error);
    document.body.append(root);

    const fetch = captureFetch((callNumber) => {
        if (callNumber === 1) {
            return responseFor({
                status: 'error',
                error: 'Validation failed',
                errorCode: 1,
                errors: { email: ['The email field must be a valid email address.'] },
            }, 422);
        }

        return responseFor({
            status: 'ok',
            componentId: 'ff-form',
            action: 'updateModel',
            result: { updated: { email: 'ada@example.com' } },
            snapshot: snapshot('ff-form', { email: 'ada@example.com' }),
        });
    });

    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        protocolVersion: '3.1',
        fetch,
    });
    boot(runtime, document);

    const failed = waitForEvent(email, 'firefiber:validation-error');
    email.dispatchEvent({
        type: 'input',
        target: email,
        preventDefault() {},
        stopPropagation() {},
    });
    await failed;

    assert.equal(String(error.textContent), 'The email field must be a valid email address.');
    assert.equal(error.getAttribute('aria-invalid'), 'true');

    email.setAttribute('value', 'ada@example.com');
    const success = waitForEvent(email, 'firefiber:success');
    email.dispatchEvent({
        type: 'input',
        target: email,
        preventDefault() {},
        stopPropagation() {},
    });
    await success;

    assert.equal(String(error.textContent), '');
    assert.equal(error.getAttribute('aria-invalid'), 'false');
});
