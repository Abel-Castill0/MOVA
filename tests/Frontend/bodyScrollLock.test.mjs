import { test } from 'node:test';
import assert from 'node:assert/strict';
import { lockBodyScroll } from '../../resources/js/utils/bodyScrollLock.js';

test('locks are reference counted and releasing twice is safe', () => {
  globalThis.document = { body: { style: { overflow: 'auto' } } };
  try {
    const unlockDrawer = lockBodyScroll();
    const unlockModal = lockBodyScroll();
    assert.equal(document.body.style.overflow, 'hidden');
    unlockDrawer();
    assert.equal(document.body.style.overflow, 'hidden');
    unlockModal();
    assert.equal(document.body.style.overflow, 'auto');
    unlockModal();
    assert.equal(document.body.style.overflow, 'auto');
  } finally {
    delete globalThis.document;
  }
});

test('preserves a preexisting overlay lock', () => {
  globalThis.document = { body: { style: { overflow: 'hidden' } } };
  try {
    const unlock = lockBodyScroll();
    unlock();
    assert.equal(document.body.style.overflow, 'hidden');
  } finally {
    delete globalThis.document;
  }
});
