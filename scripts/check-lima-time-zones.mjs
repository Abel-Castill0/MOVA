// Ejecuta tests/Frontend/limaCalendar.test.mjs bajo cuatro zonas del navegador: el mismo evento debe dar la misma hora de Perú.
import { spawnSync } from 'node:child_process'

let failed = false
for (const tz of ['America/Lima', 'UTC', 'America/New_York', 'Europe/Madrid']) {
  const r = spawnSync(process.execPath, ['--test', 'tests/Frontend/limaCalendar.test.mjs'], { env: { ...process.env, TZ: tz }, encoding: 'utf8' })
  const ok = r.status === 0
  console.log(`${ok ? 'OK  ' : 'FAIL'} TZ=${tz}`)
  if (!ok) { failed = true; console.log(r.stdout, r.stderr) }
}
process.exit(failed ? 1 : 0)
