import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile, parse as parseTemplate } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';
import { apiKeyScopes, apiKeyScopeChoices } from './apiKeyPermissions.ts';

const source = readFileSync(
    new URL('../pages/Activity.vue', import.meta.url),
    'utf8',
);
const template = parseTemplate(parse(source).descriptor.template.content);

function findNode(node, matches) {
    if (matches(node)) {
        return node;
    }

    for (const child of node.children ?? []) {
        const found = findNode(child, matches);

        if (found) {
            return found;
        }
    }
}

async function renderFragment(node, data) {
    assert.ok(node, 'Expected suppression UI element must exist');
    const { code } = compile(node.loc.source, {
        mode: 'function',
        prefixIdentifiers: true,
    });
    const render = new Function('Vue', code)(Vue);

    return renderToString(
        Vue.createSSRApp({
            data: () => data,
            render,
            components: { RefreshCw: { render: () => null } },
        }),
    );
}

test('legacy null scopes show only original permissions and empty scopes stay empty', () => {
    assert.deepEqual(apiKeyScopes({ scopes: null }), ['send', 'read:activity']);
    assert.deepEqual(apiKeyScopes({ scopes: [] }), []);
    assert.deepEqual(apiKeyScopes({ scopes: ['manage:suppressions'] }), [
        'manage:suppressions',
    ]);
});

test('limited key managers cannot select the suppression-management grant', () => {
    assert.deepEqual(
        apiKeyScopeChoices(false).map((choice) => choice.value),
        ['send', 'read:activity'],
    );
    assert.deepEqual(
        apiKeyScopeChoices(true).map((choice) => choice.value),
        ['send', 'read:activity', 'manage:suppressions'],
    );
});

test('limited key managers cannot rotate suppression-management keys in the UI', async () => {
    const button = findNode(
        template,
        (node) =>
            node.type === 1 &&
            node.tag === 'button' &&
            node.props.some(
                (prop) =>
                    prop.name === 'on' &&
                    prop.exp?.content === 'rotateApiKey(apiKey)',
            ),
    );
    const data = {
        apiKey: { scopes: ['manage:suppressions'] },
        workspace: { can_manage_suppressions: false },
        rotateApiKey: () => {},
        apiKeyScopes,
    };
    assert.match(await renderFragment(button, data), /\sdisabled(?:\s|>)/);
    assert.doesNotMatch(
        await renderFragment(button, { ...data, apiKey: { scopes: ['send'] } }),
        /\sdisabled(?:\s|>)/,
    );
});

test('suppression rows identify expired history and restrict removal controls by capability', async () => {
    const row = findNode(
        template,
        (node) =>
            node.type === 1 &&
            node.props.some(
                (prop) =>
                    prop.name === 'for' &&
                    prop.exp?.content === 'email in suppressionRows',
            ),
    );
    const data = {
        suppressionRows: [
            {
                id: 1,
                email: 'expired@example.com',
                reason: 'hard_bounce',
                source: 'Bounce',
                provider: 'ses',
                added: '1d ago',
                expires: '2026-10-28',
                active: false,
            },
        ],
        workspace: { can_manage_suppressions: false },
        removingSuppressionId: null,
        statusClass: () => '',
        removeSuppression: () => {},
    };
    const readonly = await renderFragment(row, data);
    assert.match(readonly, /Expired/);
    assert.doesNotMatch(readonly, /Remove suppression for/);
    const manager = await renderFragment(row, {
        ...data,
        workspace: { can_manage_suppressions: true },
    });
    assert.match(manager, /Remove suppression for expired@example.com/);
});

test('provider removal errors are visible beside the suppression list', async () => {
    const error = findNode(
        template,
        (node) =>
            node.type === 1 &&
            node.props.some(
                (prop) =>
                    prop.name === 'if' &&
                    prop.exp?.content === 'suppressionRemovalError',
            ),
    );
    const rendered = await renderFragment(error, {
        suppressionRemovalError: 'Provider unavailable. Try again.',
    });
    assert.match(rendered, /Provider unavailable\. Try again\./);
    assert.match(rendered, /role="alert"/);
});
