'use strict';

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

    removeAttribute(name) {
        this.attributes = this.attributes.filter((entry) => entry.name !== name);
    }

    getAttributeNames() {
        throw new Error('Runtime must inspect the attributes collection, not getAttributeNames()');
    }

    /**
     * Fake-browser form controls collection. Mirrors HTMLFormElement.elements
     * for the control elements (inputs, selects, textareas, buttons).
     */
    get elements() {
        console.error('DEBUG elements getter: tag=', this.tagName, 'children.length=', this.children ? this.children.length : 'NA');
        if (!this._formElements) {
            this._formElements = [];
            const walk = (node) => {
                const tag = String(node.tagName || '').toUpperCase();
                console.error('DEBUG walk node tag=', tag, 'children count =', node.children ? node.children.length : 'NA');
                if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON') {
                    this._formElements.push(node);
                }
                for (const child of node.children) {
                    walk(child);
                }
            };
            walk(this);
        }
        return this._formElements;
    }

    append(...children) {
        if (this.tagName === 'SELECT' && this.getAttribute('name') === 'multi-select') {
            console.error('DEBUG multiSelect.append this.children BEFORE =', this.children ? this.children.length : 'NO-children-PROP');
        }
        console.error('DEBUG append children.length =', children ? children.length : 'NO-CHILDREN-ARRAY');
        for (const child of children) {
            child.parentNode = this;
            this.children.push(child);
            if (this.tagName === 'SELECT' && this.getAttribute('name') === 'multi-select') {
                console.error('DEBUG multiSelect.append AFTER push children =', this.children.length);
            }
        }

        if (this.ownerDocument) {
            for (const child of children) {
                child.setOwnerDocument(this.ownerDocument);
            }
        }

        return children[0];
    }

    removeChild(child) {
        this.children = this.children.filter((entry) => entry !== child);
        child.parentNode = null;

        return child;
    }

    setOwnerDocument(document) {
        this.ownerDocument = document;

        for (const child of this.children) {
            child.setOwnerDocument(document);
        }
    }

    querySelectorAll(selector) {
        const matches = [];

        const visit = (node) => {
            for (const child of node.children) {
                if (this.matches(child, selector)) {
                    matches.push(child);
                }
                visit(child);
            }
        };

        visit(this);

        return matches;
    }

    /**
     * Minimal selector support for the fake DOM shim: '*' matches everything,
     * and a bare tag name matches elements with that tag name.
     */
    matches(element, selector) {
        if (selector === '*') {
            return true;
        }

        const upper = String(element.tagName || '').toUpperCase();
        const name = selector.trim().toUpperCase();

        if (name === '*' || upper === name) {
            return true;
        }

        return false;
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
        this.cookie = '';
        this.location = null;
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

function waitForEvent(element, type, timeoutMs = 1000) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            element.removeEventListener(type, listener);
            reject(new Error(`Timed out waiting for ${type}`));
        }, timeoutMs);
        const listener = (event) => {
            clearTimeout(timer);
            element.removeEventListener(type, listener);
            resolve(event);
        };

        element.addEventListener(type, listener);
    });
}

module.exports = {
    FakeDocument,
    FakeElement,
    FakeMutationObserver,
    waitForEvent,
};
