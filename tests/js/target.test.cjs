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
const COMPONENT_ID = 'ff-target';
const CSRF_TOKEN = 'target-csrf';
const HMAC_KEY = 'phase-4.7-target-test-key';

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

function makePage(markupKey) {
    const document = new FakeDocument();
    document.title = 'Dashboard';

    const page = document.createElement('div');
    page.setAttribute('data-page', markupKey);
    document.body.append(page);

    return document;
}

function componentRoot(document, id = COMPONENT_ID) {
    const root = document.createElement('div');
    root.setAttribute('data-firefiber-component', id);
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot(id)));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);

    return root;
}

function makeIndicator(document, name, options = {}) {
    const element = document.createElement('div');
    element.setAttribute('name', name);

    const applyTarget = () => {
        if (options.targets !== undefined) {
            element.setAttribute('fiber:target', options.targets);
        }
    };

    if (options.targetsFirst) {
        applyTarget();
    }

    if (options.modifier) {
        element.setAttribute(`fiber:loading.${options.modifier}`, options.value || '');
    } else {
        element.setAttribute('fiber:loading', '');
    }

    if (!options.targetsFirst) {
        applyTarget();
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

function submit(form) {
    form.dispatchEvent({ type: 'submit', target: form, preventDefault() {}, stopPropagation() {} });
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
    const fetch = options.fetch || deferredFetch(() => responseFor(successPayload()));
    const pages = options.pages || {};
    const parseHTML = (html) => pages[html] || null;

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

test('fiber:target activates only for its named action', async () => {
    let indicator = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', { targets: 'increment' });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicator, button, form);
            doc.body.append(root);

            return {};
        },
    });

    const submitSuccess = waitForEvent(form, 'firefiber:success');
    submit(form);

    assert.equal(indicator.getAttribute('hidden'), 'hidden', 'Non-matching action must not activate');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');

    const clickSuccess = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), null, 'Matching action must activate');

    await waitForCalls(fetch, 2);
    fetch.releaseAction('increment');
    await clickSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:target accepts multiple comma or space separated actions', async () => {
    for (const targets of ['increment, saveProfile', 'increment saveProfile', 'increment,saveProfile']) {
        let indicator = null;
        let button = null;
        let form = null;

        const { fetch } = setUpRuntime({
            build(doc) {
                const root = componentRoot(doc);
                indicator = makeIndicator(doc, 'spinner', { targets });
                button = doc.createElement('button');
                button.setAttribute('fiber:click', 'increment');
                form = doc.createElement('form');
                form.setAttribute('fiber:submit', 'saveProfile');
                root.append(indicator, button, form);
                doc.body.append(root);

                return {};
            },
        });

        click(button);
        assert.equal(indicator.getAttribute('hidden'), null, `${targets} must include increment`);
        submit(form);
        assert.equal(indicator.getAttribute('hidden'), null, `${targets} must include saveProfile`);

        await waitForCalls(fetch, 2);
        fetch.releaseAction('increment');
        await tick();
        fetch.releaseAction('saveProfile');
        await tick();
    }
});

test('fiber:target ignores non-matching actions entirely', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', { targets: 'deletePost' });
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
    fetch.releaseAction('increment');
    await success;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:target stays active while targeted actions overlap', async () => {
    let indicator = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', { targets: 'increment,saveProfile' });
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
    submit(form);

    await waitForCalls(fetch, 2);
    assert.equal(indicator.getAttribute('hidden'), null);

    fetch.releaseAction('increment');
    await firstSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), null, 'Second targeted action must keep it active');

    fetch.releaseAction('saveProfile');
    await secondSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), 'hidden');
});

test('fiber:target preserves component-root isolation', async () => {
    let indicatorA = null;
    let indicatorB = null;
    let buttonA = null;
    let buttonB = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const rootA = componentRoot(doc, 'ff-target-a');
            indicatorA = makeIndicator(doc, 'spinner-a', { targets: 'increment' });
            buttonA = doc.createElement('button');
            buttonA.setAttribute('fiber:click', 'increment');
            rootA.append(indicatorA, buttonA);

            const rootB = componentRoot(doc, 'ff-target-b');
            indicatorB = makeIndicator(doc, 'spinner-b', { targets: 'increment' });
            buttonB = doc.createElement('button');
            buttonB.setAttribute('fiber:click', 'increment');
            rootB.append(indicatorB, buttonB);

            doc.body.append(rootA, rootB);

            return {};
        },
    });

    click(buttonA);

    assert.equal(indicatorA.getAttribute('hidden'), null);
    assert.equal(indicatorB.getAttribute('hidden'), 'hidden', 'Other component roots must not react');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();

    click(buttonB);

    assert.equal(indicatorA.getAttribute('hidden'), 'hidden');
    assert.equal(indicatorB.getAttribute('hidden'), null);
});

