'use strict';

const assert = require('node:assert/strict');
const { afterEach, beforeEach, test } = require('node:test');
const path = require('node:path');
const {
    FakeDocument,
    FakeElement,
    FakeMutationObserver,
} = require('./support/fake-dom.cjs');

const runtimePath = path.resolve(__dirname, '../../js/firefiber.js');
const COMPONENT_ID = 'ff-upload';
const CSRF_TOKEN = 'upload-csrf';

function loadRuntime() {
    delete require.cache[require.resolve(runtimePath)];
    delete globalThis.Firefiber;
    return require(runtimePath);
}

function makeSnapshot(state = {}, signature = 'sig') {
    return { componentId: COMPONENT_ID, state: { ...state }, timestamp: Math.floor(Date.now() / 1000), protocolVersion: '3.1', signature };
}

class FakeUploadTarget {
    constructor() { this.listeners = {}; }
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    emit(type, event) { (this.listeners[type] || []).forEach((fn) => fn(event)); }
}

class FakeXMLHttpRequest {
    static instances = [];
    constructor() {
        this.method = null;
        this.url = null;
        this.async = true;
        this.headers = {};
        this.withCredentials = false;
        this.status = 0;
        this.responseText = '';
        this.upload = new FakeUploadTarget();
        this.onload = null;
        this.onerror = null;
        this.onabort = null;
        this.sentBody = null;
        FakeXMLHttpRequest.instances.push(this);
    }
    open(method, url, async) { this.method = method; this.url = url; this.async = async; }
    setRequestHeader(name, value) { this.headers[name] = value; }
    send(body) { this.sentBody = body; }
    respond(status, payload) {
        this.status = status;
        this.responseText = typeof payload === 'string' ? payload : JSON.stringify(payload);
        if (this.onload) this.onload();
    }
    fail() { if (this.onerror) this.onerror(); }
}

class FakeFormData {
    constructor() { this.entries = []; }
    append(name, value) { this.entries.push({ name, value }); }
    getAll(name) { return this.entries.filter((e) => e.name === name).map((e) => e.value); }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function bootUpload(document) {
    FakeXMLHttpRequest.instances = [];
    const runtime = loadRuntime();
    const root = document.createElement('section');
    root.setAttribute('data-firefiber-component', COMPONENT_ID);
    root.setAttribute('data-firefiber-component-name', 'App\\UploadComponent');
    root.setAttribute('data-firefiber-snapshot', JSON.stringify(makeSnapshot({ files: [] })));
    root.setAttribute('data-firefiber-csrf', CSRF_TOKEN);
    root.setAttribute('data-firefiber-upload-endpoint', '/firefiber/uploads');

    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('fiber:upload', 'avatar');
    input.files = [{ name: 'photo.png', size: 70, type: 'image/png' }];
    root.append(input);
    document.body.append(root);

    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
        window: new FakeElement('window'),
        location: { href: 'https://firefiber.test/', origin: 'https://firefiber.test', pathname: '/', search: '', hash: '' },
        history: { replaceState() {}, pushState() {} },
        fetch: async () => ({ ok: true, status: 200, text: async () => '{}' }),
        XMLHttpRequest: FakeXMLHttpRequest,
        FormData: FakeFormData,
        signBody: async () => 'sig',
        endpoint: 'https://firefiber.test/firefiber',
    });

    return { runtime, root, input };
}

beforeEach(() => { FakeMutationObserver.instances = []; FakeXMLHttpRequest.instances = []; });
afterEach(() => {
    if (globalThis.Firefiber && typeof globalThis.Firefiber.stop === 'function') globalThis.Firefiber.stop();
    delete globalThis.Firefiber;
});

