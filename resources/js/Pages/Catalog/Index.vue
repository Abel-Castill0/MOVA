<template>
  <PublicPageLayout title="Servicios y precios – MOVA">
    <div class="space-y-12">
      <header class="grid items-center gap-6 md:grid-cols-[1fr_auto]">
        <div>
          <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Servicios y precios</p>
          <h1 class="mt-2 text-3xl font-black text-slate-900 sm:text-4xl">Qué ofrece MOVA y cuánto cuesta</h1>
          <p class="mt-3 max-w-2xl text-slate-600">
            MOVA es una plataforma peruana de clases particulares en línea. <strong class="text-slate-800">MOVA vende créditos a los profesores</strong>
            para aceptar clases; <strong class="text-slate-800">las clases las paga cada familia directamente al profesor</strong>, sin intermediación de MOVA.
            Todos los precios están expresados en soles peruanos (PEN).
          </p>
        </div>
        <img src="/images/mascot/movi-saluda-192.webp" width="192" height="192" alt="Movi, la ardilla mascota de MOVA, saludando" class="hidden h-40 w-40 object-contain md:block" loading="eager" />
      </header>

      <PaymentsStatusBanner />

      <!-- 1. Lo que MOVA vende y se cobra con Culqi -->
      <section aria-labelledby="creditos">
        <div class="mb-5">
          <h2 id="creditos" class="text-2xl font-black text-slate-900">Paquetes de créditos para profesores</h2>
          <p class="mt-1 text-sm text-slate-600">
            1 crédito = {{ creditMinutes }} minutos de clase · precio base por crédito: S/ {{ money(creditPricePen) }}.
            Descuentos permanentes por volumen según el paquete.
            Se compran en línea y se acreditan automáticamente cuando el pago queda confirmado.
          </p>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          <article v-for="pack in packages" :key="pack.code" class="flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" :data-testid="`package-${pack.code}`">
            <div class="flex h-44 items-center justify-center bg-[#E5EEFB]">
              <img :src="pack.image" :alt="pack.alt" width="200" height="200" class="h-40 w-40 object-contain" loading="lazy" />
            </div>
            <div class="flex flex-1 flex-col p-5">
              <div class="flex items-start justify-between gap-3">
                <h3 class="text-xl font-black text-slate-900">{{ pack.name }}</h3>
                <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">{{ pack.credits }} créditos</span>
              </div>
              <p v-if="pack.tagline" class="mt-1 text-sm font-semibold text-brand-600">{{ pack.tagline }}</p>
              <p v-if="pack.code === 'pro'" class="mt-2 text-sm font-bold text-brand-700">Mayor ahorro</p>
              <p class="mt-2 text-sm text-slate-600">{{ pack.description }}</p>
              <p class="mt-4 text-sm text-slate-600">Precio base: S/ {{ pack.base_amount_pen }} · Descuento: {{ pack.discount_percent }}%</p>
              <p class="mt-4 text-3xl font-black text-slate-900" :aria-label="`Precio: ${money(pack.amount_pen)} soles`">S/ {{ money(pack.amount_pen) }}</p>
              <p class="text-sm font-semibold text-brand-700">Ahorro: S/ {{ pack.saving_pen }}</p>
              <p class="text-xs text-slate-500">S/ {{ pack.price_per_credit }} por crédito · {{ pack.minutes_included }} minutos de clase incluidos</p>
              <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                <li v-for="b in pack.benefits" :key="b" class="flex gap-2"><span aria-hidden="true" class="text-brand-600">✓</span><span>{{ b }}</span></li>
              </ul>
              <p class="mb-5 mt-4 text-xs text-slate-500"><strong class="text-slate-700">Para quién:</strong> profesores registrados en MOVA. <strong class="text-slate-700">Limitaciones:</strong> los créditos se usan solo dentro de MOVA y no se canjean por dinero.</p>
              <Link :href="route('catalog.package', pack.code)" class="mt-auto inline-flex w-full items-center justify-center rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2" :data-testid="`buy-${pack.code}`">
                Comprar paquete {{ pack.name }}
              </Link>
            </div>
          </article>
        </div>
      </section>

      <!-- 2. Clases por materia: las paga la familia al profesor -->
      <section aria-labelledby="clases">
        <div class="mb-5">
          <h2 id="clases" class="text-2xl font-black text-slate-900">Clases particulares por materia</h2>
          <p class="mt-1 text-sm text-slate-600">
            Clases individuales en vivo por videollamada. Cada profesor fija su tarifa por hora dentro del tope de su nivel:
            hasta <strong>S/ {{ classRates.base }}</strong> (profesores nuevos), <strong>S/ {{ classRates.experienced }}</strong> o <strong>S/ {{ classRates.expert }}</strong> según clases completadas y calificación.
            <strong class="text-slate-800">El pago de la clase se realiza directamente al profesor; MOVA no cobra la clase.</strong>
          </p>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <article v-for="subject in subjects" :key="subject.id" class="flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" :data-testid="`subject-${subject.id}`">
            <img :src="subject.image" :alt="subject.alt" width="480" height="320" class="aspect-[3/2] w-full object-cover" loading="lazy" />
            <div class="flex flex-1 flex-col p-5">
              <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-black text-slate-900">{{ subject.name }}</h3>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ levelLabel(subject.level) }}</span>
              </div>
              <p class="mt-2 text-sm text-slate-600">{{ subject.description }}</p>
              <p class="mt-3 text-lg font-black text-slate-900">Hasta S/ {{ subject.rate_cap }} <span class="text-sm font-semibold text-slate-500">por hora</span></p>
              <p class="text-xs text-slate-500">Tarifa tope para profesores nuevos; la fija cada profesor.</p>
              <p class="mb-4 mt-3 text-sm" :class="subject.verified_teachers > 0 ? 'text-emerald-700' : 'text-amber-700'">
                <template v-if="subject.verified_teachers > 0">{{ subject.verified_teachers }} {{ subject.verified_teachers === 1 ? 'profesor verificado' : 'profesores verificados' }}</template>
                <template v-else>Aún sin profesores verificados en esta materia</template>
              </p>
              <Link :href="route('marketplace')" class="mt-auto inline-flex w-full items-center justify-center rounded-xl border border-brand-200 bg-white px-4 py-2.5 text-sm font-bold text-brand-700 transition-colors hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                Ver profesores
              </Link>
            </div>
          </article>
        </div>
      </section>

      <!-- 3. Cómo se completa una compra -->
      <section aria-labelledby="proceso" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <h2 id="proceso" class="text-xl font-black text-slate-900">Cómo se completa una compra de créditos</h2>
        <ol class="mt-4 grid gap-4 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-4">
          <li><span class="font-bold text-slate-900">1. Elige un paquete.</span> Revisa créditos, precio y condiciones en su detalle.</li>
          <li><span class="font-bold text-slate-900">2. Inicia sesión como profesor.</span> Si no tienes cuenta, te registras y vuelves a tu selección.</li>
          <li><span class="font-bold text-slate-900">3. Paga en línea con Culqi.</span> Tarjeta (con verificación 3DS) y los medios que Culqi habilite para el comercio.</li>
          <li><span class="font-bold text-slate-900">4. Recibe tus créditos.</span> Se acreditan una sola vez, cuando el pago queda confirmado. Verás el resultado y el movimiento en tu historial.</li>
        </ol>
        <p class="mt-4 text-xs text-slate-500">
          Revisa los <Link :href="route('legal.terms')" class="font-semibold text-brand-600 hover:underline">Términos y Condiciones</Link>, la
          <Link :href="route('legal.refunds')" class="font-semibold text-brand-600 hover:underline">Política de cambios y devoluciones</Link> y, si necesitas presentar un reclamo, el
          <Link :href="route('complaints.create')" class="font-semibold text-brand-600 hover:underline">Libro de Reclamaciones</Link>.
        </p>
      </section>
    </div>
  </PublicPageLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import PublicPageLayout from '@/Layouts/PublicPageLayout.vue'
import PaymentsStatusBanner from '@/Components/PaymentsStatusBanner.vue'

defineProps({
  packages: { type: Array, required: true },
  subjects: { type: Array, required: true },
  classRates: { type: Object, required: true },
  creditMinutes: { type: Number, required: true },
  creditPricePen: { type: String, required: true },
  refundDays: { type: Number, required: true },
})

const money = (v) => Number(v).toFixed(2)
const levelLabel = (l) => ({ todos: 'Todos los niveles', primaria: 'Primaria', secundaria: 'Secundaria' }[l] ?? l)
</script>
