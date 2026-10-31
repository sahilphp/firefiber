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
const HMAC_KEY = 'phase-4.4-model-test-key';
const COMPONENT_ID = 'ff-model';
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
        action: 'updateModel',
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

function expectedRequestSignature(body) {
    return crypto.createHmac('sha256', HMAC_KEY).update(body).digest('base64');
}

function createInput(document, name, type, value, options = {}) {
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

    if (options.model !== false) {
        input.setAttribute('fiber:model', options.model || name);
    }

    return input;
}

function createOptions(document, values, selectedValues) {
    return values.map((value) => {
        const option = document.createElement('option');
        option.setAttribute('value', value);

        if (selectedValues.includes(value)) {
            option.setAttribute('selected', 'selected');
        }

        return option;
    });
}

function interact(element, type = 'input', target = element) {
    const state = {
        prevented: 0,
        stopped: 0,
    };

    element.dispatchEvent({
        type,
        target,
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    });

    return state;
}

function setup(build, options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = document.createElement('div');
    const snapshot = options.snapshot || makeSnapshot();

    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    if (build) {
        build(document, root);
    }

    document.body.append(root);

    const fetch = options.fetch || captureFetch(() => responseFor(successPayload()));

    runtime.configure({
        componentId: COMPONENT_ID,
        snapshot,
        endpoint: ENDPOINT,
        csrfToken: CSRF_TOKEN,
        hmacKey: HMAC_KEY,
        protocolVersion: '3.1',
        fetch,
    });

    boot(runtime, document);

    return {
        runtime,
        document,
        root,
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

test('fiber:model sends nothing before the user interacts', async () => {
    const { fetch } = setup((document, root) => {
        root.append(createInput(document, 'email', 'text', 'ada@example.com'));
    });

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(fetch.calls.length, 0, 'Binding must not dispatch a request');
});

test('fiber:model synchronizes text input edits through the signed action protocol', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(createInput(document, 'email', 'email', 'ada@example.com'));
    });
    const input = root.querySelectorAll('input')[0];

    const success = waitForEvent(input, 'firefiber:success');
    interact(input, 'input');
    const event = await success;

    assert.equal(fetch.calls.length, 1);
    assert.equal(event.detail.status, 200);

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
    assert.equal(body.type, 'handleAction');
    assert.equal(body.componentId, COMPONENT_ID);
    assert.equal(body.action, 'updateModel');
    assert.equal(body.csrfToken, CSRF_TOKEN);
    assert.equal(body.signature, 'signed-snapshot');
    assert.deepEqual(body.payload, { email: 'ada@example.com' });
});

test('fiber:model synchronizes textarea edits', async () => {
    const { root, fetch } = setup((document, root) => {
        const textarea = document.createElement('textarea');
        textarea.setAttribute('name', 'bio');
        textarea.setAttribute('fiber:model', 'bio');
        textarea.setAttribute('value', 'hello there');
        root.append(textarea);
    });
    const textarea = root.querySelectorAll('textarea')[0];

    const success = waitForEvent(textarea, 'firefiber:success');
    interact(textarea, 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, { bio: 'hello there' });
});

test('fiber:model keeps repeated checkbox values instead of overwriting them', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(
            createInput(document, 'roles', 'checkbox', 'admin', { model: 'roles', checked: true }),
            createInput(document, 'roles', 'checkbox', 'editor', { model: 'roles', checked: true }),
        );
    });
    const boxes = root.querySelectorAll('input');

    const success = waitForEvent(boxes[0], 'firefiber:success');
    interact(boxes[0], 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, {
        roles: ['admin', 'editor'],
    });
});

test('fiber:model reports an unchecked checkbox as an empty selection', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(createInput(document, 'notify', 'checkbox', 'yes', { model: 'notify' }));
    });
    const checkbox = root.querySelectorAll('input')[0];

    const success = waitForEvent(checkbox, 'firefiber:success');
    interact(checkbox, 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, { notify: [] });
});

test('fiber:model submits only the checked value of a radio group', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(
            createInput(document, 'plan', 'radio', 'basic', { model: 'plan' }),
            createInput(document, 'plan', 'radio', 'pro', { model: 'plan', checked: true }),
        );
    });
    const radios = root.querySelectorAll('input');

    const success = waitForEvent(radios[1], 'firefiber:success');
    interact(radios[1], 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, { plan: 'pro' });
});

test('fiber:model synchronizes a single select', async () => {
    const { root, fetch } = setup((document, root) => {
        const select = document.createElement('select');
        select.setAttribute('name', 'size');
        select.setAttribute('fiber:model', 'size');
        select.append(...createOptions(document, ['small', 'medium', 'large'], ['medium']));
        root.append(select);
    });
    const select = root.querySelectorAll('select')[0];

    const success = waitForEvent(select, 'firefiber:success');
    interact(select, 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, { size: 'medium' });
});

