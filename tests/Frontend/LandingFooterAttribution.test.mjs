import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const footer = readFileSync(new URL('../../resources/js/Components/LandingFooter.vue', import.meta.url), 'utf8')

test('la landing de MOVA enlaza el portafolio del desarrollador sin alterar créditos de fundadores', () => {
  assert.match(footer, /MOVA · Abel Castillo & Elias Paz/)
  assert.match(footer, /href="https:\/\/portafolio-abelcastillo\.vercel\.app\/"/)
  assert.match(footer, /Portafolio de Abel Castillo/)
  assert.match(footer, /target="_blank" rel="noopener noreferrer"/)
})
