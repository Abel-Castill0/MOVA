<template>
  <Head title="Estado de WhatsApp (WAHA) - Admin" />

  <div class="min-h-screen bg-slate-50 py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Encabezado -->
      <div class="flex items-center justify-between pb-4 border-b border-slate-200">
        <div>
          <h1 class="text-2xl font-black text-slate-900 tracking-tight">Servicio WhatsApp (WAHA)</h1>
          <p class="text-sm text-slate-500 mt-1">
            Monitoreo del contenedor WAHA y prueba de envío sin restricciones de plantillas Meta.
          </p>
        </div>
        <Link
          :href="route('admin.operations')"
          class="text-xs font-semibold px-3 py-2 bg-white border border-slate-200 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors shadow-2xs"
        >
          Volver a Operaciones
        </Link>
      </div>

      <!-- Alertas de Flash Session -->
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium flex items-center justify-between">
        <span>{{ $page.props.flash.success }}</span>
      </div>
      <div v-if="$page.props.flash?.error" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-medium flex items-center justify-between">
        <span>{{ $page.props.flash.error }}</span>
      </div>

      <!-- Tarjeta 1: Estado de la Sesión -->
      <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            <span class="w-3 h-3 rounded-full" :class="statusDotClass"></span>
            Estado de Conexión: <span class="capitalize" :class="statusTextClass">{{ statusLabel }}</span>
          </h2>
          <button
            type="button"
            @click="refreshStatus"
            :disabled="loading"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors cursor-pointer disabled:opacity-50"
          >
            <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Actualizar
          </button>
        </div>

        <!-- Detalles de estado -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 border-t border-slate-100 text-xs">
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
            <span class="text-slate-400 block font-medium">Sesión WAHA</span>
            <span class="font-bold text-slate-800 text-sm">{{ wahaConfig.session }}</span>
          </div>
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
            <span class="text-slate-400 block font-medium">URL del Servicio</span>
            <span class="font-bold text-slate-800 text-sm truncate block" :title="wahaConfig.url">{{ wahaConfig.url }}</span>
          </div>
          <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
            <span class="text-slate-400 block font-medium">Última Respuesta WAHA</span>
            <span class="font-mono font-bold text-slate-800 text-sm">{{ currentStatus.status || 'UNKNOWN' }}</span>
          </div>
        </div>

        <!-- Instrucciones si requiere QR -->
        <div v-if="currentStatus.status === 'SCAN_QR_CODE'" class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
          <p class="font-bold mb-1">¡Acción Requerida!</p>
          <p>
            La sesión está lista pero requiere vincular el teléfono dedicado: en WhatsApp ve a Dispositivos vinculados → Vincular un dispositivo y escanea este código (se renueva cada ~20 s; pulsa Actualizar si caduca). El dashboard de WAHA no está expuesto.
          </p>
          <img :src="qrSrc" alt="Código QR para vincular WhatsApp" class="mt-3 h-48 w-48 rounded-lg border border-amber-200 bg-white p-1" />
        </div>

        <!-- Estado del canal y acciones explícitas (consultar el estado ya NO reinicia ni desvincula nada) -->
        <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-600">
          <span class="rounded-lg bg-slate-100 px-2.5 py-1 font-mono">canal: {{ channel.enabled ? 'ACTIVADO' : 'desactivado' }} · proveedor: {{ channel.provider }}</span>
          <span v-if="channel.allowlist" class="rounded-lg bg-amber-100 px-2.5 py-1 text-amber-800">piloto: solo destinatarios autorizados</span>
          <span v-if="channel.pausedUntil" class="rounded-lg bg-red-100 px-2.5 py-1 text-red-800">envíos en pausa por restricción de WhatsApp hasta {{ channel.pausedUntil }}</span>
          <button type="button" class="rounded-lg border border-slate-300 px-3 py-1 font-semibold hover:bg-slate-50 cursor-pointer" @click="restartSession">Reiniciar sesión de WAHA</button>
        </div>
      </div>

      <!-- Tarjeta 2: Formulario de Prueba de Envío -->
      <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6">
        <h2 class="text-lg font-bold text-slate-900 mb-1">Disparar Mensaje de Prueba</h2>
        <p class="text-xs text-slate-500 mb-6">
          Solo se envía a usuarios registrados con teléfono verificado y consentimiento vigente, y siempre a través de <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-700">WhatsAppChannel</code> (baja, suspensión, cuenta cerrada, lista del piloto y límites incluidos).
        </p>

        <form @submit.prevent="submitTestMessage" class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Teléfono del usuario destinatario</label>
            <input
              v-model="form.phone"
              type="text"
              placeholder="Ej: 916666934 o +51916666934"
              class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 text-sm transition-all"
              required
            />
            <p class="text-[11px] text-slate-400 mt-1">Si son 9 dígitos peruanos, se agrega el prefijo +51. Si el teléfono no pertenece a un usuario con consentimiento, no se envía.</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-slate-700 mb-1">Contenido del Mensaje</label>
            <textarea
              v-model="form.message"
              rows="3"
              placeholder="¡Hola! Este es un mensaje de prueba automatizado desde MOVA Educa."
              class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 text-sm transition-all"
              required
            ></textarea>
          </div>

          <div class="flex items-center gap-2">
            <input
              id="sync_checkbox"
              v-model="form.sync"
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            <label for="sync_checkbox" class="text-xs text-slate-600 font-medium">
              Procesar ahora sin encolar (el resultado queda en whatsapp_messages)
            </label>
          </div>

          <div class="pt-2">
            <button
              type="submit"
              :disabled="form.processing"
              class="w-full sm:w-auto px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm rounded-xl shadow-sm transition-all cursor-pointer disabled:opacity-50"
            >
              <span v-if="form.processing">Enviando mensaje...</span>
              <span v-else>Enviar WhatsApp de Prueba</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps({
  sessionInfo: { type: Object, default: () => ({ success: false, status: 'UNKNOWN' }) },
  wahaConfig: { type: Object, default: () => ({ session: 'default', url: '' }) },
  channel: { type: Object, default: () => ({ enabled: false, provider: 'fake', allowlist: false, pausedUntil: null }) },
})

