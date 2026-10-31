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
const COMPONENT_ID = 'ff-dirty';
const CSRF_TOKEN = 'dirty-csrf';
const HMAC_KEY = 'phase-4.12-dirty-test-key';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function makeSnapshot(state = { count: 1 }, signature = 'signed-snapshot', componentId = COMPONENT_ID) {
    return {
        componentId,
        state,
        timestamp: Math.floor(Date.now() / 1000),
        protocolVersion: '3.1',
        signature,
    };
}

function ackPayload(state, componentId = COMPONENT_ID) {
    return {
        status: 'ok',
        componentId,
        action: 'updateModel',
        result: { updated: state },
        snapshot: {
            componentId,
            state,
            timestamp: Math.floor(Date.now() / 1000),
            protocolVersion: '3.1',
            signature: 'ack-snapshot',
        },
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

function deferredFetch(defaultResponse = () => responseFor(ackPayload({ email: 'new@example.com' }))) {
    const calls = [];
    const pending = [];

    const fetch = (url, options) => {
        calls.push({ url, options });

        return new Promise((resolve, reject) => {
            pending.push({ url, options, resolve, reject, released: false });
        });
    };

    fetch.calls = calls;
    fetch.pending = pending;
    fetch.releaseAction = (action, response) => releaseAction(fetch, action, response);

    return fetch;
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

async function waitForCalls(fetch, count = 1) {
    const deadline = Date.now() + 1000;

    while (fetch.calls.length < count) {
        if (Date.now() > deadline) {
            throw new Error(`Timed out waiting for ${count} request(s); saw ${fetch.calls.length}`);
        }

        await new Promise((resolve) => setTimeout(resolve, 1));
    }
}

function componentRoot(document) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot({ email: 'old@example.com' })));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

function makeInput(document, name, type, value, options = {}) {
    const input = document.createElement('input');
    input.setAttribute('type', type);
    input.setAttribute('name', name);
    input.setAttribute('fiber:model', options.model || name);

    if (value !== undefined) {
        input.setAttribute('value', value);
    }

    if (options.checked) {
        input.setAttribute('checked', 'checked');
    }

    if (options.dirtyAttr) {
        input.setAttribute(options.dirtyAttr, options.dirtyValue || '');
    }

    return input;
}

function dispatch(element, type = 'change') {
    element.dispatchEvent({ type, target: element, preventDefault() {}, stopPropagation() {} });
}

function classTokens(element) {
    const value = element.getAttribute('class');

    return value === null || value === undefined ? [] : value.split(/\s+/).filter(Boolean);
}

function releaseAction(fetch, action, response) {
    const entry = fetch.pending.find((candidate) => {
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
    entry.resolve(typeof response === 'function' ? response(entry.url) : response);
}

function setUpRuntime(build, overrideFetch = null) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const built = build(document) || {};

    const fetch = overrideFetch || deferredFetch();

    runtime.configure({
        componentId: COMPONENT_ID,
        snapshot: makeSnapshot(),
        endpoint: 'https://firefiber.test/firefiber',
        csrfToken: CSRF_TOKEN,
        hmacKey: HMAC_KEY,
        protocolVersion: '3.1',
        fetch,
        releaseAction,
    });
    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        fetch,
        releaseAction,
    });

    return { runtime, document, fetch, ...built };
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

test('fiber:dirty.class marks a text input dirty and clears after acknowledgement', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    assert.deepEqual(classTokens(input), []);

    input.setAttribute('value', 'new@example.com');
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'), 'Dirty state must appear immediately');

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(input), [], 'Acknowledgement must reset the baseline');
});

test('fiber:dirty.class uses fiber-dirty by default', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('fiber-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(input), []);
});

test('fiber:dirty tracks textarea changes against the acknowledged value', async () => {
    const { textarea, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const textarea = doc.createElement('textarea');
        textarea.setAttribute('name', 'bio');
        textarea.setAttribute('fiber:model', 'bio');
        textarea.setAttribute('fiber:dirty.class', 'is-dirty');
        textarea.setAttribute('value', 'hello');
        root.append(textarea);
        doc.body.append(root);

        return { textarea };
    });

    textarea.setAttribute('value', 'hello world');
    const success = waitForEvent(textarea, 'firefiber:success');
    dispatch(textarea, 'input');

    assert.ok(classTokens(textarea).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ bio: 'hello world' })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(textarea), []);
});

