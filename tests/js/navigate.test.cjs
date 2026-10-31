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

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;

    return require(runtimePath);
}

function htmlResponse(text, status = 200, contentType = 'text/html; charset=utf-8') {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: contentType === null ? {} : { 'Content-Type': contentType },
        text: async () => text,
    };
}

function makePage(markupKey, title = '') {
    const document = new FakeDocument();
    document.title = title;

    const page = document.createElement('div');
    page.setAttribute('data-page', markupKey);
    document.body.append(page);

    return document;
}

function click(element, options = {}) {
    const state = {
        prevented: 0,
        stopped: 0,
    };

    element.dispatchEvent({
        type: 'click',
        target: element,
        button: options.button === undefined ? 0 : options.button,
        metaKey: options.metaKey === true,
        ctrlKey: options.ctrlKey === true,
        shiftKey: options.shiftKey === true,
        altKey: options.altKey === true,
        preventDefault() {
            state.prevented++;
        },
        stopPropagation() {
            state.stopped++;
        },
    });

    return state;
}

function makeLink(document, href, attributes = {}) {
    const link = document.createElement('a');
    link.setAttribute('href', href);
    link.setAttribute('fiber:navigate', attributes.selector || '');

    for (const [name, value] of Object.entries(attributes)) {
        if (name !== 'selector') {
            link.setAttribute(name, value);
        }
    }

    return link;
}

