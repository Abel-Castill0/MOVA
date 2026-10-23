<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Icon from '@/Components/Icon.vue'
import { roleLabel } from '@/utils/roleLabels'
defineProps({ compact: Boolean, placement: { type: String, default: 'up' } })
const emit = defineEmits(['navigate'])
const user = computed(() => usePage().props.auth?.user)
const teacher = computed(() => user.value?.roles?.includes('teacher'))
const open = ref(false), root = ref(null), trigger = ref(null), panel = ref(null)
function close(restore = false) { open.value = false; if (restore) trigger.value?.focus() }
async function toggle() { open.value = !open.value; if (open.value) { await nextTick(); panel.value?.querySelector('[role="menuitem"]')?.focus() } }
function navigate() { close(); emit('navigate') }
function keys(event) {
  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true) }
  if (event.key === 'Tab') { close(true); return }
  if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const items = [...panel.value.querySelectorAll('[role="menuitem"]')]
  const current = items.indexOf(document.activeElement)
  const index = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length
  items[index]?.focus()
}
function outside(event) { if (!root.value?.contains(event.target)) close() }
onMounted(() => document.addEventListener('pointerdown', outside))
onUnmounted(() => document.removeEventListener('pointerdown', outside))
</script>
<template>
  <div ref="root" class="relative min-w-0">
    <button ref="trigger" type="button" aria-label="Menú de mi cuenta" aria-haspopup="menu" :aria-expanded="open" @click="toggle" @keydown.down.prevent="!open && toggle()" @keydown.esc="close(true)" class="flex min-h-11 w-full items-center gap-2.5 rounded-2xl border border-slate-200/70 bg-white/80 p-2 text-left transition-colors hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
      <img v-if="user?.avatar_url" :src="user.avatar_url" alt="" width="36" height="36" class="h-9 w-9 shrink-0 rounded-xl object-cover" />
      <span v-else :class="teacher ? 'bg-orange-600' : 'bg-brand-600'" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-sm font-extrabold text-white">{{ user?.name?.charAt(0)?.toUpperCase() }}</span>
      <span v-if="!compact" class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-900">{{ user?.name }}</span><span class="block truncate text-xs text-slate-500">{{ roleLabel(user?.roles?.[0]) }}</span></span><span v-if="!compact" aria-hidden="true" class="text-slate-400">⌄</span>
    </button>
    <Transition enter-active-class="transition duration-150 motion-reduce:transition-none" enter-from-class="opacity-0 translate-y-1" leave-active-class="transition duration-100 motion-reduce:transition-none" leave-to-class="opacity-0">
      <div v-if="open" ref="panel" role="menu" aria-label="Mi cuenta" @keydown="keys" :class="placement === 'up' ? 'bottom-full left-0 mb-2' : 'right-0 top-full mt-2'" class="absolute z-[60] w-60 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white p-1.5 shadow-xl">
        <p class="truncate px-3 py-2 text-xs text-slate-500">{{ user?.email }}</p>
        <Link role="menuitem" :href="route('profile.edit')" @click="navigate" class="account-menu-item"><Icon name="profile" :size="18" />Mi perfil</Link>
        <Link role="menuitem" :href="route('account.settings')" @click="navigate" class="account-menu-item"><Icon name="settings" :size="18" />Configuración de cuenta</Link>
        <div class="my-1 border-t border-slate-100" />
        <Link role="menuitem" :href="route('logout')" method="post" as="button" @click="navigate" class="account-menu-item"><Icon name="logout" :size="18" />Cerrar sesión</Link>
      </div>
    </Transition>
  </div>
</template>
<style scoped>.account-menu-item { @apply flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-700 focus:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500; }</style>