test('fiber:model synchronizes every selected option of a multiple select', async () => {
    const { root, fetch } = setup((document, root) => {
        const select = document.createElement('select');
        select.setAttribute('name', 'tags');
        select.setAttribute('fiber:model', 'tags');
        select.setAttribute('multiple', 'multiple');
        select.append(...createOptions(document, ['one', 'two', 'three'], ['one', 'three']));
        root.append(select);
    });
    const select = root.querySelectorAll('select')[0];

    const success = waitForEvent(select, 'firefiber:success');
    interact(select, 'change');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, {
        tags: ['one', 'three'],
    });
});

test('fiber:model keeps repeated text field names without overwriting values', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(
            createInput(document, 'line', 'text', 'first', { model: 'line' }),
            createInput(document, 'line', 'text', 'second', { model: 'line' }),
        );
    });
    const inputs = root.querySelectorAll('input');

    const success = waitForEvent(inputs[1], 'firefiber:success');
    interact(inputs[1], 'input');
    await success;

    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, {
        line: ['first', 'second'],
    });
});

test('fiber:model ignores disabled controls and excludes them from a group', async () => {
    const { root, fetch } = setup((document, root) => {
        const disabled = createInput(document, 'email', 'text', 'ada@example.com', {
            model: 'email',
        });
        disabled.setAttribute('disabled', 'disabled');

        root.append(
            disabled,
            createInput(document, 'role', 'checkbox', 'admin', {
                model: 'role',
                checked: true,
                disabled: true,
            }),
            createInput(document, 'role', 'checkbox', 'editor', {
                model: 'role',
                checked: true,
            }),
        );
    });
    const inputs = root.querySelectorAll('input');

    const success = waitForEvent(inputs[2], 'firefiber:success');
    interact(inputs[0], 'input');
    interact(inputs[2], 'change');
    await success;

    assert.equal(fetch.calls.length, 1, 'Disabled controls must not dispatch requests');
    assert.deepEqual(JSON.parse(fetch.calls[0].options.body).payload, { role: 'editor' });
});

test('fiber:model sends one request per actual edit', async () => {
    const { root, fetch } = setup((document, root) => {
        root.append(createInput(document, 'email', 'text', 'ada@example.com'));
    });
    const input = root.querySelectorAll('input')[0];

    const success = waitForEvent(input, 'firefiber:success');
    interact(input, 'input');
    interact(input, 'change');
    await success;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(fetch.calls.length, 1, 'input+change for one edit must submit once');
});

test('fiber:model surfaces server validation errors without retrying', async () => {
    const fetch = captureFetch(() => responseFor({
        status: 'error',
        error: 'The email is invalid',
        errorCode: 1,
        errors: { email: ['The email is invalid'] },
    }, 422));
    const { root } = setup((document, root) => {
        root.append(createInput(document, 'email', 'email', 'not-an-email'));
    }, { fetch });
    const input = root.querySelectorAll('input')[0];

    const validation = waitForEvent(input, 'firefiber:validation-error');
    interact(input, 'input');
    const event = await validation;

    assert.equal(event.detail.status, 422);
    assert.equal(event.detail.errorCode, 1);
    assert.equal(fetch.calls.length, 1, 'Validation errors must not be retried');
});

test('fiber:model uses the rotated server snapshot for the next edit', async () => {
    const fetch = captureFetch((callNumber) => responseFor(successPayload(COMPONENT_ID, callNumber + 1)));
    const { root } = setup((document, root) => {
        root.append(createInput(document, 'email', 'text', 'first'));
    }, { fetch });
    const input = root.querySelectorAll('input')[0];

    const first = waitForEvent(input, 'firefiber:success');
    interact(input, 'input');
    await first;

    input.setAttribute('value', 'second');
    const second = waitForEvent(input, 'firefiber:success');
    interact(input, 'change');
    await second;

    assert.equal(fetch.calls.length, 2);

    const body = JSON.parse(fetch.calls[1].options.body);
    assert.equal(body.payload.email, 'second');
    assert.equal(body.signature, 'fresh-snapshot');
    assert.equal(body.snapshot.state.count, 2);
});

test('fiber:model cleans up listeners when a control is removed or the runtime stops', async () => {
    const first = setup((document, root) => {
        root.append(createInput(document, 'email', 'text', 'ada@example.com'));
    });
    const input = first.root.querySelectorAll('input')[0];

    assert.equal(input.listenerCount('input'), 1);
    assert.equal(input.listenerCount('change'), 1);

    const observer = FakeMutationObserver.instances[0];
    first.document.body.removeChild(first.root);
    observer.emit([{ removedNodes: [first.root], addedNodes: [] }]);

    assert.equal(input.listenerCount('input'), 0);
    assert.equal(input.listenerCount('change'), 0);

    interact(input, 'input');
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(first.fetch.calls.length, 0);

    first.runtime.stop();

    const second = setup((document, root) => {
        root.append(createInput(document, 'email', 'text', 'grace@example.com'));
    });
    const secondInput = second.root.querySelectorAll('input')[0];

    assert.equal(secondInput.listenerCount('input'), 1);

    second.runtime.stop();

    assert.equal(secondInput.listenerCount('input'), 0);
    assert.equal(secondInput.listenerCount('change'), 0);

    interact(secondInput, 'input');
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(second.fetch.calls.length, 0);
});