test('fiber:dirty tracks repeated checkbox names', async () => {
    const { admin, editor, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const admin = makeInput(doc, 'roles', 'checkbox', 'admin', {
            checked: true,
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        const editor = makeInput(doc, 'roles', 'checkbox', 'editor');
        root.append(admin, editor);
        doc.body.append(root);

        return { admin, editor };
    });

    editor.setAttribute('checked', 'checked');
    const success = waitForEvent(editor, 'firefiber:success');
    dispatch(editor, 'change');

    assert.ok(classTokens(admin).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ roles: ['admin', 'editor'] })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(admin), []);
});

test('fiber:dirty tracks radio groups', async () => {
    const { basic, pro, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const basic = makeInput(doc, 'plan', 'radio', 'basic', { checked: true });
        const pro = makeInput(doc, 'plan', 'radio', 'pro', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(basic, pro);
        doc.body.append(root);

        return { basic, pro };
    });

    basic.removeAttribute('checked');
    pro.setAttribute('checked', 'checked');
    const success = waitForEvent(pro, 'firefiber:success');
    dispatch(pro, 'change');

    assert.ok(classTokens(pro).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ plan: 'pro' })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(pro), []);
});

test('fiber:dirty tracks single and multiple selects', async () => {
    const { single, multiple, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);

        const single = doc.createElement('select');
        single.setAttribute('name', 'size');
        single.setAttribute('fiber:model', 'size');
        single.setAttribute('fiber:dirty.class', 'is-dirty');
        const medium = doc.createElement('option');
        medium.setAttribute('value', 'medium');
        medium.setAttribute('selected', 'selected');
        const large = doc.createElement('option');
        large.setAttribute('value', 'large');
        single.append(medium, large);

        const multiple = doc.createElement('select');
        multiple.setAttribute('name', 'tags');
        multiple.setAttribute('fiber:model', 'tags');
        multiple.setAttribute('multiple', 'multiple');
        multiple.setAttribute('fiber:dirty.class', 'is-dirty');
        const one = doc.createElement('option');
        one.setAttribute('value', 'one');
        one.setAttribute('selected', 'selected');
        const two = doc.createElement('option');
        two.setAttribute('value', 'two');
        multiple.append(one, two);

        root.append(single, multiple);
        doc.body.append(root);

        return { single, multiple };
    });

    function selectOption(select, value, keepExisting = false) {
        for (const option of select.children) {
            if (option.getAttribute('value') === value) {
                option.setAttribute('selected', 'selected');
            } else if (!keepExisting) {
                option.removeAttribute('selected');
            }
        }
    }

    selectOption(single, 'large');
    const singleSuccess = waitForEvent(single, 'firefiber:success');
    dispatch(single, 'change');

    assert.ok(classTokens(single).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const singleAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ size: 'large' })));
    await singleSuccess;
    await singleAck;
    await tick();

    assert.deepEqual(classTokens(single), []);

    const secondCall = fetch.calls.length;
    selectOption(multiple, 'two', true);
    const multipleSuccess = waitForEvent(multiple, 'firefiber:success');
    dispatch(multiple, 'change');

    assert.ok(classTokens(multiple).includes('is-dirty'));

    await waitForCalls(fetch, secondCall + 1);
    const multipleAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ tags: ['one', 'two'] })));
    await multipleSuccess;
    await multipleAck;
    await tick();

    assert.deepEqual(classTokens(multiple), []);
});

test('fiber:dirty keeps dirty state after validation errors', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'bad-email');
    const validation = waitForEvent(input, 'firefiber:validation-error');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor({
        status: 'error',
        error: 'Invalid email',
        errorCode: 1,
        errors: { email: ['Invalid email'] },
    }, 422));
    await validation;
    await ackPromise;
    await tick();

    assert.ok(classTokens(input).includes('is-dirty'), 'Rejected values stay dirty');
});

test('fiber:dirty keeps dirty state after network errors', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    if (input.getAttribute('fiber:model') === '') {
        input.setAttribute('data-firefiber-model', input.getAttribute('name'));
    }
    const failure = waitForEvent(input, 'firefiber:error');
    dispatch(input, 'input');

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', Promise.reject(new Error('network down')));
    await failure;
    await ackPromise;
    await tick();

    assert.ok(classTokens(input).includes('is-dirty'), 'Failed requests stay dirty');
});

