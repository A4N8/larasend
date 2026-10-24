type MessageBody = {
    id: string;
    body_fingerprint: string;
    body_retryable?: boolean;
    html?: string | null;
    text?: string | null;
};

type ThreadBodies = {
    body_scope: string;
    body_version: string;
    messages: MessageBody[];
};

export function mergeInboxBodies<T extends ThreadBodies>(
    previous: T | null,
    incoming: T | null,
): T | null {
    if (!incoming || previous?.body_scope !== incoming.body_scope) {
        return incoming;
    }

    const known = new Map(
        previous.messages.map((message) => [message.id, message]),
    );

    return {
        ...incoming,
        messages: incoming.messages.map((message) => {
            const body = known.get(message.id);

            if (!body || body.body_fingerprint !== message.body_fingerprint) {
                return message;
            }

            return {
                ...message,
                html: message.html === undefined ? body.html : message.html,
                text: message.text === undefined ? body.text : message.text,
                body_retryable:
                    message.body_retryable ??
                    (message.html === undefined && message.text === undefined
                        ? body.body_retryable
                        : false),
            };
        }),
    };
}

export function inboxBodyHeaders(
    thread: ThreadBodies | null,
    retryIds?: readonly string[],
): Record<string, string> {
    if (!thread) {
        return {};
    }

    const complete = thread.messages.filter(
        (message) => message.html !== undefined && message.text !== undefined,
    );
    const retry =
        retryIds ??
        complete
            .filter((message) => message.body_retryable)
            .slice(-25)
            .map((message) => message.id);

    return {
        'X-Inbox-Body-Scope': thread.body_scope,
        ...(complete.length === thread.messages.length
            ? { 'X-Inbox-Body-Version': thread.body_version }
            : {}),
        'X-Inbox-Known-Bodies': JSON.stringify(
            Object.fromEntries(
                complete
                    .filter((message) => !retry.includes(message.id))
                    .slice(-25)
                    .map((message) => [message.id, message.body_fingerprint]),
            ),
        ),
        'X-Inbox-Retry-Bodies': JSON.stringify(retry.slice(-25)),
    };
}

export function createInboxBodyRetries() {
    let scope: string | null = null;
    let poll = 0;
    const attempts = new Map<string, { count: number; nextPoll: number }>();

    return {
        reset(): void {
            scope = null;
            poll = 0;
            attempts.clear();
        },
        headers(thread: ThreadBodies | null): Record<string, string> {
            if (thread?.body_scope !== scope) {
                scope = thread?.body_scope ?? null;
                poll = 0;
                attempts.clear();
            }

            poll++;
            const retry: string[] = [];
            const active = new Set<string>();

            for (const message of thread?.messages ?? []) {
                if (!message.body_retryable) {
                    continue;
                }

                const key = `${message.id}:${message.body_fingerprint}`;
                active.add(key);
                const attempt = attempts.get(key) ?? {
                    count: 0,
                    nextPoll: poll,
                };
                attempts.set(key, attempt);

                if (
                    retry.length < 25 &&
                    attempt.count < 4 &&
                    poll >= attempt.nextPoll
                ) {
                    retry.push(message.id);
                    attempt.count++;
                    attempt.nextPoll = poll + 2 ** (attempt.count - 1);
                }
            }

            for (const key of attempts.keys()) {
                if (!active.has(key)) {
                    attempts.delete(key);
                }
            }

            return inboxBodyHeaders(thread, retry);
        },
    };
}

export function cancelInboxPreviewRequests(
    cancel: (options: {
        async: boolean;
        prefetch: boolean;
        sync: boolean;
    }) => void,
): void {
    cancel({ async: true, prefetch: false, sync: false });
}

export function hasMissingInboxBodies(thread: ThreadBodies | null): boolean {
    return (
        thread?.messages.some(
            (message) =>
                message.html === undefined || message.text === undefined,
        ) ?? false
    );
}

export function createInboxBodyRecovery() {
    let attempted: string | null = null;
    let pending: symbol | null = null;

    return {
        reset(): void {
            attempted = null;
            pending = null;
        },
        recover(
            thread: ThreadBodies | null,
            reload: (
                headers: Record<string, string>,
                finish: () => void,
            ) => void,
        ): boolean {
            if (!thread || !hasMissingInboxBodies(thread)) {
                attempted = null;

                return false;
            }

            const key = `${thread.body_scope}:${thread.body_version}`;

            if (pending !== null || attempted === key) {
                return false;
            }

            attempted = key;
            const request = Symbol();
            pending = request;
            reload(inboxBodyHeaders(thread), () => {
                if (pending === request) {
                    pending = null;
                }
            });

            return true;
        },
    };
}
