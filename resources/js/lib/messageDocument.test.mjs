import assert from 'node:assert/strict';
import test from 'node:test';
import { messageDocument } from './messageDocument.ts';

const png = 'data:image/png;base64,iVBORw0KGgo=';
const jpeg = 'data:image/jpeg;base64,/9j/4AAQ';

test('inbox message document retains resolved PNG and JPEG images in either theme', () => {
    for (const dark of [true, false]) {
        const document = messageDocument(
            `<p>Hello</p><img src="${png}" width="120"><img src='${jpeg}'>`,
            dark,
        );
        assert.ok(document.includes(`src="${png}"`));
        assert.ok(document.includes(`src="${jpeg}"`));
        assert.ok(document.includes('width="120"'));
    }
});

test('inbox images cannot retain remote sources alternate sources or active attributes', () => {
    const document = messageDocument(
        `<img src="https://tracker.test/image"><img src="cid:unresolved@example.test"><img src="data:image/svg+xml;base64,PHN2Zz4="><img src="${png}" srcset="https://tracker.test/image 2x" onerror="alert(1)">`,
        false,
    );
    assert.ok(document.includes(`src="${png}"`));
    assert.ok(!document.includes('https://tracker.test'));
    assert.ok(!document.includes('srcset='));
    assert.ok(!document.includes('onerror='));
    assert.ok(!document.includes('data:image/svg'));
    assert.ok(!document.includes('src="cid:'));
});
