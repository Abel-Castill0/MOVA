<template>
  <AppLayout title="WhatsApp — Vincular dispositivo">
    <div class="space-y-6 max-w-4xl">
      <!-- Encabezado -->
      <div class="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h2 class="text-2xl font-bold text-gray-900">WhatsApp de MOVA</h2>
          <p class="text-sm text-slate-500 mt-1">Vincula y administra el número dedicado que enviará los avisos automáticos.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <span class="px-3 py-1 text-xs font-bold rounded-lg border bg-brand-50 text-brand-700 border-brand-200 uppercase">{{ p.environment.label }}</span>
          <span v-if="pilotLeft" class="px-3 py-1 text-xs font-bold rounded-lg border bg-amber-50 text-amber-700 border-amber-200">{{ pilotLeft }}</span>
          <span :class="['px-3 py-1 text-xs font-bold rounded-lg border',
            p.notifications.enabled ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-100 text-slate-600 border-slate-200']">
            {{ p.notifications.enabled ? 'AVISOS A USUARIOS ACTIVOS' : 'AVISOS A USUARIOS DESACTIVADOS' }}
          </span>
        </div>
      </div>

      <div v-if="!p.controlEnabled" class="p-4 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm">
        El control administrativo de WhatsApp está desactivado en este entorno (<code>WAHA_ADMIN_CONTROL_ENABLED</code>).
      </div>

      <template v-else>
        <!-- MFA reciente -->
        <div v-if="!stepUp.valid" class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm flex items-center justify-between gap-3 flex-wrap">
          <span>Para ver el QR y controlar la sesión debes confirmar tu verificación en dos pasos (MFA) recientemente.</span>
          <a :href="stepUp.challengeUrl" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700">Verificar MFA</a>
        </div>

        <div v-if="notice" :class="['p-4 rounded-xl border text-sm font-medium', notice.ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800']" role="status">
          {{ notice.text }}
        </div>

        <!-- Alerta: la vinculación se perdió -->
        <div v-if="p.link.lost" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-900 text-sm" role="alert">
          <p class="font-bold">La vinculación de WhatsApp se perdió ({{ p.link.lost.status }} desde {{ fmt(p.link.lost.at) }}).</p>
          <p class="mt-1">Causas habituales: WhatsApp revocó el dispositivo (p. ej. tras escribir a un contacto nuevo), alguien lo quitó en el celular, o el celular principal estuvo más de 14 días sin usarse. No se borró nada automáticamente. Revisa «Dispositivos vinculados» en el celular +51 905 922 835 y, si ya no aparece, usa «Restablecer vinculación» y escanea de nuevo.</p>
        </div>

        <!-- 1. Estado -->
        <section class="bg-white rounded-xl border border-gray-200 p-5" aria-labelledby="wa-estado">
          <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
            <h3 id="wa-estado" class="text-base font-bold text-slate-800 flex items-center gap-2">
              <span :class="['w-3 h-3 rounded-full', meta.dot]"></span>
              Estado: <span :class="meta.text">{{ meta.label }}</span>
            </h3>
            <span class="text-xs text-slate-500">Actualizado {{ updatedAgo }}</span>
          </div>
          <p v-if="p.error" class="text-sm text-slate-600 mb-4">{{ p.error }}</p>
          <dl class="grid grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Número dedicado de MOVA</dt><dd class="font-semibold text-slate-800">{{ p.number.expected || 'Sin configurar' }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Número conectado</dt><dd class="font-semibold" :class="p.state === 'wrong_number' ? 'text-rose-700' : 'text-slate-800'">{{ p.number.connected || '—' }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Estado de WAHA</dt><dd class="font-semibold text-slate-800">{{ p.rawStatus || '—' }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Servidor</dt><dd class="font-semibold" :class="p.server.reachable ? 'text-emerald-700' : 'text-rose-700'">{{ p.server.reachable ? 'Disponible' : 'No disponible' }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Última conexión</dt><dd class="font-semibold text-slate-800">{{ p.lastConnectedAt ? fmt(p.lastConnectedAt) : 'Sin registro' }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Lista autorizada</dt><dd class="font-semibold text-slate-800">{{ p.notifications.allowlistActive ? 'Activa' : 'No configurada' }}</dd></div>
          </dl>
        </section>

        <!-- 2. Vincular (QR) -->
        <section v-if="showLink" class="bg-white rounded-xl border border-gray-200 p-5" aria-labelledby="wa-vincular">
          <h3 id="wa-vincular" class="text-base font-bold text-slate-800 mb-4">Vincular WhatsApp de MOVA</h3>

          <div class="grid md:grid-cols-2 gap-6 items-start">
            <div class="flex flex-col items-center">
              <!-- QR real de WAHA -->
              <div v-if="p.state === 'waiting_qr'" class="w-64 h-64 rounded-xl border border-gray-200 bg-white flex items-center justify-center overflow-hidden">
                <img v-if="qrUrl" :src="qrUrl" alt="Código QR real de WhatsApp para vincular el número de MOVA" class="w-full h-full" style="image-rendering: pixelated" />
                <p v-else class="text-sm text-slate-500 px-4 text-center">{{ qrMessage || 'Cargando QR…' }}</p>
              </div>
              <p v-if="p.state === 'waiting_qr' && qrUrl" class="text-xs text-slate-500 mt-2">QR renovado hace {{ qrAge }} s · se actualiza solo (caduca a los ~20 s)</p>
              <p v-if="p.state === 'waiting_qr' && qrMessage && !qrUrl" class="text-xs text-rose-600 mt-2">{{ qrMessage }}</p>

              <div v-else-if="p.state === 'starting'" class="w-64 h-64 rounded-xl border border-dashed border-gray-300 flex items-center justify-center text-sm text-slate-500">Iniciando la sesión…</div>
              <div v-else-if="p.state !== 'waiting_qr'" class="w-64 h-64 rounded-xl border border-dashed border-gray-300 flex items-center justify-center text-sm text-slate-500 text-center px-6">
                {{ p.state === 'unavailable' ? 'Sin conexión con WAHA' : p.state === 'unverified_number' ? 'Verificando el número vinculado…' : 'Aún no hay un QR: inicia la vinculación' }}
              </div>
            </div>

            <div class="text-sm text-slate-700 space-y-4">
              <ol class="list-decimal pl-5 space-y-1.5">
                <li>Abre WhatsApp en el celular <strong>{{ p.number.expected || 'dedicado de MOVA' }}</strong>.</li>
                <li>Entra a <strong>Dispositivos vinculados</strong> (Ajustes).</li>
                <li>Selecciona <strong>Vincular un dispositivo</strong>.</li>
                <li>Escanea el QR que aparece en pantalla.</li>
                <li>Espera la confirmación: aquí cambiará a «Conectado».</li>
              </ol>
              <p class="text-xs text-slate-500">WAHA solo espera unos 2–3 minutos el escaneo; si expira, inicia una nueva vinculación (no se reinicia sola).</p>

              <div class="flex flex-wrap gap-2">
                <button v-if="p.actions.start" type="button" :disabled="busy" @click="run('start')" :class="btn.primary">Iniciar vinculación</button>
                <button v-if="p.actions.restart" type="button" :disabled="busy" @click="run('restart')" :class="btn.secondary">Recuperar sesión</button>
                <button v-if="p.actions.reset" type="button" :disabled="busy" @click="resetOpen = !resetOpen" :class="btn.danger">Restablecer vinculación</button>
                <button v-if="p.actions.pair" type="button" :disabled="busy" @click="pair" :class="btn.secondary">Vincular mediante código</button>
              </div>

              <div v-if="resetOpen" class="p-3 rounded-lg border border-rose-200 bg-rose-50 space-y-2">
                <p class="text-xs text-rose-800">Descarta las credenciales guardadas de esta sesión (si había una vinculación a medias o de otro número) y arranca limpia. Solo úsalo si «Recuperar sesión» no funcionó. Escribe <strong>RESTABLECER</strong> para confirmar.</p>
                <div class="flex gap-2">
                  <input v-model="resetText" aria-label="Escribe RESTABLECER para confirmar" type="text" autocomplete="off" class="flex-1 rounded-lg border border-rose-300 px-3 py-1.5 text-sm" placeholder="RESTABLECER" />
                  <button type="button" :disabled="busy || resetText !== 'RESTABLECER'" @click="doReset" :class="btn.danger">Confirmar</button>
                </div>
              </div>

              <div v-if="pairCode" class="p-4 rounded-lg border border-brand-200 bg-brand-50 text-center">
                <p class="text-xs text-brand-800 mb-1">En WhatsApp: Dispositivos vinculados → Vincular un dispositivo → <em>Vincular con el número de teléfono</em></p>
                <p class="text-3xl font-black tracking-widest text-brand-800 font-mono">{{ pairCode }}</p>
                <p class="text-xs text-slate-500 mt-1">Caduca en pocos minutos. No lo compartas.</p>
              </div>
            </div>
          </div>
        </section>

        <!-- 3. Conectado + prueba -->
        <section v-if="p.state === 'connected'" class="bg-white rounded-xl border border-emerald-200 p-5" aria-labelledby="wa-ok">
          <h3 id="wa-ok" class="text-base font-bold text-emerald-800">✅ WhatsApp conectado</h3>
          <p class="text-sm text-slate-600 mt-1">Número: <strong>{{ p.number.connected }}</strong>. El QR ya no se muestra.</p>
        </section>

        <section class="bg-white rounded-xl border border-gray-200 p-5" aria-labelledby="wa-prueba">
          <h3 id="wa-prueba" class="text-base font-bold text-slate-800">Enviar mensaje de prueba</h3>
          <p class="text-sm text-slate-600 mt-1">Destinatario autorizado: <strong>{{ p.test.recipient || '—' }}</strong> (fijo; no se puede cambiar desde aquí).</p>
          <blockquote class="mt-3 p-3 rounded-lg bg-slate-50 border border-slate-100 text-sm text-slate-700">{{ p.test.message }}</blockquote>
          <p v-if="p.test.blockedReason" class="text-xs text-slate-500 mt-2">{{ p.test.blockedReason }}</p>
          <button type="button" :class="[btn.primary, 'mt-3']" :disabled="busy || !p.test.canSend" @click="sendTest">Enviar mensaje de prueba</button>

          <div v-if="last" class="mt-4 border-t border-gray-100 pt-4 text-sm">
            <p class="font-semibold text-slate-800 mb-2">Último envío de prueba</p>
            <ul class="space-y-1.5">
              <li :class="stepClass(true)">① Solicitud registrada · {{ fmt(last.at) }}</li>
              <li :class="stepClass(['sent','delivered','read'].includes(last.status))">② Aceptado por WAHA<span v-if="last.messageId"> · id <code class="text-xs">{{ last.messageId }}</code></span></li>
              <li :class="stepClass(['delivered','read'].includes(last.status))">③ Entregado (ACK){{ last.deliveredAt ? ' · ' + fmt(last.deliveredAt) : ' · pendiente de confirmación' }}</li>
              <li :class="stepClass(last.status === 'read')">④ Leído{{ last.readAt ? ' · ' + fmt(last.readAt) : '' }}</li>
            </ul>
            <p v-if="['failed','skipped','unknown'].includes(last.status)" class="mt-2 text-rose-700">
              Resultado: <strong>{{ last.status }}</strong>{{ last.skipReason ? ' (' + last.skipReason + ')' : '' }}{{ last.error ? ' — ' + last.error : '' }}
            </p>
            <p class="mt-2 text-xs text-slate-500">«Aceptado» no significa «entregado»: la entrega solo se confirma cuando llega el ACK del dispositivo.</p>
          </div>
        </section>

        <!-- Política de envío (protege la vinculación) -->
        <section class="bg-white rounded-xl border border-gray-200 p-5" aria-labelledby="wa-politica">
          <h3 id="wa-politica" class="text-base font-bold text-slate-800">Política de envío que protege la vinculación</h3>
          <ul class="mt-2 text-sm text-slate-700 space-y-1.5 list-disc pl-5">
            <li>WhatsApp revoca los dispositivos de clientes no oficiales que <strong>inician chats con desconocidos</strong>. {{ p.link.requireUserInitiated ? 'MOVA solo escribe a quien escribió primero al número.' : 'MOVA no inicia chats nuevos durante el calentamiento.' }}</li>
            <li>Para activar los avisos, cada usuario escribe «ACTIVAR» al {{ p.number.expected || 'número de MOVA' }} desde su WhatsApp; desde entonces MOVA puede responderle.</li>
            <li>Contactos que ya escribieron: <strong>{{ p.link.contactedCount }}</strong>.</li>
            <li v-if="p.link.warmupRemainingMinutes > 0" class="text-amber-700">Calentamiento tras vincular: faltan <strong>{{ Math.ceil(p.link.warmupRemainingMinutes / 60) }} h</strong> sin iniciar chats nuevos.</li>
            <li v-if="p.link.restrictedUntil" class="text-rose-700">WhatsApp aplicó una restricción de contactos nuevos hasta {{ fmt(p.link.restrictedUntil) }}; solo se responde a quien ya escribió.</li>
          </ul>
        </section>

        <!-- Mensajes -->
        <section class="bg-white rounded-xl border border-gray-200 p-5" aria-labelledby="wa-mensajes">
          <h3 id="wa-mensajes" class="text-base font-bold text-slate-800">Mensajes de los últimos {{ p.delivery.days }} días</h3>
          <dl class="mt-3 grid grid-cols-3 lg:grid-cols-6 gap-3 text-center text-sm">
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Aceptados</dt><dd class="font-bold text-slate-800">{{ p.delivery.accepted }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Entregados</dt><dd class="font-bold text-emerald-700">{{ p.delivery.delivered }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Leídos</dt><dd class="font-bold text-emerald-700">{{ p.delivery.read }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Fallidos</dt><dd class="font-bold text-rose-700">{{ p.delivery.failed }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Omitidos</dt><dd class="font-bold text-slate-800">{{ p.delivery.skipped }}</dd></div>
            <div class="p-3 rounded-lg bg-slate-50 border border-slate-100"><dt class="text-xs text-slate-500">Inciertos</dt><dd class="font-bold text-amber-700">{{ p.delivery.unknown }}</dd></div>
          </dl>
        </section>

        <!-- Persistencia e infraestructura -->
        <section class="grid md:grid-cols-2 gap-4" aria-label="Persistencia e infraestructura">
          <div class="bg-white rounded-xl border border-gray-200 p-5 text-sm text-slate-700">
            <h3 class="text-base font-bold text-slate-800 mb-2">Persistencia de la sesión</h3>
            <p>{{ p.persistence.storage }}.</p>
            <p class="mt-1">{{ p.persistence.restartPolicy }}.</p>
            <p class="mt-1">{{ p.persistence.autoRecovery }}.</p>
            <p class="mt-2 font-semibold" :class="p.persistence.lastBackupAt ? 'text-emerald-700' : 'text-amber-700'">
              Último respaldo cifrado: {{ p.persistence.lastBackupAt ? fmt(p.persistence.lastBackupAt) + ' · huella ' + p.persistence.lastBackupFingerprint : 'ninguno registrado' }}
            </p>
            <p v-if="!p.persistence.backupOffVm" class="mt-1 text-xs text-amber-700">Los respaldos están solo en la VM: se eliminan con ella. Falta un destino fuera de la VM (requiere tu aprobación).</p>
          </div>
          <div class="bg-white rounded-xl border border-gray-200 p-5 text-sm text-slate-700">
            <h3 class="text-base font-bold text-slate-800 mb-2">Infraestructura</h3>
            <p>{{ p.infra.type }}</p>
            <p class="mt-1">Costo mensual estimado: <strong>{{ p.infra.monthlyUsd !== null ? 'US$ ' + p.infra.monthlyUsd.toFixed(2) : 'sin definir' }}</strong></p>
            <p v-if="p.infra.pilotExpiresAt" class="mt-1 text-amber-700">Piloto con caducidad: {{ fmt(p.infra.pilotExpiresAt) }}. La limpieza automática eliminará la VM y la sesión que contiene si no se migra antes.</p>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  panel: { type: Object, required: true },
  stepUp: { type: Object, required: true },
})

const p = ref(props.panel)
const stepUp = ref(props.stepUp)
const busy = ref(false)
const notice = ref(null)
const qrUrl = ref(null)
const qrMessage = ref('')
const qrAt = ref(0)
const now = ref(Date.now())
const pairCode = ref('')
const resetOpen = ref(false)
const resetText = ref('')
let statusTimer = null
let qrTimer = null
let clock = null
let pairTimer = null

const btn = {
  primary: 'px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed',
  secondary: 'px-4 py-2 rounded-lg bg-white text-slate-700 text-sm font-semibold border border-gray-300 hover:bg-gray-50 disabled:opacity-50',
  danger: 'px-4 py-2 rounded-lg bg-white text-rose-700 text-sm font-semibold border border-rose-300 hover:bg-rose-50 disabled:opacity-50',
}

const META = {
  connected: { label: 'Conectado', dot: 'bg-emerald-500', text: 'text-emerald-700' },
  waiting_qr: { label: 'Esperando QR', dot: 'bg-amber-500 animate-pulse', text: 'text-amber-700' },
  starting: { label: 'Iniciando', dot: 'bg-amber-500 animate-pulse', text: 'text-amber-700' },
  disconnected: { label: 'Desconectado', dot: 'bg-slate-400', text: 'text-slate-600' },
  error: { label: 'Error de sesión', dot: 'bg-rose-500', text: 'text-rose-700' },
  wrong_number: { label: 'Número incorrecto', dot: 'bg-rose-500', text: 'text-rose-700' },
  unverified_number: { label: 'Verificando número', dot: 'bg-amber-500 animate-pulse', text: 'text-amber-700' },
  unavailable: { label: 'Servicio no disponible', dot: 'bg-rose-500', text: 'text-rose-700' },
  disabled: { label: 'Control desactivado', dot: 'bg-slate-400', text: 'text-slate-600' },
}
const meta = computed(() => META[p.value.state] ?? META.unavailable)
const showLink = computed(() => p.value.state !== 'connected')
const last = computed(() => p.value.test.last)
const qrAge = computed(() => Math.max(0, Math.round((now.value - qrAt.value) / 1000)))
const updatedAgo = computed(() => {
  const s = Math.max(0, Math.round((now.value - new Date(p.value.generatedAt).getTime()) / 1000))
  return s < 3 ? 'ahora' : `hace ${s} s`
})
const pilotLeft = computed(() => {
  const exp = p.value.environment.pilotExpiresAt
  if (!exp) return ''
  const ms = new Date(exp).getTime() - now.value
  if (ms <= 0) return 'Piloto vencido'
  const h = Math.floor(ms / 3600000)
  const m = Math.floor((ms % 3600000) / 60000)
  return `Piloto: quedan ${h} h ${m} min`
})

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'medium' }) : '')
const stepClass = (done) => (done ? 'text-emerald-700' : 'text-slate-400')