test('fiber:target works with fiber:loading.remove', async () => {
    let indicator = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'content', {
                targets: 'saveProfile',
                modifier: 'remove',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicator, button, form);
            doc.body.append(root);

            return {};
        },
    });

    const clickSuccess = waitForEvent(button, 'firefiber:success');
    click(button);

    assert.equal(indicator.getAttribute('hidden'), null, 'Non-target action must not hide');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await clickSuccess;
    await tick();

    const submitSuccess = waitForEvent(form, 'firefiber:success');
    submit(form);

    assert.equal(indicator.getAttribute('hidden'), 'hidden', 'Target action must hide');

    await waitForCalls(fetch, 2);
    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();

    assert.equal(indicator.getAttribute('hidden'), null);
});

test('fiber:target works with fiber:loading.class', async () => {
    let indicator = null;
    let button = null;
    let form = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                targets: 'increment',
                modifier: 'class',
                value: 'is-loading',
                className: 'spinner',
                hidden: false,
            });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            form = doc.createElement('form');
            form.setAttribute('fiber:submit', 'saveProfile');
            root.append(indicator, button, form);
            doc.body.append(root);

            return {};
        },
    });

    const submitSuccess = waitForEvent(form, 'firefiber:success');
    submit(form);

    assert.equal(indicator.getAttribute('class'), 'spinner', 'Non-target action must not add the class');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('saveProfile');
    await submitSuccess;
    await tick();

    click(button);

    assert.match(indicator.getAttribute('class'), /(^|\s)is-loading(\s|$)/);

    await waitForCalls(fetch, 2);
    fetch.releaseAction('increment');
    await tick();

    assert.equal(indicator.getAttribute('class'), 'spinner');
});

test('fiber:target works regardless of attribute order', async () => {
    let indicator = null;
    let button = null;

    const { fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', { targets: 'increment', targetsFirst: true });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            root.append(indicator, button);
            doc.body.append(root);

            return {};
        },
    });

    click(button);

    assert.equal(indicator.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();
});

test('indicators without fiber:target keep responding to every action and navigation', async () => {
    let untargeted = null;
    let targeted = null;
    let button = null;
    let anchor = null;

    const { fetch, window, document } = setUpRuntime({
        fetch: deferredFetch(() => htmlResponse('page:dashboard')),
        pages: { 'page:dashboard': makePage('dashboard') },
        build(doc) {
            const root = componentRoot(doc);
            untargeted = makeIndicator(doc, 'any');
            targeted = makeIndicator(doc, 'targeted', { targets: 'increment' });
            button = doc.createElement('button');
            button.setAttribute('fiber:click', 'increment');
            anchor = doc.createElement('a');
            anchor.setAttribute('href', '/dashboard');
            anchor.setAttribute('fiber:navigate', '');
            root.append(untargeted, targeted, button, anchor);
            doc.body.append(root);

            return {};
        },
    });

    click(button);

    assert.equal(untargeted.getAttribute('hidden'), null);
    assert.equal(targeted.getAttribute('hidden'), null);

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();

    assert.equal(untargeted.getAttribute('hidden'), 'hidden');
    assert.equal(targeted.getAttribute('hidden'), 'hidden');

    const navigation = waitForEvent(window, 'firefiber:navigate-success');
    click(anchor);

    assert.equal(untargeted.getAttribute('hidden'), null, 'Navigation keeps driving untargeted indicators');
    assert.equal(targeted.getAttribute('hidden'), 'hidden', 'Navigation has no target identity');

    fetch.pending[fetch.pending.length - 1].resolve(htmlResponse('page:dashboard'));
    await navigation;
    await tick();

    assert.equal(untargeted.getAttribute('hidden'), 'hidden');
    assert.equal(document.title, 'Dashboard');
});

test('fiber:target cleans up on element removal and runtime stop', async () => {
    let indicator = null;
    let button = null;

    const { runtime, fetch } = setUpRuntime({
        build(doc) {
            const root = componentRoot(doc);
            indicator = makeIndicator(doc, 'spinner', {
                targets: 'increment',
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

    click(button);
    assert.match(indicator.getAttribute('class'), /(^|\s)is-loading(\s|$)/);

    const parent = indicator.parentNode;
    parent.removeChild(indicator);
    FakeMutationObserver.instances[0].emit([{ removedNodes: [indicator], addedNodes: [] }]);

    assert.equal(indicator.getAttribute('class'), 'spinner', 'Removal must clear only the loading class');

    await waitForCalls(fetch, 1);
    fetch.releaseAction('increment');
    await tick();

    runtime.stop();
    runtime.stop();

    assert.equal(indicator.getAttribute('class'), 'spinner');
});
