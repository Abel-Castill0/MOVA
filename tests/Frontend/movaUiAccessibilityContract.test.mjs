import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function luminance(hex) {
  const c = hex.replace('#', '').match(/../g).map(n => parseInt(n, 16) / 255);
  const linear = c.map(n => n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4);
  return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
}
function contrast(a, b) {
  const x = luminance(a), y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

test('lesson confirmation and destructive action colors meet WCAG AA text contrast', () => {
  const card = read('resources/js/Components/Lessons/ParentLessonCard.vue');
  const teacher = read('resources/js/Components/Lessons/TeacherLessonCard.vue');
  assert.ok(contrast('#b45309', '#ffffff') >= 4.5);
  assert.ok(contrast('#b91c1c', '#ffffff') >= 4.5);
  assert.match(card, /bg-amber-700 text-white/);
  assert.doesNotMatch(card, /bg-yellow-500 text-white/);
  assert.match(card, /text-sm text-red-700 hover:text-red-800/);
  assert.match(teacher, /text-sm text-red-700 hover:text-red-800/);
});

test('credits state switches do not repeat HTML attributes', () => {
  const credits = read('resources/js/Pages/Teacher/Credits/Index.vue');
  for (const match of credits.matchAll(/<button\b[^>]*>/gs)) {
    const button = match[0];
    assert.ok((button.match(/\btype=/g) ?? []).length <= 1, 'Duplicate type attr in credits');
  }
  assert.match(credits, /aria-pressed="activeTab === 'transactions'"/);
  assert.match(credits, /aria-pressed="activeTab === 'recharges'"/);
});

test('main UI supports keyboard navigation and form feedback', () => {
  const layout = read('resources/js/Layouts/AppLayout.vue');
  const review = read('resources/js/Pages/Reviews/Create.vue');
  const diagnostic = read('resources/js/Pages/Diagnostics/Create.vue');
  assert.match(layout, /href="#mova-main"/);
  assert.match(review, /aria-label=.*Calificar con/);
  assert.match(review, /aria-pressed="form.rating === n"/);
  assert.match(review, /for="review-comment"/);
  assert.match(diagnostic, /for="diagnostic-difficulty"/);
  assert.match(diagnostic, /for="diagnostic-feedback"/);
  assert.match(diagnostic, /if \(errorBag\.student_id\) step\.value = 1/);
});

test('private unread count is not capped by the twenty most recent messages', () => {
  const controller = read('app/Http/Controllers/NotificationController.php');
  assert.match(controller, /X-Mova-Unread-Count/);
  assert.match(controller, /unreadNotifications\(\)->count\(\)/);
});

test('modal and mobile navigation both use shared scroll locking', () => {
  const modal = read('resources/js/Components/Modal.vue');
  const navbar = read('resources/js/Components/LandingNavbar.vue');
  const chatbot = read('resources/js/Components/ChatbotWidget.vue');
  assert.match(modal, /lockBodyScroll/);
  assert.match(navbar, /lockBodyScroll/);
  assert.match(chatbot, /lockBodyScroll/);
});
