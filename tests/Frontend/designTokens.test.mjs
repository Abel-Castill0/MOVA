import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8')
function luminance([r, g, b]) {
  const [R, G, B] = [r, g, b].map(v => {
    const c = v / 255
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * R + 0.7152 * G + 0.0722 * B
}
function contrast(a, b) {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (l1 + 0.05) / (l2 + 0.05)
}
test('interactive input borders pass 3:1 contrast against light surfaces', () => {
  const light = css.match(/:root\s*\{([\s\S]*?)\n\}/)?.[1]
  assert.ok(light, 'light theme exists')
  const components = light.match(/--line-strong:\s*(\d+)\s+(\d+)\s+(\d+)/)
  const background = light.match(/--surface:\s*(\d+)\s+(\d+)\s+(\d+)/)
  assert.ok(components && background)
  const border = components.slice(1).map(Number)
  const surface = background.slice(1).map(Number)
  assert.ok(contrast(border, surface) >= 3, 'border contrast must be at least 3:1')
})
