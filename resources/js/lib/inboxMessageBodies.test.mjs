import assert from 'node:assert/strict';
import test from 'node:test';
import {
    createInboxBodyRecovery,
    createInboxBodyRetries,
    cancelInboxPreviewRequests,
    hasMissingInboxBodies,
    inboxBodyHeaders,
    mergeInboxBodies,
} from './inboxMessageBodies.ts';

const fingerprint = 'a'.repeat(64);
const first = {
    body_scope: '1'.repeat(64),
    body_version: '2'.repeat(64),
    messages: [
        {
            id: 'email_first',
            body_fingerprint: fingerprint,
            html: '<img src="data:first">',
            text: 'First',
            status: 'queued',
        },
    ],
};

test('metadata polls retain only bodies with matching thread message and fingerprint', () => {
    const incoming = {
        ...first,
        body_version: '3'.repeat(64),
        messages: [
            {
                id: 'email_first',
                body_fingerprint: fingerprint,
                status: 'delivered',
            },
            {
                id: 'email_second',
                body_fingerprint: 'b'.repeat(64),
                html: '<p>New</p>',
                text: 'New',
            },
        ],
    };
    const merged = mergeInboxBodies(first, incoming);
    assert.equal(merged.messages[0].html, first.messages[0].html);
    assert.equal(merged.messages[0].status, 'delivered');
    assert.equal(merged.messages[1].html, '<p>New</p>');
    const changed = mergeInboxBodies(first, {
        ...first,
        messages: [
            {
                id: 'email_first',
                body_fingerprint: 'b'.repeat(64),
                html: '<p>Changed</p>',
                text: null,
            },
        ],
    });
    assert.equal(changed.messages[0].html, '<p>Changed</p>');
});

test('switching thread or project and clearing selection discard known bodies', () => {
    const other = {
        ...first,
        body_scope: '4'.repeat(64),
        messages: [{ id: 'email_first', body_fingerprint: fingerprint }],
    };
    assert.equal(mergeInboxBodies(first, other).messages[0].html, undefined);
    assert.equal(mergeInboxBodies(first, null), null);
    assert.deepEqual(inboxBodyHeaders(null), {});
    const cleared = mergeInboxBodies(null, other);
    assert.deepEqual(
        JSON.parse(inboxBodyHeaders(cleared)['X-Inbox-Known-Bodies']),
        {},
    );
});

test('a changed fingerprint never reuses an older body from a late response', () => {
    const changed = {
        ...first,
        messages: [{ id: 'email_first', body_fingerprint: 'c'.repeat(64) }],
    };
    assert.equal(mergeInboxBodies(first, changed).messages[0].html, undefined);
    assert.deepEqual(
        JSON.parse(inboxBodyHeaders(changed)['X-Inbox-Known-Bodies']),
        {},
    );
    assert.equal(inboxBodyHeaders(changed)['X-Inbox-Body-Version'], undefined);
});

test('known body input is bounded while a complete thread version covers larger stable threads', () => {
    const thread = {
        ...first,
        messages: Array.from({ length: 60 }, (_, index) => ({
            ...first.messages[0],
            id: `email_${index}`,
        })),
    };
    const headers = inboxBodyHeaders(thread);
    assert.equal(headers['X-Inbox-Body-Version'], thread.body_version);
    assert.equal(headers['X-Inbox-Body-Scope'], thread.body_scope);
    assert.equal(
        Object.keys(JSON.parse(headers['X-Inbox-Known-Bodies'])).length,
        25,
    );
    assert.ok(headers['X-Inbox-Known-Bodies'].length < 4096);
});

test('history restoration immediately requests missing bodies once and heals without a reload loop', () => {
    const rawHistoryProps = JSON.parse(
        JSON.stringify({
            ...first,
            messages: [{ id: 'email_first', body_fingerprint: fingerprint }],
        }),
    );
    const state = { value: mergeInboxBodies(null, rawHistoryProps) };
    const recovery = createInboxBodyRecovery();
    const requests = [];
    const reload = (headers, finish) => requests.push({ headers, finish });
    assert.equal(hasMissingInboxBodies(state.value), true);
    assert.equal(recovery.recover(state.value, reload), true);
    assert.equal(recovery.recover(state.value, reload), false);
    assert.equal(requests[0].headers['X-Inbox-Body-Version'], undefined);
    assert.deepEqual(
        JSON.parse(requests[0].headers['X-Inbox-Known-Bodies']),
        {},
    );
    state.value = mergeInboxBodies(state.value, first);
    requests[0].finish();
    assert.equal(hasMissingInboxBodies(state.value), false);
    assert.equal(recovery.recover(state.value, reload), false);
    assert.equal(requests.length, 1);
});

test('missing-body recovery does not loop on failure and stale completions cannot clear another thread request', () => {
    const recovery = createInboxBodyRecovery();
    const requests = [];
    const reload = (headers, finish) => requests.push({ headers, finish });
    const missing = {
        ...first,
        messages: [{ id: 'email_first', body_fingerprint: fingerprint }],
    };
    recovery.recover(missing, reload);
    requests[0].finish();
    assert.equal(recovery.recover(missing, reload), false);
    recovery.reset();
    const other = { ...missing, body_scope: '4'.repeat(64) };
    recovery.recover(other, reload);
    requests[0].finish();
    assert.equal(recovery.recover(other, reload), false);
    assert.equal(requests.length, 2);
    assert.equal(requests[1].headers['X-Inbox-Body-Scope'], other.body_scope);
});

