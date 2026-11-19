import assert from 'node:assert/strict'
import { test } from 'node:test'
import { addLimaDays, fmtLimaClock, limaDayKey, limaLocalToIso, limaMinutesOfDay, limaParts, limaStartOfWeek, toLimaDatetimeLocal } from '../../resources/js/utils/limaTime.js'

// Se ejecuta bajo varias zonas del proceso (TZ=America/Lima, UTC, America/New_York, Europe/Madrid): el resultado no puede cambiar.
const CASES = [
  { name: 'A · 09:30 Lima', utc: '2026-11-16T14:30:00Z', hm: '09:30', day: '2026-11-16', min: 570 },
  { name: 'B · 04:30 Lima (clase de Abel, fuera del rango 07–24)', utc: '2026-11-16T09:30:00Z', hm: '04:30', day: '2026-11-16', min: 270 },
  { name: 'C · 23:30 Lima = día siguiente en UTC', utc: '2026-11-17T04:30:00Z', hm: '23:30', day: '2026-11-16', min: 1410 },
  { name: 'D · 00:00 exacta Lima', utc: '2026-11-16T05:00:00Z', hm: '00:00', day: '2026-11-16', min: 0 },
  { name: 'D · 23:59 Lima', utc: '2026-11-17T04:59:00Z', hm: '23:59', day: '2026-11-16', min: 1439 },
]

for (const c of CASES) {
  test(`${c.name}`, () => {
    assert.equal(fmtLimaClock(c.utc), c.hm)
    assert.equal(limaDayKey(c.utc), c.day)
    assert.equal(limaMinutesOfDay(c.utc), c.min)
  })
}

test('lunes 16-nov-2026 es lunes en Lima y la semana empieza ese día a las 05:00 UTC', () => {
  assert.equal(limaParts('2026-11-16T09:30:00Z').dow, 1)
  assert.equal(limaStartOfWeek('2026-11-16T09:30:00Z').toISOString(), '2026-11-16T05:00:00.000Z')
  // domingo 22 23:30 Lima sigue en la misma semana; lunes 23 00:00 Lima ya es la siguiente
  assert.equal(limaStartOfWeek('2026-11-23T04:30:00Z').toISOString(), '2026-11-16T05:00:00.000Z')
  assert.equal(limaStartOfWeek('2026-11-23T05:00:00Z').toISOString(), '2026-11-23T05:00:00.000Z')
})

test('navegación entre semanas: 7 días exactos de Lima', () => {
  const start = limaStartOfWeek('2026-11-16T09:30:00Z')
  const next = addLimaDays(start, 7)
  assert.equal(next.toISOString(), '2026-11-23T05:00:00.000Z')
  assert.equal(limaDayKey(addLimaDays(start, 6)), '2026-11-22')
})

test('datetime-local ↔ ISO UTC son inversas y no se convierte dos veces', () => {
  assert.equal(limaLocalToIso('2026-11-16T04:30'), '2026-11-16T09:30:00.000Z')
  assert.equal(toLimaDatetimeLocal('2026-11-16T09:30:00Z'), '2026-11-16T04:30')
  assert.equal(limaLocalToIso(toLimaDatetimeLocal('2026-11-17T04:30:00Z')), '2026-11-17T04:30:00.000Z')
})
