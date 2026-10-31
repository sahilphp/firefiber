'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');

class FakeElement {
    constructor(tagName) {
        this.nodeType = 1;
        this.tagName = String(tagName || 'div').toUpperCase();
        this.children = [];
        this.parentNode = null;
        this.ownerDocument = null;
        this.attributes = [];
        this.listeners = new Map();
    }

    setAttribute(name, value) {
        const existing = this.attributes.find((attribute) => attribute.name === name);

        if (existing) {
            existing.value = String(value);
        } else {
            this.attributes.push({ name, value: String(value) });
        }
    }

    getAttribute(name) {
        const attribute = this.attributes.find((entry) => entry.name === name);

        return attribute ? attribute.value : null;
    }

    getAttributeNames() {
        throw new Error('Runtime must inspect the attributes collection, not getAttributeNames()');
    }

    append(child) {
        child.parentNode = this;
        this.children.push(child);

        if (this.ownerDocument) {
            child.setOwnerDocument(this.ownerDocument);
        }

        return child;
    }

    setOwnerDocument(document) {
        this.ownerDocument = document;

        for (const child of this.children) {
            child.setOwnerDocument(document);
        }
    }

    querySelectorAll(selector) {
        if (selector !== '*') {
            throw new Error(`Unexpected selector: ${selector}`);
        }

        const matches = [];
        const visit = (node) => {
            for (const child of node.children) {
                matches.push(child);
                visit(child);
            }
        };

        visit(this);

        return matches;
    }

    addEventListener(event, listener, options) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }

        this.listeners.get(event).push({ listener, options });
    }

    removeEventListener(event, listener) {
        const listeners = this.listeners.get(event);

        if (!listeners) {
            return;
        }

        this.listeners.set(
            event,
            listeners.filter((entry) => entry.listener !== listener),
        );
    }

    dispatchEvent(event) {
        const listeners = (this.listeners.get(event.type) || []).slice();

        for (const entry of listeners) {
            entry.listener.call(this, event);
        }

        return true;
    }

    listenerCount(event) {
        return (this.listeners.get(event) || []).length;
    }
}

class FakeDocument {
    constructor() {
        this.nodeType = 9;
        this.documentElement = new FakeElement('html');
        this.documentElement.ownerDocument = this;
        this.body = this.documentElement.append(new FakeElement('body'));
    }

    createElement(tagName) {
        const element = new FakeElement(tagName);
        element.ownerDocument = this;

        return element;
    }

    querySelectorAll(selector) {
        return this.documentElement.querySelectorAll(selector);
    }
}

class FakeMutationObserver {
    static instances = [];

    constructor(callback) {
        this.callback = callback;
        this.observeCalls = [];
        this.disconnectCalls = 0;
        FakeMutationObserver.instances.push(this);
    }

    observe(target, options) {
        this.observeCalls.push({ target, options });
    }

    disconnect() {
        this.disconnectCalls++;
    }

    emit(mutations) {
        this.callback(mutations, this);
    }
}

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

beforeEach(() => {
    FakeMutationObserver.instances = [];
});

afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') {
        globalThis.Firefiber.stop();
    }

    delete globalThis.Firefiber;
});

test('initializes explicitly and binds registered directives without network requests', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:probe', 'increment');
    document.body.append(button);

    let handlerCalls = 0;
    let fetchCalls = 0;
    const originalFetch = globalThis.fetch;

    globalThis.fetch = () => {
        fetchCalls++;

        return Promise.reject(new Error('network request must not be made'));
    };

    runtime.directives.register('probe', {
        event: 'click',
        handler(event, context) {
            handlerCalls++;
            assert.equal(event.type, 'click');
            assert.equal(context.element, button);
            assert.equal(context.value, 'increment');
        },
    });

    assert.equal(runtime.isBooted(), false);
    assert.equal(button.listenerCount('click'), 0);

    const result = boot(runtime, document);

    assert.equal(result.booted, true);
    assert.equal(runtime.isBooted(), true);
    assert.equal(button.listenerCount('click'), 1);
    assert.equal(FakeMutationObserver.instances.length, 1);
    assert.equal(FakeMutationObserver.instances[0].observeCalls.length, 1);

    button.dispatchEvent({ type: 'click' });

    assert.equal(handlerCalls, 1);
    assert.equal(fetchCalls, 0);

    if (originalFetch === undefined) {
        delete globalThis.fetch;
    } else {
        globalThis.fetch = originalFetch;
    }
});

test('repeated initialization and rescans do not duplicate listeners or observers', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:probe', 'value');
    document.body.append(button);

    let handlerCalls = 0;
    runtime.directives.register('probe', {
        event: 'click',
        handler() {
            handlerCalls++;
        },
    });

    const firstBoot = boot(runtime, document);
    const secondBoot = boot(runtime, document);

    runtime.scan(document);
    runtime.scan(document);

    assert.equal(firstBoot, secondBoot);
    assert.equal(FakeMutationObserver.instances.length, 1);
    assert.equal(FakeMutationObserver.instances[0].observeCalls.length, 1);
    assert.equal(button.listenerCount('click'), 1);

    button.dispatchEvent({ type: 'click' });

    assert.equal(handlerCalls, 1);
});