test('transient failures retry only failed bodies while a complete thread digest preserves healthy bodies', () => {
    const degraded = {
        ...first,
        messages: [
            { ...first.messages[0], body_retryable: true },
            ...Array.from({ length: 30 }, (_, index) => ({
                ...first.messages[0],
                id: `email_good_${index}`,
            })),
        ],
    };
    const headers = inboxBodyHeaders(degraded);
    assert.equal(headers['X-Inbox-Body-Version'], degraded.body_version);
    assert.deepEqual(JSON.parse(headers['X-Inbox-Retry-Bodies']), [
        'email_first',
    ]);
    assert.equal(
        JSON.parse(headers['X-Inbox-Known-Bodies']).email_first,
        undefined,
    );
});

test('failed image retries back off and stop after four attempts without retrying healthy bodies', () => {
    const retries = createInboxBodyRetries();
    const degraded = {
        ...first,
        messages: [{ ...first.messages[0], body_retryable: true }],
    };
    let thread = degraded;
    const attempts = [];

    for (let poll = 1; poll <= 20; poll++) {
        const headers = retries.headers(thread);
        const retry = JSON.parse(headers['X-Inbox-Retry-Bodies']);
        assert.equal(headers['X-Inbox-Body-Version'], thread.body_version);

        if (retry.length) {
            attempts.push(poll);
            thread = mergeInboxBodies(thread, degraded);
        } else {
            assert.equal(
                JSON.parse(headers['X-Inbox-Known-Bodies']).email_first,
                fingerprint,
            );
            thread = mergeInboxBodies(thread, {
                ...thread,
                messages: [
                    { id: 'email_first', body_fingerprint: fingerprint },
                ],
            });
            assert.equal(thread.messages[0].body_retryable, true);
        }
    }

    assert.deepEqual(attempts, [1, 2, 4, 8]);
    const changed = {
        ...degraded,
        messages: [
            { ...degraded.messages[0], body_fingerprint: 'b'.repeat(64) },
        ],
    };
    assert.deepEqual(
        JSON.parse(retries.headers(changed)['X-Inbox-Retry-Bodies']),
        ['email_first'],
    );
    retries.reset();
    assert.deepEqual(
        JSON.parse(retries.headers(degraded)['X-Inbox-Retry-Bodies']),
        ['email_first'],
    );
});

test('preview cancellation leaves synchronous forms and prefetch requests running', () => {
    const requests = [
        { kind: 'async', active: true },
        { kind: 'sync', active: true },
        { kind: 'prefetch', active: true },
    ];
    cancelInboxPreviewRequests((options) => {
        for (const request of requests) {
            if (options[request.kind]) {
                request.active = false;
            }
        }
    });
    assert.equal(requests[0].active, false);
    assert.equal(requests[1].active, true);
    assert.equal(requests[2].active, true);
});

test('installed Inertia snapshots dynamic poll headers once before reload reads them twice', async () => {
    const { router } = await import('@inertiajs/core');
    const originalWindow = Object.getOwnPropertyDescriptor(
        globalThis,
        'window',
    );
    const originalVisit = router.visit;
    const originalReload = router.reload;
    const retries = createInboxBodyRetries();
    let thread = {
        ...first,
        messages: [{ ...first.messages[0], body_retryable: true }],
    };
    let tick;
    let getterReads = 0;
    let reloadReads = 0;
    const sent = [];
    let poll;

    Object.defineProperty(globalThis, 'window', {
        configurable: true,
        value: {
            location: { href: 'https://example.test/inbox' },
            setInterval: (callback) => {
                tick = callback;

                return 1;
            },
            clearInterval: () => {},
        },
    });

    try {
        router.visit = (_url, options) => {
            sent.push(options.headers);
        };
        router.reload = (options) => {
            const descriptor = Object.getOwnPropertyDescriptor(
                options,
                'headers',
            );
            assert.ok(
                descriptor && 'value' in descriptor,
                'poll must snapshot the getter into a data property',
            );
            Object.defineProperty(options, 'headers', {
                enumerable: true,
                get: () => {
                    reloadReads++;

                    return descriptor.value;
                },
            });

            return originalReload.call(router, options);
        };
        poll = router.poll(10000, {
            get headers() {
                getterReads++;

                return retries.headers(thread);
            },
        });
        const attempts = [];

        for (let number = 1; number <= 20; number++) {
            tick();

            if (JSON.parse(sent.at(-1)['X-Inbox-Retry-Bodies']).length) {
                attempts.push(number);
            }
        }

        assert.equal(getterReads, 20);
        assert.equal(reloadReads, 40);
        assert.deepEqual(attempts, [1, 2, 4, 8]);
        thread = {
            ...thread,
            body_scope: '5'.repeat(64),
            messages: [{ ...thread.messages[0], id: 'email_second' }],
        };
        tick();
        assert.equal(getterReads, 21);
        assert.equal(reloadReads, 42);
        assert.deepEqual(JSON.parse(sent.at(-1)['X-Inbox-Retry-Bodies']), [
            'email_second',
        ]);
        assert.equal(sent.at(-1)['X-Inbox-Body-Scope'], thread.body_scope);
    } finally {
        poll?.stop();
        router.visit = originalVisit;
        router.reload = originalReload;

        if (originalWindow) {
            Object.defineProperty(globalThis, 'window', originalWindow);
        } else {
            delete globalThis.window;
        }
    }
});
