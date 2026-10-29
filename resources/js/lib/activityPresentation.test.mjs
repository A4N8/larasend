import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile, parse as parseTemplate } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

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
        const match = findNode(child, matches);

        if (match) {
            return match;
        }
    }
}

async function renderFragment(node, data) {
    assert.ok(node, 'The expected presentation element must exist');
    const { code } = compile(node.loc.source, {
        mode: 'function',
        prefixIdentifiers: true,
    });
    const render = new Function('Vue', code)(Vue);

    return renderToString(Vue.createSSRApp({ data: () => data, render }));
}

test('the inspector renders the server-formatted time without adding another suffix', async () => {
    const sentTime = findNode(
        template,
        (node) =>
            node.type === 1 &&
            node.tag === 'dd' &&
            node.loc.source.includes('{{ selected.time }}'),
    );

    for (const time of ['2h ago', '3d ago', 'just now']) {
        assert.equal(
            await renderFragment(sentTime, { selected: { time } }),
            `<dd>${time}</dd>`,
        );
    }
});

test('an empty outbound list explains that no emails match, and hides the message when rows exist', async () => {
    const list = findNode(
        template,
        (node) =>
            node.type === 1 &&
            node.children.some(
                (child) =>
                    child.type === 1 &&
                    child.props.some(
                        (prop) =>
                            prop.name === 'for' &&
                            prop.exp?.content === 'group in groupedEmails',
                    ),
            ),
    );
    assert.ok(list, 'The outbound list container must exist');
    const emptyState = findNode(
        list,
        (node) =>
            node.type === 1 &&
            node.props.some(
                (prop) =>
                    prop.name === 'role' && prop.value?.content === 'status',
            ),
    );
    const empty = await renderFragment(emptyState, { groupedEmails: [] });
    assert.match(empty, /No emails found/);
    assert.match(empty, /search, status filter, or date range/);
    const populated = await renderFragment(emptyState, {
        groupedEmails: [{ label: 'Today', rows: [{}] }],
    });
    assert.doesNotMatch(populated, /No emails found/);
});
