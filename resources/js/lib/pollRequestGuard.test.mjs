import assert from 'node:assert/strict';
import test from 'node:test';
import { createPollRequestGuard } from './pollRequestGuard.ts';

test('polling waits for every pending navigation before loading the new URL', () => {
    const guard = createPollRequestGuard();
    const finishFirst = guard.beginNavigation();
    assert.equal(guard.onBefore(), false);
    const finishSecond = guard.beginNavigation();
    finishFirst();
    assert.equal(guard.onBefore(), false);
    finishFirst();
    assert.equal(guard.onBefore(), false);
    finishSecond();
    assert.equal(guard.onBefore(), true);
});

test('polling skips overlapping requests and resumes after completion', () => {
    const guard = createPollRequestGuard();
    assert.equal(guard.onBefore(), true);
    guard.onStart();
    assert.equal(guard.onBefore(), false);
    guard.onFinish();
    assert.equal(guard.onBefore(), true);
});
