<template>
  <Head title="Política de cambios, cancelaciones y devoluciones – MOVA" />
  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <section class="relative overflow-hidden bg-gradient-to-br from-brand-900 to-brand-700 pb-14 pt-28">
      <div class="relative mx-auto max-w-3xl px-4 text-white sm:px-6">
        <span class="mb-4 inline-block rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest">Legal</span>
        <h1 class="mb-3 text-4xl font-black sm:text-5xl">Cambios, cancelaciones y devoluciones</h1>
        <p class="text-white/70">Resume lo establecido en los Términos y Condiciones (versión {{ version }}), secciones 5 y 7.</p>
      </div>
    </section>

    <main class="mx-auto -mt-8 max-w-3xl px-4 pb-20 sm:px-6">
      <div class="space-y-8 rounded-2xl border border-gray-100 bg-white p-6 shadow-xl shadow-brand-900/5 sm:p-10">
        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">1. Qué compras en MOVA</h2>
          <p class="leading-relaxed text-slate-600">
            MOVA vende <strong class="text-slate-800">créditos de plataforma</strong> a los profesores. Un crédito equivale a {{ creditMinutes }} minutos de clase y tiene un precio base de
            S/ {{ Number(creditPricePen).toFixed(2) }}, con descuentos por volumen según el paquete. El importe pagado consta en cada compra. Los créditos se usan para aceptar clases dentro de MOVA y no se canjean por dinero. <strong class="text-slate-800">La clase en sí la paga
            la familia directamente al profesor</strong>: MOVA no cobra ni intermedia ese pago.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">2. Cuándo se acreditan los créditos</h2>
          <p class="leading-relaxed text-slate-600">
            Los créditos se acreditan <strong class="text-slate-800">una sola vez y solo cuando el pago queda confirmado</strong> por el proveedor de pagos. Si el pago está pendiente (por ejemplo,
            una orden de pago aún sin abonar), es rechazado o expira, no se acredita ningún crédito y no se cobra nada. Puedes ver el estado de cada compra en tu panel de créditos.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">3. Devolución de créditos comprados</h2>
          <ul class="list-disc space-y-2 pl-5 leading-relaxed text-slate-600">
            <li>Puedes pedir el <strong class="text-slate-800">reembolso íntegro</strong> de los créditos comprados que <strong class="text-slate-800">no hayas usado ni reservado</strong>, dentro de los
              <strong class="text-slate-800">{{ refundDays }} días</strong> siguientes a la compra.</li>
            <li>La solicitud se hace escribiendo al correo de soporte indicado al final de esta página, indicando el correo de tu cuenta y la fecha de la compra.</li>
            <li>El reembolso se hace por el mismo medio de pago; el plazo en que lo veas en tu estado de cuenta depende del proveedor de pagos o de tu banco.</li>
            <li>Los créditos ya <strong class="text-slate-800">consumidos</strong> (clases dictadas) no se reembolsan. Pasados los {{ refundDays }} días, los créditos no son reembolsables, salvo error atribuible a MOVA
              (por ejemplo, un cobro duplicado o un cobro sin acreditación).</li>
            <li>Cuando un reembolso se confirma, los créditos correspondientes se descuentan de tu saldo y queda el registro en tu historial.</li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">4. Cancelaciones y reprogramaciones de clases</h2>
          <ul class="list-disc space-y-2 pl-5 leading-relaxed text-slate-600">
            <li>Tanto la familia como el profesor pueden cancelar o reprogramar una clase indicando el motivo; las reprogramaciones deben indicar una fecha futura.</li>
            <li>Si se cancela una clase agendada, los créditos que el profesor tenía reservados para ella se le devuelven.</li>
            <li>Si el profesor no se presenta, la familia lo reporta, el equipo de MOVA cancela la clase, los créditos reservados se liberan al profesor y la familia no paga nada.</li>
            <li>Las condiciones económicas de una clase (tarifa, cambios de horario con el profesor, devolución de lo pagado al profesor) se acuerdan entre la familia y el profesor, porque
              el pago de la clase no pasa por MOVA. Ante una disputa, el equipo de MOVA la revisa con el reporte de la clase y la evidencia de presencia.</li>
          </ul>
        </section>

        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">5. Reclamos y atención de incidencias</h2>
          <p class="leading-relaxed text-slate-600">
            Si tienes una queja o un reclamo sobre el servicio, puedes registrarlo en nuestro <Link :href="route('complaints.create')" class="font-semibold text-brand-600 hover:underline">Libro de Reclamaciones</Link>:
            recibirás un código de seguimiento y una respuesta en un plazo máximo de {{ complaintResponseDays }} días hábiles. Para consultas sobre una compra o un reembolso también puedes escribirnos a
            <a :href="`mailto:${$page.props.support.email}`" class="font-semibold text-brand-600 hover:underline">{{ $page.props.support.email }}</a>.
          </p>
        </section>

        <section>
          <h2 class="mb-2 text-lg font-black text-slate-900">6. Contacto</h2>
          <p class="leading-relaxed text-slate-600">
            <template v-if="$page.props.merchant.business_name">{{ $page.props.merchant.business_name }}<template v-if="$page.props.merchant.ruc"> · RUC {{ $page.props.merchant.ruc }}</template><template v-if="$page.props.merchant.address"> · {{ $page.props.merchant.address }}</template>. </template>
            Correo: <a :href="`mailto:${$page.props.support.email}`" class="font-semibold text-brand-600 hover:underline">{{ $page.props.support.email }}</a><template v-if="$page.props.support.phone"> · Teléfono: {{ $page.props.support.phone }}</template>.
          </p>
          <p class="mt-4 text-sm text-slate-500">
            Documentos relacionados:
            <Link :href="route('legal.terms')" class="font-semibold text-brand-600 hover:underline">Términos y Condiciones</Link> ·
            <Link :href="route('legal.privacy')" class="font-semibold text-brand-600 hover:underline">Política de Privacidad</Link> ·
            <Link :href="route('catalog.index')" class="font-semibold text-brand-600 hover:underline">Servicios y precios</Link>.
          </p>
        </section>
      </div>
    </main>

    <LandingFooter />
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'

defineProps({
  version: { type: String, required: true },
  refundDays: { type: Number, required: true },
  creditMinutes: { type: Number, required: true },
  creditPricePen: { type: String, required: true },
  complaintResponseDays: { type: Number, required: true },
})
</script>
