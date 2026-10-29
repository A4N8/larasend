import assert from 'node:assert/strict';
import test from 'node:test';
import {
    createSearchDebounce,
    hasSearchChanges,
    reconcileSearchDraft,
} from './activitySearch.ts';

test('clearing back to the old query while a search is in flight still sends the clear', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let draft = '';
    const requests = [];
    const search = createSearchDebounce(() => requests.push(draft));
    if (hasSearchChanges(draft, 'ma', '')) {
        search.schedule();
    }
    draft = reconcileSearchDraft(draft, 'ma', 'ma');
    t.mock.timers.tick(350);
    assert.deepEqual(requests, ['']);
});

test('navigation starts cancel a pending search before the new page responds but polls do not', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let requests = 0;
    const search = createSearchDebounce(() => requests++);
    search.schedule();
    search.onNavigationStart({ detail: { visit: { async: false } } });
    t.mock.timers.tick(350);
    assert.equal(requests, 0);
    search.schedule();
    search.onNavigationStart({ detail: { visit: { async: true } } });
    t.mock.timers.tick(350);
    assert.equal(requests, 1);
});

test('surrounding whitespace alone does not submit an equivalent search', () => {
    assert.equal(hasSearchChanges(' ma ', null, 'ma'), false);
});

test('cancelled history or unmount work cannot trigger a later search', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let requests = 0;
    const search = createSearchDebounce(() => requests++);
    search.schedule();
    search.cancel();
    t.mock.timers.tick(350);
    assert.equal(requests, 0);
    search.schedule();
    t.mock.timers.tick(350);
    assert.equal(requests, 1);
});

test('continued typing replaces the previous pending search', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let requests = 0;
    const search = createSearchDebounce(() => requests++);
    search.schedule();
    t.mock.timers.tick(300);
    search.schedule();
    t.mock.timers.tick(300);
    assert.equal(requests, 0);
    t.mock.timers.tick(50);
    assert.equal(requests, 1);
});

test('a delayed search response preserves newer unsent typing', () => {
    assert.equal(reconcileSearchDraft('may', 'ma', 'ma'), 'may');
});

test('history and external navigation restore the incoming query', () => {
    assert.equal(reconcileSearchDraft('may', null, 'old search'), 'old search');
    assert.equal(
        reconcileSearchDraft('may', 'ma', 'another search'),
        'another search',
    );
});
