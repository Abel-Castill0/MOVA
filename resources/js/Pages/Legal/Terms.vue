<template>
  <Head title="Términos y Condiciones – MOVA" />
  <div class="min-h-screen bg-white">
    <header class="border-b border-gray-100 px-4 sm:px-8 py-3 flex items-center gap-3">
      <Link href="/" class="flex items-center gap-2">
        <div class="w-8 h-8 bg-gradient-to-br from-brand-600 to-brand-800 rounded-lg flex items-center justify-center shadow">
          <span class="text-white font-black text-sm">M</span>
        </div>
        <span class="font-black text-lg text-brand-900">MOVA</span>
      </Link>
      <span class="text-gray-300 mx-1">|</span>
      <span class="text-sm text-gray-500">Términos y Condiciones</span>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-8 py-10">
      <h1 class="text-3xl font-black text-gray-900 mb-2">Términos y Condiciones</h1>
      <p class="text-sm text-gray-400 mb-8">Última actualización: 29 de junio de 2026</p>

      <div class="prose prose-sm max-w-none text-gray-700 space-y-6">

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">1. Descripción del servicio</h2>
          <p>MOVA es una plataforma educativa que conecta familias con profesores particulares para clases personalizadas online. MOVA no imparte clases directamente ni es empleador de los profesores: facilita el contacto entre padres o apoderados y profesores independientes que ofrecen sus servicios de manera autónoma.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">2. Registro y cuentas</h2>
          <p>Para usar MOVA debes registrarte como padre/apoderado o como profesor. Al registrarte, garantizas que la información proporcionada es veraz y que tienes al menos 18 años. Las cuentas que incluyen menores de edad deben ser gestionadas por un padre o apoderado legal, quien acepta estos términos en nombre del menor.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">3. Responsabilidad sobre datos de menores</h2>
          <p>El padre o apoderado que registra a un menor es responsable del uso de esa cuenta y del consentimiento para el tratamiento de los datos del menor. MOVA solo recopila los datos mínimos necesarios para la operación del servicio educativo y no los usa para publicidad ni los comparte sin autorización.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">4. Reseñas verificadas</h2>
          <p>Los padres pueden dejar una reseña y calificación al profesor únicamente después de una clase completada, y solo una reseña por clase. Las reseñas son anónimas: no se muestra el nombre del padre ni del alumno. MOVA puede ocultar reseñas que incumplan las normas de uso, pero no las elimina permanentemente.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">5. Cancelación y reprogramación</h2>
          <p>Tanto el padre como el profesor pueden cancelar o reprogramar una clase con el motivo correspondiente. Las reprogramaciones deben indicar una fecha futura. MOVA registra los eventos de cancelación y reprogramación para fines de trazabilidad, pero no emite penalidades automáticas.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">6. Uso de inteligencia artificial</h2>
          <p>MOVA puede utilizar inteligencia artificial de forma opcional para analizar descripciones de dificultades académicas y generar mejores recomendaciones de profesores. Esta función:</p>
          <ul class="list-disc pl-5 space-y-1 mt-2">
            <li>Solo analiza descripciones académicas anónimas, sin datos personales del alumno o familia.</li>
            <li>Nunca decide de forma autónoma qué profesor recomendar.</li>
            <li>Puede estar desactivada sin afectar el funcionamiento principal de la plataforma.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">7. Notificaciones</h2>
          <p>MOVA envía notificaciones relevantes por correo electrónico y notificaciones dentro de la plataforma. Si has proporcionado un número de teléfono verificado, también podemos enviarte mensajes de WhatsApp, dependiendo de la disponibilidad del servicio. Puedes contactarnos para solicitar la desactivación de estas comunicaciones.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">8. Uso aceptable</h2>
          <p>Queda prohibido usar MOVA para solicitar resolución de tareas, pruebas o exámenes en tiempo real; compartir contenido inapropiado; suplantar identidades; o cualquier uso que viole la legislación peruana vigente.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">9. Modificaciones</h2>
          <p>MOVA puede actualizar estos términos. Los cambios importantes serán notificados por email con anticipación. El uso continuado de la plataforma implica la aceptación de los términos vigentes.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">10. Contacto y soporte</h2>
          <p>Para dudas, reportes o soporte, escríbenos a: <a href="mailto:abelcastillotrabajo@gmail.com" class="text-indigo-600 hover:underline">abelcastillotrabajo@gmail.com</a>. Responderemos en un plazo razonable.</p>
        </section>

      </div>

      <div class="mt-10 pt-6 border-t border-gray-100 flex flex-wrap gap-4 text-sm text-gray-400">
        <Link href="/" class="hover:text-indigo-600 transition-colors">Inicio</Link>
        <Link :href="route('legal.privacy')" class="hover:text-indigo-600 transition-colors">Política de Privacidad</Link>
        <Link :href="route('register')" class="hover:text-indigo-600 transition-colors">Crear cuenta</Link>
      </div>
    </main>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
</script>
