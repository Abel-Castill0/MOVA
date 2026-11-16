import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// Source-level regression guards; do not replace compiled Vue, browser E2E,
// Laravel policies or a production smoke test.
const view = (name) => readFileSync(new URL('../../resources/js/' + name, import.meta.url), 'utf8')

test('suspending a user requires a modal confirmation before POST', () => {
  const users = view('Pages/Admin/Users.vue')
  assert.match(users, /@click="confirmSuspend\(u\)"/)
  assert.match(users, /<Modal :show="!!suspendTarget"/)
  assert.doesNotMatch(users, /@click\.prevent="confirmSuspend\(u\)"/)
})

test('teacher verification has confirmation and guarded server mutation', () => {
  const teachers = view('Pages/Admin/PendingTeachers.vue')
  assert.match(teachers, /<Modal :show="!!verifyTarget"/)
  assert.match(teachers, /function submitVerify\(\)/)
  assert.match(teachers, /if \(verifying\.value \|\| !verifyTarget\.value\) return/)
  assert.doesNotMatch(teachers, /<Link :href="route\('admin\.teachers\.verify'/)
})

test('class scheduling and counteroffers use Peru time, not browser-local time', () => {
  const accept = view('Pages/ClassRequests/Accept.vue')
  const teacher = view('Pages/ClassRequests/TeacherIndex.vue')
  assert.match(accept, /peruLocalDateTimeToIso\(form\.start_time\)/)
  assert.match(teacher, /peruLocalDateTimeToIso\(counterofferForm\.counteroffer_time\)/)
  assert.doesNotMatch(accept, /new Date\(data\.start_time\)\.toISOString\(\)/)
  assert.doesNotMatch(teacher, /new Date\(data\.counteroffer_time\)\.toISOString\(\)/)
})

test('teacher dialogs close after success without a processing guard', () => {
  const teacher = view('Pages/ClassRequests/TeacherIndex.vue')
  assert.doesNotMatch(teacher, /onSuccess:\s*\(\)\s*=>\s*closeCounterofferModal\(\)/)
  assert.doesNotMatch(teacher, /onSuccess:\s*\(\)\s*=>\s*closeRejectModal\(\)/)
})

test('a review restoration error does not remove the moderation list', () => {
  const reviews = view('Pages/Admin/Reviews.vue')
  assert.match(reviews, /<div v-if="reviews\.data\.length" class="space-y-3">/)
})

test('two notification bells must not share an aria-controls id', () => {
  const bell = view('Components/NotificationBell.vue')
  assert.match(bell, /:aria-controls="panelId"/)
  assert.match(bell, /:id="panelId"/)
})
