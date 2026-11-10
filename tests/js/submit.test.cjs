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
const HMAC_KEY = 'phase-4.2-submit-test-key';
const COMPONENT_ID = 'ff-submit';
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
        action: 'saveProfile',
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

function buildForm(document, payload, attributes = {}) {
    const form = document.createElement('form');
    form.setAttribute('fiber:submit', 'saveProfile');

    // Every typed control is an <input> element carrying its type; select and
    // textarea use their own tags. Checkboxes/radios without an explicit value
    // fall back to their control name so the serialized payload stays simple.
    const createInput = (name, type, value, checked = false) => {
        const el = document.createElement('input');
        el.setAttribute('type', type);
        el.setAttribute('name', name);
        el.setAttribute('value', value === undefined ? name : value);
        if (checked) el.setAttribute('checked', 'checked');
        form.append(el);
        return el;
    };

    const createOptions = (values, selectedValues) => values.map((value) => {
        const option = document.createElement('option');
        option.setAttribute('value', value);
        if (selectedValues.includes(value)) {
            option.setAttribute('selected', 'selected');
        }
        return option;
    });

    createInput('text-field', 'text', 'text-field');
    createInput('checked-checkbox', 'checkbox', 'checked-checkbox', true);
    // An unchecked checkbox is not a successful control; it must be omitted.
    createInput('multi-check', 'checkbox', 'multi-check', false);

    // Radio group: only the checked member contributes a value.
    createInput('checked-radio', 'radio', 'a', false);
    createInput('checked-radio', 'radio', 'b', true);

    const singleSelect = document.createElement('select');
    singleSelect.setAttribute('name', 'single-select');
    singleSelect.append(...createOptions(['one', 'two', 'three'], ['two']));
    form.append(singleSelect);

    const multiSelect = document.createElement('select');
    multiSelect.setAttribute('name', 'multi-select');
    multiSelect.setAttribute('multiple', 'multiple');
    multiSelect.append(...createOptions(['one', 'two', 'three'], ['one', 'two', 'three']));
    form.append(multiSelect);

    const textArea = document.createElement('textarea');
    textArea.setAttribute('name', 'text-area');
    textArea.setAttribute('value', 'hello');
    form.append(textArea);

    createInput('repeated-checkbox', 'checkbox', 'x', true);
    createInput('repeated-checkbox', 'checkbox', 'y', true);

    for (const [name, value] of Object.entries(attributes)) {
        form.setAttribute(name, value);
    }

    return form;
}

function expectedRequestSignature(body) {
    return crypto.createHmac('sha256', HMAC_KEY).update(body).digest('base64');
}