test('binds multiple directives on one element with independent handlers', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:first', 'one');
    button.setAttribute('fiber:second', 'two');
    document.body.append(button);

    const calls = [];
    runtime.directives.register('first', {
        event: 'click',
        handler(event, context) {
            calls.push(context.name);
        },
    });
    runtime.directives.register('second', {
        event: 'click',
        handler(event, context) {
            calls.push(context.name);
        },
    });

    boot(runtime, document);

    assert.equal(button.listenerCount('click'), 2);

    button.dispatchEvent({ type: 'click' });

    assert.deepEqual(calls, ['first', 'second']);
});

test('parses fiber directives through DOM attribute inspection', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const element = document.createElement('div');

    element.setAttribute('fiber:probe', 'alpha');
    element.setAttribute('fiber:missing', 'beta');
    element.setAttribute('data-other:thing', 'ignored');
    element.setAttribute('aria:label', 'ignored');

    runtime.directives.register('probe', {
        event: 'click',
        handler() {},
    });

    const parsed = runtime.directives.parse(element);

    assert.deepEqual(
        parsed.map((directive) => ({
            name: directive.name,
            value: directive.value,
            known: directive.known,
        })),
        [
            { name: 'probe', value: 'alpha', known: true },
            { name: 'missing', value: 'beta', known: false },
        ],
    );

    assert.equal(parsed[0].element, element);
    assert.equal(parsed[0].attribute, 'fiber:probe');
    assert.equal(parsed[0].prefix, 'fiber');
    assert.equal(parsed[0].definition, runtime.directives.get('probe'));
});

test('unknown directives are parsed but never bound or executed', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:unknown', 'ignored');
    document.body.append(button);

    boot(runtime, document);

    const parsed = runtime.directives.parse(button);

    assert.equal(parsed.length, 1);
    assert.equal(parsed[0].name, 'unknown');
    assert.equal(parsed[0].known, false);
    assert.equal(button.listenerCount('click'), 0);

    button.dispatchEvent({ type: 'click' });
});

test('duplicate directive listeners are prevented across boot, scan, and rescans', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:probe', 'value');
    document.body.append(button);

    let handlerCalls = 0;
    const definition = {
        event: 'click',
        handler() {
            handlerCalls++;
        },
    };

    runtime.directives.register('probe', definition);
    runtime.directives.register('probe', definition);

    boot(runtime, document);
    runtime.scan(document);
    runtime.scan(document);

    assert.equal(button.listenerCount('click'), 1);

    button.dispatchEvent({ type: 'click' });

    assert.equal(handlerCalls, 1);
});

test('removed elements are cleaned up safely and can be rebound when reinserted', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:probe', 'value');
    document.body.append(button);

    let handlerCalls = 0;
    let cleanupCalls = 0;

    runtime.directives.register('probe', {
        event: 'click',
        handler() {
            handlerCalls++;
        },
        cleanup(context) {
            cleanupCalls++;
            assert.equal(context.element, button);
        },
    });

    boot(runtime, document);
    const observer = FakeMutationObserver.instances[0];

    button.dispatchEvent({ type: 'click' });
    assert.equal(handlerCalls, 1);
    assert.equal(button.listenerCount('click'), 1);

    document.body.children = document.body.children.filter((child) => child !== button);
    button.parentNode = null;
    observer.emit([{ removedNodes: [button], addedNodes: [] }]);

    assert.equal(cleanupCalls, 1);
    assert.equal(button.listenerCount('click'), 0);

    button.dispatchEvent({ type: 'click' });
    assert.equal(handlerCalls, 1);

    document.body.append(button);
    runtime.scan(document);

    assert.equal(button.listenerCount('click'), 1);

    button.dispatchEvent({ type: 'click' });
    assert.equal(handlerCalls, 2);
});

test('stop disconnects observation and cleans every tracked listener', () => {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const button = document.createElement('button');
    button.setAttribute('fiber:probe', 'value');
    document.body.append(button);

    runtime.directives.register('probe', {
        event: 'click',
        handler() {},
    });

    boot(runtime, document);
    const observer = FakeMutationObserver.instances[0];

    assert.equal(runtime.isBooted(), true);
    assert.equal(button.listenerCount('click'), 1);

    assert.equal(runtime.stop(), true);

    assert.equal(runtime.isBooted(), false);
    assert.equal(observer.disconnectCalls, 1);
    assert.equal(button.listenerCount('click'), 0);
});

test('does not overwrite existing global functions or an existing Firefiber namespace', () => {
    const sentinelFetch = function sentinelFetch() {};
    const existingNamespace = { thirdPartyMarker: 'preserved' };
    const originalFetch = globalThis.fetch;

    globalThis.fetch = sentinelFetch;
    globalThis.Firefiber = existingNamespace;
    delete require.cache[require.resolve(runtimePath)];

    const runtime = require(runtimePath);

    assert.equal(globalThis.fetch, sentinelFetch);
    assert.equal(globalThis.Firefiber, existingNamespace);
    assert.equal(globalThis.Firefiber.thirdPartyMarker, 'preserved');
    assert.equal(runtime, existingNamespace);
    assert.equal(typeof runtime.boot, 'function');
    assert.equal(typeof runtime.directives.register, 'function');

    if (originalFetch === undefined) {
        delete globalThis.fetch;
    } else {
        globalThis.fetch = originalFetch;
    }
});
