import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { readLocalPreference, writeLocalPreference } from '../../resources/js/utils/safeLocalStorage.js'

const originalWindow = globalThis.window
afterEach(() => {
  if (originalWindow === undefined) delete globalThis.window
  else globalThis.window = originalWindow
})

test('SSR sin window: retorna fallback sin lanzar', () => {
  delete globalThis.window
  assert.equal(readLocalPreference('test'), null)
  assert.equal(writeLocalPreference('test', 'list'), false)
})

test('localStorage deshabilitado al acceder: retorna fallback', () => {
  globalThis.window = Object.defineProperty({}, 'localStorage', {
    get() { throw new DOMException('Blocked', 'SecurityError') },
  })
  assert.equal(readLocalPreference('test'), null)
  assert.equal(writeLocalPreference('test', 'list'), false)
})

test('localStorage deshabilitado durante lectura/escritura: retorna fallback', () => {
  globalThis.window = { localStorage: {
    getItem() { throw new DOMException('Blocked', 'SecurityError') },
    setItem() { throw new DOMException('Blocked', 'SecurityError') },
  } }
  assert.equal(readLocalPreference('test'), null)
  assert.equal(writeLocalPreference('test', 'list'), false)
})

test('localStorage disponible: conserva las preferencias', () => {
  const stored = new Map()
  globalThis.window = { localStorage: {
    getItem(key) { return stored.get(key) ?? null },
    setItem(key, value) { stored.set(key, value) },
  } }
  assert.equal(writeLocalPreference('test', 'list'), true)
  assert.equal(readLocalPreference('test'), 'list')
})