test('fiber:dirty keeps dirty state after an HTTP 200 rejection body', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    const failure = waitForEvent(input, 'firefiber:error');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor({
        status: 'error',
        componentId: COMPONENT_ID,
        action: 'updateModel',
        error: 'Rejected',
        errorCode: 9,
    }));
    await failure;
    await ackPromise;
    await tick();

    assert.ok(classTokens(input).includes('is-dirty'), 'Only an acknowledged action resets the baseline');
});

test('fiber:dirty.class removes only its own token', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        input.setAttribute('class', 'base other-theme');
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    if (input.getAttribute('fiber:model') === '') {
        input.setAttribute('data-firefiber-model', input.getAttribute('name'));
    }
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.deepEqual(classTokens(input), ['base', 'other-theme', 'is-dirty']);

    // Another script updates the class list while the request is pending.
    input.setAttribute('class', 'base is-dirty theme-updated');

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(input), ['base', 'theme-updated']);
});

test('fiber:dirty.remove hides a standalone indicator only while dirty', async () => {
    const { input, indicator, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com');
        const indicator = doc.createElement('div');
        indicator.setAttribute('fiber:dirty.remove', 'email');
        root.append(input, indicator);
        doc.body.append(root);

        return { input, indicator };
    });

    assert.equal(indicator.getAttribute('hidden'), null, 'Clean state is visible');

    input.setAttribute('value', 'new@example.com');
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.equal(indicator.getAttribute('hidden'), 'hidden', 'Dirty state hides .remove indicators');

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ackPromise;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), null);
});

test('fiber:dirty shows a hidden standalone indicator while dirty', async () => {
    const { input, indicator, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com');
        const indicator = doc.createElement('div');
        indicator.setAttribute('fiber:dirty', 'email');
        indicator.setAttribute('hidden', 'hidden');
        root.append(input, indicator);
        doc.body.append(root);

        return { input, indicator };
    });

    input.setAttribute('value', 'new@example.com');
    dispatch(input, 'input');

    assert.equal(indicator.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    const pending = fetch.pending[0];

    pending.resolve(responseFor(ackPayload({ email: 'new@example.com' })));
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:dirty restores its class when the element is removed', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    if (input.getAttribute('fiber:model') === '') {
        input.setAttribute('data-firefiber-model', input.getAttribute('name'));
    }
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'));

    const parent = input.parentNode;
    parent.removeChild(input);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [input], addedNodes: [] }]);

    assert.deepEqual(classTokens(input), [], 'Removal must clear the dirty class');

    await waitForCalls(fetch, 1);
    const ackPromise = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ackPromise;
    await tick();

    assert.deepEqual(classTokens(input), [], 'A late acknowledgement must not re-add the class');
});

test('fiber:dirty restores its class on runtime stop', async () => {
    const { runtime, input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    if (input.getAttribute('fiber:model') === '') {
        input.setAttribute('data-firefiber-model', input.getAttribute('name'));
    }

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'));

    runtime.stop();
    runtime.stop();

    assert.deepEqual(classTokens(input), [], 'Stop must clear the dirty class');

    await waitForCalls(fetch, 1);
    const stopAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await stopAck;

    assert.deepEqual(classTokens(input), [], 'A late acknowledgement must not re-add the class after stop');
});

test('fiber:dirty clears before firefiber:success reaches listeners', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');

    let classesDuringSuccess = null;
    input.addEventListener('firefiber:success', () => {
        classesDuringSuccess = classTokens(input).slice();
    });

    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    await waitForCalls(fetch, 1);
    const ack = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ack;

    assert.deepEqual(classesDuringSuccess, [], 'The baseline must advance before observers run');
    assert.deepEqual(classTokens(input), []);
});

test('fiber:dirty compares against the last acknowledged value, not the initial HTML', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    const firstSuccess = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    await waitForCalls(fetch, 1);
    const firstAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await firstSuccess;
    await firstAck;

    assert.deepEqual(classTokens(input), []);

    // Going back to the server-rendered value is still an unsaved change now.
    input.setAttribute('value', 'old@example.com');
    const secondSuccess = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.ok(classTokens(input).includes('is-dirty'), 'The acknowledged value is the clean baseline');

    await waitForCalls(fetch, 2);
    const secondAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'old@example.com' })));
    await secondSuccess;
    await secondAck;

    assert.deepEqual(classTokens(input), []);
});

