import assert from 'node:assert/strict';
import test from 'node:test';
import { refreshEmailSelection, selectEmailPreview } from './emailSelection.ts';

const first = { id: 'first', html: '<img src="data:first">', status: 'queued' };
const second = {
    id: 'second',
    html: '<img src="cid:second">',
    status: 'queued',
};

test('selection immediately shows the new email body while requesting its detail', () => {
    const state = { value: first };
    const requests = [];
    selectEmailPreview(state, second, (id, receive) =>
        requests.push({ id, receive }),
    );
    assert.equal(state.value.id, 'second');
    assert.equal(state.value.html, second.html);
    assert.equal(requests[0].id, 'second');
    requests[0].receive({ ...second, html: '<img src="data:second">' });
    assert.equal(state.value.html, '<img src="data:second">');
});

test('late responses cannot replace another selected email', () => {
    const state = { value: null };
    const requests = [];
    const reload = (id, receive) => requests.push({ id, receive });
    selectEmailPreview(state, first, reload);
    selectEmailPreview(state, second, reload);
    requests[0].receive(first);
    assert.equal(state.value.id, 'second');
    assert.equal(state.value.html, second.html);
    requests[1].receive({ ...second, html: '<img src="data:second">' });
    assert.equal(state.value.html, '<img src="data:second">');
});

test('closing clears selection and late responses cannot reopen it', () => {
    const state = { value: null };
    const requests = [];
    const reload = (id, receive) => requests.push({ id, receive });
    selectEmailPreview(state, first, reload);
    selectEmailPreview(state, null, reload);
    assert.equal(state.value, null);
    assert.equal(requests[1].id, '');
    requests[0].receive(first);
    requests[1].receive(null);
    assert.equal(state.value, null);
});

test('unavailable details clear selection without showing a different response body', () => {
    const state = { value: null };
    let receive;
    selectEmailPreview(state, second, (_id, callback) => {
        receive = callback;
    });
    receive(first);
    assert.equal(state.value.id, 'second');
    assert.equal(state.value.html, second.html);
    receive(null);
    assert.equal(state.value, null);
});

test('polling updates status while preserving resolved HTML for the same email', () => {
    const selected = refreshEmailSelection(first, [
        { ...first, status: 'delivered', html: '<img src="cid:first">' },
    ]);
    assert.equal(selected.status, 'delivered');
    assert.equal(selected.html, first.html);
});

test('polling preserves a selected email outside the current list window and keeps a closed inspector closed', () => {
    assert.equal(refreshEmailSelection(first, [second]), first);
    assert.equal(refreshEmailSelection(null, [first, second]), null);
});

test('changing filters clears selection even if the selected row still appears', () => {
    assert.equal(refreshEmailSelection(first, [first, second], true), null);
});