function setNotice(ok, text) {
  notice.value = { ok, text }
  setTimeout(() => { if (notice.value?.text === text) notice.value = null }, 9000)
}

async function loadStatus() {
  try {
    const { data } = await axios.get(route('admin.whatsapp.status'))
    p.value = data.panel
    stepUp.value = data.stepUp
  } catch {
    /* se conserva el último estado; el siguiente ciclo reintenta */
  }
  if (p.value.state !== 'waiting_qr') clearQr()
  if (p.value.state === 'connected') { pairCode.value = ''; resetOpen.value = false }
}

function clearQr() {
  if (qrUrl.value) URL.revokeObjectURL(qrUrl.value)
  qrUrl.value = null
  qrMessage.value = ''
}

async function loadQr() {
  if (p.value.state !== 'waiting_qr' || !stepUp.value.valid || document.hidden) return
  try {
    const res = await axios.get(route('admin.whatsapp.qr'), { responseType: 'blob', validateStatus: () => true, params: { t: Date.now() } })
    if (res.status === 200 && res.data.type === 'image/png') {
      const url = URL.createObjectURL(res.data)
      if (qrUrl.value) URL.revokeObjectURL(qrUrl.value)
      qrUrl.value = url
      qrAt.value = Date.now()
      qrMessage.value = ''
      return
    }
    let msg = 'No se pudo obtener el QR.'
    try { msg = JSON.parse(await res.data.text()).message || msg } catch { /* respuesta no JSON */ }
    if (res.status === 403) { stepUp.value = { ...stepUp.value, valid: false }; msg = 'Confirma tu MFA para ver el QR.' }
    if (qrUrl.value) { URL.revokeObjectURL(qrUrl.value); qrUrl.value = null }
    qrMessage.value = msg
  } catch {
    qrMessage.value = 'No se pudo contactar al servidor.'
  }
}