test('fiber:dirty groups repeated text field names like fiber:model', async () => {
    const { first, second, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const first = makeInput(doc, 'line', 'text', 'first', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        const second = makeInput(doc, 'line', 'text', 'second', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        });
        root.append(first, second);
        doc.body.append(root);

        return { first, second };
    });

    second.setAttribute('value', 'second-edit');
    const success = waitForEvent(second, 'firefiber:success');
    dispatch(second, 'change');

    assert.ok(classTokens(first).includes('is-dirty'));
    assert.ok(classTokens(second).includes('is-dirty'));

    await waitForCalls(fetch, 1);
    const ack = fetch.releaseAction('updateModel', responseFor(ackPayload({ line: ['first', 'second-edit'] })));
    await success;
    await ack;

    assert.deepEqual(classTokens(first), []);
    assert.deepEqual(classTokens(second), []);

    first.setAttribute('value', 'first-edit');
    const nextSuccess = waitForEvent(first, 'firefiber:success');
    dispatch(first, 'input');

    assert.ok(classTokens(first).includes('is-dirty'));
    assert.ok(classTokens(second).includes('is-dirty'));

    await waitForCalls(fetch, 2);
    const nextAck = fetch.releaseAction('updateModel', responseFor(ackPayload({ line: ['first-edit', 'second-edit'] })));
    await nextSuccess;
    await nextAck;

    assert.deepEqual(classTokens(first), []);
    assert.deepEqual(classTokens(second), []);
});

test('fiber:dirty.class preserves a class that was already present at bind time', async () => {
    const { input, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
        });
        input.setAttribute('class', 'base fiber-dirty');
        root.append(input);
        doc.body.append(root);

        return { input };
    });

    input.setAttribute('value', 'new@example.com');
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.deepEqual(classTokens(input), ['base', 'fiber-dirty']);

    await waitForCalls(fetch, 1);
    const ack = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ack;

    assert.deepEqual(classTokens(input), ['base', 'fiber-dirty'], 'The page still owns its original class');
});

test('fiber:dirty sends no requests of its own', async () => {
    const { runtime, document, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        root.append(makeInput(doc, 'email', 'text', 'old@example.com', {
            dirtyAttr: 'fiber:dirty.class',
            dirtyValue: 'is-dirty',
        }));
        doc.body.append(root);

        return {};
    });

    await tick();
    runtime.scan(document);
    await tick();

    assert.equal(fetch.calls.length, 0);
});

test('fiber:dirty.remove restores and cleans up when the indicator is removed', async () => {
    const { input, indicator, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com');
        const indicator = doc.createElement('div');
        indicator.setAttribute('fiber:dirty.remove', 'email');
        root.append(input, indicator);
        doc.body.append(root);

        return { input, indicator };
    });

    input.setAttribute('value', 'new@example.com');
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.equal(indicator.getAttribute('hidden'), 'hidden');

    const parent = indicator.parentNode;
    parent.removeChild(indicator);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [indicator], addedNodes: [] }]);

    assert.equal(indicator.getAttribute('hidden'), null, 'Removal must restore visibility');

    await waitForCalls(fetch, 1);
    const ack = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ack;

    assert.equal(indicator.getAttribute('hidden'), null, 'A late acknowledgement must not hide it again');
});

test('fiber:dirty.remove restores visibility when the runtime stops while dirty', async () => {
    const { runtime, input, indicator, fetch } = setUpRuntime((doc) => {
        const root = componentRoot(doc);
        const input = makeInput(doc, 'email', 'text', 'old@example.com');
        const indicator = doc.createElement('div');
        indicator.setAttribute('fiber:dirty.remove', 'email');
        indicator.setAttribute('class', 'status');
        root.append(input, indicator);
        doc.body.append(root);

        return { input, indicator };
    });

    input.setAttribute('value', 'new@example.com');
    const success = waitForEvent(input, 'firefiber:success');
    dispatch(input, 'input');

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
    assert.deepEqual(classTokens(indicator), ['status']);

    runtime.stop();
    runtime.stop();

    assert.equal(indicator.getAttribute('hidden'), null);
    assert.deepEqual(classTokens(indicator), ['status']);

    await waitForCalls(fetch, 1);
    const ack = fetch.releaseAction('updateModel', responseFor(ackPayload({ email: 'new@example.com' })));
    await success;
    await ack;

    assert.equal(indicator.getAttribute('hidden'), null);
});
