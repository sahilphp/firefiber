'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeMutationObserver,
    waitForEvent,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../dist/runtime.min.js');
const CSRF_TOKEN = 'dist-csrf';
const ENDPOINT = 'https://firefiber.test/firefiber';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
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

function responseFor(payload) {
    return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify(payload),
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

test('the distributed runtime updates parent components without a signing secret', async () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const childRoot = document.createElement('div');
    const button = document.createElement('button');
    childRoot.setAttribute('data-firefiber-component', 'ff-dist-child');
    childRoot.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot('ff-dist-child', { count: 1 })));
    button.setAttribute('fiber:click', 'increment');
    childRoot.append(button);

    const parentRoot = document.createElement('div');
    const parentOutput = document.createElement('span');
    parentRoot.setAttribute('data-firefiber-component', 'ff-dist-parent');
    parentRoot.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot('ff-dist-parent', { last_count: 0 })));
    parentOutput.setAttribute('data-firefiber-bind', 'last_count');
    parentOutput.textContent = '0';
    parentRoot.append(parentOutput);

    document.body.append(parentRoot);
    document.body.append(childRoot);

    const calls = [];
    const fetch = (url, options) => {
        calls.push({ url, options });

        return Promise.resolve(responseFor({
            status: 'ok',
            componentId: 'ff-dist-child',
            action: 'increment',
            result: { count: 2 },
            snapshot: snapshot('ff-dist-child', { count: 2 }),
            parentSnapshots: [snapshot('ff-dist-parent', { last_count: 2 })],
        }));
    };

    runtime.configure({
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        protocolVersion: '3.1',
        fetch,
    });
    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
    });

    const success = waitForEvent(button, 'firefiber:success');
    button.dispatchEvent({
        type: 'click',
        target: button,
        preventDefault() {},
        stopPropagation() {},
    });
    await success;

    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.headers['X-Firefiber-Signature'], undefined);
    assert.equal(String(parentOutput.textContent), '2');
});