const currentStatus = ref(props.sessionInfo)
const loading = ref(false)
const qrTick = ref(Date.now())
const qrSrc = computed(() => route('admin.whatsapp.qr') + '?t=' + qrTick.value)

const form = useForm({
  phone: '',
  message: '¡Hola! Este es un mensaje de prueba automatizado desde MOVA Educa.',
  sync: false,
})

const statusLabel = computed(() => {
  const s = currentStatus.value?.status
  if (s === 'WORKING') return 'Conectado y Operativo'
  if (s === 'SCAN_QR_CODE') return 'Requiere Escanear Código QR'
  if (s === 'STARTING') return 'Iniciando Servicio...'
  if (s === 'FAILED') return 'Sesión caída (reiníciala o vincula de nuevo)'
  if (s === 'STOPPED') return 'Sesión detenida (reiníciala)'
  if (s === 'DISCONNECTED') return 'Desconectado / Sin Conexión'
  return s || 'No disponible'
})

const statusDotClass = computed(() => {
  const s = currentStatus.value?.status
  if (s === 'WORKING') return 'bg-emerald-500'
  if (s === 'SCAN_QR_CODE') return 'bg-amber-500 animate-pulse'
  return 'bg-rose-500'
})

const statusTextClass = computed(() => {
  const s = currentStatus.value?.status
  if (s === 'WORKING') return 'text-emerald-700 font-bold'
  if (s === 'SCAN_QR_CODE') return 'text-amber-700 font-bold'
  return 'text-rose-700 font-bold'
})

function restartSession() {
  if (!window.confirm('¿Reiniciar la sesión de WAHA? Los envíos se interrumpen unos segundos (no desvincula el teléfono).')) return
  router.post(route('admin.whatsapp.restart'), {}, { preserveScroll: true })
}

async function refreshStatus() {
  loading.value = true
  qrTick.value = Date.now()
  try {
    const res = await axios.get(route('admin.whatsapp.status'))
    currentStatus.value = res.data
  } catch (e) {
    currentStatus.value = { success: false, status: 'DISCONNECTED', error: e.message }
  } finally {
    loading.value = false
  }
}

function submitTestMessage() {
  form.post(route('admin.whatsapp.send-test'), {
    preserveScroll: true,
    onSuccess: () => {
      form.message = ''
    },
  })
}
</script>
