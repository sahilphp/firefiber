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
const COMPONENT_ID = 'ff-disabled';
const CSRF_TOKEN = 'disabled-csrf';
const HMAC_KEY = 'phase-4.11-disabled-test-key';

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

function htmlResponse(text) {
    return {
        ok: true,
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
        text: async () => text,
    };
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
    fetch.releaseLast = (response) => {
        const entry = pending[pending.length - 1];
        entry.released = true;
        entry.resolve(response);
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

function click(element) {
    element.dispatchEvent({
        type: 'click',
        target: element,
        button: 0,
        preventDefault() {},
        stopPropagation() {},
    });
}

function submit(form) {
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });
}

function makeTrigger(document, tagName, action, attribute = 'fiber:click') {
    const element = document.createElement(tagName);
    element.setAttribute(attribute, action);

    return element;
}

function makeIndicator(document, options = {}) {
    const element = document.createElement(options.tagName || 'button');
    element.setAttribute('fiber:disabled', '');

    if (options.targets !== undefined) {
        element.setAttribute('fiber:target', options.targets);
    }

    if (options.disabled) {
        element.setAttribute('disabled', options.disabled === true ? 'disabled' : options.disabled);
    }

    if (options.ariaDisabled !== undefined) {
        element.setAttribute('aria-disabled', options.ariaDisabled);
    }

    return element;
}

function makePage() {
    const document = new FakeDocument();
    const page = document.createElement('div');
    page.setAttribute('data-page', 'page');
    document.body.append(page);

    return document;
}

function setUpRuntime(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const window = new FakeElement('window');
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
    const fetch = options.fetch || deferredFetch(() => responseFor(successPayload()));
    const parseHTML = options.parseHTML || (() => null);

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

test('fiber:disabled disables a button while its action is pending and restores it', async () => {
    let button = null;
    let indicator = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            indicator = makeIndicator(doc);
            root.append(button, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await success;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), null);
    assert.equal(button.getAttribute('disabled'), null);
});

test('fiber:disabled leaves an initially disabled element disabled', async () => {
    let trigger = null;
    let indicator = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            trigger = makeTrigger(doc, 'button', 'increment');
            indicator = makeIndicator(doc, { disabled: true });
            root.append(trigger, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(trigger, 'firefiber:success');
    click(trigger);

    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await success;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), 'disabled', 'Original disabled state must be restored');
});

test('fiber:disabled with fiber:target only disables for matching actions', async () => {
    let button = null;
    let form = null;
    let indicator = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            form = makeTrigger(doc, 'form', 'saveProfile', 'fiber:submit');
            indicator = makeIndicator(doc, { targets: 'increment' });
            root.append(button, form, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const submitSuccess = waitForEvent(form, 'firefiber:success');
    submit(form);

    assert.equal(indicator.getAttribute('disabled'), null, 'Non-matching action must not disable');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();

    const clickSuccess = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    await waitForCalls(fetch, 2);
    fetch.releaseAction('increment');
    await clickSuccess;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), null);
});

test('fiber:disabled handles overlapping matching and non-matching actions', async () => {
    let button = null;
    let form = null;
    let indicator = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            form = makeTrigger(doc, 'form', 'saveProfile', 'fiber:submit');
            indicator = makeIndicator(doc, { targets: 'increment,saveProfile' });
            root.append(button, form, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const clickSuccess = waitForEvent(button, 'firefiber:success');
    const submitSuccess = waitForEvent(form, 'firefiber:success');

    click(button);
    submit(form);

    await waitForCalls(fetch, 2);
    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    fetch.releaseAction('increment');
    await clickSuccess;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), 'disabled', 'A matching action is still pending');

    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), null);
});

test('fiber:disabled handles non-matching overlap without extending the disabled state', async () => {
    let button = null;
    let form = null;
    let indicator = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            form = makeTrigger(doc, 'form', 'saveProfile', 'fiber:submit');
            indicator = makeIndicator(doc, { targets: 'increment' });
            root.append(button, form, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const clickSuccess = waitForEvent(button, 'firefiber:success');
    const submitSuccess = waitForEvent(form, 'firefiber:success');

    click(button);
    submit(form);

    await waitForCalls(fetch, 2);
    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    fetch.releaseAction('increment');
    await clickSuccess;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), null, 'Only matching actions hold the disabled state');

    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();
});

test('fiber:disabled stays isolated between component roots', async () => {
    let triggerA = null;
    let triggerB = null;
    let indicatorA = null;
    let indicatorB = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const rootA = componentRoot(doc, 'ff-disabled-a');
            triggerA = makeTrigger(doc, 'button', 'increment');
            indicatorA = makeIndicator(doc);
            rootA.append(triggerA, indicatorA);

            const rootB = componentRoot(doc, 'ff-disabled-b');
            triggerB = makeTrigger(doc, 'button', 'increment');
            indicatorB = makeIndicator(doc);
            rootB.append(triggerB, indicatorB);

            doc.body.append(rootA, rootB);

            return {};
        },
    });

    click(triggerA);

    assert.equal(indicatorA.getAttribute('disabled'), 'disabled');
    assert.equal(indicatorB.getAttribute('disabled'), null);

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();

    click(triggerB);

    assert.equal(indicatorA.getAttribute('disabled'), null);
    assert.equal(indicatorB.getAttribute('disabled'), 'disabled');
});

