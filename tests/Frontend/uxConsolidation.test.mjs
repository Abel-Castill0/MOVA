import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8')

test('the same accessible heading component is shared by the three key workspaces', () => {
  const head = read('Components/PageHeader.vue')
  assert.match(head, /<h2[^>]*>\{\{ title \}\}<\/h2>/)
  for (const page of ['Pages/ClassRequests/TeacherIndex.vue', 'Pages/ClassRequests/Index.vue', 'Pages/Teacher/Credits/Index.vue']) {
    const source = read(page)
    assert.match(source, /import PageHeader from '@\/Components\/PageHeader\.vue'/)
    assert.match(source, /<PageHeader/)
  }
})

test('the teacher can find and sort requests with labelled native controls', () => {
  const source = read('Pages/ClassRequests/TeacherIndex.vue')
  assert.match(source, /<label for="teacher-request-search"/)
  assert.match(source, /id="teacher-request-search"/)
  assert.match(source, /<label for="teacher-request-order"/)
  assert.match(source, /id="teacher-request-order"/)
})

test('parent approval and refusal cannot POST from an opening link', () => {
  const source = read('Pages/ClassRequests/Index.vue')
  assert.doesNotMatch(source, /method="post" as="button"/)
  assert.match(source, /<Modal :show="!!approvalTarget"/)
  assert.match(source, /function openApproval\(request, kind\)/)
  assert.match(source, /function submitApproval\(\)/)
  assert.match(source, /if \(approvalBusy\.value \|\| !approvalTarget\.value/)
  assert.match(source, /shouldRefresh: \(\) => processingId\.value === null && !approvalTarget\.value/)
})

test('credits display honest savings and payment information from server data', () => {
  const source = read('Pages/Teacher/Credits/Index.vue')
  assert.doesNotMatch(source, /pack\.code === 'pro'/)
  assert.match(source, /highestSavingPen/)
  assert.match(source, /isHighestSaving\(pack\)/)
  assert.match(source, /signedCredits\(transaction\.amount\)/)
  assert.match(source, /request\.operation_number \|\| 'Compra en línea'/)
})

test('registration uses native radio choices, readable terms and no duplicate submit', () => {
  const source = read('Pages/Auth/Register.vue')
  assert.match(source, /<fieldset v-else/)
  assert.match(source, /type="radio" name="registration-role"/)
  assert.doesNotMatch(source, /role="radio"/)
  assert.match(source, /if \(form\.processing \|\| !isCurrentStepValid\.value\) return/)
  assert.match(source, /if \(form\.processing \|\| !form\.accepted_terms\) return/)
  assert.match(source, /rel="noopener noreferrer"/)
})