function setupForm(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const root = document.createElement('div');
    const form = buildForm(document, {}, options.attributes);

    root.append(form);
    document.body.append(root);

    const fetch = options.fetch || captureFetch(() => responseFor(successPayload()));
    const action = options.action || 'saveProfile';
    form.setAttribute('fiber:submit', action);

    if (options.modifiers) {
        form.setAttribute('fiber:submit', `${action}.${options.modifiers}`);
    }

    // DEBUG: inspect the form controls and the multi-select options
    console.error('DEBUG form.children in setupForm:', form.children.map((c) => c.tagName).join(','));
    // DEBUG: inspect the multi-select control
    const ms = form.querySelectorAll('select')[0];
    console.error('DEBUG ms.children.length (in setupForm) =', ms.children ? ms.children.length : 'n/a');
    runtime.configure({
        componentId: options.componentId || COMPONENT_ID,
        snapshot: options.snapshot || makeSnapshot(),
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
        form,
        fetch,
        snapshot: options.snapshot || makeSnapshot(),
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

test('fiber:submit dispatches the named action and serializes the form controls', async () => {
    const { form, fetch } = setupForm({
        attributes: {
            'data-firefiber-component': COMPONENT_ID,
            'data-firefiber-snapshot': JSON.stringify(makeSnapshot()),
            'data-firefiber-csrf': CSRF_TOKEN,
        },
    });

    const success = waitForEvent(form, 'firefiber:success');
    form.dispatchEvent({
        type: 'submit',
        target: form,
        preventDefault() {},
        stopPropagation() {},
    });

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
    assert.equal(body.protocolVersion, '3.1');
    assert.equal(body.type, 'handleAction');
    assert.match(body.requestId, /^ff-req-/);
    assert.equal(body.componentId, COMPONENT_ID);
    assert.equal(body.action, 'saveProfile');
    assert.equal(body.csrfToken, CSRF_TOKEN);


    // DEBUG
    const ms = form.querySelectorAll('select')[0];
    console.error('DEBUG multiSelect children.length =', ms.children ? ms.children.length : 'N/A');
    console.error('DEBUG multiSelect children:', JSON.stringify(ms.children ? ms.children.map(function (c) { return { v: c.getAttribute('value'), s: c.getAttribute('selected') }; }) : []));
    console.error('DEBUG form.children.length =', form.children ? form.children.length : 'no-children-prop');
    console.error('DEBUG form.children tags:', form.children ? form.children.map(function (c) { return c.tagName; }).join(',') : 'N/A');
    console.error('DEBUG form.elements.length =', form.elements.length);
    console.error('DEBUG form.elements names:', form.elements.map(function (e) { return e.getAttribute('name'); }).join(','));
    console.error('DEBUG form.elements[0] =', form.elements[0] && form.elements[0].tagName, 'name=', form.elements[0] && form.elements[0].getAttribute('name'));
    assert.deepEqual(body.payload, {
        'text-field': 'text-field',
        'checked-checkbox': 'checked-checkbox',
        'checked-radio': 'b',
        'single-select': 'two',
        'multi-select': ['one', 'two', 'three'],
        'text-area': 'hello',
        'repeated-checkbox': ['x', 'y'],
    });
});

test('fiber:submit rejects invalid CSRF tokens', async () => {
    const fetch = captureFetch(() => responseFor({ status: 'error', error: 'forbidden', errorCode: 2 }, 403));
    const { form, fetch: fetcher } = setupForm({ fetch, attributes: { 'data-firefiber-csrf': 'wrong-csrf' } });

    const failure = waitForEvent(form, 'firefiber:error');
    form.dispatchEvent({
        type: 'submit',
        target: form,
        preventDefault() {},
        stopPropagation() {},
    });

    const event = await failure;
    assert.equal(event.detail.status, 403);
    assert.equal(event.detail.errorCode, 2);
    assert.equal(fetcher.calls.length, 1, 'CSRF rejection must send exactly one request');
});

test('fiber:submit rejects server errors without retrying', async () => {
    const fetch = captureFetch(() => responseFor({ status: 'error', error: 'boom', errorCode: 4 }, 500));
    const { form, fetch: fetcher } = setupForm({ fetch });

    const failure = waitForEvent(form, 'firefiber:error');
    form.dispatchEvent({
        type: 'submit',
        target: form,
        preventDefault() {},
        stopPropagation() {},
    });

    const event = await failure;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(event.detail.status, 500);
    assert.equal(event.detail.errorCode, 4);
    assert.equal(fetcher.calls.length, 1, 'Server errors must not be retried');
});

test('fiber:submit re-synchronises an expired snapshot and replays the submit once', async () => {
    const fetch = captureFetch((call) => {
        if (call === 1) {
            return responseFor({ status: 'error', error: 'expired', errorCode: 5 }, 409);
        }

        if (call === 2) {
            return responseFor({
                status: 'snapshot',
                componentId: COMPONENT_ID,
                state: { count: 1 },
                snapshot: makeSnapshot(COMPONENT_ID, 1, 'resynced-form-snapshot'),
            });
        }

        return responseFor(successPayload(COMPONENT_ID, 2));
    });
    const { form, fetch: fetcher } = setupForm({ fetch });

    const resynced = waitForEvent(form, 'firefiber:resync');
    const success = waitForEvent(form, 'firefiber:success');
    form.dispatchEvent({
        type: 'submit',
        target: form,
        preventDefault() {},
        stopPropagation() {},
    });

    const resyncEvent = await resynced;
    const event = await success;

    assert.equal(resyncEvent.detail.errorCode, 5);
    assert.equal(event.detail.status, 200);
    assert.equal(fetcher.calls.length, 3, 'submit, getSnapshot, replayed submit');
    assert.equal(JSON.parse(fetcher.calls[1].options.body).type, 'getSnapshot');
    assert.equal(
        JSON.parse(fetcher.calls[2].options.body).snapshot.signature,
        'resynced-form-snapshot',
    );
});

test('fiber:submit supports .prevent and .once modifiers', async () => {
    const { form, fetch } = setupForm({ modifiers: 'prevent.once' });

    const prevented = [];
    const stopped = [];
    const events = [];
    for (const type of ['submit', 'click']) {
        // capture the dispatch-level preventDefault when the runtime fires it
    }
    const eventState = {
        prevented: 0,
        stopped: 0,
    };
    form.dispatchEvent({
        type: 'submit',
        target: form,
        preventDefault() { eventState.prevented++; },
        stopPropagation() { eventState.stopped++; },
    });

    await waitForEvent(form, 'firefiber:success');
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(eventState.prevented, 1);
    assert.equal(eventState.stopped, 0);
    assert.equal(fetch.calls.length, 1);
});
test('fiber:submit prevents accidental duplicate submissions while pending', async () => {
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
    const { form } = setupForm({ fetch });

    const first = waitForEvent(form, 'firefiber:success');
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });
    await fetchStarted;

    assert.equal(fetch.calls.length, 1, 'Pending duplicate must not submit again');

    resolveFetch(responseFor(successPayload()));
    await first;

    assert.equal(fetch.calls.length, 1, 'A resolved pending submission must not trigger another request');
});

test('fiber:submit preserves the unchanged third-party handlers and markup', async () => {
    const { form, fetch } = setupForm();
    let thirdParty = 0;
    form.addEventListener('submit', () => { thirdParty++; });

    const success = waitForEvent(form, 'firefiber:success');
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });
    await success;
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(thirdParty, 1);
    assert.equal(form.querySelectorAll('input').length > 0, true);
});
