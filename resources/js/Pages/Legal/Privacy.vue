<template>
  <Head title="Política de Privacidad – MOVA" />
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
      <span class="text-sm text-gray-500">Política de Privacidad</span>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-8 py-10">
      <div class="mb-2 inline-block bg-yellow-50 border border-yellow-200 text-yellow-800 text-xs font-semibold px-3 py-1 rounded-full">
        Versión beta · Junio 2026
      </div>
      <h1 class="text-3xl font-black text-gray-900 mb-2">Política de Privacidad</h1>
      <p class="text-sm text-gray-400 mb-8">Última actualización: 28 de junio de 2026</p>

      <div class="prose prose-sm max-w-none text-gray-700 space-y-6">

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">1. Datos que recopilamos</h2>
          <p>Para operar el servicio, MOVA recopila:</p>
          <ul class="list-disc pl-5 space-y-1 mt-2">
            <li><strong>Datos de registro:</strong> nombre, email, número de teléfono opcional, rol (padre o profesor).</li>
            <li><strong>Datos del estudiante:</strong> nombre, grado o nivel, materias de interés. Solo accede el padre/apoderado y el profesor aceptado.</li>
            <li><strong>Datos de uso:</strong> clases solicitadas, diagnósticos académicos, reportes de clase.</li>
            <li><strong>Datos técnicos:</strong> dirección IP, tipo de dispositivo, logs de errores (sin contenido personal).</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">2. Para qué usamos tus datos</h2>
          <ul class="list-disc pl-5 space-y-1">
            <li>Conectar familias con profesores compatibles.</li>
            <li>Enviar notificaciones de clases por email y WhatsApp (si proporcionaste número).</li>
            <li>Mejorar la plataforma durante la beta.</li>
            <li>Cumplir obligaciones legales.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">3. Datos de menores de edad</h2>
          <p>Los datos de estudiantes menores de edad son ingresados y gestionados por el padre o apoderado registrado. MOVA no recopila datos de menores directamente ni los usa para publicidad. El padre es responsable de la exactitud y autorización de dichos datos.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">4. Uso de inteligencia artificial</h2>
          <p>Cuando está activada, la función de IA analiza descripciones académicas para mejorar las recomendaciones. Solo se envían datos anónimos al servicio de IA (descripción de dificultad, materia, nivel). Nunca se envía nombre, email, teléfono ni identificadores del alumno o familia.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">5. Compartición de datos</h2>
          <p>No vendemos ni compartimos tus datos personales con terceros, salvo:</p>
          <ul class="list-disc pl-5 space-y-1 mt-2">
            <li>Proveedores necesarios para operar: Gmail (envío de correos), Twilio (WhatsApp), Zoom (videollamadas), Railway (hosting), Google Gemini / OpenAI (IA opcional).</li>
            <li>Cuando la ley lo exija.</li>
          </ul>
          <p class="mt-2">El profesor solo ve el nombre del estudiante y la descripción del problema académico <strong>después de aceptar una solicitud</strong>.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">6. Seguridad</h2>
          <p>MOVA usa HTTPS en todas las comunicaciones, contraseñas encriptadas (bcrypt), sesiones seguras y cookies con protección CSRF. No almacenamos datos de tarjetas de crédito.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">7. Tus derechos</h2>
          <p>Puedes solicitar acceso, corrección o eliminación de tus datos escribiéndonos a: <a href="mailto:abelcastillotrabajo@gmail.com" class="text-indigo-600 hover:underline">abelcastillotrabajo@gmail.com</a>. Responderemos en un plazo razonable.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">8. Retención de datos</h2>
          <p>Conservamos tus datos mientras tu cuenta esté activa o lo requiera la operación del servicio. Puedes eliminar tu cuenta contactándonos.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">9. Cookies</h2>
          <p>MOVA usa solo cookies de sesión necesarias para el funcionamiento del sistema. No usamos cookies de rastreo publicitario.</p>
        </section>

        <section>
          <h2 class="text-lg font-bold text-gray-900 mb-2">10. Contacto</h2>
          <p>Para cualquier consulta sobre privacidad o para ejercer tus derechos, escríbenos a: <a href="mailto:abelcastillotrabajo@gmail.com" class="text-indigo-600 hover:underline">abelcastillotrabajo@gmail.com</a></p>
        </section>

      </div>

      <div class="mt-10 pt-6 border-t border-gray-100 flex flex-wrap gap-4 text-sm text-gray-400">
        <Link href="/" class="hover:text-indigo-600 transition-colors">Inicio</Link>
        <Link :href="route('legal.terms')" class="hover:text-indigo-600 transition-colors">Términos y Condiciones</Link>
        <Link :href="route('register')" class="hover:text-indigo-600 transition-colors">Crear cuenta</Link>
      </div>
    </main>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
</script>
