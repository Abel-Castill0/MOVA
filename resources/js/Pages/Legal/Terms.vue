<template>
  <Head title="Términos y Condiciones – MOVA" />
  <div class="min-h-screen bg-white">
    <!-- Nav minimal -->
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
      <div class="mb-2 inline-block bg-yellow-50 border border-yellow-200 text-yellow-800 text-xs font-semibold px-3 py-1 rounded-full">
        Versión beta · Junio 2026
      </div>
      <h1 class="text-3xl font-black text-gray-900 mb-2">Términos y Condiciones</h1>
      <p class="text-sm text-gray-400 mb-8">Última actualización: 28 de junio de 2026</p>

      <div class="prose prose-sm max-w-none text-gray-700 space-y-6">

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">1. Descripción del servicio</h2>
          <p>MOVA es una plataforma educativa en fase beta que conecta familias con profesores particulares para clases personalizadas. MOVA no imparte clases directamente, sino que facilita el contacto entre padres o apoderados y profesores independientes.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">2. Registro y cuentas</h2>
          <p>Para usar MOVA debes registrarte como padre/apoderado o como profesor. Al registrarte, garantizas que la información proporcionada es veraz y que tienes al menos 18 años. Las cuentas de menores de edad deben ser gestionadas por un padre o apoderado legal.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">3. Responsabilidad sobre datos de menores</h2>
          <p>El padre o apoderado que registra a un menor en la plataforma es responsable del uso de esa cuenta y del consentimiento para el tratamiento de los datos del menor. MOVA solo recopila los datos mínimos necesarios para la operación del servicio educativo.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">4. Uso de inteligencia artificial</h2>
          <p>MOVA puede utilizar inteligencia artificial de forma opcional para analizar la descripción de dificultades académicas de un estudiante y generar recomendaciones de profesores más precisas. Esta función:</p>
          <ul class="list-disc pl-5 space-y-1 mt-2">
            <li>Solo analiza descripciones académicas anónimas, no datos personales.</li>
            <li>Nunca elige ni decide de forma definitiva qué profesor recomienda.</li>
            <li>Puede ser desactivada sin afectar el funcionamiento principal.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">5. Uso aceptable</h2>
          <p>Queda prohibido usar MOVA para solicitar resolución de tareas, pruebas o exámenes en tiempo real; compartir contenido inapropiado; suplantar identidades; o cualquier uso que viole la legislación peruana.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">6. Versión beta</h2>
          <p>MOVA se encuentra en fase de prueba (beta). El servicio puede tener interrupciones, cambios o mejoras sin previo aviso. Agradecemos la comprensión de los usuarios durante esta etapa.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">7. Modificaciones</h2>
          <p>MOVA puede actualizar estos términos. Los cambios importantes serán notificados por email. El uso continuado de la plataforma implica la aceptación de los nuevos términos.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">8. Contacto</h2>
          <p>Para dudas, reportes o soporte durante la beta, escríbenos a: <a href="mailto:abelcastillotrabajo@gmail.com" class="text-indigo-600 hover:underline">abelcastillotrabajo@gmail.com</a></p>
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
