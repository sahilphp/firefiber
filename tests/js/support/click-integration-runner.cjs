'use strict';

const { FakeDocument, FakeMutationObserver } = require('./fake-dom.cjs');

const [
    runtimePath,
    componentId,
    snapshotJson,
    csrfToken,
    hmacKey,
] = process.argv.slice(2);

function waitForAny(element, types, timeoutMs = 3000) {
    return new Promise((resolve, reject) => {
        const listeners = [];
        const timer = setTimeout(() => {
            cleanup();
            reject(new Error(`Timed out waiting for ${types.join(' or ')}`));
        }, timeoutMs);
        const cleanup = () => {
            clearTimeout(timer);

            for (const entry of listeners) {
                element.removeEventListener(entry.type, entry.listener);
            }
        };

        for (const type of types) {
            const listener = (event) => {
                cleanup();
                resolve(event);
            };

            element.addEventListener(type, listener);
            listeners.push({ type, listener });
        }
    });
}

async function main() {
    if (!runtimePath || !componentId || !snapshotJson || !csrfToken || !hmacKey) {
        throw new Error('Missing integration runner arguments');
    }

    const snapshot = JSON.parse(snapshotJson);
    const runtime = require(runtimePath);
    const document = new FakeDocument();
    const root = document.createElement('div');
    const button = document.createElement('button');

    root.setAttribute('data-firefiber-component', componentId);
    root.setAttribute('data-firefiber-snapshot', snapshotJson);
    button.setAttribute('fiber:click', 'increment');
    root.append(button);
    document.body.append(root);

    const requests = [];
    const fetch = (url, options) => {
        requests.push({ url, options });

        const previous = Number(snapshot.state && snapshot.state.count ? snapshot.state.count : 0);
        const count = previous + 1;

        return Promise.resolve({
            ok: true,
            status: 200,
            text: async () => JSON.stringify({
                status: 'ok',
                componentId,
                action: 'increment',
                result: { previous, count },
                snapshot: {
                    ...snapshot,
                    state: { count },
                    timestamp: Math.floor(Date.now() / 1000),
                    signature: 'integration-fresh-snapshot',
                },
            }),
        });
    };

    runtime.configure({
        endpoint: 'https://firefiber.test/firefiber',
        csrfToken,
        hmacKey,
        protocolVersion: '3.1',
        fetch,
    });
    runtime.boot({
        document,
        MutationObserver: FakeMutationObserver,
    });

    const requestsBeforeClick = requests.length;
    const result = waitForAny(button, [
        'firefiber:success',
        'firefiber:validation-error',
        'firefiber:expired',
        'firefiber:error',
    ]);

    button.dispatchEvent({
        type: 'click',
        target: button,
        preventDefault() {},
        stopPropagation() {},
    });

    const event = await result;

    runtime.stop();

    process.stdout.write(JSON.stringify({
        requestsBeforeClick,
        requests,
        event: {
            type: event.type,
            detail: event.detail,
        },
    }, null, 2));
}

main().catch((error) => {
    process.stdout.write(JSON.stringify({
        error: error && error.message ? error.message : String(error),
    }, null, 2));
    process.exitCode = 1;
});