async function post(name, params, body = {}) {
  busy.value = true
  try {
    const res = await axios.post(route(name, params), body, { validateStatus: () => true })
    if (res.status === 403 && !res.data?.ok) {
      stepUp.value = { ...stepUp.value, valid: false }
      setNotice(false, 'Confirma tu verificación MFA y vuelve a intentarlo.')
      return null
    }
    if (res.data?.panel) p.value = res.data.panel
    return res
  } catch {
    setNotice(false, 'No se pudo contactar al servidor.')
    return null
  } finally {
    busy.value = false
  }
}

async function run(action) {
  const text = action === 'start'
    ? '¿Iniciar una nueva vinculación? Tendrás unos 2–3 minutos para escanear el QR.'
    : '¿Recuperar la sesión? Reinicia la sesión de WAHA (no desvincula el teléfono).'
  if (!window.confirm(text)) return
  const res = await post('admin.whatsapp.control', { action }, { confirm: true })
  if (res) setNotice(res.data.ok, res.data.message)
  await loadStatus()
}

async function doReset() {
  const res = await post('admin.whatsapp.control', { action: 'reset' }, { confirm: resetText.value })
  if (res) setNotice(res.data.ok, res.data.message)
  resetOpen.value = false
  resetText.value = ''
  await loadStatus()
}

async function pair() {
  const res = await post('admin.whatsapp.pairing-code', {})
  if (!res) return
  if (res.data.ok) {
    pairCode.value = res.data.code.length === 8 ? `${res.data.code.slice(0, 4)}-${res.data.code.slice(4)}` : res.data.code
    clearTimeout(pairTimer)
    pairTimer = setTimeout(() => { pairCode.value = '' }, 180000) // el código no queda en pantalla
  } else {
    setNotice(false, res.data.message || 'No se pudo generar el código.')
  }
}

async function sendTest() {
  if (!window.confirm(`¿Enviar el mensaje de prueba a ${p.value.test.recipient}?`)) return
  const res = await post('admin.whatsapp.send-test', {})
  if (res) setNotice(res.data.ok, res.data.message)
}

onMounted(() => {
  statusTimer = setInterval(() => { if (!document.hidden) loadStatus() }, 3000)
  qrTimer = setInterval(loadQr, 4000)
  clock = setInterval(() => { now.value = Date.now() }, 1000)
  loadQr()
})

onBeforeUnmount(() => {
  clearInterval(statusTimer); clearInterval(qrTimer); clearInterval(clock); clearTimeout(pairTimer)
  clearQr()
})
</script>
