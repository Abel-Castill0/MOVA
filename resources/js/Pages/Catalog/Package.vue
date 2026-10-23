<template>
  <PublicPageLayout :title="`${package.name} – ${package.credits} créditos – MOVA`">
    <nav aria-label="Ruta de navegación" class="mb-5 text-sm text-slate-500">
      <Link :href="route('catalog.index')" class="font-semibold text-brand-600 hover:underline">Servicios y precios</Link>
      <span aria-hidden="true"> / </span>
      <span class="text-slate-700">Paquete {{ package.name }}</span>
    </nav>

    <div class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
      <!-- Detalle de la oferta -->
      <section class="space-y-5" aria-labelledby="detalle">
        <div class="flex h-56 items-center justify-center rounded-2xl bg-[#E5EEFB]">
          <img :src="package.image" :alt="package.alt" width="224" height="224" class="h-52 w-52 object-contain" />
        </div>
        <div>
          <h1 id="detalle" class="text-3xl font-black text-slate-900">Paquete {{ package.name }}</h1>
          <p v-if="package.tagline" class="mt-1 font-semibold text-brand-600">{{ package.tagline }}</p>
          <p class="mt-3 text-slate-600">{{ package.description }}</p>
        </div>
        <ul class="space-y-2 text-sm text-slate-600">
          <li v-for="b in package.benefits" :key="b" class="flex gap-2"><span aria-hidden="true" class="text-brand-600">✓</span><span>{{ b }}</span></li>
        </ul>
        <div class="rounded-2xl border border-gray-100 bg-white p-5 text-sm text-slate-600 shadow-sm">
          <h2 class="font-black text-slate-900">Antes de comprar</h2>
          <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>Estás comprando <strong>créditos de la plataforma MOVA</strong>: se usan para aceptar clases dentro de MOVA y no se canjean por dinero. No es un pago a un profesor por una clase.</li>
            <li>Los créditos se acreditan <strong>una sola vez, cuando el pago queda confirmado</strong>. Un pago pendiente o rechazado no acredita créditos.</li>
            <li>Reembolso íntegro de los créditos no usados dentro de los <strong>{{ refundDays }} días</strong> siguientes a la compra; los créditos ya consumidos no se reembolsan. Detalle en la
              <Link :href="route('legal.refunds')" class="font-semibold text-brand-600 hover:underline">Política de cambios y devoluciones</Link>.</li>
            <li>Al comprar aceptas los <Link :href="route('legal.terms')" class="font-semibold text-brand-600 hover:underline">Términos y Condiciones</Link> y la
              <Link :href="route('legal.privacy')" class="font-semibold text-brand-600 hover:underline">Política de Privacidad</Link>. Si necesitas presentar un reclamo: <Link :href="route('complaints.create')" class="font-semibold text-brand-600 hover:underline">Libro de Reclamaciones</Link>.</li>
          </ul>
        </div>
      </section>

      <!-- Resumen de compra -->
      <aside class="lg:sticky lg:top-24 lg:self-start" aria-labelledby="resumen">
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm" data-testid="purchase-summary">
          <h2 id="resumen" class="text-lg font-black text-slate-900">Resumen de compra</h2>
          <dl class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Producto</dt><dd class="font-semibold text-slate-900">Paquete {{ package.name }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Créditos</dt><dd class="font-semibold text-slate-900">{{ package.credits }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Equivalencia</dt><dd class="font-semibold text-slate-900">{{ package.minutes_included }} min de clase</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Valor por crédito</dt><dd class="font-semibold text-slate-900">S/ {{ package.price_per_credit }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Moneda</dt><dd class="font-semibold text-slate-900">Soles (PEN)</dd></div>
          </dl>
          <div class="mt-4 flex items-baseline justify-between border-t border-gray-100 pt-4">
            <span class="text-sm font-bold text-slate-700">Total a pagar</span>
            <span class="text-3xl font-black text-slate-900" data-testid="total">S/ {{ money(package.amount_pen) }}</span>
          </div>

          <p v-if="$page.props.payments?.online" class="mt-3 text-xs text-slate-500">
            Medios de pago: {{ methodsLabel }}. El pago se procesa en un formulario seguro de Culqi; MOVA nunca recibe ni guarda los datos de tu tarjeta.
          </p>

          <div class="mt-4 space-y-3">
            <PaymentsStatusBanner />

            <!-- Invitado -->
            <template v-if="!viewer.authenticated">
              <p class="text-sm text-slate-600">Para comprar créditos necesitas una cuenta de <strong>profesor</strong>. Tu selección se conserva al volver.</p>
              <Link :href="route('login')" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2" data-testid="login-to-buy">
                Iniciar sesión para comprar
              </Link>
              <Link :href="route('register')" class="inline-flex w-full items-center justify-center rounded-xl border border-brand-200 px-4 py-3 text-sm font-bold text-brand-700 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Crear cuenta de profesor
              </Link>
            </template>

            <!-- Profesor autenticado -->
            <template v-else-if="viewer.isTeacher">
              <button type="button" :disabled="!$page.props.payments?.online || processing" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none" data-testid="continue-to-payment" @click="buy">
                {{ processing ? 'Abriendo el pago…' : 'Continuar al pago' }}
              </button>
              <p v-if="!$page.props.payments?.online" class="text-xs text-slate-500">El botón se habilitará cuando los pagos en línea estén activos para tu cuenta.</p>
              <Link :href="route('teacher.credits.index')" class="block text-center text-xs font-semibold text-brand-600 hover:underline">Ver mi saldo e historial de créditos</Link>
            </template>

            <!-- Otro rol -->
            <p v-else class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Los créditos los compran los profesores. Con tu cuenta ({{ viewer.role }}) no puedes comprar este paquete.</p>
          </div>
        </div>

        <div class="mt-5 text-sm">
          <p class="font-bold text-slate-900">Otros paquetes</p>
          <ul class="mt-2 space-y-1">
            <li v-for="p in otherPackages" :key="p.code">
              <Link :href="route('catalog.package', p.code)" class="text-brand-600 hover:underline">{{ p.name }} · {{ p.credits }} créditos · S/ {{ money(p.amount_pen) }}</Link>
            </li>
          </ul>
        </div>
      </aside>
    </div>
  </PublicPageLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import PublicPageLayout from '@/Layouts/PublicPageLayout.vue'
import PaymentsStatusBanner from '@/Components/PaymentsStatusBanner.vue'

const props = defineProps({
  package: { type: Object, required: true },
  viewer: { type: Object, required: true },
  refundDays: { type: Number, required: true },
  creditMinutes: { type: Number, required: true },
  otherPackages: { type: Array, default: () => [] },
})

const page = usePage()
const processing = ref(false)
const money = (v) => Number(v).toFixed(2)

// Solo se muestran los medios que el backend dice que están realmente habilitados.
const METHOD_LABELS = { card: 'tarjeta de crédito o débito', yape: 'Yape', order: 'efectivo, banca móvil o billeteras (PagoEfectivo)' }
const methodsLabel = computed(() => (page.props.payments?.methods ?? []).map((m) => METHOD_LABELS[m] ?? m).join(', '))

function buy() {
  if (processing.value) return
  processing.value = true
  // Solo viaja el código del paquete: importe, créditos y moneda los decide el servidor.
  router.post(route('teacher.credits.checkout.store'), { package_code: props.package.code }, { onFinish: () => { processing.value = false } })
}
</script>
