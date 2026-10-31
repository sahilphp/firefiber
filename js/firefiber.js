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
            snapshot: snapshot,
            csrfToken: csrfToken,
            endpoint: endpoint,
            hmacKey: hmacKey,
            payload: parsePayloadValue(payloadValue),
            protocolVersion: String(protocolVersion),
            headers: headers,
            credentials: configValue('credentials') || 'same-origin',
            fetch: state.config.fetch || (global && global.fetch) || (root && root.fetch) || null,
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
            throw new Error('Firefiber request signing key is not configured');
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

        if (field === '') {
            return;
        }

        ensureDirtyBaseline(loadingScopeOf(context.element), field);
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

    function handleModel(event, context) {
        dispatchModelAction(event, context).catch(function (error) {
            reportError(error, context);
            emitRuntimeEvent(context.element, 'firefiber:error', {
                action: String(context.value || ''),
                error: error && error.message ? error.message : String(error),
                kind: 'exception',
            });
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

        if (!nonEmptyString(config.hmacKey) && typeof config.signBody !== 'function') {
            emitRuntimeEvent(element, 'firefiber:error', {
                action: action,
                componentId: config.componentId,
                error: 'Firefiber request signing key is not configured',
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
        beginActionLoading(element, action);

        var requestId = createRequestId();

        try {
            var bodyData = {
                protocolVersion: config.protocolVersion,
                type: 'handleAction',
                requestId: requestId,
                componentId: config.componentId,
                action: action,
                payload: payload,
                csrfToken: config.csrfToken,
                signature: config.snapshot.signature,
                snapshot: config.snapshot,
            };
            var body = JSON.stringify(bodyData);
            var signature = await signRequestBody(body, config);
            var headers = Object.assign({}, config.headers, {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Firefiber-Target': '1',
                'X-Csrf-Token': config.csrfToken,
                'X-Firefiber-Signature': signature,
            });
    
            var response = await config.fetch(config.endpoint, {
                method: 'POST',
                headers: headers,
                body: body,
                credentials: config.credentials,
                cache: 'no-store',
                redirect: 'follow',
            });

            var responsePayload = await readResponsePayload(response);
            var status = response && response.status !== undefined ? Number(response.status) : 0;
            var ok = isSuccessfulResponse(response);
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
                if (
                    responsePayload.snapshot
                    && typeof responsePayload.snapshot === 'object'
                    && !Array.isArray(responsePayload.snapshot)
                ) {
                    rememberSnapshot(config.componentId, responsePayload.snapshot);
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

                emitRuntimeEvent(element, 'firefiber:success', detail);

                return detail;
            }

            var errorCode = responsePayload && responsePayload.errorCode !== undefined
                ? Number(responsePayload.errorCode)
                : null;

            if (status === 422 || errorCode === 1 || (responsePayload && responsePayload.errors)) {
                emitRuntimeEvent(element, 'firefiber:validation-error', detail);
            } else if (errorCode === 5) {
                emitRuntimeEvent(element, 'firefiber:expired', detail);
            } else {
                emitRuntimeEvent(element, 'firefiber:error', detail);
            }

            return detail;
        } catch (error) {
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
            endActionLoading(element, action);
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
        }

        if (typeof node.querySelectorAll === 'function') {
            var descendants = node.querySelectorAll('*');

            for (var index = 0; index < descendants.length; index++) {
                cleaned += cleanupElement(descendants[index]);
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
        }

        if (typeof node.querySelectorAll === 'function') {
            var descendants = node.querySelectorAll('*');


            for (var index = 0; index < descendants.length; index++) {
                bound += bindElement(descendants[index]);
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
        state.observer = null;
        state.document = null;
        state.root = null;
        state.booted = false;
        state.bootResult = null;
        state.onError = null;

        return wasBooted;
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

        isBooted: function isBooted() {
            return state.booted === true;
        },
    };

    define(api, '__firefiberRuntime', true);

    return api;
});
