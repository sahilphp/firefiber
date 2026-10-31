'use strict';

const assert = require('node:assert/strict');
const { afterEach, test } = require('node:test');
const path = require('node:path');

const runtimePath = path.resolve(__dirname, '../../dist/runtime.min.js');

class FakeElement {
    constructor(tagName) {
        this.nodeType = 1;
        this.tagName = tagName;
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

    addEventListener(event, listener) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }

        this.listeners.get(event).push(listener);
    }

    removeEventListener(event, listener) {
        const listeners = this.listeners.get(event) || [];
        this.listeners.set(event, listeners.filter((entry) => entry !== listener));
    }

    dispatchEvent(event) {
        for (const listener of this.listeners.get(event.type) || []) {
            listener.call(this, event);
        }

        return true;
    }

    querySelectorAll() {
        return [];
    }
}

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') {
        globalThis.Firefiber.stop();
    }

    delete globalThis.Firefiber;
});

test('the minified production runtime exposes the same explicit boot surface', () => {
    const runtime = loadRuntime();

    assert.equal(typeof runtime.boot, 'function');
    assert.equal(typeof runtime.stop, 'function');
    assert.equal(typeof runtime.scan, 'function');
    assert.equal(typeof runtime.directives.register, 'function');
    assert.equal(runtime.isBooted(), false);
});

test('the minified production runtime boots and binds directives without network requests', () => {
    const runtime = loadRuntime();
    const button = new FakeElement('BUTTON');
    button.setAttribute('fiber:probe', 'increment');
    const document = {
        nodeType: 9,
        documentElement: new FakeElement('HTML'),
        querySelectorAll() {
            return [button];
        },
    };

    let calls = 0;
    runtime.directives.register('probe', {
        event: 'click',
        handler(event, context) {
            calls++;
            assert.equal(context.element, button);
            assert.equal(context.value, 'increment');
        },
    });

    const result = runtime.boot({
        document,
        MutationObserver: class {
            observe() {}
            disconnect() {}
        },
    });

    assert.equal(result.booted, true);
    assert.equal(result.bound, 1);
    assert.equal(runtime.isBooted(), true);

    button.dispatchEvent({ type: 'click' });

    assert.equal(calls, 1);
});
