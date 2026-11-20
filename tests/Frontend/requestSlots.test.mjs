import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
  addDays, dayLabel, describePreference, monthGrid, parseDayKey, slotContains, slotEnd, slotHasEnded, slotPhrase, slotRange,
} from '../../resources/js/utils/requestSlots.js'

// Mismos valores que config/class_requests.php (en la app llegan por props; aquí solo se ejercitan las funciones puras).
const MORNING = { value: 'morning', start: 6, end: 12 }
const AFTERNOON = { value: 'afternoon', start: 12, end: 18 }
const EVENING = { value: 'evening', start: 18, end: 22 }

// Se ejecuta bajo varias zonas del proceso (Lima, UTC, Nueva York, Madrid): el resultado no puede cambiar.
test('un día es una clave de calendario: sin desfase por la zona del navegador', () => {
  assert.deepEqual(parseDayKey('2026-11-20'), { y: 2026, m: 10, d: 20 })
  assert.equal(parseDayKey('2026-02-30'), null)
  assert.equal(parseDayKey('20/11/2026'), null)
  assert.equal(addDays('2026-11-30', 1), '2026-12-01')
  assert.equal(addDays('2026-03-01', -1), '2026-02-28')
  assert.equal(dayLabel('2026-11-20'), 'viernes 20 de noviembre')
  assert.equal(dayLabel('2026-11-22'), 'domingo 22 de noviembre')
})

test('el texto que lee el profesor', () => {
  assert.equal(describePreference('2026-11-20', 'afternoon'), 'viernes 20 de noviembre, por la tarde')
  assert.equal(describePreference('2026-11-21', 'morning'), 'sábado 21 de noviembre, por la mañana')
  assert.equal(slotPhrase('evening'), 'por la noche')
  assert.equal(slotRange(AFTERNOON), '12:00 – 18:00')
})

test('el fin de la franja es una hora de Lima, no del navegador', () => {
  assert.equal(slotEnd('2026-11-20', AFTERNOON).toISOString(), '2026-11-20T23:00:00.000Z')
  assert.equal(slotEnd('2026-11-20', EVENING).toISOString(), '2026-11-21T03:00:00.000Z')
})

test('hoy no se ofrecen las franjas que ya terminaron', () => {
  const at = (iso) => new Date(iso)
  // 15:00 Lima = 20:00 UTC
  assert.equal(slotHasEnded('2026-11-19', MORNING, at('2026-11-19T20:00:00Z')), true)
  assert.equal(slotHasEnded('2026-11-19', AFTERNOON, at('2026-11-19T20:00:00Z')), false)
  assert.equal(slotHasEnded('2026-11-19', EVENING, at('2026-11-19T20:00:00Z')), false)
  // 22:00 Lima exactas = 03:00 UTC del 20: la noche termina (fin exclusivo)
  assert.equal(slotHasEnded('2026-11-19', EVENING, at('2026-11-20T03:00:00Z')), true)
  assert.equal(slotHasEnded('2026-11-19', EVENING, at('2026-11-20T02:59:00Z')), false)
  // mañana siempre está disponible
  assert.equal(slotHasEnded('2026-11-20', MORNING, at('2026-11-19T20:00:00Z')), false)
})

test('una hora propuesta cae dentro o fuera de la franja (inicio incluido, fin excluido)', () => {
  assert.equal(slotContains('2026-11-20', AFTERNOON, '2026-11-20T21:00:00Z'), true) // 16:00 Lima
  assert.equal(slotContains('2026-11-20', AFTERNOON, '2026-11-20T17:00:00Z'), true) // 12:00
  assert.equal(slotContains('2026-11-20', AFTERNOON, '2026-11-20T23:00:00Z'), false) // 18:00
  assert.equal(slotContains('2026-11-20', AFTERNOON, '2026-11-20T16:59:00Z'), false) // 11:59
  assert.equal(slotContains('2026-11-20', AFTERNOON, '2026-11-21T21:00:00Z'), false) // otro día
})

test('la cuadrícula del mes empieza en lunes', () => {
  const g = monthGrid(2026, 10) // noviembre 2026: el 1 es domingo
  assert.equal(g[0].filter(Boolean).length, 1)
  assert.equal(g[0][6], '2026-11-01')
  assert.equal(g.at(-1).filter(Boolean).at(-1), '2026-11-30')
  assert.ok(g.every((w) => w.length === 7))
})
