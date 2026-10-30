import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';

test('the existing webhook editor offers every documented subscription without changing default grants', () => {
    const source = readFileSync(
        new URL('../pages/Activity.vue', import.meta.url),
        'utf8',
    );
    const descriptor = parse(source).descriptor;
    const script = compileScript(descriptor, {
        id: 'webhook-subscription-contract',
    });
    const declaration = script.scriptSetupAst
        .flatMap((node) =>
            node.type === 'VariableDeclaration' ? node.declarations : [],
        )
        .find((node) => node.id.name === 'webhookEventOptions');
    const types = declaration.init.elements.map((node) => node.value);

    for (const type of [
        'submission_started',
        'submission_accepted',
        'submission_rejected',
        'delivery_uncertain',
        'failed',
        'send',
        'reject',
        'delivery_delay',
        'rendering_failure',
        'delivery',
        'bounce',
        'complaint',
        'open',
        'click',
        'suppress',
        'inbound.received',
    ]) {
        assert.ok(types.includes(type), `Missing subscription: ${type}`);
    }

    const form = script.scriptSetupAst
        .flatMap((node) =>
            node.type === 'VariableDeclaration' ? node.declarations : [],
        )
        .find((node) => node.id.name === 'webhookForm');
    const defaults = form.init.arguments[0].properties
        .find((node) => node.key.name === 'events')
        .value.elements.map((node) => node.value);
    assert.deepEqual(defaults, [
        'delivery',
        'bounce',
        'complaint',
        'open',
        'click',
    ]);
});