test('fiber:upload posts files to the upload endpoint with CSRF and snapshot metadata', async () => {
    const document = new FakeDocument();
    const { root, input } = bootUpload(document);

    const progress = [];
    root.addEventListener('firefiber:uploadprogress', (e) => progress.push(e.detail));
    const success = new Promise((resolve) => root.addEventListener('firefiber:uploadsuccess', (e) => resolve(e.detail)));

    input.dispatchEvent({ type: 'change', target: input });
    assert.equal(FakeXMLHttpRequest.instances.length, 1);

    const xhr = FakeXMLHttpRequest.instances[0];
    assert.equal(xhr.method, 'POST');
    assert.equal(xhr.url, '/firefiber/uploads');
    assert.equal(xhr.headers['X-Firefiber-Target'], '1');
    assert.equal(xhr.headers['X-Firefiber-Upload'], '1');
    assert.equal(xhr.headers['X-Csrf-Token'], CSRF_TOKEN);
    assert.equal(xhr.headers['Accept'], 'application/json');

    // The form data carries the component identity, field and signed snapshot.
    const body = xhr.sentBody;
    assert.equal(body instanceof FakeFormData, true);
    assert.deepEqual(body.getAll('componentId'), [COMPONENT_ID]);
    assert.deepEqual(body.getAll('field'), ['avatar']);
    assert.deepEqual(body.getAll('csrfToken'), [CSRF_TOKEN]);
    assert.equal(body.getAll('snapshot').length, 1);
    assert.equal(body.getAll('avatar').length, 1);

    // Progress events flow through the runtime.
    xhr.upload.emit('progress', { lengthComputable: true, loaded: 35, total: 70 });
    xhr.upload.emit('progress', { lengthComputable: true, loaded: 70, total: 70 });
    assert.equal(progress.length, 2);
    assert.equal(progress[1].percent, 100);

    xhr.respond(200, {
        status: 'ok',
        componentId: COMPONENT_ID,
        field: 'avatar',
        uploads: [{ id: 'abc', storedName: 'abc.png', path: 'firefiber/uploads/abc/abc.png', originalName: 'photo.png', mimeType: 'image/png', extension: 'png', size: 70, promoted: false }],
        snapshot: makeSnapshot({ files: ['abc'] }, 'fresh'),
    });

    const detail = await success;
    assert.equal(detail.ok, true);
    assert.equal(detail.field, 'avatar');
    assert.equal(detail.data.uploads[0].storedName, 'abc.png');
});

test('upload failures surface validation and authorization errors', async () => {
    const document = new FakeDocument();
    const { root, input } = bootUpload(document);

    const failure = new Promise((resolve) => root.addEventListener('firefiber:uploaderror', (e) => resolve(e.detail)));
    input.dispatchEvent({ type: 'change', target: input });

    const xhr = FakeXMLHttpRequest.instances[0];
    xhr.respond(422, { status: 'error', error: 'Validation failed', errorCode: 1, errors: { avatar: ['The uploaded file type is not permitted.'] } });

    const detail = await failure;
    assert.equal(detail.ok, false);
    assert.equal(detail.status, 422);
    assert.equal(detail.errorCode, 1);
    assert.deepEqual(detail.data.errors.avatar, ['The uploaded file type is not permitted.']);
});

test('upload network failures emit firefiber:uploaderror', async () => {
    const document = new FakeDocument();
    const { root, input } = bootUpload(document);

    const failure = new Promise((resolve) => root.addEventListener('firefiber:uploaderror', (e) => resolve(e.detail)));
    input.dispatchEvent({ type: 'change', target: input });

    FakeXMLHttpRequest.instances[0].fail();
    const detail = await failure;
    assert.equal(detail.ok, false);
    assert.equal(detail.kind, 'network');
});

test('runtime.upload() returns a promise with the upload result', async () => {
    const document = new FakeDocument();
    const { runtime } = bootUpload(document);

    const promise = runtime.upload(COMPONENT_ID, 'avatar', [{ name: 'a.png', size: 70, type: 'image/png' }]);
    assert.equal(FakeXMLHttpRequest.instances.length, 1);

    FakeXMLHttpRequest.instances[0].respond(200, {
        status: 'ok', componentId: COMPONENT_ID, field: 'avatar', uploads: [{ id: 'x' }],
        snapshot: makeSnapshot({}, 'fresh'),
    });

    const detail = await promise;
    assert.equal(detail.ok, true);
    assert.equal(detail.data.uploads[0].id, 'x');
});
