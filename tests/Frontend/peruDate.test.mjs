import test from 'node:test'
import assert from 'node:assert/strict'
import { peruCalendarParts, peruDayKey, peruClockMinutes, localCalendarDayKey } from '../../resources/js/utils/peruDate.js'
import { startOfWeek, splitByWeek } from '../../resources/js/utils/weekGrouping.js'

test('01:00 UTC maps to 20:00 of previous calendar day in Peru', () => {
  const instant = '2026-11-17T01:00:00.000Z'
  assert.deepEqual(peruCalendarParts(instant), { day: 16, month: 11, year: 2026, hour: 20, minute: 0 })
  assert.equal(peruDayKey(instant), '2026-11-16')
  assert.equal(peruClockMinutes(instant), 1200)
})

test('week grouping uses Monday in Peru even when UTC has crossed into the next week', () => {
  const reference = '2026-11-16T03:00:00Z' // Domingo 15/11 22:00 Perú
  assert.equal(localCalendarDayKey(startOfWeek(reference)), '2026-11-09')
  const events = [
    { id: 'sun', start_time: '2026-11-16T03:59:00Z' }, // domingo 15
    { id: 'mon', start_time: '2026-11-16T05:00:00Z' }, // lunes 16
  ]
  const result = splitByWeek(events, 'start_time', '2026-11-17T01:00:00Z') // lunes 16/11
  assert.deepEqual(result.past.map(e => e.id), ['sun'])
  assert.deepEqual(result.thisWeek.map(e => e.id), ['mon'])
})

import { toPeruDateTimeLocal, peruLocalDateTimeToIso } from '../../resources/js/utils/peruDate.js'

test('hora civil de Perú convertida a UTC independientemente del dispositivo', () => {
  const utc = '2026-11-17T01:00:00.000Z'
  assert.equal(toPeruDateTimeLocal(utc), '2026-11-16T20:00')
  assert.equal(peruLocalDateTimeToIso('2026-11-16T20:00'), utc)
})

test('datetime-local inválido se rechaza en lugar de cambiar de fecha', () => {
  assert.throws(() => peruLocalDateTimeToIso('2026-02-30T18:00'), RangeError)
  assert.throws(() => peruLocalDateTimeToIso(''), RangeError)
  assert.throws(() => peruLocalDateTimeToIso('2026-11-16T25:30'), RangeError)
})