function setup(options = {}) {
    const runtime = loadRuntime();
    const document = new FakeDocument();
    const window = new FakeElement('window');
    const location = {
        href: options.href || 'https://firefiber.test/start',
        origin: options.origin || 'https://firefiber.test',
        assigns: [],
        assign(url) {
            this.assigns.push(url);
        },
    };
    const history = {
        entries: [],
        states: [],
        pushState(state, title, url) {
            this.states.push(state);
            this.entries.push(url);
        },
    };

    const root = document.createElement('div');
    document.body.append(root);

    const fetch = options.fetch || (() => Promise.resolve(htmlResponse('page:dashboard')));
    const calls = [];
    const fetchSpy = (url, init) => {
        calls.push({ url, options: init });

        return Promise.resolve(fetch(url, init));
    };
    fetchSpy.calls = calls;

    const pages = options.pages || {
        'page:dashboard': makePage('dashboard', 'Dashboard'),
    };

    const parseHTML = (html) => pages[html] || null;

    const build = options.build || ((document, root) => {
        root.append(makeLink(document, '/dashboard'));
    });
    build(document, root);

    runtime.configure({
        endpoint: 'https://firefiber.test/firefiber',
        csrfToken: 'csrf-token',
        hmacKey: 'navigate-test-key',
        protocolVersion: '3.1',
        fetch: fetchSpy,
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
        fetch: fetchSpy,
    });

    return {
        runtime,
        document,
        window,
        location,
        history,
        fetch: fetchSpy,
        root,
        anchor: root.querySelectorAll('a')[0],
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

test('fiber:navigate renders a same-origin destination and updates history', async () => {
    const { document, window, history, location, fetch, anchor } = setup();

    const success = waitForEvent(window, 'firefiber:navigate-success');
    const eventState = click(anchor);
    const event = await success;

    assert.equal(eventState.prevented, 1);
    assert.equal(location.assigns.length, 0, 'A successful navigation must not fall back');
    assert.equal(fetch.calls.length, 1);

    assert.equal(fetch.calls[0].url, 'https://firefiber.test/dashboard');
    assert.equal(fetch.calls[0].options.method, 'GET');
    assert.equal(fetch.calls[0].options.headers.Accept, 'text/html');
    assert.equal(fetch.calls[0].options.headers['X-Firefiber-Navigate'], '1');
    assert.equal(fetch.calls[0].options.credentials, 'same-origin');

    assert.deepEqual(history.entries, ['https://firefiber.test/dashboard']);
    assert.equal(event.detail.status, 200);
    assert.equal(event.detail.url, 'https://firefiber.test/dashboard');
    assert.equal(document.title, 'Dashboard');

    const rendered = document.querySelectorAll('div').filter((node) => node.getAttribute('data-page') === 'dashboard');

    assert.equal(rendered.length, 1);
});

test('fiber:navigate swaps only the selected container and keeps loaded scripts', async () => {
    let container = null;
    let script = null;
    let untouched = null;

    const pages = {
        'page:next': (() => {
            const pageDocument = new FakeDocument();
            pageDocument.title = 'Next';
            const main = pageDocument.createElement('main');
            const content = pageDocument.createElement('section');
            content.setAttribute('data-page', 'next');
            main.append(content);
            pageDocument.body.append(main);

            return pageDocument;
        })(),
    };

    const { document, window, anchor } = setup({
        pages,
        build(doc, root) {
            untouched = doc.createElement('aside');
            untouched.setAttribute('data-role', 'sidebar');

            container = doc.createElement('main');
            const old = doc.createElement('section');
            old.setAttribute('data-page', 'old');
            script = doc.createElement('script');
            script.setAttribute('src', '/app.js');

            container.append(old, script);
            root.append(makeLink(doc, '/next', { selector: 'main' }), container, untouched);
        },
        fetch: () => Promise.resolve(htmlResponse('page:next')),
    });

    const success = waitForEvent(window, 'firefiber:navigate-success');
    click(anchor);
    await success;

    assert.equal(document.querySelectorAll('main').length, 1, 'The container itself must be preserved');
    assert.equal(document.querySelectorAll('main')[0], container);
    assert.equal(container.querySelectorAll('section').length, 1);
    assert.equal(container.querySelectorAll('section')[0].getAttribute('data-page'), 'next');
    assert.equal(container.querySelectorAll('script').length, 1);
    assert.equal(container.querySelectorAll('script')[0], script, 'Loaded scripts must not be re-created');
    assert.equal(document.querySelectorAll('aside')[0], untouched, 'Outside content must be untouched');
});

test('fiber:navigate ignores external links', async () => {
    const { window, history, location, fetch, anchor } = setup({
        build(document, root) {
            root.append(makeLink(document, 'https://external.test/pricing'));
        },
    });

    const eventState = click(anchor);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(eventState.prevented, 0, 'External links keep normal navigation');
    assert.equal(fetch.calls.length, 0);
    assert.equal(history.entries.length, 0);
    assert.equal(location.assigns.length, 0);
    assert.equal(window.listenerCount('firefiber:navigate-success'), 0);
});

test('fiber:navigate ignores modified clicks', async () => {
    for (const modifier of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey']) {
        const { fetch, anchor, history } = setup();
        const eventState = click(anchor, { [modifier]: true });

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(eventState.prevented, 0, `${modifier} clicks must not be intercepted`);
        assert.equal(fetch.calls.length, 0);
        assert.equal(history.entries.length, 0);
    }

    const { fetch, anchor } = setup();
    const middle = click(anchor, { button: 1 });

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(middle.prevented, 0, 'Non-primary buttons keep normal behavior');
    assert.equal(fetch.calls.length, 0);
});

test('fiber:navigate ignores downloads and new-tab targets', async () => {
    const download = setup({
        build(document, root) {
            root.append(makeLink(document, '/files/report.pdf', { download: '' }));
        },
    });
    const downloadClick = click(download.anchor);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(downloadClick.prevented, 0);
    assert.equal(download.fetch.calls.length, 0);
    assert.equal(download.history.entries.length, 0);

    const newTab = setup({
        build(document, root) {
            root.append(makeLink(document, '/dashboard', { target: '_blank' }));
        },
    });
    const newTabClick = click(newTab.anchor);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(newTabClick.prevented, 0);
    assert.equal(newTab.fetch.calls.length, 0);
    assert.equal(newTab.history.entries.length, 0);
});

test('fiber:navigate ignores unsupported elements', async () => {
    const { document, fetch, history } = setup({
        build(doc, root) {
            const button = doc.createElement('button');
            button.setAttribute('href', '/dashboard');
            button.setAttribute('fiber:navigate', '');
            root.append(button);
        },
    });

    const button = document.querySelectorAll('button')[0];
    const state = click(button);

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(state.prevented, 0, 'Only links and areas are navigation elements');
    assert.equal(fetch.calls.length, 0);
    assert.equal(history.entries.length, 0);
});

test('ordinary links without fiber:navigate keep normal browser behavior', async () => {
    const { document, fetch, history } = setup({
        build(doc, root) {
            const link = doc.createElement('a');
            link.setAttribute('href', '/dashboard');
            root.append(link);
        },
    });

    const link = document.querySelectorAll('a')[0];
    const state = click(link);

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(state.prevented, 0);
    assert.equal(fetch.calls.length, 0);
    assert.equal(history.entries.length, 0);
});

test('fiber:navigate supports Back and Forward through popstate', async () => {
    const pages = {
        'page:dashboard': makePage('dashboard', 'Dashboard'),
        'page:settings': makePage('settings', 'Settings'),
        'page:start': makePage('start', 'Start'),
    };
    const { document, window, history, location, fetch, anchor } = setup({
        pages,
        build(doc, root) {
            root.append(makeLink(doc, '/dashboard'));
        },
        fetch: (url) => Promise.resolve(htmlResponse(
            url.endsWith('/settings')
                ? 'page:settings'
                : (url.endsWith('/start') ? 'page:start' : 'page:dashboard'),
        )),
    });

    const first = waitForEvent(window, 'firefiber:navigate-success');
    click(anchor);
    await first;

    assert.deepEqual(history.entries, ['https://firefiber.test/dashboard']);
    assert.equal(document.querySelectorAll('div').filter((n) => n.getAttribute('data-page') === 'dashboard').length, 1);

    // Browser Back: the URL is restored by the browser, then popstate fires.
    location.href = 'https://firefiber.test/start';
    const back = waitForEvent(window, 'firefiber:navigate-success');
    window.dispatchEvent({ type: 'popstate', target: window });
    await back;

    assert.equal(fetch.calls[1].url, 'https://firefiber.test/start');
    assert.deepEqual(history.entries, ['https://firefiber.test/dashboard'], 'Back must not push a new entry');
    assert.equal(location.assigns.length, 0);
    assert.equal(document.querySelectorAll('div').filter((n) => n.getAttribute('data-page') === 'dashboard').length, 0);
    assert.equal(document.querySelectorAll('div').filter((n) => n.getAttribute('data-page') === 'start').length, 1);

    // Browser Forward to a different destination.
    location.href = 'https://firefiber.test/settings';
    const forward = waitForEvent(window, 'firefiber:navigate-success');
    window.dispatchEvent({ type: 'popstate', target: window });
    await forward;

    assert.equal(fetch.calls[2].url, 'https://firefiber.test/settings');
    assert.deepEqual(history.entries, ['https://firefiber.test/dashboard']);
    assert.equal(document.querySelectorAll('div').filter((n) => n.getAttribute('data-page') === 'settings').length, 1);
});

test('fiber:navigate falls back to normal navigation on a failed response', async () => {
    const { window, location, anchor, history } = setup({
        fetch: () => Promise.resolve(htmlResponse('error', 500)),
    });

    const failure = waitForEvent(window, 'firefiber:navigate-error');
    const state = click(anchor);
    const event = await failure;

    assert.equal(state.prevented, 1);
    assert.equal(event.detail.url, 'https://firefiber.test/dashboard');
    assert.match(event.detail.error, /status 500/);
    assert.deepEqual(location.assigns, ['https://firefiber.test/dashboard']);
    assert.equal(history.entries.length, 0, 'Failed navigations must not touch history');
});

test('fiber:navigate rejects non-HTML responses and falls back', async () => {
    const { window, location, anchor } = setup({
        fetch: () => Promise.resolve(htmlResponse('{"status":"ok"}', 200, 'application/json')),
    });

    const failure = waitForEvent(window, 'firefiber:navigate-error');
    click(anchor);
    const event = await failure;

    assert.match(event.detail.error, /not HTML/);
    assert.deepEqual(location.assigns, ['https://firefiber.test/dashboard']);
});

test('fiber:navigate falls back when the destination is unavailable', async () => {
    const { window, location, anchor } = setup({
        fetch: () => Promise.reject(new Error('network down')),
    });

    const failure = waitForEvent(window, 'firefiber:navigate-error');
    click(anchor);
    const event = await failure;

    assert.match(event.detail.error, /network down/);
    assert.deepEqual(location.assigns, ['https://firefiber.test/dashboard']);
});

test('fiber:navigate falls back when the destination cannot be parsed', async () => {
    const { window, location, anchor } = setup({
        pages: {},
    });

    const failure = waitForEvent(window, 'firefiber:navigate-error');
    click(anchor);
    const event = await failure;

    assert.match(event.detail.error, /could not be parsed/);
    assert.deepEqual(location.assigns, ['https://firefiber.test/dashboard']);
});

test('fiber:navigate removes click and popstate listeners when the runtime stops', async () => {
    const { runtime, window, anchor, fetch } = setup();

    assert.equal(anchor.listenerCount('click'), 1);
    assert.equal(window.listenerCount('popstate'), 1);

    runtime.stop();

    assert.equal(anchor.listenerCount('click'), 0);
    assert.equal(window.listenerCount('popstate'), 0);

    const state = click(anchor);
    window.dispatchEvent({ type: 'popstate', target: window });
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(state.prevented, 0);
    assert.equal(fetch.calls.length, 0);
});