test('fiber:disabled restores after validation and server errors', async () => {
    let button = null;
    let indicator = null;

    const first = setUpRuntime({
        fetch: deferredFetch(() => responseFor({
            status: 'error',
            error: 'Invalid',
            errorCode: 1,
            errors: { count: ['Invalid count'] },
        }, 422)),
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            indicator = makeIndicator(doc);
            root.append(button, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const validation = waitForEvent(button, 'firefiber:validation-error');
    click(button);

    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    await waitForCalls(first.fetch, 1);
    first.fetch.releaseAction('increment');
    await validation;
    await tick();

    assert.equal(indicator.getAttribute('disabled'), null);

    let serverButton = null;
    let serverIndicator = null;

    const second = setUpRuntime({
        fetch: deferredFetch(() => responseFor({ status: 'error', error: 'boom' }, 500)),
        build(doc) {
            const root = componentRoot(doc);
            serverButton = makeTrigger(doc, 'button', 'increment');
            serverIndicator = makeIndicator(doc);
            root.append(serverButton, serverIndicator);
            doc.body.append(root);

            return {};
        },
    });

    const failure = waitForEvent(serverButton, 'firefiber:error');
    click(serverButton);

    assert.equal(serverIndicator.getAttribute('disabled'), 'disabled');

    await waitForCalls(second.fetch, 1);
    second.fetch.releaseAction('increment');
    await failure;
    await tick();

    assert.equal(serverIndicator.getAttribute('disabled'), null);
});

test('fiber:disabled does not disable during navigation', async () => {
    let anchor = null;
    let untargeted = null;
    let targeted = null;

    const { fetch, window } = setUpRuntime({
        fetch: deferredFetch(() => htmlResponse('page')),
        parseHTML: () => makePage(),
        build(doc) {
            const root = componentRoot(doc);
            anchor = doc.createElement('a');
            anchor.setAttribute('href', '/next');
            anchor.setAttribute('fiber:navigate', '');
            untargeted = makeIndicator(doc);
            targeted = makeIndicator(doc, { targets: 'increment' });
            root.append(anchor, untargeted, targeted);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(window, 'firefiber:navigate-success');
    click(anchor);

    assert.equal(untargeted.getAttribute('disabled'), null, 'Navigation must not disable');
    assert.equal(targeted.getAttribute('disabled'), null, 'Navigation has no explicit target');

    await waitForCalls(fetch, 1);
    fetch.releaseLast(htmlResponse('page'));
    await success;
    await tick();

    assert.equal(untargeted.getAttribute('disabled'), null);
    assert.equal(targeted.getAttribute('disabled'), null);
});

test('fiber:disabled uses aria-disabled for non-disableable elements and restores it', async () => {
    let trigger = null;
    let element = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            trigger = makeTrigger(doc, 'button', 'increment');
            element = makeIndicator(doc, { tagName: 'div', ariaDisabled: 'false' });
            root.append(trigger, element);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(trigger, 'firefiber:success');
    click(trigger);

    assert.equal(element.getAttribute('disabled'), null, 'Non-disableable elements must not get disabled');
    assert.equal(element.getAttribute('aria-disabled'), 'true');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await success;
    await tick();

    assert.equal(element.getAttribute('aria-disabled'), 'false', 'Original aria-disabled must be restored');
    assert.equal(element.getAttribute('disabled'), null);
});

test('fiber:disabled restores on element removal and runtime stop', async () => {
    let button = null;
    let indicator = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            indicator = makeIndicator(doc);
            root.append(button, indicator);
            doc.body.append(root);

            return {};
        },
    });

    const success = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('disabled'), 'disabled');

    const parent = indicator.parentNode;
    parent.removeChild(indicator);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [indicator], addedNodes: [] }]);

    assert.equal(indicator.getAttribute('disabled'), null, 'Removal must restore the original state');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await success;
    await tick();

    const second = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            const trigger = makeTrigger(doc, 'button', 'increment');
            const target = makeIndicator(doc);
            root.append(trigger, target);
            doc.body.append(root);

            return { trigger, target };
        },
    });

    const secondSuccess = waitForEvent(second.trigger, 'firefiber:success');
    click(second.trigger);

    assert.equal(second.target.getAttribute('disabled'), 'disabled');

    await waitForCalls(second.fetch, 1);

    second.runtime.stop();
    second.runtime.stop();

    assert.equal(second.target.getAttribute('disabled'), null, 'Stop must restore the original state');

    second.fetch.releaseAction('increment');
    await secondSuccess;
    await tick();

    assert.equal(second.target.getAttribute('disabled'), null);
});

test('elements without fiber:disabled remain unchanged', async () => {
    let button = null;
    let other = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            button = makeTrigger(doc, 'button', 'increment');
            other = doc.createElement('button');
            root.append(button, other);
            doc.body.append(root);

            return {};
        },
    });

    click(button);

    assert.equal(other.getAttribute('disabled'), null);
    assert.equal(button.getAttribute('disabled'), null);

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();

    assert.equal(other.getAttribute('disabled'), null);
});
