/**
 * Firefiber browser runtime.
 *
 * This file provides the explicit bootstrap, directive registry, DOM
 * discovery, duplicate-safe binding, cleanup primitives, and the fiber:click
 * action transport. Other directives, forms, navigation, DOM morphing, and
 * loading indicators remain intentionally out of scope.
 */
(function (root, factory) {
    'use strict';

    function define(target, key, value) {
        try {
            Object.defineProperty(target, key, {
                configurable: true,
                enumerable: false,
                writable: true,
                value: value,
            });
        } catch (error) {
            try {
                target[key] = value;
            } catch (assignmentError) {
                // A frozen or sealed host object is left untouched.
            }
        }
    }

    if (root && root.Firefiber && root.Firefiber.__firefiberRuntime === true) {
        if (typeof module === 'object' && module.exports) {
            module.exports = root.Firefiber;
        }

        return;
    }

    var api = factory(root);

    if (root) {
        if (!root.Firefiber) {
            define(root, 'Firefiber', api);
        } else {
            // Preserve anything that already owns the Firefiber name. Only
            // fill in missing runtime entry points instead of replacing a
            // third-party object or an existing global function.
            Object.keys(api).forEach(function (key) {
                if (!(key in root.Firefiber)) {
                    define(root.Firefiber, key, api[key]);
                }
            });

            if (!('__firefiberRuntime' in root.Firefiber)) {
                define(root.Firefiber, '__firefiberRuntime', true);
            }
        }
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = root && root.Firefiber ? root.Firefiber : api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
    'use strict';

    var VERSION = '0.2.0';
    var DEFAULT_PREFIX = 'fiber';
    var DIRECTIVE_NAME = /^[A-Za-z][A-Za-z0-9_-]*$/;
    var CLICK_MODIFIERS = ['prevent', 'stop', 'once', 'self'];
    var MODEL_EVENTS = ['input', 'change'];
    var DEFAULT_MODEL_ACTION = 'updateModel';
    // Built-in actions the browser runtime may issue without a developer
    // registration; the server treats each as a fixed, allow-listed entry
    // point (never an arbitrary method call).
    var QUERY_ACTION = 'firefiberQuery';
    var DEFAULT_CONFIRM_MESSAGE = 'Are you sure?';
    var KEYBOARD_MODIFIERS = ['ctrl', 'alt', 'shift', 'meta'];
    var KEYBOARD_FILTERS = ['prevent', 'stop', 'once', 'self'];
    var KEY_ALIASES = {
        space: ' ',
        spacebar: ' ',
        esc: 'escape',
        up: 'arrowup',
        down: 'arrowdown',
        left: 'arrowleft',
        right: 'arrowright',
    };
    var NAVIGATE_HEADER = 'X-Firefiber-Navigate';
    var COMPONENT_ATTRIBUTES = [
        'data-firefiber-component',
        'data-firefiber-component-id',
        'data-firefiber-id',
        'data-component-id',
    ];

    var definitions = new Map();
    var bindings = new WeakMap();
    var trackedElements = new Set();
    var snapshots = new Map();
    var pendingActions = new Map();
    var onceHandled = new WeakSet();
    var signingKeyCache = new Map();
    // Last synchronized value per model field, scoped to a component root. It
    // deduplicates the input+change pair browsers emit for one edit.
    var modelValues = new WeakMap();
    var navigationInFlight = false;
    var navigationPopstateListener = null;
    // Events that already passed fiber:confirm, so a bubbling or re-entrant
    // handler chain cannot show the same dialog twice.
    var confirmedEvents = new WeakSet();
    // Change events already dispatched; guards against re-dispatching the same
    // native interaction through duplicate listeners.
    var changeEvents = new WeakSet();
    // Keyboard events already dispatched, for the same reason.
    var keydownEvents = new WeakSet();
    // fiber:dirty indicators and the last server-acknowledged value per
    // component scope + model field.
    var dirtyIndicators = new Set();
    var dirtyBaselines = new WeakMap();
    // fiber:loading indicators, grouped by the component root that scopes
    // their requests. Pending counts keep overlapping requests from flickering
    // the indicator off early; navigation is application-wide.
    var loadingIndicators = new Set();
    var disabledControls = new Set();
    var DISABLEABLE_TAGS = ['BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'OPTGROUP', 'OPTION', 'FIELDSET'];
    // scope -> Map(action name -> pending count). The action name is the one
    // actually dispatched, so targeting can never be spoofed by DOM attributes.
    var loadingActions = new WeakMap();
    // element -> list of target action names (from fiber:target).
    var loadingTargets = new WeakMap();
    var navigationLoadingCount = 0;
    // Advanced capabilities (URL sync, polling, lazy, optimistic, uploads),
    // keyed by component root element.
    var advancedComponents = new Map();
    var urlSyncRecords = new Set();
    var urlPopstateListener = null;
    // Monotonic per-component request/response ordering. Older responses can
    // never overwrite newer authoritative state.
    var actionSequences = new Map();
    var appliedSequences = new Map();
    // Debounced fiber:model timers, cleared on stop and element disposal.
    var debounceTimers = new Map();
    var PUSH_SETTLE_MS = 700;

    var state = {
        booted: false,
        bootResult: null,
        document: null,
        root: null,
        prefix: DEFAULT_PREFIX,
        observer: null,
        onError: null,
        config: {
            endpoint: null,
            csrfToken: null,
            // Legacy optional transport-integrity key. Pages do not render
            // signing secrets, and requests do not require this value.
            hmacKey: null,
            componentId: null,
            snapshot: null,
            protocolVersion: null,
            payload: null,
            headers: null,
            credentials: 'same-origin',
            fetch: null,
            crypto: null,
            signBody: null,
        },
    };

    function define(target, key, value) {
        try {
            Object.defineProperty(target, key, {
                configurable: true,
                enumerable: false,
                writable: true,
                value: value,
            });
        } catch (error) {
            try {
                target[key] = value;
            } catch (assignmentError) {
                // A frozen or sealed host object is left untouched.
            }
        }
    }

    function lookupName(name) {
        return typeof name === 'string' ? name.toLowerCase() : '';
    }

    function normalizeRegisteredName(name) {
        if (typeof name !== 'string' || !DIRECTIVE_NAME.test(name)) {
            throw new TypeError('Firefiber directive names must match ' + DIRECTIVE_NAME);
        }

        return name.toLowerCase();
    }

    function normalizeEvents(event) {
        if (event === undefined || event === null || event === '') {
            return [];
        }

        var events = Array.isArray(event) ? event : [event];
        var normalized = [];

        for (var index = 0; index < events.length; index++) {
            if (
                typeof events[index] === 'string'
                && events[index] !== ''
                && normalized.indexOf(events[index]) === -1
            ) {
                normalized.push(events[index]);
            }
        }

        return normalized;
    }

    function parseAttribute(attribute, prefix) {
        if (!attribute || typeof attribute.name !== 'string') {
            return null;
        }

        var rawName = attribute.name;
        var separator = rawName.indexOf(':');

        if (separator <= 0 || separator === rawName.length - 1) {
            return null;
        }

        var attributePrefix = rawName.slice(0, separator);
        var expectedPrefix = (prefix || DEFAULT_PREFIX).toLowerCase();

        if (attributePrefix.toLowerCase() !== expectedPrefix) {
            return null;
        }

        var directiveParts = rawName.slice(separator + 1).split('.');
        var name = directiveParts.shift().toLowerCase();
        var modifiers = [];

        if (!DIRECTIVE_NAME.test(name)) {
            return null;
        }

        for (var index = 0; index < directiveParts.length; index++) {
            var modifier = directiveParts[index].toLowerCase();

            if (modifier !== '') {
                modifiers.push(modifier);
            }
        }

        var definition = definitions.get(name) || null;

        return {
            attribute: rawName,
            prefix: attributePrefix,
            name: name,
            modifiers: modifiers,
            value: attribute.value === undefined || attribute.value === null
                ? ''
                : String(attribute.value),
            definition: definition,
            known: definition !== null,
        };
    }

    var registry = {
        register: function register(name, definition) {
            var normalized = normalizeRegisteredName(name);

            if (!definition || typeof definition !== 'object') {
                throw new TypeError('Firefiber directive "' + normalized + '" requires a definition object');
            }

            // Re-registering a directive is safe and keeps initialization
            // idempotent. Existing bindings are only created during scan.
            definitions.set(normalized, definition);

            return registry;
        },

        unregister: function unregister(name) {
            return definitions.delete(lookupName(name));
        },

        has: function has(name) {
            return definitions.has(lookupName(name));
        },

        get: function get(name) {
            return definitions.get(lookupName(name));
        },

        list: function list() {
            return Array.from(definitions.keys());
        },

        parseAttribute: function parseAttributeFor(attribute, prefix) {
            return parseAttribute(attribute, prefix);
        },

        /**
         * Inspect an element's actual attribute collection. This deliberately
         * does not rely on selector strings containing escaped colons or on
         * getAttributeNames(), which are easy to get wrong across DOM
         * implementations.
         */
        parse: function parse(element, prefix) {
            var parsed = [];
            var attributes = element && element.attributes;

            if (!attributes) {
                return parsed;
            }

            for (var index = 0; index < attributes.length; index++) {
                var attribute = typeof attributes.item === 'function'
                    ? attributes.item(index)
                    : attributes[index];
                var descriptor = parseAttribute(attribute, prefix);

                if (descriptor === null) {
                    continue;
                }

                descriptor.element = element;
                descriptor.definition = definitions.get(descriptor.name) || null;
                descriptor.known = descriptor.definition !== null;
                parsed.push(descriptor);
            }

            return parsed;
        },
    };

    function nonEmptyString(value) {
        return typeof value === 'string' && value !== '';
    }

    function normalizeSnapshot(value) {
        if (typeof value === 'string') {
            if (value.trim() === '') {
                return null;
            }

            try {
                value = JSON.parse(value);
            } catch (error) {
                return null;
            }
        }

        if (value && typeof value === 'object' && !Array.isArray(value)) {
            return value;
        }

        return null;
    }

    function globalConfig() {
        var config = root && root.FirefiberConfig;

        return config && typeof config === 'object' ? config : null;
    }

    function configValue(key) {
        if (
            state.config[key] !== undefined
            && state.config[key] !== null
            && state.config[key] !== ''
        ) {
            return state.config[key];
        }

        var global = globalConfig();

        if (
            global
            && global[key] !== undefined
            && global[key] !== null
            && global[key] !== ''
        ) {
            return global[key];
        }

        return null;
    }

    function findAttributeValue(element, names) {
        var current = element;

        while (current && current.nodeType === 1) {
            if (typeof current.getAttribute === 'function') {
                for (var index = 0; index < names.length; index++) {
                    var value = current.getAttribute(names[index]);

                    if (value !== null && value !== undefined && value !== '') {
                        return value;
                    }
                }
            }

            current = current.parentNode;
        }

        return null;
    }

    function metaContent(document, names) {
        if (!document || typeof document.querySelectorAll !== 'function') {
            return null;
        }

        var elements = document.querySelectorAll('*');

        for (var index = 0; index < elements.length; index++) {
            var element = elements[index];

            if (!element || String(element.tagName || '').toUpperCase() !== 'META') {
                continue;
            }

            var name = element.getAttribute ? element.getAttribute('name') : null;

            if (!name) {
                continue;
            }

            name = String(name).toLowerCase();

            for (var nameIndex = 0; nameIndex < names.length; nameIndex++) {
                if (name === names[nameIndex].toLowerCase()) {
                    var content = element.getAttribute('content');

                    if (content !== null && content !== undefined && content !== '') {
                        return content;
                    }
                }
            }
        }

        return null;
    }

    function cookieValue(document, name) {
        var cookie = document && document.cookie;

        if (!cookie) {
            return null;
        }

        var parts = String(cookie).split(';');

        for (var index = 0; index < parts.length; index++) {
            var part = parts[index].trim();
            var separator = part.indexOf('=');

            if (separator < 0 || part.slice(0, separator).trim() !== name) {
                continue;
            }

            var value = part.slice(separator + 1).trim();

            try {
                return decodeURIComponent(value);
            } catch (error) {
                return value;
            }
        }

        return null;
    }

    function parsePayloadValue(value) {
        if (value === null || value === undefined || value === '') {
            return {};
        }

        if (typeof value === 'object' && !Array.isArray(value)) {
            return value;
        }

        try {
            var parsed = JSON.parse(String(value));

            return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
                ? parsed
                : {};
        } catch (error) {
            return {};
        }
    }

    function resolveActionConfig(element) {
        var document = (element && element.ownerDocument)
            || state.document
            || (root && root.document)
            || null;
        var attributeComponentId = findAttributeValue(element, [
            'data-firefiber-component',
            'data-firefiber-component-id',
            'data-firefiber-id',
            'data-component-id',
        ]);
        var attributeSnapshot = normalizeSnapshot(findAttributeValue(element, ['data-firefiber-snapshot']));
        var componentName = findAttributeValue(element, [
            'data-firefiber-component-name',
            'data-firefiber-class',
            'data-firefiber-name',
        ]) || (attributeSnapshot && (attributeSnapshot.componentName || attributeSnapshot.componentClass))
            || configValue('componentName') || configValue('componentClass');
        var configuredComponentId = configValue('componentId');
        var configuredSnapshot = normalizeSnapshot(configValue('snapshot'));
        var componentId = attributeComponentId
            || configuredComponentId
            || (attributeSnapshot && attributeSnapshot.componentId)
            || (configuredSnapshot && configuredSnapshot.componentId)
            || null;
        var snapshot = componentId && snapshots.has(componentId)
            ? snapshots.get(componentId)
            : (attributeSnapshot || configuredSnapshot);

        if (!componentId && snapshot && snapshot.componentId) {
            componentId = snapshot.componentId;
        }

        if (snapshot && componentId && !snapshot.componentId) {
            snapshot = Object.assign({}, snapshot, { componentId: componentId });
        }

        var csrfToken = findAttributeValue(element, [
            'data-firefiber-csrf',
            'data-firefiber-csrf-token',
            'data-csrf-token',
        ]) || configValue('csrfToken')
            || metaContent(document, ['firefiber-csrf-token', 'firefiber-csrf', 'csrf-token'])
            || cookieValue(document, 'firefiber_csrf');
        var endpoint = findAttributeValue(element, ['data-firefiber-endpoint'])
            || configValue('endpoint')
            || metaContent(document, ['firefiber-endpoint'])
            || (root && root.location && root.location.href ? root.location.href : '');
        var hmacKey = findAttributeValue(element, [
            'data-firefiber-key',
            'data-firefiber-hmac-key',
        ]) || configValue('hmacKey')
            || metaContent(document, ['firefiber-hmac-key', 'firefiber-key']);
        var payloadValue = findAttributeValue(element, ['data-firefiber-payload']);

        if (payloadValue === null) {
            payloadValue = configValue('payload');
        }

        var global = globalConfig();
        var headers = {};

        if (global && global.headers && typeof global.headers === 'object') {
            Object.assign(headers, global.headers);
        }

        if (state.config.headers && typeof state.config.headers === 'object') {
            Object.assign(headers, state.config.headers);
        }

        var protocolVersion = configValue('protocolVersion')
            || (snapshot && snapshot.protocolVersion)
            || '3.1';

        return {
            componentId: componentId,
            componentName: componentName || null,
            snapshot: snapshot,
            csrfToken: csrfToken,
            endpoint: endpoint,
            hmacKey: hmacKey,
            payload: parsePayloadValue(payloadValue),
            protocolVersion: String(protocolVersion),
            headers: headers,
            credentials: configValue('credentials') || 'same-origin',
            fetch: state.config.fetch || (global && global.fetch) || (root && typeof root.fetch === 'function' ? root.fetch.bind(root) : null),
            crypto: state.config.crypto || (global && global.crypto) || (root && root.crypto) || null,
            signBody: state.config.signBody || (global && global.signBody) || null,
        };
    }

    function rememberSnapshot(componentId, snapshot) {
        if (
            !nonEmptyString(componentId)
            || !snapshot
            || typeof snapshot !== 'object'
            || Array.isArray(snapshot)
        ) {
            return;
        }

        if (snapshot.componentId && String(snapshot.componentId) !== componentId) {
            return;
        }

        if (!snapshot.componentId) {
            snapshot = Object.assign({}, snapshot, { componentId: componentId });
        }

        snapshots.set(componentId, snapshot);
    }

    function findComponentRoot(componentId) {
        var document = state.document || (root && root.document) || null;

        if (!document || typeof document.querySelectorAll !== 'function') {
            return null;
        }

        var candidates;
        try {
            candidates = document.querySelectorAll('[data-firefiber-component]');
        } catch (error) {
            candidates = [];
        }

        if (!candidates || candidates.length === 0) {
            try {
                candidates = document.querySelectorAll('*');
            } catch (fallbackError) {
                return null;
            }
        }

        for (var index = 0; index < candidates.length; index++) {
            var element = candidates[index];

            if (element && typeof element.getAttribute === 'function'
                && element.getAttribute('data-firefiber-component') === componentId) {
                return element;
            }
        }

        return null;
    }

    function bindValueToString(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'object') {
            try {
                return JSON.stringify(value);
            } catch (error) {
                return '';
            }
        }

        return String(value);
    }

    function belongsToComponentRoot(element, componentRoot) {
        var cursor = element;

        while (cursor) {
            if (cursor === componentRoot) {
                return true;
            }

            if (
                cursor !== componentRoot
                && typeof cursor.getAttribute === 'function'
                && cursor.getAttribute('data-firefiber-component')
            ) {
                return false;
            }

            cursor = cursor.parentNode || cursor.parentElement || null;
        }

        return false;
    }

    function applySnapshotToDom(componentId, snapshot, skipRootAttribute) {
        if (!snapshot || !snapshot.state || typeof snapshot.state !== 'object' || Array.isArray(snapshot.state)) {
            return;
        }

        var componentRoot = findComponentRoot(componentId);

        if (!componentRoot) {
            return;
        }

        // Optimistic patches update bound values only: the signed snapshot
        // attribute must always carry server-authoritative state.
        if (!skipRootAttribute && typeof componentRoot.setAttribute === 'function') {
            componentRoot.setAttribute('data-firefiber-snapshot', JSON.stringify(snapshot));
        }

        if (typeof componentRoot.querySelectorAll !== 'function') {
            return;
        }

        var fields;
        try {
            fields = componentRoot.querySelectorAll('[data-firefiber-bind]');
        } catch (error) {
            fields = [];
        }

        if (!fields || fields.length === 0) {
            try {
                fields = componentRoot.querySelectorAll('*');
            } catch (fallbackError) {
                return;
            }
        }

        for (var index = 0; index < fields.length; index++) {
            var fieldElement = fields[index];

            if (!fieldElement || typeof fieldElement.getAttribute !== 'function') {
                continue;
            }

            var field = fieldElement.getAttribute('data-firefiber-bind');

            if (!field || !Object.prototype.hasOwnProperty.call(snapshot.state, field)) {
                continue;
            }

            if (!belongsToComponentRoot(fieldElement, componentRoot)) {
                continue;
            }

            var value = snapshot.state[field];
            var tag = String(fieldElement.tagName || '').toUpperCase();
            var inputType = '';

            if (typeof fieldElement.getAttribute === 'function') {
                inputType = String(fieldElement.getAttribute('type') || '').toLowerCase();
            }

            if (inputType === 'checkbox' || inputType === 'radio') {
                if ('checked' in fieldElement) {
                    fieldElement.checked = Boolean(value);
                }
            } else if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                if ('value' in fieldElement) {
                    fieldElement.value = bindValueToString(value);
                }
            } else if (value !== null && typeof value === 'object') {
                // Collections and objects are rendered as markup by the server
                // (the staged upload list, for example). Writing their JSON as
                // text would destroy the element's children — clobbering the
                // just-applied server markup — and expose internal record
                // fields such as storage paths in the page. Scalars still
                // bind normally.
                continue;
            } else {
                fieldElement.textContent = bindValueToString(value);
            }
        }
    }

    function errorMessageText(messages) {
        if (typeof messages === 'string') {
            return messages;
        }

        if (Array.isArray(messages)) {
            return messages.join('\n');
        }

        return '';
    }

    function updateValidationErrorDisplay(errors, componentId) {
        var document = state.document || (root && root.document) || null;

        if (!document || typeof document.querySelectorAll !== 'function') {
            return;
        }

        var componentRoot = componentId ? findComponentRoot(componentId) : null;
        var sourceElements = componentRoot && typeof componentRoot.querySelectorAll === 'function'
            ? componentRoot
            : document;
        var errorElements;
        try {
            errorElements = sourceElements.querySelectorAll('[data-firefiber-error], [fiber\\:error]');
        } catch (error) {
            return;
        }

        if (!errorElements || errorElements.length === 0) {
            try {
                errorElements = sourceElements.querySelectorAll('*');
            } catch (error) {
                return;
            }
        }

        if (!errorElements) {
            return;
        }

        var errorMap = errors && typeof errors === 'object' && !Array.isArray(errors)
            ? errors
            : {};

        for (var index = 0; index < errorElements.length; index++) {
            var errorElement = errorElements[index];
            var field = nonEmptyString(errorElement.getAttribute('data-firefiber-error'))
                ? errorElement.getAttribute('data-firefiber-error')
                : errorElement.getAttribute('fiber:error');

            if (!field) {
                continue;
            }

            var messages = Object.prototype.hasOwnProperty.call(errorMap, field)
                ? errorMap[field]
                : (field === '_form'
                    ? (errorMap.__form || errorMap._form || null)
                    : null);
            if (componentRoot && !belongsToComponentRoot(errorElement, componentRoot)) {
                continue;
            }

            var text = errorMessageText(messages);

            errorElement.textContent = text;
            if (typeof errorElement.setAttribute === 'function') {
                errorElement.setAttribute('aria-invalid', text === '' ? 'false' : 'true');
            }

            if (text !== '' && typeof errorElement.setAttribute === 'function') {
                errorElement.setAttribute('aria-live', 'polite');
            }
        }
    }

    function clearValidationErrors(componentId) {
        updateValidationErrorDisplay({}, componentId);
    }

    function createRequestId() {
        var crypto = state.config.crypto
            || (globalConfig() && globalConfig().crypto)
            || (root && root.crypto);

        if (crypto && typeof crypto.randomUUID === 'function') {
            return 'ff-req-' + crypto.randomUUID();
        }

        if (crypto && typeof crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            var hex = '';

            for (var index = 0; index < bytes.length; index++) {
                hex += bytes[index].toString(16).padStart(2, '0');
            }

            return 'ff-req-' + hex;
        }

        return 'ff-req-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function utf8Bytes(value) {
        var Encoder = root && root.TextEncoder;

        if (typeof Encoder !== 'function') {
            throw new Error('TextEncoder is required to sign Firefiber requests');
        }

        return new Encoder().encode(String(value));
    }

    function bytesToBase64(bytes) {
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        var output = '';

        for (var index = 0; index < bytes.length; index += 3) {
            var byte1 = bytes[index];
            var byte2 = index + 1 < bytes.length ? bytes[index + 1] : 0;
            var byte3 = index + 2 < bytes.length ? bytes[index + 2] : 0;
            var triplet = (byte1 << 16) | (byte2 << 8) | byte3;

            output += alphabet[(triplet >> 18) & 63];
            output += alphabet[(triplet >> 12) & 63];
            output += index + 1 < bytes.length ? alphabet[(triplet >> 6) & 63] : '=';
            output += index + 2 < bytes.length ? alphabet[triplet & 63] : '=';
        }

        return output;
    }

    async function signRequestBody(body, config) {
        if (typeof config.signBody === 'function') {
            var customSignature = await config.signBody(body, config);

            if (!nonEmptyString(customSignature)) {
                throw new Error('Firefiber request signer returned an empty signature');
            }

            return customSignature;
        }

        if (!nonEmptyString(config.hmacKey)) {
            return null;
        }

        var crypto = config.crypto || (root && root.crypto);

        if (
            !crypto
            || !crypto.subtle
            || typeof crypto.subtle.importKey !== 'function'
            || typeof crypto.subtle.sign !== 'function'
        ) {
            throw new Error('Web Crypto HMAC support is required to sign Firefiber requests');
        }

        var cacheKey = config.hmacKey;
        var keyPromise = signingKeyCache.get(cacheKey);

        if (!keyPromise) {
            keyPromise = crypto.subtle.importKey(
                'raw',
                utf8Bytes(config.hmacKey),
                { name: 'HMAC', hash: 'SHA-256' },
                false,
                ['sign'],
            );
            signingKeyCache.set(cacheKey, keyPromise);
        }

        try {
            var key = await keyPromise;
            var signature = await crypto.subtle.sign('HMAC', key, utf8Bytes(body));

            return bytesToBase64(new Uint8Array(signature));
        } catch (error) {
            signingKeyCache.delete(cacheKey);
            throw error;
        }
    }

    function isSuccessfulResponse(response) {
        if (!response) {
            return false;
        }

        if (typeof response.ok === 'boolean') {
            return response.ok;
        }

        var status = Number(response.status);

        return status >= 200 && status < 300;
    }

    async function readResponsePayload(response) {
        if (!response) {
            return null;
        }

        if (typeof response.text === 'function') {
            var text = await response.text();

            if (text === null || text === undefined || text === '') {
                return null;
            }

            try {
                return JSON.parse(String(text));
            } catch (error) {
                return { raw: String(text) };
            }
        }

        if (typeof response.json === 'function') {
            try {
                return await response.json();
            } catch (error) {
                return null;
            }
        }

        return null;
    }

    function emitRuntimeEvent(element, type, detail) {
        var event = null;
        var CustomEventConstructor = root && root.CustomEvent;

        if (typeof CustomEventConstructor === 'function') {
            try {
                event = new CustomEventConstructor(type, {
                    bubbles: true,
                    cancelable: false,
                    detail: detail,
                });
            } catch (error) {
                event = null;
            }
        }

        if (!event) {
            event = {
                type: type,
                bubbles: true,
                cancelable: false,
                detail: detail,
            };
        }

        if (element && typeof element.dispatchEvent === 'function') {
            element.dispatchEvent(event);
        }
    }

    function parseClickValue(context) {
        var modifiers = Array.isArray(context.modifiers) ? context.modifiers.slice() : [];
        var value = String(context.value || '').trim();
        var parts = value.split('.');

        while (parts.length > 1) {
            var modifier = parts[parts.length - 1].toLowerCase();

            if (CLICK_MODIFIERS.indexOf(modifier) === -1) {
                break;
            }

            modifiers.push(modifier);
            parts.pop();
        }

        return {
            action: parts.join('.'),
            modifiers: modifiers,
        };
    }

    /**
     * Serialize a form's successful controls into a payload object.
     *
     * Mirrors the shape of a standard form encode: a single value for most
     * controls, and an array for controls that can repeat (select-multiple,
     * checkboxes, or several controls sharing one name). Disabled controls,
     * buttons, file inputs, and image inputs are excluded.
     */
    function serializeForm(form) {
        var payload = {};
        var elements = form && form.elements;
        var index = 0;
        var length = elements ? elements.length : 0;
        var control;

        for (; index < length; index++) {
            control = elements[index];

            // The fake DOM shim stores attributes, not properties; fall back
            // to attributes for the control name.
            if (!control.name && control.getAttribute) {
                control.name = control.getAttribute('name');
            }

            if (!control.name) {
                continue;
            }

            var type = control.getAttribute ? control.getAttribute('type') : '';

            if (control.disabled || type === 'submit' || type === 'reset' || type === 'button' || type === 'file' || type === 'image') {
                continue;
            }

            // A <select> is always a select control. It is multi-select when
            // it carries the multiple attribute; a single <select> emits the
            // value of its selected option.
            if (control.tagName === 'SELECT') {
                var options = control.children;
                var idx = 0;
                var olen = options ? options.length : 0;

                if (control.getAttribute && control.getAttribute('multiple') === 'multiple') {
                    for (; idx < olen; idx++) {
                        var so = options[idx];

                        if (so && !so.disabled && so.getAttribute && so.getAttribute('selected') === 'selected') {
                            addValue(payload, control.name, so.getAttribute('value'));
                        }
                    }
                } else {
                    // Single select: the value of the selected option (or the
                    // control's own value attribute when nothing is selected).
                    if (control.getAttribute && control.getAttribute('value') !== null) {
                        addValue(payload, control.name, control.getAttribute('value'));
                    } else {
                        for (var si = 0; si < olen; si++) {
                            var so = options[si];

                            if (so && so.getAttribute && so.getAttribute('selected') === 'selected') {
                                addValue(payload, control.name, so.getAttribute('value'));
                                break;
                            }
                        }
                    }
                }

                continue;
            }

            if (type === 'checkbox' || type === 'radio') {
                if (control.getAttribute && control.getAttribute('checked') !== 'checked') {
                    continue;
                }

                addValue(payload, control.name, control.getAttribute('value'));
                continue;
            }

            var value = null;

            if (control.tagName === 'TEXTAREA') {
                value = control.getAttribute ? control.getAttribute('value') : control.value;
            } else {
                value = control.getAttribute ? control.getAttribute('value') : control.value;
            }

            if (value === '' && type === 'hidden') {
                value = null;
            }

            addValue(payload, control.name, value === null ? '' : value);
        }

        return payload;
    }

    /** Append a value to a payload key, converting scalars to arrays as needed. */
    function addValue(payload, name, value) {
        if (payload[name] === undefined) {
            payload[name] = value;
        } else if (Array.isArray(payload[name])) {
            payload[name].push(value);
        } else {
            payload[name] = [payload[name], value];
        }
    }

    /**
     * Split a fiber:click value into its action name and trailing modifiers,
     * e.g. "increment.prevent.once".
     */
    function parseClickValue(value) {
        var parts = String(value || '').trim().split('.');
        var modifiers = [];

        while (parts.length > 1) {
            var modifier = parts[parts.length - 1].toLowerCase();

            if (CLICK_MODIFIERS.indexOf(modifier) === -1) {
                break;
            }

            modifiers.push(modifier);
            parts.pop();
        }

        return {
            action: parts.join('.'),
            modifiers: modifiers,
        };
    }

    /** Fiber confirm message for an element, or null when not configured. */
    function confirmMessageOf(element) {
        var descriptors = registry.parse(element, state.prefix);

        for (var index = 0; index < descriptors.length; index++) {
            if (descriptors[index].name === 'confirm') {
                return descriptors[index].value;
            }
        }

        return null;
    }

    function nativeConfirm() {
        var window = navigationWindow();

        if (window && typeof window.confirm === 'function') {
            return { call: window.confirm, owner: window };
        }

        if (root && typeof root.confirm === 'function') {
            return { call: root.confirm, owner: root };
        }

        return null;
    }

    /**
     * Ask for confirmation before an action is dispatched. Returns false when
     * the user cancels. A cancelled action never reaches dispatchAction().
     */
    function confirmFirefiberAction(element, event) {
        var message = confirmMessageOf(element);

        if (message === null) {
            return true;
        }

        // Programmatic dispatches and duplicate calls for one event must not
        // open a second dialog.
        if (!event || typeof event !== 'object' || confirmedEvents.has(event)) {
            return true;
        }

        var dialog = nativeConfirm();

        if (!dialog) {
            return true;
        }

        var text = String(message).trim() === ''
            ? DEFAULT_CONFIRM_MESSAGE
            : String(message);
        var accepted = false;

        try {
            accepted = dialog.call.call(dialog.owner, text) === true;
        } catch (error) {
            accepted = false;
        }

        if (!accepted) {
            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            return false;
        }

        confirmedEvents.add(event);

        return true;
    }

    function controlNameOf(element) {
        var name = element && typeof element.getAttribute === 'function'
            ? element.getAttribute('name')
            : null;

        if ((name === null || name === undefined || name === '') && element && typeof element.name === 'string') {
            name = element.name;
        }

        return name === null || name === undefined || String(name) === '' ? '' : String(name);
    }

    /** Nearest form if there is one, otherwise the component/document scope. */
    function changeScopeOf(element) {
        var current = element;

        while (current && current.nodeType === 1) {
            if (tagNameOf(current) === 'FORM') {
                return current;
            }

            current = current.parentNode;
        }

        return loadingScopeOf(element);
    }

    function collectNamedControls(scope, name) {
        var controls = [];

        var consider = function (element) {
            if (!isModelControl(element) || isDisabledControl(element)) {
                return;
            }

            if (controlNameOf(element) === name) {
                controls.push(element);
            }
        };

        consider(scope);

        if (scope && typeof scope.querySelectorAll === 'function') {
            var descendants = scope.querySelectorAll('*');

            for (var index = 0; index < descendants.length; index++) {
                consider(descendants[index]);
            }
        }

        return controls;
    }

    /**
     * Payload for a fiber:change action: the changed control's name mapped to
     * the current value of its name group. Repeated names (checkbox/radio
     * groups, multi-selects) keep every value instead of overwriting.
     */
    function changePayloadOf(element) {
        var name = controlNameOf(element);

        if (name === '') {
            return {};
        }

        var controls = collectNamedControls(changeScopeOf(element), name);

        if (controls.indexOf(element) === -1) {
            controls.push(element);
        }

        var payload = {};
        payload[name] = readModelValue(controls);

        return payload;
    }

    function normalizeKeyToken(token) {
        var key = String(token || '').toLowerCase().replace(/[\s_-]+/g, '');

        if (key === '') {
            return '';
        }

        return KEY_ALIASES[key] !== undefined ? KEY_ALIASES[key] : key;
    }

    function normalizedEventKey(event) {
        var key = typeof event.key === 'string' ? event.key : '';

        if (key === ' ' || key === 'Spacebar') {
            return ' ';
        }

        var normalized = key.toLowerCase().replace(/[\s_-]+/g, '');

        if (normalized !== '') {
            return normalized;
        }

        var fallback = {
            9: 'tab',
            13: 'enter',
            27: 'escape',
            32: ' ',
            37: 'arrowleft',
            38: 'arrowup',
            39: 'arrowright',
            40: 'arrowdown',
        };

        return fallback[event.keyCode] || '';
    }

    /**
     * Match a keydown against attribute-name modifiers. Non-filter modifiers
     * are key names; ctrl/alt/shift/meta are exact modifier requirements.
     */
    function keydownMatches(event, modifiers) {
        var keys = [];
        var required = { ctrl: false, alt: false, shift: false, meta: false };

        for (var index = 0; index < modifiers.length; index++) {
            var modifier = String(modifiers[index] || '').toLowerCase();

            if (KEYBOARD_MODIFIERS.indexOf(modifier) !== -1) {
                required[modifier] = true;
                continue;
            }

            if (KEYBOARD_FILTERS.indexOf(modifier) !== -1) {
                continue;
            }

            var key = normalizeKeyToken(modifier);

            if (key !== '') {
                keys.push(key);
            }
        }

        if (keys.length === 0 || keys.indexOf(normalizedEventKey(event)) === -1) {
            return false;
        }

        return Boolean(event.ctrlKey) === required.ctrl
            && Boolean(event.altKey) === required.alt
            && Boolean(event.shiftKey) === required.shift
            && Boolean(event.metaKey) === required.meta;
    }

    function isInteractiveEventTarget(target) {
        if (!target || target.nodeType !== 1) {
            return false;
        }

        var tag = tagNameOf(target);

        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'A') {
            return true;
        }

        if (typeof target.getAttribute === 'function') {
            var editable = target.getAttribute('contenteditable');

            if (editable !== null && editable !== 'false') {
                return true;
            }
        }

        return false;
    }

    async function dispatchKeydownAction(event, context) {
        var element = context.element;

        if (!element || isDisabledControl(element) || !event) {
            return;
        }

        if (event.isComposing === true || event.defaultPrevented === true) {
            return;
        }

        // Keys pressed inside an editable/interactive descendant belong to
        // that control, not to a surrounding fiber:keydown container.
        if (event.target && event.target !== element && isInteractiveEventTarget(event.target)) {
            return;
        }

        var modifiers = (Array.isArray(context.modifiers) ? context.modifiers : []).slice();
        var parsed = parseClickValue(context.value);
        modifiers = modifiers.concat(parsed.modifiers);

        if (!keydownMatches(event, modifiers)) {
            return;
        }

        if (keydownEvents.has(event)) {
            return;
        }

        keydownEvents.add(event);
        context.event = event;
        context.modifiers = modifiers;

        if (parsed.action === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing action name',
                kind: 'configuration',
            });

            return;
        }

        if (!confirmFirefiberAction(element, event)) {
            return;
        }

        return dispatchAction(element, context, parsed.action, {});
    }

    function handleKeydown(event, context) {
        dispatchKeydownAction(event, context).catch(function (error) {
            reportError(error, context);
            emitRuntimeEvent(context.element, 'firefiber:error', {
                action: String(context.value || ''),
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
        });
    }

    async function dispatchChangeAction(event, context) {
        var element = context.element;

        if (!element || !isModelControl(element) || isDisabledControl(element)) {
            return;
        }

        if (event) {
            // A prevented or already-seen native change is not a new
            // interaction and must not dispatch again.
            if (event.defaultPrevented === true || changeEvents.has(event)) {
                return;
            }

            changeEvents.add(event);
        }

        var parsed = parseClickValue(context.value);

        context.event = event;
        context.modifiers = (Array.isArray(context.modifiers) ? context.modifiers : [])
            .concat(parsed.modifiers);

        if (parsed.action === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing action name',
                kind: 'configuration',
            });

            return;
        }

        // Confirmation runs before modifiers so cancelling does not consume
        // .once or apply .prevent/.stop semantics.
        if (!confirmFirefiberAction(element, event)) {
            return;
        }

        return dispatchAction(element, context, parsed.action, changePayloadOf(element));
    }

    function handleChange(event, context) {
        dispatchChangeAction(event, context).catch(function (error) {
            reportError(error, context);
            emitRuntimeEvent(context.element, 'firefiber:error', {
                action: String(context.value || ''),
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
        });
    }

    async function dispatchClickAction(event, context) {
        var element = context.element;
        var parsed = parseClickValue(context.value);

        // Click actions carry the event so .self, .prevent, .stop and .once
        // can be evaluated against the actual click before dispatching.
        context.event = event;
        context.modifiers = (Array.isArray(context.modifiers) ? context.modifiers : [])
            .concat(parsed.modifiers);

        if (parsed.action === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing action name',
                kind: 'configuration',
            });

            return;
        }

        // Confirmation happens before modifiers so a cancellation does not
        // consume .once or apply .prevent/.stop semantics.
        if (!confirmFirefiberAction(element, event)) {
            return;
        }

        return dispatchAction(element, context, parsed.action, {});
    }

    var UNSUPPORTED_INPUT_TYPES = ['submit', 'reset', 'button', 'file', 'image'];

    function tagNameOf(element) {
        return String((element && element.tagName) || '').toUpperCase();
    }

    function inputTypeOf(element) {
        var type = element && typeof element.getAttribute === 'function'
            ? element.getAttribute('type')
            : null;

        if (type === null || type === undefined || type === '') {
            type = element && typeof element.type === 'string' ? element.type : 'text';
        }

        return String(type || 'text').toLowerCase();
    }

    function isDisabledControl(element) {
        if (!element) {
            return true;
        }

        if (element.disabled === true) {
            return true;
        }

        return typeof element.getAttribute === 'function'
            && element.getAttribute('disabled') !== null;
    }

    function isModelControl(element) {
        if (!element || element.nodeType !== 1) {
            return false;
        }

        var tag = tagNameOf(element);

        if (tag === 'SELECT' || tag === 'TEXTAREA') {
            return true;
        }

        if (tag !== 'INPUT') {
            return false;
        }

        return UNSUPPORTED_INPUT_TYPES.indexOf(inputTypeOf(element)) === -1;
    }

    /** The model binding key written on an element (its fiber:model value). */
    function modelFieldOf(element) {
        var descriptors = registry.parse(element, state.prefix);

        for (var index = 0; index < descriptors.length; index++) {
            if (descriptors[index].name === 'model') {
                return String(descriptors[index].value || '').trim();
            }
        }

        return '';
    }

    /** Nearest component root, used to scope a model field's control group. */
    function modelScopeOf(element) {
        var current = element;

        while (current && current.nodeType === 1) {
            if (typeof current.getAttribute === 'function') {
                for (var index = 0; index < COMPONENT_ATTRIBUTES.length; index++) {
                    if (current.getAttribute(COMPONENT_ATTRIBUTES[index]) !== null) {
                        return current;
                    }
                }
            }

            current = current.parentNode;
        }

        return state.root || (element && element.ownerDocument) || null;
    }

    function collectModelControls(scope, field) {
        var controls = [];

        var consider = function (element) {
            if (!isModelControl(element) || isDisabledControl(element)) {
                return;
            }

            if (modelFieldOf(element) === field) {
                controls.push(element);
            }
        };

        consider(scope);

        if (scope && typeof scope.querySelectorAll === 'function') {
            var descendants = scope.querySelectorAll('*');

            for (var index = 0; index < descendants.length; index++) {
                consider(descendants[index]);
            }
        }

        return controls;
    }

    function controlIsChecked(control) {
        if (typeof control.checked === 'boolean') {
            return control.checked;
        }

        return typeof control.getAttribute === 'function'
            && control.getAttribute('checked') === 'checked';
    }

    function controlValueOf(control) {
        var value = typeof control.value === 'string' ? control.value : null;

        if (value === null && typeof control.getAttribute === 'function') {
            value = control.getAttribute('value');
        }

        if (value === null && inputTypeOf(control) === 'checkbox') {
            value = 'on';
        }

        return value === null || value === undefined ? '' : String(value);
    }

    function optionIsSelected(option) {
        if (typeof option.selected === 'boolean') {
            return option.selected;
        }

        return typeof option.getAttribute === 'function'
            && option.getAttribute('selected') === 'selected';
    }

    function optionValueOf(option) {
        if (typeof option.value === 'string') {
            return option.value;
        }

        var value = typeof option.getAttribute === 'function'
            ? option.getAttribute('value')
            : null;

        return value === null || value === undefined ? '' : String(value);
    }

    function selectIsMultiple(control) {
        if (control.multiple === true) {
            return true;
        }

        return typeof control.getAttribute === 'function'
            && control.getAttribute('multiple') !== null;
    }

    function selectedOptionValues(control) {
        var options = control.options && typeof control.options.length === 'number'
            ? control.options
            : control.children;
        var values = [];

        if (!options) {
            return values;
        }

        for (var index = 0; index < options.length; index++) {
            var option = options[index];

            if (option && optionIsSelected(option)) {
                values.push(optionValueOf(option));
            }
        }

        return values;
    }

    function firstSelectedOptionValue(control) {
        var options = control.options && typeof control.options.length === 'number'
            ? control.options
            : control.children;

        if (!options) {
            return null;
        }

        for (var index = 0; index < options.length; index++) {
            if (options[index] && optionIsSelected(options[index])) {
                return optionValueOf(options[index]);
            }
        }

        return null;
    }

    /** A single value for scalars, and an array once a field repeats. */
    function collapseModelValues(values) {
        if (values.length === 0) {
            return [];
        }

        if (values.length === 1) {
            return values[0];
        }

        return values;
    }

    /**
     * Read the current value of a model group. Radio groups and single selects
     * are scalar, checkbox groups and multiple selects are arrays (collapsed to
     * a scalar while only one value applies), and repeated text controls keep
     * every value instead of overwriting each other.
     */
    function readModelValue(controls) {
        var radios = [];
        var checkboxes = [];
        var others = [];

        for (var index = 0; index < controls.length; index++) {
            var control = controls[index];
            var tag = tagNameOf(control);

            if (tag === 'INPUT' && inputTypeOf(control) === 'radio') {
                radios.push(control);
            } else if (tag === 'INPUT' && inputTypeOf(control) === 'checkbox') {
                checkboxes.push(control);
            } else {
                others.push(control);
            }
        }

        if (radios.length > 0) {
            for (var radioIndex = 0; radioIndex < radios.length; radioIndex++) {
                if (controlIsChecked(radios[radioIndex])) {
                    return controlValueOf(radios[radioIndex]);
                }
            }

            return null;
        }

        if (checkboxes.length > 0) {
            var checkedValues = [];

            for (var boxIndex = 0; boxIndex < checkboxes.length; boxIndex++) {
                if (controlIsChecked(checkboxes[boxIndex])) {
                    checkedValues.push(controlValueOf(checkboxes[boxIndex]));
                }
            }

            return collapseModelValues(checkedValues);
        }

        if (others.length === 1) {
            var only = others[0];

            if (tagNameOf(only) === 'SELECT') {
                return selectIsMultiple(only)
                    ? collapseModelValues(selectedOptionValues(only))
                    : firstSelectedOptionValue(only);
            }

            return controlValueOf(only);
        }

        if (others.length > 1) {
            var values = [];

            for (var otherIndex = 0; otherIndex < others.length; otherIndex++) {
                var other = others[otherIndex];

                if (tagNameOf(other) === 'SELECT' && selectIsMultiple(other)) {
                    var selected = selectedOptionValues(other);

                    for (var selectedIndex = 0; selectedIndex < selected.length; selectedIndex++) {
                        values.push(selected[selectedIndex]);
                    }
                } else if (tagNameOf(other) === 'SELECT') {
                    var first = firstSelectedOptionValue(other);

                    if (first !== null) {
                        values.push(first);
                    }
                } else {
                    values.push(controlValueOf(other));
                }
            }

            return values;
        }

        return null;
    }

    function dirtySerialized(value) {
        return JSON.stringify(value === undefined ? null : value);
    }

    function dirtyBaselineMap(scope, create) {
        var baselines = dirtyBaselines.get(scope);

        if (!baselines && create) {
            baselines = new Map();
            dirtyBaselines.set(scope, baselines);
        }

        return baselines;
    }

    function currentModelValue(scope, field) {
        return readModelValue(collectModelControls(scope, field));
    }

    function ensureDirtyBaseline(scope, field) {
        var baselines = dirtyBaselineMap(scope, true);

        if (!baselines.has(field)) {
            baselines.set(field, dirtySerialized(currentModelValue(scope, field)));
        }
    }

    function setDirtyBaseline(scope, field, value) {
        dirtyBaselineMap(scope, true).set(field, dirtySerialized(value));
    }

    function dirtyActiveFor(record) {
        var baselines = dirtyBaselines.get(record.scope);

        if (!baselines || !baselines.has(record.field)) {
            return false;
        }

        return baselines.get(record.field) !== dirtySerialized(currentModelValue(record.scope, record.field));
    }

    function refreshDirtyIndicators() {
        dirtyIndicators.forEach(function (record) {
            var dirty = dirtyActiveFor(record);

            if (dirty && !record.active) {
                activateLoading(record);
            } else if (!dirty && record.active) {
                deactivateLoading(record);
            }
        });
    }

    /**
     * Resolve the value a successful response actually acknowledged. The
     * rotated snapshot is authoritative; result.updated covers responses that
     * do not include a snapshot, and the submitted payload is the last resort.
     */
    function acknowledgedModelValue(detail, field, fallback) {
        var data = detail && detail.data;
        var snapshotState = data && data.snapshot ? data.snapshot.state : null;
        var updated = data && data.result ? data.result.updated : null;

        if (
            snapshotState
            && typeof snapshotState === 'object'
            && !Array.isArray(snapshotState)
            && Object.prototype.hasOwnProperty.call(snapshotState, field)
        ) {
            return snapshotState[field];
        }

        if (
            updated
            && typeof updated === 'object'
            && !Array.isArray(updated)
            && Object.prototype.hasOwnProperty.call(updated, field)
        ) {
            return updated[field];
        }

        return fallback;
    }

    /** Initialize the server-rendered value as the clean baseline at bind time. */
    function setupModelBaseline(context) {
        var field = modelFieldOf(context.element);

        if (field !== '') {
            ensureDirtyBaseline(loadingScopeOf(context.element), field);
        }

        var element = context.element;

        return function cleanupModel() {
            var timer = debounceTimers.get(element);

            if (timer !== undefined && timer !== null) {
                cancelTimer(timer);
                debounceTimers.delete(element);
            }
        };
    }

    /**
     * fiber:dirty marks an element as dirty while its model field differs from
     * the last server-acknowledged value. It only observes fiber:model, so it
     * never sends requests of its own.
     *
     * Syntax:
     * - on a model control: fiber:dirty[.class="name"][.remove]
     * - on a standalone indicator: fiber:dirty="field"[.remove]
     *   (a custom standalone class is not expressible; put .class on the
     *   model-bound control instead)
     *
     * Without .class the element is shown only while dirty (an indicator that
     * starts hidden is revealed, restored when clean). .remove inverts that
     * visibility so the element is hidden while dirty. .class adds the named
     * class (default: fiber-dirty) and leaves visibility untouched unless
     * .remove is also present.
     *
     * Supported controls are the same as fiber:model: text-like inputs,
     * textareas, checkboxes, radios, single selects, and multiple selects.
     */
    function setupDirty(context) {
        var element = context.element;
        var modifiers = Array.isArray(context.modifiers) ? context.modifiers : [];
        var hasClassModifier = modifiers.indexOf('class') !== -1;
        var removeWhileDirty = modifiers.indexOf('remove') !== -1;
        var className = hasClassModifier
            ? (String(context.value || '').trim() || 'fiber-dirty')
            : '';
        var field = String(context.value || '').trim();

        if (hasClassModifier || field === '') {
            var modelField = modelFieldOf(element);

            if (modelField !== '') {
                field = modelField;
            } else if (hasClassModifier) {
                return;
            }
        }

        if (field === '') {
            return;
        }

        var scope = loadingScopeOf(element);

        ensureDirtyBaseline(scope, field);

        var record = {
            element: element,
            scope: scope,
            field: field,
            className: className,
            managesVisibility: !hasClassModifier || removeWhileDirty,
            removeWhileLoading: removeWhileDirty,
            active: false,
            addedClass: false,
            originalHidden: null,
            originalDisplay: null,
        };

        dirtyIndicators.add(record);

        if (dirtyActiveFor(record)) {
            activateLoading(record);
        }

        return function cleanupDirty() {
            deactivateLoading(record);
            dirtyIndicators.delete(record);
        };
    }

    function modelActionOf(element) {
        return findAttributeValue(element, ['data-firefiber-model-action'])
            || configValue('modelAction')
            || DEFAULT_MODEL_ACTION;
    }

    async function dispatchModelAction(event, context) {
        var element = context.element;

        if (!element || !isModelControl(element) || isDisabledControl(element)) {
            return;
        }

        // Reflect the edit immediately, before any request settles.
        refreshDirtyIndicators();

        var field = modelFieldOf(element);

        if (field === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing model field name',
                kind: 'configuration',
            });

            return;
        }

        var scope = modelScopeOf(element);
        var controls = collectModelControls(scope, field);
        var value = readModelValue(controls);
        var serialized = JSON.stringify(value === undefined ? null : value);
        var tracker = modelValues.get(scope);

        if (!tracker) {
            tracker = new Map();
            modelValues.set(scope, tracker);
        }

        // input and change usually both fire for one edit, and browsers fire
        // input for selects too; only a real value change may reach the server.
        if (tracker.get(field) === serialized) {
            return;
        }

        tracker.set(field, serialized);

        context.event = event;

        var action = modelActionOf(element);
        var payload = {};
        payload[field] = value;
        var acknowledged = false;
        var previousAcknowledged = context.onAcknowledged;

        // dispatchAction() calls this before broadcasting firefiber:success, so
        // listeners already observe the new clean state.
        var ourAcknowledged = function (acknowledgement) {
            acknowledged = true;
            setDirtyBaseline(
                scope,
                field,
                acknowledgedModelValue(acknowledgement, field, payload[field]),
            );
            refreshDirtyIndicators();
        };

        context.onAcknowledged = ourAcknowledged;

        var detail;

        try {
            detail = await dispatchAction(element, context, action, payload);
        } catch (error) {
            if (tracker.get(field) === serialized) {
                tracker.delete(field);
            }

            throw error;
        } finally {
            // A re-entrant dispatch may already have installed its own
            // callback; never overwrite it with this older one.
            if (context.onAcknowledged === ourAcknowledged) {
                if (typeof previousAcknowledged === 'function') {
                    context.onAcknowledged = previousAcknowledged;
                } else {
                    delete context.onAcknowledged;
                }
            }
        }

        // The server rejected or skipped the change, so let the same value be
        // attempted again on the next interaction. HTTP 200 alone is not an
        // acknowledgement: the body must report an ok/success action status.
        var acknowledgedByServer = detail
            && detail.ok === true
            && detail.data
            && (detail.data.status === 'ok' || detail.data.status === 'success');

        if (!acknowledgedByServer) {
            if (tracker.get(field) === serialized) {
                tracker.delete(field);
            }
        } else if (!acknowledged) {
            // Fallback for a success path that did not run the callback.
            setDirtyBaseline(
                scope,
                field,
                acknowledgedModelValue(detail, field, payload[field]),
            );
        }

        refreshDirtyIndicators();

        return detail;
    }

    function reportModelException(context, error) {
        reportError(error, context);
        emitRuntimeEvent(context.element, 'firefiber:error', {
            action: String(context.value || ''),
            error: error && error.message ? error.message : String(error),
            kind: 'exception',
        });
    }

    function modelDebounceDelay(element) {
        if (!element || typeof element.getAttribute !== 'function') {
            return 0;
        }

        var raw = element.getAttribute('data-firefiber-debounce');

        if (raw === null || raw === undefined || raw === '') {
            return 0;
        }

        var delay = parseInt(raw, 10);

        return isFinite(delay) && delay > 0 ? Math.min(delay, 10000) : 0;
    }

    function handleModel(event, context) {
        var element = context.element;
        var delay = modelDebounceDelay(element);

        // Debounced inputs coalesce rapid edits into one server round trip.
        // The timer lives in a tracked map so stop() can cancel it.
        if (delay > 0 && element) {
            var existing = debounceTimers.get(element);

            if (existing !== undefined && existing !== null) {
                cancelTimer(existing);
            }

            var timer = scheduleTimer(function () {
                debounceTimers.delete(element);

                dispatchModelAction(null, context).catch(function (error) {
                    reportModelException(context, error);
                });
            }, delay);

            debounceTimers.set(element, timer);

            return;
        }

        dispatchModelAction(event, context).catch(function (error) {
            reportModelException(context, error);
        });
    }

    function navigationWindow() {
        var configured = state.config.window;

        if (configured && typeof configured.addEventListener === 'function') {
            return configured;
        }

        return root && typeof root.addEventListener === 'function' ? root : null;
    }

    function navigationLocation() {
        return state.config.location
            || (root && root.location)
            || null;
    }

    function navigationHistory() {
        return state.config.history
            || (root && root.history)
            || null;
    }

    function navigationDocument() {
        return state.document
            || (root && root.document)
            || null;
    }

    /** Navigation events are broadcast on the window when one is available. */
    function navigationEventTarget(element) {
        var window = navigationWindow();

        if (window && typeof window.dispatchEvent === 'function') {
            return window;
        }

        return element || navigationDocument();
    }

    function resolveNavigationUrl(href, base) {
        var UrlConstructor = state.config.URL
            || (root && root.URL)
            || (typeof URL === 'function' ? URL : null);

        if (!UrlConstructor) {
            return null;
        }

        try {
            return new UrlConstructor(String(href), String(base));
        } catch (error) {
            return null;
        }
    }

    function originOf(location) {
        if (location && typeof location.origin === 'string' && location.origin !== '') {
            return location.origin;
        }

        var url = resolveNavigationUrl(location && location.href, location && location.href);

        return url ? url.origin : null;
    }

    /**
     * Decide whether a click is an eligible fiber:navigate request. Returns
     * the resolved URL only for plain, same-origin, HTML navigations; every
     * other case keeps the browser's normal navigation.
     */
    function eligibleNavigationUrl(element, event, location) {
        if (!element || !location) {
            return null;
        }

        var tag = tagNameOf(element);

        if (tag !== 'A' && tag !== 'AREA') {
            return null;
        }

        var href = typeof element.getAttribute === 'function'
            ? element.getAttribute('href')
            : null;

        if (href === null || href === undefined || String(href).trim() === '') {
            return null;
        }

        href = String(href).trim();

        if (href.charAt(0) === '#') {
            return null;
        }

        if (typeof element.getAttribute === 'function' && element.getAttribute('download') !== null) {
            return null;
        }

        var target = typeof element.getAttribute === 'function'
            ? element.getAttribute('target')
            : null;

        if (target !== null && target !== '' && String(target).toLowerCase() !== '_self') {
            return null;
        }

        var rel = typeof element.getAttribute === 'function'
            ? element.getAttribute('rel')
            : null;

        if (rel !== null && /(^|\s)external(\s|$)/i.test(String(rel))) {
            return null;
        }

        if (event) {
            if (event.defaultPrevented === true) {
                return null;
            }

            if (typeof event.button === 'number' && event.button !== 0) {
                return null;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return null;
            }
        }

        var url = resolveNavigationUrl(href, location.href);
        var origin = originOf(location);

        if (!url || origin === null || url.origin !== origin) {
            return null;
        }

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return null;
        }

        return url;
    }

    function findNavigationContainer(document, selector) {
        if (!document) {
            return null;
        }

        if (selector === '') {
            return document.body || null;
        }

        if (typeof document.querySelector === 'function') {
            try {
                return document.querySelector(selector);
            } catch (error) {
                return null;
            }
        }

        if (typeof document.querySelectorAll === 'function') {
            try {
                var matches = document.querySelectorAll(selector);

                return matches && matches.length > 0 ? matches[0] : null;
            } catch (error) {
                return null;
            }
        }

        return null;
    }

    function clearNavigationContainer(container) {
        if (!container) {
            return;
        }

        // Prefer firstChild so text nodes are removed too; the fake DOM shim
        // only exposes an element children list.
        if (container.firstChild !== undefined) {
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }

            return;
        }

        while (container.children && container.children.length > 0) {
            container.removeChild(container.children[0]);
        }
    }

    function importNavigationNode(document, node) {
        if (document && typeof document.importNode === 'function') {
            try {
                return document.importNode(node, true);
            } catch (error) {
                // Fall through to the node as-is.
            }
        }

        return node;
    }

    function isHtmlResponse(response) {
        var headers = response && response.headers;
        var contentType = '';

        if (headers) {
            if (typeof headers.get === 'function') {
                contentType = String(headers.get('content-type') || '');
            } else {
                Object.keys(headers).forEach(function (name) {
                    if (name.toLowerCase() === 'content-type' && contentType === '') {
                        contentType = String(headers[name] || '');
                    }
                });
            }
        }

        contentType = contentType.toLowerCase();

        // A missing Content-Type is tolerated for compatibility; a declared
        // non-HTML type (JSON, files, streams) is not a navigation response.
        return contentType === '' || contentType.indexOf('text/html') !== -1;
    }

    function parseNavigationDocument(html) {
        if (typeof state.config.parseHTML === 'function') {
            return state.config.parseHTML(html);
        }

        var Parser = state.config.DOMParser || (root && root.DOMParser);

        if (typeof Parser !== 'function') {
            return null;
        }

        try {
            return new Parser().parseFromString(html, 'text/html');
        } catch (error) {
            return null;
        }
    }

    /** Replace the page container while keeping already-loaded scripts alive. */
    function renderNavigationDocument(parsed, selector) {
        var document = navigationDocument();

        if (!document) {
            throw new Error('Document is not available for navigation');
        }

        var current = findNavigationContainer(document, selector);
        var next = findNavigationContainer(parsed, selector);

        if (!current || !next || typeof current.append !== 'function') {
            throw new Error('Navigation target container is missing');
        }

        var scripts = [];
        var existing = current.children || [];

        for (var index = 0; index < existing.length; index++) {
            if (tagNameOf(existing[index]) === 'SCRIPT') {
                scripts.push(existing[index]);
            }
        }

        cleanupNode(current);
        clearNavigationContainer(current);

        var imported = [];
        var incoming = next.children || [];

        for (var incomingIndex = 0; incomingIndex < incoming.length; incomingIndex++) {
            imported.push(importNavigationNode(document, incoming[incomingIndex]));
        }

        current.append.apply(current, imported);

        // Re-attach loaded scripts instead of re-inserting fresh copies, which
        // keeps third-party libraries and page code intact.
        for (var scriptIndex = 0; scriptIndex < scripts.length; scriptIndex++) {
            current.append(scripts[scriptIndex]);
        }

        scanNode(current);

        if (parsed && typeof parsed.title === 'string') {
            document.title = parsed.title;
        }

        return current;
    }

    function fallbackNavigation(url) {
        var location = navigationLocation();

        if (!location) {
            return;
        }

        if (typeof location.assign === 'function') {
            location.assign(url.href);
        } else {
            location.href = url.href;
        }
    }

    async function navigateTo(element, selector, url, options) {
        var settings = options || {};
        var target = navigationEventTarget(element);
        var fetchImpl = state.config.fetch || (root && root.fetch) || null;

        emitRuntimeEvent(target, 'firefiber:navigate-start', {
            url: url.href,
        });

        navigationInFlight = true;
        beginNavigationLoading();

        try {
            if (typeof fetchImpl !== 'function') {
                throw new Error('fetch is not available for navigation');
            }

            var response = await fetchImpl(url.href, {
                method: 'GET',
                headers: {
                    'Accept': 'text/html',
                    [NAVIGATE_HEADER]: '1',
                },
                credentials: 'same-origin',
                redirect: 'follow',
                cache: 'no-store',
            });
            var status = response && response.status !== undefined ? Number(response.status) : 0;

            if (!response || !isSuccessfulResponse(response)) {
                throw new Error('Navigation request failed with status ' + status);
            }

            if (!isHtmlResponse(response)) {
                throw new Error('Navigation response is not HTML');
            }

            if (typeof response.text !== 'function') {
                throw new Error('Navigation response has no body');
            }

            var html = await response.text();
            var parsed = parseNavigationDocument(html);

            if (!parsed) {
                throw new Error('Navigation response could not be parsed');
            }

            renderNavigationDocument(parsed, selector);

            var finalUrl = url;

            if (response.url) {
                var resolved = resolveNavigationUrl(response.url, url.href);
                var origin = originOf(navigationLocation());

                if (resolved && origin !== null && resolved.origin === origin) {
                    finalUrl = resolved;
                }
            }

            if (settings.push !== false) {
                var history = navigationHistory();

                if (history && typeof history.pushState === 'function') {
                    history.pushState({ firefiber: true, url: finalUrl.href }, '', finalUrl.href);
                }
            }

            emitRuntimeEvent(target, 'firefiber:navigate-success', {
                url: finalUrl.href,
                status: status,
            });

            return finalUrl;
        } catch (error) {
            emitRuntimeEvent(target, 'firefiber:navigate-error', {
                url: url.href,
                error: error && error.message ? error.message : String(error),
            });

            if (settings.fallback !== false && element) {
                fallbackNavigation(url);
            }

            return null;
        } finally {
            navigationInFlight = false;
            endNavigationLoading();
        }
    }

    function handleNavigate(event, context) {
        if (navigationInFlight) {
            return;
        }

        var element = context.element;
        var url = eligibleNavigationUrl(element, event, navigationLocation());

        if (!url) {
            return;
        }

        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        var selector = String(context.value || '').trim();

        navigateTo(element, selector, url, { push: true, fallback: true }).catch(function (error) {
            reportError(error, context);
        });
    }

    function handlePopState() {
        if (navigationInFlight) {
            return;
        }

        var location = navigationLocation();
        var url = location ? resolveNavigationUrl(location.href, location.href) : null;

        if (!url) {
            return;
        }

        var configured = state.config.navigateContainer;
        var selector = typeof configured === 'string' ? configured : '';

        navigateTo(null, selector, url, { push: false, fallback: false }).catch(function (error) {
            reportError(error, null);
        });
    }

    function setupNavigation() {
        var window = navigationWindow();

        if (!window) {
            return;
        }

        if (!navigationPopstateListener) {
            navigationPopstateListener = function () {
                handlePopState();
            };
        }

        window.addEventListener('popstate', navigationPopstateListener);
    }

    function teardownNavigation() {
        var window = navigationWindow();

        if (window && navigationPopstateListener && typeof window.removeEventListener === 'function') {
            window.removeEventListener('popstate', navigationPopstateListener);
        }

        navigationPopstateListener = null;
        navigationInFlight = false;
    }

    function loadingStyle(element) {
        return element && element.style && typeof element.style === 'object'
            ? element.style
            : null;
    }

    function loadingScopeOf(element) {
        return modelScopeOf(element) || navigationDocument();
    }

    function loadingClassTokens(element) {
        var value = element && typeof element.getAttribute === 'function'
            ? element.getAttribute('class')
            : null;

        return value === null || value === undefined
            ? []
            : String(value).split(/\s+/).filter(Boolean);
    }

    function addLoadingClass(element, className) {
        var tokens = loadingClassTokens(element);

        if (tokens.indexOf(className) !== -1) {
            return false;
        }

        tokens.push(className);
        element.setAttribute('class', tokens.join(' '));

        return true;
    }

    /** Remove only this class; unrelated classes and their order are kept. */
    function removeLoadingClass(element, className) {
        var tokens = loadingClassTokens(element);
        var remaining = tokens.filter(function (token) {
            return token !== className;
        });

        if (remaining.length === tokens.length) {
            return;
        }

        if (remaining.length === 0) {
            if (typeof element.removeAttribute === 'function') {
                element.removeAttribute('class');
            }

            return;
        }

        element.setAttribute('class', remaining.join(' '));
    }

    function activateLoading(record) {
        var element = record.element;

        if (!element || typeof element.setAttribute !== 'function' || record.active) {
            return;
        }

        record.active = true;
        record.originalHidden = typeof element.getAttribute === 'function'
            ? element.getAttribute('hidden')
            : null;
        record.addedClass = false;

        var style = loadingStyle(element);
        record.originalDisplay = style && typeof style.display === 'string' ? style.display : null;

        if (record.className !== '') {
            // Only a class this activation actually adds may be removed again;
            // a pre-existing loading class belongs to the page.
            record.addedClass = addLoadingClass(element, record.className);
        }

        if (!record.managesVisibility) {
            return;
        }

        if (record.removeWhileLoading) {
            element.setAttribute('hidden', 'hidden');

            if (style) {
                style.display = 'none';
            }

            return;
        }

        // Default: the indicator is shown only while loading. Reveal an
        // element that is hidden either by the attribute or inline style.
        if (typeof element.removeAttribute === 'function') {
            element.removeAttribute('hidden');
        }

        if (style && record.originalDisplay === 'none') {
            style.display = '';
        }
    }

    function deactivateLoading(record) {
        if (!record.active) {
            return;
        }

        var element = record.element;

        if (element && typeof element.setAttribute === 'function') {
            if (record.className !== '' && record.addedClass) {
                removeLoadingClass(element, record.className);
            }

            if (record.managesVisibility) {
                if (record.originalHidden === null || record.originalHidden === undefined) {
                    if (typeof element.removeAttribute === 'function') {
                        element.removeAttribute('hidden');
                    }
                } else {
                    element.setAttribute('hidden', record.originalHidden);
                }

                var style = loadingStyle(element);

                if (style && record.originalDisplay !== null) {
                    style.display = record.originalDisplay;
                }
            }
        }

        record.active = false;
        record.addedClass = false;
        record.originalHidden = null;
        record.originalDisplay = null;
    }

    function loadingActiveFor(record) {
        var targeted = Array.isArray(record.targets) && record.targets.length > 0;

        // Navigation has no explicit per-action target, so it only drives
        // indicators that are not restricted to named actions.
        if (!targeted && navigationLoadingCount > 0) {
            return true;
        }

        var actions = loadingActions.get(record.scope);

        if (!actions) {
            return false;
        }

        if (!targeted) {
            return actions.size > 0;
        }

        for (var index = 0; index < record.targets.length; index++) {
            if ((actions.get(record.targets[index]) || 0) > 0) {
                return true;
            }
        }

        return false;
    }

    function refreshLoadingIndicators() {
        loadingIndicators.forEach(function (record) {
            var active = loadingActiveFor(record);

            if (active && !record.active) {
                activateLoading(record);
            } else if (!active && record.active) {
                deactivateLoading(record);
            }
        });

        refreshDisabledControls();
    }

    function beginActionLoading(element, action) {
        var scope = loadingScopeOf(element);

        if (!scope) {
            return;
        }

        var actions = loadingActions.get(scope);

        if (!actions) {
            actions = new Map();
            loadingActions.set(scope, actions);
        }

        var name = String(action || '');

        actions.set(name, (actions.get(name) || 0) + 1);
        refreshLoadingIndicators();
    }

    function endActionLoading(element, action) {
        var scope = loadingScopeOf(element);

        if (!scope) {
            return;
        }

        var actions = loadingActions.get(scope);

        if (!actions) {
            return;
        }

        var name = String(action || '');
        var count = Math.max(0, (actions.get(name) || 0) - 1);

        if (count === 0) {
            actions.delete(name);
        } else {
            actions.set(name, count);
        }

        if (actions.size === 0) {
            loadingActions.delete(scope);
        }

        refreshLoadingIndicators();
    }

    function beginNavigationLoading() {
        navigationLoadingCount++;
        refreshLoadingIndicators();
    }

    function endNavigationLoading() {
        navigationLoadingCount = Math.max(0, navigationLoadingCount - 1);
        refreshLoadingIndicators();
    }

    function isNativeDisableable(element) {
        return DISABLEABLE_TAGS.indexOf(tagNameOf(element)) !== -1;
    }

    /** Navigation has no explicit action identity, so it never disables. */
    function disabledActiveFor(record) {
        var actions = loadingActions.get(record.scope);

        if (!actions || actions.size === 0) {
            return false;
        }

        var targeted = Array.isArray(record.targets) && record.targets.length > 0;

        if (!targeted) {
            return true;
        }

        for (var index = 0; index < record.targets.length; index++) {
            if ((actions.get(record.targets[index]) || 0) > 0) {
                return true;
            }
        }

        return false;
    }

    function activateDisabled(record) {
        var element = record.element;

        if (!element || record.active) {
            return;
        }

        record.active = true;
        record.native = isNativeDisableable(element);

        if (record.native) {
            record.originalDisabled = element.disabled === true;
            record.originalDisabledAttribute = typeof element.getAttribute === 'function'
                ? element.getAttribute('disabled')
                : null;

            if ('disabled' in element) {
                element.disabled = true;
            }

            if (typeof element.setAttribute === 'function') {
                element.setAttribute('disabled', 'disabled');
            }

            return;
        }

        // Non-disableable elements get the ARIA state instead of a fake
        // native disabled property.
        record.originalAriaDisabled = typeof element.getAttribute === 'function'
            ? element.getAttribute('aria-disabled')
            : null;

        if (typeof element.setAttribute === 'function') {
            element.setAttribute('aria-disabled', 'true');
        }
    }

    function deactivateDisabled(record) {
        if (!record.active) {
            return;
        }

        var element = record.element;

        if (element && typeof element.setAttribute === 'function') {
            if (record.native) {
                if ('disabled' in element) {
                    element.disabled = record.originalDisabled === true;
                }

                if (record.originalDisabledAttribute === null || record.originalDisabledAttribute === undefined) {
                    if (typeof element.removeAttribute === 'function') {
                        element.removeAttribute('disabled');
                    }
                } else {
                    element.setAttribute('disabled', record.originalDisabledAttribute);
                }
            } else if (record.originalAriaDisabled === null || record.originalAriaDisabled === undefined) {
                if (typeof element.removeAttribute === 'function') {
                    element.removeAttribute('aria-disabled');
                }
            } else {
                element.setAttribute('aria-disabled', record.originalAriaDisabled);
            }
        }

        record.active = false;
        record.native = false;
        record.originalDisabled = false;
        record.originalDisabledAttribute = null;
        record.originalAriaDisabled = null;
    }

    function refreshDisabledControls() {
        disabledControls.forEach(function (record) {
            var active = disabledActiveFor(record);

            if (active && !record.active) {
                activateDisabled(record);
            } else if (!active && record.active) {
                deactivateDisabled(record);
            }
        });
    }

    function setupDisabled(context) {
        var record = {
            element: context.element,
            scope: loadingScopeOf(context.element),
            targets: (loadingTargets.get(context.element) || []).slice(),
            active: false,
            native: false,
            originalDisabled: false,
            originalDisabledAttribute: null,
            originalAriaDisabled: null,
        };

        disabledControls.add(record);

        if (disabledActiveFor(record)) {
            activateDisabled(record);
        }

        return function cleanupDisabled() {
            deactivateDisabled(record);
            disabledControls.delete(record);
        };
    }

    /**
     * Parse a fiber:target value into action names. Names may be separated by
     * commas and/or whitespace: fiber:target="saveProfile, deletePost".
     */
    function parseTargetActions(value) {
        var names = [];

        String(value || '').split(/[\s,]+/).forEach(function (raw) {
            var name = raw.trim();

            if (name !== '' && names.indexOf(name) === -1) {
                names.push(name);
            }
        });

        return names;
    }

    /**
     * fiber:target restricts a loading indicator to the named component
     * actions. Targets are matched against the action string actually passed
     * to dispatchAction(), inside the indicator's own component scope.
     */
    function setupTarget(context) {
        var element = context.element;
        var targets = parseTargetActions(context.value);

        loadingTargets.set(element, targets);
        loadingIndicators.forEach(function (record) {
            if (record.element === element) {
                record.targets = targets.slice();
            }
        });
        disabledControls.forEach(function (record) {
            if (record.element === element) {
                record.targets = targets.slice();
            }
        });
        refreshLoadingIndicators();

        return function cleanupTarget() {
            loadingTargets.delete(element);
            loadingIndicators.forEach(function (record) {
                if (record.element === element) {
                    record.targets = [];
                }
            });
            disabledControls.forEach(function (record) {
                if (record.element === element) {
                    record.targets = [];
                }
            });
            refreshLoadingIndicators();
        };
    }

    /**
     * fiber:loading observes the existing request lifecycle instead of owning
     * state of its own. Modifiers:
     * - (none): shown while loading, hidden again afterwards
     * - .remove: hidden while loading, restored afterwards
     * - .class="name": adds the class (default fiber-loading) while loading
     */
    function setupLoading(context) {
        var modifiers = Array.isArray(context.modifiers) ? context.modifiers : [];
        var hasClassModifier = modifiers.indexOf('class') !== -1;
        var removeWhileLoading = modifiers.indexOf('remove') !== -1;
        var className = hasClassModifier
            ? (String(context.value || '').trim() || 'fiber-loading')
            : '';
        var record = {
            element: context.element,
            scope: loadingScopeOf(context.element),
            targets: (loadingTargets.get(context.element) || []).slice(),
            className: className,
            managesVisibility: !hasClassModifier || removeWhileLoading,
            removeWhileLoading: removeWhileLoading,
            active: false,
            addedClass: false,
            originalHidden: null,
            originalDisplay: null,
        };

        loadingIndicators.add(record);

        // A node bound while a matching request is already pending reflects it
        // immediately instead of waiting for the next lifecycle change.
        if (loadingActiveFor(record)) {
            activateLoading(record);
        }

        return function cleanupLoading() {
            deactivateLoading(record);
            loadingIndicators.delete(record);
        };
    }

    /**
     * Send one action request and hand back the pieces every caller needs.
     */
    async function sendActionRequest(config, action, payload, requestId) {
        var snapshot = config.snapshot;
        var bodyData = {
            protocolVersion: config.protocolVersion,
            type: 'handleAction',
            requestId: requestId,
            componentId: config.componentId,
            componentName: config.componentName,
            componentClass: config.componentName,
            action: action,
            payload: payload,
            csrfToken: config.csrfToken,
            signature: snapshot.signature,
            snapshot: snapshot,
        };
        var body = JSON.stringify(bodyData);
        var signature = await signRequestBody(body, config);
        var headers = Object.assign({}, config.headers, {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Firefiber-Target': '1',
            'X-Csrf-Token': config.csrfToken,
        });

        if (nonEmptyString(signature)) {
            headers['X-Firefiber-Signature'] = signature;
        }

        var response = await config.fetch(config.endpoint, {
            method: 'POST',
            headers: headers,
            body: body,
            credentials: config.credentials,
            cache: 'no-store',
            redirect: 'follow',
        });

        return {
            response: response,
            payload: await readResponsePayload(response),
            status: response && response.status !== undefined ? Number(response.status) : 0,
            ok: isSuccessfulResponse(response),
        };
    }

    /**
     * Whether the server rejected the request because the client snapshot is
     * no longer current. The server answers 409 Conflict for that case
     * (5 = expired snapshot, 6 = modified snapshot); any other status is a
     * different failure and must not be retried.
     */
    function isStaleSnapshot(exchange) {
        if (exchange.ok || exchange.status !== 409 || !exchange.payload) {
            return false;
        }

        var errorCode = exchange.payload.errorCode !== undefined
            ? Number(exchange.payload.errorCode)
            : null;

        return errorCode === null || errorCode === 5 || errorCode === 6;
    }

    /**
     * Fetch the server's authoritative snapshot for this component and adopt
     * it, so a stale page can recover without a full reload. Returns false when
     * the server has no state for the component (nothing can be replayed).
     */
    async function resynchronize(element, config) {
        if (
            !nonEmptyString(config.endpoint)
            || !nonEmptyString(config.componentId)
            || typeof config.fetch !== 'function'
        ) {
            return false;
        }

        var bodyData = {
            protocolVersion: config.protocolVersion,
            type: 'getSnapshot',
            requestId: createRequestId(),
            componentId: config.componentId,
            componentName: config.componentName,
            componentClass: config.componentName,
            csrfToken: config.csrfToken,
            signature: config.snapshot && nonEmptyString(config.snapshot.signature)
                ? config.snapshot.signature
                : '',
        };

        var response;
        var payload;

        try {
            response = await config.fetch(config.endpoint, {
                method: 'POST',
                headers: Object.assign({}, config.headers, {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Firefiber-Target': '1',
                    'X-Csrf-Token': config.csrfToken,
                }),
                body: JSON.stringify(bodyData),
                credentials: config.credentials,
                cache: 'no-store',
                redirect: 'follow',
            });

            payload = await readResponsePayload(response);
        } catch (error) {
            return false;
        }

        if (!isSuccessfulResponse(response) || !payload || payload.status !== 'snapshot') {
            return false;
        }

        var snapshot = payload.snapshot;

        if (
            !snapshot
            || typeof snapshot !== 'object'
            || !nonEmptyString(snapshot.signature)
        ) {
            return false;
        }

        rememberSnapshot(config.componentId, snapshot);
        applySnapshotToDom(config.componentId, snapshot);

        return true;
    }

    function handleClick(event, context) {
        dispatchClickAction(event, context).catch(function (error) {
            reportError(error, context);
            emitRuntimeEvent(context.element, 'firefiber:error', {
                action: String(context.value || ''),
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
        });
    }

    async function dispatchAction(element, context, action, payload) {
        var modifiers = Array.isArray(context.modifiers) ? context.modifiers.slice() : [];
        var hasModifier = function (name) {
            return modifiers.indexOf(name) !== -1;
        };

        var event = context.event;

        if (hasModifier('self') && event && event.target && event.target !== element) {
            return;
        }

        if (hasModifier('prevent') && event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        if (hasModifier('stop') && event && typeof event.stopPropagation === 'function') {
            event.stopPropagation();
        }

        if (hasModifier('once')) {
            if (onceHandled.has(element)) {
                return;
            }

            onceHandled.add(element);

            if (typeof context.dispose === 'function') {
                context.dispose();
            }
        }

        if (action === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing action name',
                kind: 'configuration',
            });

            return;
        }

        var config = resolveActionConfig(element);

        if (!payload && config.payload) {
            payload = config.payload;
        }

        if (
            !nonEmptyString(config.componentId)
            || !config.snapshot
            || !nonEmptyString(config.snapshot.signature)
        ) {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: action,
                componentId: config.componentId,
                error: 'Missing component identity or signed snapshot',
                kind: 'configuration',
            });

            return;
        }

        if (!nonEmptyString(config.csrfToken) || !nonEmptyString(config.endpoint)) {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: action,
                componentId: config.componentId,
                error: 'Missing CSRF token or action endpoint',
                kind: 'configuration',
            });

            return;
        }

        if (typeof config.fetch !== 'function') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: action,
                componentId: config.componentId,
                error: 'fetch is not available',
                kind: 'configuration',
            });

            return;
        }

        var pendingKey = config.componentId + '\u0000' + action;

        if (pendingActions.has(pendingKey)) {
            return;
        }

        pendingActions.set(pendingKey, true);

        var requestId = createRequestId();
        var seq = (actionSequences.get(config.componentId) || 0) + 1;
        actionSequences.set(config.componentId, seq);

        // Opt-in optimistic interaction: patch the DOM immediately, then let
        // the authoritative response confirm or roll it back.
        var optimisticMap = optimisticMapFor(config.componentId);
        var optimisticPatch = optimisticMap
            && Object.prototype.hasOwnProperty.call(optimisticMap, action)
            && optimisticMap[action]
            && typeof optimisticMap[action] === 'object'
            && !Array.isArray(optimisticMap[action])
            ? optimisticMap[action]
            : null;
        var preActionSnapshot = optimisticPatch
            ? (snapshots.get(config.componentId) || config.snapshot || null)
            : null;

        var restoreOptimistic = function () {
            if (!optimisticPatch) {
                return;
            }

            if (seq < (appliedSequences.get(config.componentId) || 0)) {
                // A newer response already produced fresher authoritative
                // state; restoring an older snapshot would clobber it.
                return;
            }

            if (preActionSnapshot) {
                applySnapshotToDom(config.componentId, preActionSnapshot);
            }

            emitRuntimeEvent(element, 'firefiber:rollback', {
                requestId: requestId,
                componentId: config.componentId,
                action: action,
                snapshot: preActionSnapshot,
            });
        };

        if (optimisticPatch) {
            applyOptimisticState(config.componentId, optimisticPatch);
            emitRuntimeEvent(element, 'firefiber:optimistic', {
                requestId: requestId,
                componentId: config.componentId,
                action: action,
                patch: optimisticPatch,
            });
        }

        if (!context.silent) {
            beginActionLoading(element, action);
        }

        try {
            var exchange = await sendActionRequest(config, action, payload, requestId);

            // A signed snapshot carries a short replay window. When the server
            // rejects it as stale (the page was open too long, or a concurrent
            // request already moved the component forward), fetch the
            // authoritative snapshot and replay the action once instead of
            // leaving the control silently dead.
            if (isStaleSnapshot(exchange) && await resynchronize(element, config)) {
                var refreshed = resolveActionConfig(element) || config;

                emitRuntimeEvent(element, 'firefiber:resync', {
                    requestId: requestId,
                    componentId: config.componentId,
                    action: action,
                    errorCode: exchange.payload && exchange.payload.errorCode !== undefined
                        ? Number(exchange.payload.errorCode)
                        : null,
                });

                exchange = await sendActionRequest(refreshed, action, payload, createRequestId());
            }

            var response = exchange.response;
            var responsePayload = exchange.payload;
            var status = exchange.status;
            var ok = exchange.ok;
            var detail = {
                requestId: requestId,
                componentId: config.componentId,
                action: action,
                status: status,
                ok: ok,
                data: responsePayload,
                error: responsePayload && responsePayload.error ? responsePayload.error : null,
                errorCode: responsePayload && responsePayload.errorCode !== undefined ? responsePayload.errorCode : null,
            };

            if (ok && responsePayload && (responsePayload.status === 'ok' || responsePayload.status === 'success')) {
                // Out-of-order protection: only the freshest response may
                // write authoritative state back into the DOM.
                var newest = seq >= (appliedSequences.get(config.componentId) || 0);

                if (newest) {
                    appliedSequences.set(config.componentId, seq);

                    // Server-driven markup (lazy loads, polling refreshes and
                    // opt-in re-renders) replaces the component fragment.
                    if (typeof responsePayload.html === 'string' && responsePayload.html !== '') {
                        replaceComponentHtml(config.componentId, responsePayload.html);
                    }

                    if (
                        responsePayload.snapshot
                        && typeof responsePayload.snapshot === 'object'
                        && !Array.isArray(responsePayload.snapshot)
                    ) {
                        rememberSnapshot(config.componentId, responsePayload.snapshot);
                        applySnapshotToDom(config.componentId, responsePayload.snapshot);
                    }

                    clearValidationErrors(config.componentId);

                    var parentSnapshots = Array.isArray(responsePayload.parentSnapshots)
                        ? responsePayload.parentSnapshots
                        : [];
                    for (var parentIndex = 0; parentIndex < parentSnapshots.length; parentIndex++) {
                        var parentSnapshot = parentSnapshots[parentIndex];
                        if (
                            parentSnapshot
                            && typeof parentSnapshot === 'object'
                            && !Array.isArray(parentSnapshot)
                            && nonEmptyString(parentSnapshot.componentId)
                        ) {
                            rememberSnapshot(parentSnapshot.componentId, parentSnapshot);
                            applySnapshotToDom(parentSnapshot.componentId, parentSnapshot);
                            clearValidationErrors(parentSnapshot.componentId);
                            emitRuntimeEvent(element, 'firefiber:parent-updated', Object.assign({}, detail, {
                                parentComponentId: parentSnapshot.componentId,
                                parentSnapshot: parentSnapshot,
                            }));
                        }
                    }
                }

                // Let the originating directive update its own acknowledgement
                // state before observers receive the success event.
                if (typeof context.onAcknowledged === 'function') {
                    try {
                        context.onAcknowledged(detail);
                    } catch (error) {
                        reportError(error, context);
                    }
                }

                if (newest) {
                    queueUrlSync(config.componentId);
                }

                emitRuntimeEvent(element, 'firefiber:success', detail);

                return detail;
            }

            restoreOptimistic();

            var errorCode = responsePayload && responsePayload.errorCode !== undefined
                ? Number(responsePayload.errorCode)
                : null;

            if (status === 422 || errorCode === 1 || (responsePayload && responsePayload.errors)) {
                updateValidationErrorDisplay(responsePayload && responsePayload.errors ? responsePayload.errors : {}, config.componentId);
                emitRuntimeEvent(element, 'firefiber:validation-error', detail);
            } else if (errorCode === 5) {
                emitRuntimeEvent(element, 'firefiber:expired', detail);
            } else {
                emitRuntimeEvent(element, 'firefiber:error', detail);
            }

            return detail;
        } catch (error) {
            restoreOptimistic();

            var failure = {
                requestId: requestId,
                componentId: config.componentId,
                action: action,
                status: 0,
                ok: false,
                data: null,
                error: error && error.message ? error.message : String(error),
                errorCode: null,
                kind: 'network',
            };

            emitRuntimeEvent(element, 'firefiber:error', failure);

            return failure;
        } finally {
            pendingActions.delete(pendingKey);

            if (!context.silent) {
                endActionLoading(element, action);
            }
        }
    }

    function handleSubmit(event, context) {
        dispatchSubmitAction(event, context).catch(function (error) {
            reportError(error, context);
            emitRuntimeEvent(context.element, 'firefiber:error', {
                action: String(context.value || ''),
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
        });
    }

    async function dispatchSubmitAction(event, context) {
        var element = context.element;
        var value = String(context.value || '').trim();
        var parts = value.split('.');
        var modifiers = [];

        while (parts.length > 1) {
            var modifier = parts[parts.length - 1].toLowerCase();

            if (CLICK_MODIFIERS.indexOf(modifier) === -1) {
                break;
            }

            modifiers.push(modifier);
            parts.pop();
        }

        var action = parts.join('.');
        var hasModifier = function (name) {
            return modifiers.indexOf(name) !== -1;
        };

        if (action === '') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: '',
                error: 'Missing action name',
                kind: 'configuration',
            });

            return;
        }

        // Confirmation happens before modifiers so a cancellation does not
        // consume .once or apply .prevent/.stop semantics.
        if (!confirmFirefiberAction(element, event)) {
            return;
        }

        // A form is always intercepted, so its default submission is always
        // prevented. The .prevent modifier is accepted for API consistency and
        // is a no-op for submit beyond the intercept semantics above.
        if (hasModifier('prevent') && event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        if (hasModifier('stop') && event && typeof event.stopPropagation === 'function') {
            event.stopPropagation();
        }

        if (hasModifier('once')) {
            if (onceHandled.has(element)) {
                return;
            }

            onceHandled.add(element);

            if (typeof context.dispose === 'function') {
                context.dispose();
            }
        }

        var payload = serializeForm(context.element);

        return dispatchAction(element, context, action, payload);
    }

    function configure(options) {
        if (!options || typeof options !== 'object') {
            return api;
        }

        var headers = options.headers;

        Object.keys(options).forEach(function (key) {
            if (key !== 'headers') {
                state.config[key] = options[key];
            }
        });

        if (headers && typeof headers === 'object') {
            state.config.headers = Object.assign({}, state.config.headers || {}, headers);
        }

        return api;
    }

    function reportError(error, context) {
        if (typeof state.onError === 'function') {
            state.onError(error, context);

            return;
        }

        if (root && root.console && typeof root.console.error === 'function') {
            root.console.error('Firefiber runtime error', error);
        }
    }

    function createContext(element, descriptor) {
        return {
            element: element,
            name: descriptor.name,
            modifiers: Array.isArray(descriptor.modifiers) ? descriptor.modifiers.slice() : [],
            value: descriptor.value,
            attribute: descriptor.attribute,
            prefix: descriptor.prefix,
            definition: descriptor.definition,
        };
    }

    function attachListener(element, definition, context, record, eventName) {
        var listener = function (event) {
            definition.handler.call(element, event, context);
        };

        element.addEventListener(eventName, listener, definition.options || false);
        record.listeners.push({
            event: eventName,
            listener: listener,
            options: definition.options || false,
        });
    }

    function bindElement(element) {
        var descriptors = registry.parse(element, state.prefix);
        var elementBindings = bindings.get(element);
        var bound = 0;

        for (var index = 0; index < descriptors.length; index++) {
            var descriptor = descriptors[index];

            if (!descriptor.known) {

                continue;
            }

            if (elementBindings && elementBindings.has(descriptor.name)) {

                continue;
            }

            var definition = descriptor.definition;
            var context = createContext(element, descriptor);
            var record = {
                context: context,
                listeners: [],
                cleanups: [],
            };

            try {
                if (typeof definition.setup === 'function') {
                    var setupCleanup = definition.setup(context);

                    if (typeof setupCleanup === 'function') {
                        record.cleanups.push(setupCleanup);
                    }
                }

        

                var events = normalizeEvents(definition.event);

                if (typeof definition.handler === 'function') {
                    events.forEach(function (eventName) {
                        attachListener(element, definition, context, record, eventName);
                    });
                }
            } catch (error) {
    
                cleanupRecord(element, record);
                reportError(error, context);
                continue;
            }

            if (!elementBindings) {
                elementBindings = new Map();
                bindings.set(element, elementBindings);
            }

            elementBindings.set(descriptor.name, record);
            trackedElements.add(element);
            // Capture the directive name: `descriptor` is loop-scoped with var,
            // so a multi-directive element would otherwise dispose the last one.
            context.dispose = (function (bindingName) {
                return function () {
                    return disposeBinding(element, bindingName);
                };
            })(descriptor.name);
            bound++;
        }

        return bound;
    }

    function cleanupRecord(element, record) {
        record.listeners.forEach(function (entry) {
            element.removeEventListener(entry.event, entry.listener, entry.options);
        });

        record.cleanups.forEach(function (cleanup) {
            try {
                cleanup();
            } catch (error) {
                reportError(error, record.context);
            }
        });

        if (typeof record.context.definition.cleanup === 'function') {
            try {
                record.context.definition.cleanup(record.context);
            } catch (error) {
                reportError(error, record.context);
            }
        }
    }

    function disposeBinding(element, name) {
        var elementBindings = bindings.get(element);

        if (!elementBindings || !elementBindings.has(name)) {
            return false;
        }

        var record = elementBindings.get(name);
        cleanupRecord(element, record);
        elementBindings.delete(name);

        if (elementBindings.size === 0) {
            bindings.delete(element);
            trackedElements.delete(element);
        }

        return true;
    }

    function cleanupElement(element) {
        var elementBindings = bindings.get(element);

        if (!elementBindings) {
            return 0;
        }

        var cleaned = 0;

        elementBindings.forEach(function (record) {
            cleanupRecord(element, record);
            cleaned++;
        });

        bindings.delete(element);
        trackedElements.delete(element);

        return cleaned;
    }

    function cleanupNode(node) {
        if (!node) {
            return 0;
        }

        var cleaned = 0;

        if (node.nodeType === 1) {
            cleaned += cleanupElement(node);
            cleanupAdvancedComponent(node);
        }

        if (typeof node.querySelectorAll === 'function') {
            var descendants = node.querySelectorAll('*');

            for (var index = 0; index < descendants.length; index++) {
                cleaned += cleanupElement(descendants[index]);
                cleanupAdvancedComponent(descendants[index]);
            }
        }

        return cleaned;
    }

    function scanNode(node) {
        if (!node) {
            return 0;
        }

        var bound = 0;

        if (node.nodeType === 1) {
            bound += bindElement(node);
            setupAdvancedComponent(node);
        }

        if (typeof node.querySelectorAll === 'function') {
            var descendants = node.querySelectorAll('*');


            for (var index = 0; index < descendants.length; index++) {
                bound += bindElement(descendants[index]);
                setupAdvancedComponent(descendants[index]);
            }
        }

        return bound;
    }

    function handleMutations(mutations) {
        for (var index = 0; index < mutations.length; index++) {
            var mutation = mutations[index];
            var removed = mutation.removedNodes || [];
            var added = mutation.addedNodes || [];

            for (var removedIndex = 0; removedIndex < removed.length; removedIndex++) {
                cleanupNode(removed[removedIndex]);
            }

            for (var addedIndex = 0; addedIndex < added.length; addedIndex++) {
                scanNode(added[addedIndex]);
            }
        }
    }

    function scan(target) {
        var scanTarget = target || state.root || (root && root.document);

        if (!scanTarget) {
            return 0;
        }

        return scanNode(scanTarget);
    }

    function boot(options) {
        if (state.booted) {
            return state.bootResult;
        }

        options = options || {};

        // Allow the common explicit forms boot(document) and boot(element)
        // in addition to the full options object.
        if (options.nodeType === 9) {
            options = { document: options };
        } else if (options.nodeType === 1) {
            options = {
                document: options.ownerDocument || null,
                root: options,
            };
        }

        configure(options);

        var document = options.document || (root && root.document) || null;
        var scanRoot = options.root || document;

        if (!scanRoot) {
            throw new Error('Firefiber.boot() requires a document or root element');
        }

        state.document = document || scanRoot.ownerDocument || null;
        state.root = scanRoot;
        state.prefix = typeof options.prefix === 'string' && options.prefix !== ''
            ? options.prefix.toLowerCase()
            : DEFAULT_PREFIX;
        state.onError = typeof options.onError === 'function' ? options.onError : null;

        var Observer = options.MutationObserver || (root && root.MutationObserver);

        if (typeof Observer === 'function') {
            var observeTarget = state.document && state.document.documentElement
                ? state.document.documentElement
                : scanRoot;

            state.observer = new Observer(handleMutations);
            state.observer.observe(observeTarget, {
                childList: true,
                subtree: true,
            });
        }

        var bound = scan(scanRoot);


        setupNavigation();

        state.booted = true;
        state.bootResult = Object.freeze({
            booted: true,
            prefix: state.prefix,
            directives: registry.list().length,
            bound: bound,
        });

        return state.bootResult;
    }

    function stop() {
        var wasBooted = state.booted;

        teardownNavigation();

        if (state.observer && typeof state.observer.disconnect === 'function') {
            state.observer.disconnect();
        }

        trackedElements.forEach(function (element) {
            cleanupElement(element);
        });

        trackedElements.clear();
        bindings = new WeakMap();
        snapshots.clear();
        pendingActions.clear();
        onceHandled = new WeakSet();
        signingKeyCache.clear();
        modelValues = new WeakMap();
        loadingIndicators.clear();
        disabledControls.clear();
        loadingActions = new WeakMap();
        loadingTargets = new WeakMap();
        navigationLoadingCount = 0;
        confirmedEvents = new WeakSet();
        changeEvents = new WeakSet();
        keydownEvents = new WeakSet();
        dirtyIndicators.clear();
        dirtyBaselines = new WeakMap();

        advancedComponents.forEach(function (record, element) {
            cleanupAdvancedComponent(element);
        });
        advancedComponents.clear();
        urlSyncRecords.clear();
        teardownUrlListenersIfEmpty();
        debounceTimers.forEach(function (timer) {
            cancelTimer(timer);
        });
        debounceTimers.clear();
        actionSequences.clear();
        appliedSequences.clear();
        state.observer = null;
        state.document = null;
        state.root = null;
        state.booted = false;
        state.bootResult = null;
        state.onError = null;

        return wasBooted;
    }

    // ------------------------------------------------------------------
    // Advanced component capabilities: URL synchronization, polling, lazy
    // loading, optimistic interactions and uploads. Every record is keyed
    // by the component root element and torn down with it.
    // ------------------------------------------------------------------

    function scheduleTimer(callback, delay) {
        var timerFn = state.config.setTimeout
            || (root && typeof root.setTimeout === 'function' ? root.setTimeout.bind(root) : null)
            || (typeof setTimeout === 'function' ? setTimeout : null);

        if (typeof timerFn !== 'function') {
            callback();
            return null;
        }

        return timerFn(callback, delay);
    }

    function cancelTimer(timer) {
        if (timer === null || timer === undefined) {
            return;
        }

        var cancelFn = state.config.clearTimeout
            || (root && typeof root.clearTimeout === 'function' ? root.clearTimeout.bind(root) : null)
            || (typeof clearTimeout === 'function' ? clearTimeout : null);

        if (typeof cancelFn === 'function') {
            cancelFn(timer);
        }
    }

    function attributeOf(element, name) {
        return element && typeof element.getAttribute === 'function'
            ? element.getAttribute(name)
            : null;
    }

    function parseJsonObject(value) {
        if (!value) {
            return null;
        }

        if (typeof value === 'object' && !Array.isArray(value)) {
            return value;
        }

        try {
            var parsed = JSON.parse(String(value));

            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function findRecordById(componentId) {
        var found = null;

        advancedComponents.forEach(function (record) {
            if (found === null && record.componentId === componentId) {
                found = record;
            }
        });

        return found;
    }

    function documentContains(element) {
        if (!element) {
            return false;
        }

        var doc = state.document || (root && root.document) || null;

        if (doc && doc.documentElement && typeof doc.documentElement.contains === 'function') {
            try {
                return doc.documentElement.contains(element);
            } catch (error) {
                // fall through to the parent walk below
            }
        }

        var cursor = element;

        while (cursor) {
            if (cursor === doc || cursor === doc.documentElement || cursor === doc.body) {
                return true;
            }

            cursor = cursor.parentNode || cursor.parentElement || null;
        }

        return false;
    }

    // ------------------------------ URL sync -----------------------------

    function parseSearchString(search) {
        var params = {};
        var raw = String(search || '').replace(/^\?/, '');

        if (raw === '') {
            return params;
        }

        raw.split('&').forEach(function (pair) {
            if (pair === '') {
                return;
            }

            var separator = pair.indexOf('=');
            var rawKey = separator === -1 ? pair : pair.slice(0, separator);
            var rawValue = separator === -1 ? '' : pair.slice(separator + 1);
            var key;
            var value;

            try {
                key = decodeURIComponent(rawKey.replace(/\+/g, ' '));
            } catch (error) {
                key = rawKey;
            }

            try {
                value = decodeURIComponent(rawValue.replace(/\+/g, ' '));
            } catch (decodeError) {
                value = rawValue;
            }

            if (key === '') {
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(params, key)) {
                params[key] = [];
            }

            params[key].push(value);
        });

        return params;
    }

    function buildSearchString(params) {
        var parts = [];

        Object.keys(params).forEach(function (key) {
            var values = params[key];

            if (!Array.isArray(values)) {
                values = [values];
            }

            values.forEach(function (value) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
            });
        });

        return parts.length > 0 ? '?' + parts.join('&') : '';
    }

    function urlScalar(value) {
        if (value === null || value === undefined) {
            return '';
        }

        if (typeof value === 'object') {
            try {
                return JSON.stringify(value);
            } catch (error) {
                return '';
            }
        }

        return String(value);
    }

    /** Serialize one component state value into URL parameter value(s). */
    function serializeUrlValue(value, definition) {
        if (value === null || value === undefined) {
            return null;
        }

        if (definition.multiple) {
            var items = Array.isArray(value) ? value : [value];
            var serialized = items
                .map(urlScalar)
                .filter(function (entry) { return entry !== ''; });

            return serialized.length > 0 ? serialized : null;
        }

        if (definition.type === 'bool') {
            return value === true || value === 1 || value === '1' || value === 'true' ? '1' : '0';
        }

        if (definition.type === 'int') {
            var number = Number(value);

            if (!isFinite(number) || Math.floor(number) !== number) {
                return null;
            }

            return String(number);
        }

        var text = urlScalar(value);

        return text === '' ? null : text;
    }

    /** Compare a raw URL value with the component's current state value. */
    function urlMatchesState(raw, current, definition) {
        if (definition.multiple) {
            var currentItems = Array.isArray(current) ? current : (current === null || current === undefined ? [] : [current]);
            var rawItems = Array.isArray(raw) ? raw : [raw];

            if (currentItems.length !== rawItems.length) {
                return false;
            }

            return currentItems.every(function (item, index) {
                return urlScalar(item) === String(rawItems[index]);
            });
        }

        if (current === null || current === undefined) {
            return false;
        }

        if (definition.type === 'int') {
            return /^-?\d+$/.test(String(raw)) && String(current) === String(raw);
        }

        if (definition.type === 'bool') {
            var truthy = current === true || current === 1 || current === '1' || current === 'true';

            return (truthy && (raw === '1' || raw === 'true'))
                || (!truthy && (raw === '0' || raw === 'false'));
        }

        return String(current) === String(raw);
    }

    /**
     * Write the component's synchronized state into the URL.
     *
     * mode 'replace' keeps the address bar current without new history
     * entries; mode 'push' records one settled entry per interaction burst.
     */
    function syncUrlForRecord(record, mode) {
        if (!record || !record.querySync) {
            return;
        }

        var location = navigationLocation();
        var history = navigationHistory();

        if (!location || !history || typeof history.replaceState !== 'function') {
            return;
        }

        var snapshot = snapshots.get(record.componentId);
        var currentState = snapshot && snapshot.state && typeof snapshot.state === 'object' && !Array.isArray(snapshot.state)
            ? snapshot.state
            : null;

        if (!currentState) {
            return;
        }

        var params = parseSearchString(location.search);
        var changed = false;

        Object.keys(record.querySync).forEach(function (key) {
            var definition = record.querySync[key];

            if (!definition || typeof definition !== 'object' || !nonEmptyString(definition.param)) {
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(currentState, key)) {
                return;
            }

            var param = definition.param;
            var serialized = serializeUrlValue(currentState[key], definition);
            var existing = Object.prototype.hasOwnProperty.call(params, param) ? params[param] : null;

            if (serialized === null) {
                if (existing !== null) {
                    delete params[param];
                    changed = true;
                }

                return;
            }

            var next = (Array.isArray(serialized) ? serialized : [serialized]).map(String);

            if (existing !== null && existing.length === next.length && existing.every(function (value, index) {
                return value === next[index];
            })) {
                return;
            }

            params[param] = next;
            changed = true;
        });

        // Unchanged URLs need no replace, but a settled push must still
        // record a history entry when this search has not been pushed yet.
        if (!changed && (mode !== 'push' || record.lastPushedSearch === search)) {
            return;
        }

        var search = buildSearchString(params);
        var target;

        if (nonEmptyString(location.href)) {
            var href = String(location.href);
            var hashIndex = href.indexOf('#');
            var hash = hashIndex >= 0 ? href.slice(hashIndex) : '';
            var withoutHash = hashIndex >= 0 ? href.slice(0, hashIndex) : href;
            var queryIndex = withoutHash.indexOf('?');
            var base = queryIndex >= 0 ? withoutHash.slice(0, queryIndex) : withoutHash;
            target = base + search + hash;
        } else {
            target = String(location.pathname || '') + search + String(location.hash || '');
        }

        try {
            if (mode === 'push' && typeof history.pushState === 'function' && record.lastPushedSearch !== search) {
                history.pushState(null, '', target);
                record.lastPushedSearch = search;
            } else {
                history.replaceState(null, '', target);
            }
        } catch (error) {
            reportError(error, { element: record.root });
            return;
        }

        emitRuntimeEvent(record.root, 'firefiber:urlchange', {
            componentId: record.componentId,
            search: search,
            mode: mode,
        });
    }

    /** Keep the URL current during an interaction, then settle one entry. */
    function queueUrlSync(componentId) {
        var record = findRecordById(componentId);

        if (!record || !record.querySync) {
            return;
        }

        syncUrlForRecord(record, 'replace');

        if (record.urlPushTimer !== null && record.urlPushTimer !== undefined) {
            cancelTimer(record.urlPushTimer);
        }

        record.urlPushTimer = scheduleTimer(function () {
            record.urlPushTimer = null;
            syncUrlForRecord(record, 'push');
        }, PUSH_SETTLE_MS);
    }

    function ensureUrlListeners() {
        if (urlPopstateListener) {
            return;
        }

        var window = navigationWindow();

        if (!window || typeof window.addEventListener !== 'function') {
            return;
        }

        urlPopstateListener = function () {
            handleUrlPopstate();
        };

        window.addEventListener('popstate', urlPopstateListener);
    }

    function teardownUrlListenersIfEmpty() {
        if (urlSyncRecords.size > 0 || !urlPopstateListener) {
            return;
        }

        var window = navigationWindow();

        if (window && typeof window.removeEventListener === 'function') {
            window.removeEventListener('popstate', urlPopstateListener);
        }

        urlPopstateListener = null;
    }

    /**
     * Back/forward navigation: push the URL's values back through the normal
     * validated model action so the server stays authoritative.
     */
    function handleUrlPopstate() {
        var records = Array.from(urlSyncRecords);
        var location = navigationLocation();

        records.forEach(function (record) {
            if (!documentContains(record.root)) {
                cleanupAdvancedComponent(record.root);
                return;
            }

            if (record.urlPushTimer !== null && record.urlPushTimer !== undefined) {
                cancelTimer(record.urlPushTimer);
                record.urlPushTimer = null;
            }

            var params = parseSearchString(location ? location.search : '');
            var snapshot = snapshots.get(record.componentId);
            var currentState = snapshot && snapshot.state && typeof snapshot.state === 'object'
                ? snapshot.state
                : {};
            var payload = {};
            var changed = false;

            Object.keys(record.querySync).forEach(function (key) {
                var definition = record.querySync[key];

                if (!definition || typeof definition !== 'object' || !nonEmptyString(definition.param)) {
                    return;
                }

                var param = definition.param;
                var present = Object.prototype.hasOwnProperty.call(params, param);
                var raw = present
                    ? (definition.multiple ? params[param].slice() : params[param][0])
                    : null;
                var current = Object.prototype.hasOwnProperty.call(currentState, key)
                    ? currentState[key]
                    : undefined;

                if (raw === null) {
                    // Missing parameter: reset the key to its default.
                    if (current !== undefined) {
                        payload[key] = null;
                        changed = true;
                    }

                    return;
                }

                if (!urlMatchesState(raw, current, definition)) {
                    payload[key] = raw;
                    changed = true;
                }
            });

            if (!changed) {
                return;
            }

            var context = {
                element: record.root,
                value: '',
                modifiers: [],
                event: null,
                silent: true,
            };

            // URL state is pushed through the built-in query action so the
            // server re-validates every value against the component's
            // declared query-sync configuration instead of trusting
            // arbitrary model fields.
            Promise.resolve(dispatchAction(record.root, context, QUERY_ACTION, payload)).then(function () {
                // Reconcile the URL with whatever the server accepted.
                syncUrlForRecord(record, 'replace');
            });
        });
    }

    // --------------------------- component records -----------------------

    function setupAdvancedComponent(element) {
        if (!element || element.nodeType !== 1 || typeof element.getAttribute !== 'function') {
            return;
        }

        var componentId = attributeOf(element, 'data-firefiber-component');

        if (!nonEmptyString(componentId) || advancedComponents.has(element)) {
            return;
        }

        var pollInterval = parseInt(attributeOf(element, 'data-firefiber-poll') || '', 10);
        var record = {
            root: element,
            componentId: componentId,
            querySync: parseJsonObject(attributeOf(element, 'data-firefiber-query-sync')),
            optimistic: parseJsonObject(attributeOf(element, 'data-firefiber-optimistic')),
            uploadEndpoint: attributeOf(element, 'data-firefiber-upload-endpoint'),
            pollInterval: isFinite(pollInterval) && pollInterval > 0 ? pollInterval : 0,
            pollPausedHidden: attributeOf(element, 'data-firefiber-poll-paused-hidden') !== '0',
            pollTimer: null,
            pollFailures: 0,
            pollStopped: false,
            pollSuspended: false,
            visibilityListener: null,
            lazyTrigger: attributeOf(element, 'data-firefiber-lazy'),
            lazyLoaded: false,
            lazyInFlight: false,
            lazyAttempts: 0,
            lazyObserver: null,
            lazyRetryTimer: null,
            urlPushTimer: null,
            lastPushedSearch: null,
        };

        advancedComponents.set(element, record);

        if (record.querySync) {
            var location = navigationLocation();
            record.lastPushedSearch = location ? String(location.search || '') : '';
            urlSyncRecords.add(record);
            ensureUrlListeners();
        }

        if (record.pollInterval > 0) {
            schedulePoll(record, record.pollInterval);
        }

        if (record.lazyTrigger === 'visible') {
            setupLazyObserver(record);
        }
    }

    function cleanupAdvancedComponent(element) {
        var record = advancedComponents.get(element);

        if (!record) {
            return false;
        }

        record.pollStopped = true;

        if (record.pollTimer !== null && record.pollTimer !== undefined) {
            cancelTimer(record.pollTimer);
        }

        record.pollTimer = null;

        if (record.lazyRetryTimer !== null && record.lazyRetryTimer !== undefined) {
            cancelTimer(record.lazyRetryTimer);
        }

        record.lazyRetryTimer = null;

        if (record.urlPushTimer !== null && record.urlPushTimer !== undefined) {
            cancelTimer(record.urlPushTimer);
        }

        record.urlPushTimer = null;

        if (record.lazyObserver && typeof record.lazyObserver.disconnect === 'function') {
            record.lazyObserver.disconnect();
        }

        record.lazyObserver = null;

        if (record.visibilityListener) {
            var doc = navigationDocument();

            if (doc && typeof doc.removeEventListener === 'function') {
                doc.removeEventListener('visibilitychange', record.visibilityListener);
            }

            record.visibilityListener = null;
        }

        advancedComponents.delete(element);
        urlSyncRecords.delete(record);
        teardownUrlListenersIfEmpty();

        return true;
    }

    // ------------------------------ polling ------------------------------

    function isDocumentHidden() {
        var doc = navigationDocument();

        return !!(doc && typeof doc.hidden === 'boolean' ? doc.hidden : false);
    }

    function ensureVisibilityListener(record) {
        if (record.visibilityListener) {
            return;
        }

        var doc = navigationDocument();

        if (!doc || typeof doc.addEventListener !== 'function') {
            return;
        }

        record.visibilityListener = function () {
            handleVisibilityChange(record);
        };

        doc.addEventListener('visibilitychange', record.visibilityListener);
    }

    function handleVisibilityChange(record) {
        if (record.pollStopped || !advancedComponents.has(record.root)) {
            return;
        }

        if (record.pollPausedHidden && isDocumentHidden()) {
            record.pollSuspended = true;

            if (record.pollTimer !== null && record.pollTimer !== undefined) {
                cancelTimer(record.pollTimer);
                record.pollTimer = null;
            }

            return;
        }

        if (record.pollSuspended) {
            record.pollSuspended = false;
            schedulePoll(record, 0);
        }
    }

    function pollBackoffDelay(record) {
        return Math.min(record.pollInterval * Math.pow(2, record.pollFailures), 60000);
    }

    function schedulePoll(record, delay) {
        if (!record || record.pollStopped || record.pollTimer !== null && record.pollTimer !== undefined) {
            return;
        }

        ensureVisibilityListener(record);

        if (record.pollPausedHidden && isDocumentHidden()) {
            record.pollSuspended = true;
            return;
        }

        record.pollTimer = scheduleTimer(function () {
            record.pollTimer = null;
            tickPoll(record);
        }, Math.max(0, delay));
    }

    function tickPoll(record) {
        if (record.pollStopped || !advancedComponents.has(record.root)) {
            return;
        }

        if (record.pollPausedHidden && isDocumentHidden()) {
            record.pollSuspended = true;
            return;
        }

        var context = {
            element: record.root,
            value: '',
            modifiers: [],
            event: null,
            silent: true,
        };
        var result;

        try {
            result = dispatchAction(record.root, context, 'firefiberPoll', {});
        } catch (error) {
            reportError(error, context);
            record.pollFailures += 1;
            emitRuntimeEvent(record.root, 'firefiber:pollerror', {
                componentId: record.componentId,
                error: error && error.message ? error.message : String(error),
            });
            schedulePoll(record, pollBackoffDelay(record));
            return;
        }

        // dispatchAction resolves null when the request was de-duplicated;
        // reschedule at the normal interval without counting it as a failure.
        Promise.resolve(result).then(function (detail) {
            if (record.pollStopped || !advancedComponents.has(record.root)) {
                return;
            }

            if (!detail || typeof detail !== 'object') {
                schedulePoll(record, record.pollInterval);
                return;
            }

            var ok = detail.ok === true
                && detail.data
                && (detail.data.status === 'ok' || detail.data.status === 'success');

            if (ok) {
                record.pollFailures = 0;

                if (detail.data.polling === false) {
                    record.pollStopped = true;
                    emitRuntimeEvent(record.root, 'firefiber:pollstopped', detail);
                    return;
                }

                emitRuntimeEvent(record.root, 'firefiber:poll', detail);
                schedulePoll(record, record.pollInterval);
                return;
            }

            record.pollFailures += 1;
            emitRuntimeEvent(record.root, 'firefiber:pollerror', detail);
            schedulePoll(record, pollBackoffDelay(record));
        });
    }

    // ---------------------------- lazy loading ---------------------------

    function setupLazyObserver(record) {
        var Observer = state.config.IntersectionObserver
            || (root && root.IntersectionObserver)
            || null;

        if (typeof Observer === 'function') {
            try {
                record.lazyObserver = new Observer(function (entries) {
                    for (var index = 0; index < entries.length; index++) {
                        var entry = entries[index];

                        if (entry && (entry.isIntersecting || entry.intersectionRatio > 0)) {
                            triggerLazyLoad(record, false);
                            break;
                        }
                    }
                }, typeof state.config.intersectionOptions === 'object' && state.config.intersectionOptions
                    ? state.config.intersectionOptions
                    : { threshold: 0.1 });

                record.lazyObserver.observe(record.root);

                return;
            } catch (error) {
                reportError(error, { element: record.root });
                record.lazyObserver = null;
            }
        }

        // Progressive enhancement: without an observer the deferred content
        // loads as soon as the runtime is idle instead of never rendering.
        record.lazyRetryTimer = scheduleTimer(function () {
            record.lazyRetryTimer = null;
            triggerLazyLoad(record, false);
        }, 0);
    }

    function triggerLazyLoad(record, manual) {
        if (!record || record.lazyInFlight || record.lazyLoaded || !advancedComponents.has(record.root)) {
            return Promise.resolve(null);
        }

        if (!manual && record.lazyAttempts >= 3) {
            return Promise.resolve(null);
        }

        record.lazyInFlight = true;
        record.lazyAttempts += 1;

        var context = {
            element: record.root,
            value: '',
            modifiers: [],
            event: null,
            silent: true,
        };
        var result;

        try {
            result = dispatchAction(record.root, context, 'firefiberLoad', {});
        } catch (error) {
            record.lazyInFlight = false;
            reportError(error, context);
            emitRuntimeEvent(record.root, 'firefiber:loaderror', {
                componentId: record.componentId,
                error: error && error.message ? error.message : String(error),
            });
            return Promise.resolve(null);
        }

        return Promise.resolve(result).then(function (detail) {
            record.lazyInFlight = false;

            if (
                detail
                && detail.ok === true
                && detail.data
                && (detail.data.status === 'ok' || detail.data.status === 'success')
                && detail.data.loaded
            ) {
                record.lazyLoaded = true;
                record.lazyAttempts = 0;

                if (record.lazyRetryTimer !== null && record.lazyRetryTimer !== undefined) {
                    cancelTimer(record.lazyRetryTimer);
                    record.lazyRetryTimer = null;
                }

                if (record.lazyObserver && typeof record.lazyObserver.disconnect === 'function') {
                    record.lazyObserver.disconnect();
                }

                record.lazyObserver = null;
                emitRuntimeEvent(record.root, 'firefiber:loaded', detail);

                return detail;
            }

            emitRuntimeEvent(record.root, 'firefiber:loaderror', detail);

            // 404 means the component does not support lazy loading at all.
            var permanent = detail && detail.ok === false && detail.status === 404;

            if (permanent) {
                record.lazyLoaded = true;
                return detail;
            }

            if (record.lazyAttempts < 3) {
                var delay = 500 * Math.pow(2, record.lazyAttempts - 1);

                record.lazyRetryTimer = scheduleTimer(function () {
                    record.lazyRetryTimer = null;
                    triggerLazyLoad(record, false);
                }, delay);
            }

            return detail;
        });
    }

    function loadLazyById(componentId, options) {
        var record = findRecordById(componentId);

        if (!record) {
            return Promise.resolve(null);
        }

        if (options && options.force) {
            record.lazyLoaded = false;
            record.lazyAttempts = 0;
        }

        return triggerLazyLoad(record, true);
    }

    // ----------------------------- optimistic ----------------------------

    function optimisticMapFor(componentId) {
        var record = findRecordById(componentId);

        if (record && record.optimistic) {
            return record.optimistic;
        }

        var componentRoot = findComponentRoot(componentId);

        return componentRoot
            ? parseJsonObject(attributeOf(componentRoot, 'data-firefiber-optimistic'))
            : null;
    }

    /** Apply an optimistic state patch to bound values only. */
    function applyOptimisticState(componentId, patch) {
        var componentRoot = findComponentRoot(componentId);

        if (!componentRoot || !patch) {
            return false;
        }

        applySnapshotToDom(componentId, { state: patch }, true);

        return true;
    }

    // --------------------------- server-driven HTML ----------------------

    function replaceComponentHtml(componentId, html) {
        var componentRoot = findComponentRoot(componentId);

        if (!componentRoot || typeof html !== 'string' || typeof componentRoot.innerHTML === 'undefined') {
            return false;
        }

        // Copy the live collection before replacing the markup.
        var oldChildren = [];
        var children = componentRoot.children;

        if (children) {
            for (var index = 0; index < children.length; index++) {
                oldChildren.push(children[index]);
            }
        }

        try {
            componentRoot.innerHTML = html;
        } catch (error) {
            reportError(error, { element: componentRoot });
            return false;
        }

        for (var oldIndex = 0; oldIndex < oldChildren.length; oldIndex++) {
            cleanupNode(oldChildren[oldIndex]);
        }

        scanNode(componentRoot);

        return true;
    }

    // -------------------------------- uploads ----------------------------

    function componentRootOf(element) {
        var current = element;

        while (current && current.nodeType === 1) {
            if (attributeOf(current, 'data-firefiber-component')) {
                return current;
            }

            current = current.parentNode || current.parentElement || null;
        }

        return null;
    }

    /**
     * Upload files for a component field through the dedicated multipart
     * endpoint. Progress, success and failure surface as runtime events.
     */
    function uploadFiles(componentId, field, files, options) {
        options = options || {};

        var record = findRecordById(componentId);
        var componentRoot = (record && record.root) || findComponentRoot(componentId);

        if (!componentRoot) {
            return Promise.reject(new Error('Unknown Firefiber component: ' + componentId));
        }

        if (!nonEmptyString(field)) {
            return Promise.reject(new Error('A Firefiber upload field is required.'));
        }

        var config = resolveActionConfig(componentRoot);
        var endpoint = options.endpoint
            || (record && record.uploadEndpoint)
            || attributeOf(componentRoot, 'data-firefiber-upload-endpoint');

        if (!nonEmptyString(endpoint)) {
            return Promise.reject(new Error('Firefiber upload endpoint is not configured.'));
        }

        if (!nonEmptyString(config.csrfToken)) {
            return Promise.reject(new Error('Firefiber CSRF token is missing.'));
        }

        var list = files && typeof files.length === 'number'
            ? Array.prototype.slice.call(files)
            : [files];
        list = list.filter(function (file) { return !!file; });

        if (list.length === 0) {
            return Promise.resolve({
                ok: false,
                status: 0,
                componentId: componentId,
                field: field,
                error: 'No files selected.',
            });
        }

        var XHR = state.config.XMLHttpRequest
            || (root && root.XMLHttpRequest)
            || (typeof XMLHttpRequest !== 'undefined' ? XMLHttpRequest : null);

        if (typeof XHR !== 'function') {
            return Promise.reject(new Error('XMLHttpRequest is not available.'));
        }

        var FormDataConstructor = state.config.FormData
            || (root && root.FormData)
            || (typeof FormData !== 'undefined' ? FormData : null);

        if (typeof FormDataConstructor !== 'function') {
            return Promise.reject(new Error('FormData is not available.'));
        }

        var formData = new FormDataConstructor();
        var fieldName = list.length > 1 ? field + '[]' : field;

        list.forEach(function (file) {
            formData.append(fieldName, file);
        });

        var requestId = createRequestId();
        formData.append('componentId', componentId);
        formData.append('componentName', config.componentName || '');
        formData.append('componentClass', config.componentName || '');
        formData.append('field', field);
        formData.append('requestId', requestId);
        formData.append('protocolVersion', config.protocolVersion);
        formData.append('csrfToken', config.csrfToken);

        if (config.snapshot) {
            formData.append('snapshot', JSON.stringify(config.snapshot));
        }

        return new Promise(function (resolve) {
            var xhr = new XHR();

            try {
                xhr.open('POST', String(endpoint), true);
                xhr.setRequestHeader('X-Firefiber-Target', '1');
                xhr.setRequestHeader('X-Firefiber-Upload', '1');
                xhr.setRequestHeader('X-Csrf-Token', config.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.withCredentials = config.credentials === 'include';

                if (xhr.upload && typeof xhr.upload.addEventListener === 'function') {
                    xhr.upload.addEventListener('progress', function (event) {
                        var total = event && event.lengthComputable ? Number(event.total) : 0;
                        var loaded = event ? Number(event.loaded) : 0;

                        emitRuntimeEvent(componentRoot, 'firefiber:uploadprogress', {
                            componentId: componentId,
                            field: field,
                            requestId: requestId,
                            loaded: loaded,
                            total: total,
                            percent: total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0,
                        });
                    });
                }

                xhr.onload = function () {
                    var payload = null;

                    try {
                        payload = JSON.parse(String(xhr.responseText || ''));
                    } catch (error) {
                        payload = null;
                    }

                    var ok = xhr.status >= 200 && xhr.status < 300
                        && payload
                        && (payload.status === 'ok' || payload.status === 'success');
                    var detail = {
                        requestId: requestId,
                        componentId: componentId,
                        field: field,
                        status: Number(xhr.status) || 0,
                        ok: !!ok,
                        data: payload,
                        error: payload && payload.error ? payload.error : null,
                        errorCode: payload && payload.errorCode !== undefined ? payload.errorCode : null,
                    };

                    if (ok) {
                        if (
                            payload.html
                            && typeof payload.html === 'string'
                            && payload.snapshot
                            && typeof payload.snapshot === 'object'
                            && !Array.isArray(payload.snapshot)
                        ) {
                            // Server-driven re-render: the staged file list is
                            // rebuilt from authoritative state, then binds apply.
                            replaceComponentHtml(componentId, payload.html);
                        }

                        if (
                            payload.snapshot
                            && typeof payload.snapshot === 'object'
                            && !Array.isArray(payload.snapshot)
                        ) {
                            rememberSnapshot(componentId, payload.snapshot);
                            applySnapshotToDom(componentId, payload.snapshot);
                        }

                        clearValidationErrors(componentId);
                        emitRuntimeEvent(componentRoot, 'firefiber:uploadsuccess', detail);
                    } else {
                        if (payload && payload.errors) {
                            updateValidationErrorDisplay(payload.errors, componentId);
                        }

                        emitRuntimeEvent(componentRoot, 'firefiber:uploaderror', detail);
                    }

                    resolve(detail);
                };

                xhr.onerror = function () {
                    var detail = {
                        requestId: requestId,
                        componentId: componentId,
                        field: field,
                        status: 0,
                        ok: false,
                        data: null,
                        error: 'Upload failed: network error',
                        kind: 'network',
                    };

                    emitRuntimeEvent(componentRoot, 'firefiber:uploaderror', detail);
                    resolve(detail);
                };

                xhr.onabort = function () {
                    var detail = {
                        requestId: requestId,
                        componentId: componentId,
                        field: field,
                        status: 0,
                        ok: false,
                        data: null,
                        error: 'Upload aborted',
                        kind: 'abort',
                    };

                    emitRuntimeEvent(componentRoot, 'firefiber:uploaderror', detail);
                    resolve(detail);
                };

                xhr.send(formData);
            } catch (error) {
                var detail = {
                    requestId: requestId,
                    componentId: componentId,
                    field: field,
                    status: 0,
                    ok: false,
                    data: null,
                    error: error && error.message ? error.message : String(error),
                    kind: 'exception',
                };

                emitRuntimeEvent(componentRoot, 'firefiber:uploaderror', detail);
                resolve(detail);
            }
        });
    }

    function handleUpload(event, context) {
        var element = context.element;
        var field = String(context.value || '').trim();
        var files = element && element.files;

        if (!files || files.length === 0) {
            return;
        }

        var componentRoot = componentRootOf(element);
        var componentId = componentRoot ? attributeOf(componentRoot, 'data-firefiber-component') : null;

        if (!nonEmptyString(componentId)) {
            emitRuntimeEvent(element, 'firefiber:uploaderror', {
                action: 'upload',
                error: 'Upload control is outside a Firefiber component',
                kind: 'configuration',
            });
            return;
        }

        uploadFiles(componentId, field, files).catch(function (error) {
            emitRuntimeEvent(element, 'firefiber:uploaderror', {
                componentId: componentId,
                field: field,
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
        });
    }

    registry.register('click', {
        event: 'click',
        handler: handleClick,
    });

    registry.register('submit', {
        event: 'submit',
        handler: handleSubmit,
    });

    registry.register('model', {
        event: MODEL_EVENTS,
        setup: setupModelBaseline,
        handler: handleModel,
    });

    registry.register('navigate', {
        event: 'click',
        handler: handleNavigate,
    });

    registry.register('loading', {
        setup: setupLoading,
    });

    registry.register('target', {
        setup: setupTarget,
    });

    registry.register('confirm', {});

    registry.register('error', {});

    registry.register('change', {
        event: 'change',
        handler: handleChange,
    });

    registry.register('keydown', {
        event: 'keydown',
        handler: handleKeydown,
    });

    registry.register('disabled', {
        setup: setupDisabled,
    });

    registry.register('dirty', {
        setup: setupDirty,
    });

    registry.register('upload', {
        event: 'change',
        handler: handleUpload,
    });

    var api = {
        version: VERSION,
        registry: registry,
        directives: registry,
        registerDirective: registry.register,
        parseDirectives: registry.parse,
        configure: configure,

        /**
         * Dispatches a named component action from a form with fiber:submit.
         * Returns the action detail (or null) without broadcasting an event.
         */
        dispatchSubmit: function (form, action) {
            var context = {
                element: form,
                value: String(action || '').trim(),
                modifiers: [],
            };

            return dispatchSubmitAction(null, context);
        },

        /**
         * Explicitly start the runtime. Nothing is scanned or observed until
         * this method is called.
         */
        boot: boot,
        bootstrap: boot,
        start: boot,
        init: boot,

        stop: stop,

        scan: scan,
        cleanup: cleanupNode,

        /**
         * Upload files for a component field. Returns a promise resolving to
         * the action detail; progress arrives as firefiber:uploadprogress.
         */
        upload: uploadFiles,

        /** Force a lazy component to load (resets the retry budget). */
        loadLazy: function loadLazy(componentId) {
            return loadLazyById(componentId, { force: true });
        },

        /** Alias for loadLazy() kept for symmetry with retry semantics. */
        retryLazy: function retryLazy(componentId) {
            return loadLazyById(componentId, { force: true });
        },

        /** Start polling for a component (after stopPolling). */
        startPolling: function startPolling(componentId) {
            var record = findRecordById(componentId);

            if (!record || record.pollInterval <= 0) {
                return false;
            }

            record.pollStopped = false;
            record.pollFailures = 0;
            record.pollSuspended = false;
            schedulePoll(record, record.pollInterval);

            return true;
        },

        /** Pause polling for a component until startPolling is called. */
        stopPolling: function stopPolling(componentId) {
            var record = findRecordById(componentId);

            if (!record) {
                return false;
            }

            record.pollStopped = true;

            if (record.pollTimer !== null && record.pollTimer !== undefined) {
                cancelTimer(record.pollTimer);
            }

            record.pollTimer = null;

            return true;
        },

        /** Write the component's synchronized state into the URL now. */
        syncUrl: function syncUrl(componentId) {
            var record = findRecordById(componentId);

            if (!record) {
                return false;
            }

            syncUrlForRecord(record, 'replace');

            return true;
        },

        isBooted: function isBooted() {
            return state.booted === true;
        },
    };

    define(api, '__firefiberRuntime', true);

    return api;
});
