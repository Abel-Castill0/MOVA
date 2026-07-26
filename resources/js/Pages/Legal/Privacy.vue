<template>
  <Head title="Política de Privacidad – MOVA" />
  <div class="min-h-screen bg-white">
    <LandingNavbar />

    <!-- Header -->
    <section class="pt-28 pb-14 bg-gradient-to-br from-brand-900 to-brand-700 relative overflow-hidden">
      <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(rgba(255,255,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.1) 1px,transparent 1px);background-size:60px 60px"></div>
      <div class="relative max-w-3xl mx-auto px-4 sm:px-6 text-white">
        <span class="inline-block px-3 py-1 bg-white/10 border border-white/20 rounded-full text-xs font-semibold uppercase tracking-widest mb-4">Legal</span>
        <h1 class="text-4xl sm:text-5xl font-black mb-3">Política de Privacidad</h1>
        <p class="text-white/70">Última actualización: 26 de julio de 2026</p>
      </div>
    </section>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 -mt-8 pb-20">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-xl shadow-brand-900/5 p-6 sm:p-10 space-y-8">

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">1. Datos que recopilamos</h2>
          <p class="text-slate-600 leading-relaxed">Para operar el servicio, MOVA recopila:</p>
          <ul class="list-disc pl-5 space-y-1.5 mt-2 text-slate-600">
            <li><strong class="text-slate-800">Datos de registro:</strong> nombre, email, número de teléfono (opcional), rol (padre o profesor).</li>
            <li><strong class="text-slate-800">Datos del estudiante:</strong> nombre, grado o nivel, materias de interés. Solo accede el padre/apoderado y el profesor aceptado.</li>
            <li><strong class="text-slate-800">Datos de uso:</strong> clases solicitadas, diagnósticos académicos, reportes de clase, reseñas.</li>
            <li><strong class="text-slate-800">Datos técnicos:</strong> dirección IP, tipo de dispositivo, logs de errores (sin contenido personal).</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">2. Para qué usamos tus datos</h2>
          <ul class="list-disc pl-5 space-y-1.5 text-slate-600">
            <li>Conectar familias con profesores compatibles según el perfil académico del estudiante.</li>
            <li>Enviar notificaciones de clases por email y notificaciones dentro de la plataforma.</li>
            <li>Enviar mensajes de WhatsApp si proporcionaste un número verificado y el servicio está disponible.</li>
            <li>Mejorar la plataforma con datos agregados y anonimizados.</li>
            <li>Cumplir obligaciones legales.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">3. Datos de menores de edad</h2>
          <p class="text-slate-600 leading-relaxed">Los datos de estudiantes menores de edad son ingresados y gestionados por el padre o apoderado registrado, quien otorga el consentimiento para su tratamiento conforme a la Ley N° 29733, Ley de Protección de Datos Personales, y su reglamento. MOVA no recopila datos de menores directamente ni los usa para publicidad. El padre es responsable de la exactitud y autorización de dichos datos. El profesor solo accede al nombre del estudiante y a la descripción del problema académico <strong class="text-slate-800">después de aceptar una solicitud de clase</strong>.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">4. Uso de inteligencia artificial</h2>
          <p class="text-slate-600 leading-relaxed">Cuando está activada, la función de IA analiza descripciones académicas para mejorar las recomendaciones de profesores. Solo se envían datos anónimos al servicio de IA (descripción de dificultad académica, materia, nivel educativo). Nunca se envía nombre, email, teléfono ni identificadores del alumno o familia. La IA nunca decide de forma autónoma qué profesor se recomienda — eso lo hace un algoritmo determinista. Los registros de uso de IA no contienen textos de diagnóstico ni datos personales.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">5. Reseñas verificadas</h2>
          <p class="text-slate-600 leading-relaxed">Las reseñas que los padres dejan sobre los profesores son anónimas: no se muestra el nombre del padre ni del alumno en el perfil público del profesor. Las reseñas quedan vinculadas internamente al padre y la clase para fines de verificación y moderación.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">6. Compartición de datos con terceros</h2>
          <p class="text-slate-600 leading-relaxed">No vendemos tus datos personales. Los compartimos únicamente, y en la medida necesaria, con:</p>
          <ul class="list-disc pl-5 space-y-1.5 mt-2 text-slate-600">
            <li><strong class="text-slate-800">Gmail</strong> — envío de correos transaccionales.</li>
            <li><strong class="text-slate-800">Twilio</strong> — envío de códigos de verificación y notificaciones por WhatsApp, si el servicio está disponible.</li>
            <li><strong class="text-slate-800">Jitsi Meet</strong> — videollamadas de clase.</li>
            <li><strong class="text-slate-800">Railway</strong> — alojamiento (hosting) de la aplicación.</li>
            <li><strong class="text-slate-800">Google Gemini / OpenAI</strong> — enriquecimiento opcional del diagnóstico pedagógico (ver sección 4).</li>
            <li>Cuando la ley peruana o una resolución judicial lo exija.</li>
          </ul>
          <p class="text-sm text-slate-400 mt-3 leading-relaxed">MOVA no utiliza actualmente Meta Pixel, Google Analytics ni ninguna otra herramienta de analítica publicitaria o de rastreo de terceros. Si en el futuro se integra alguna, esta sección se actualizará antes de activarla.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">7. Seguridad</h2>
          <p class="text-slate-600 leading-relaxed">MOVA usa HTTPS en todas las comunicaciones, contraseñas encriptadas con bcrypt, sesiones seguras y protección CSRF. No almacenamos datos de tarjetas de crédito — los pagos entre padre y profesor se coordinan fuera de la plataforma (Yape/Plin).</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">8. Tus derechos</h2>
          <p class="text-slate-600 leading-relaxed">Conforme a la Ley N° 29733, puedes ejercer tus derechos de acceso, rectificación, cancelación y oposición (derechos ARCO) sobre tus datos personales y los de tus hijos, escribiéndonos a <a href="mailto:abelcastillotrabajo@gmail.com" class="text-brand-600 hover:underline font-medium">abelcastillotrabajo@gmail.com</a>. Responderemos en un plazo máximo de 15 días hábiles.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">9. Retención de datos</h2>
          <p class="text-slate-600 leading-relaxed">Conservamos tus datos mientras tu cuenta esté activa o sea necesario para la operación del servicio. Puedes solicitar la eliminación de tu cuenta contactándonos directamente.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">10. Cookies</h2>
          <p class="text-slate-600 leading-relaxed">MOVA usa solo cookies de sesión necesarias para el funcionamiento del sistema. No usamos cookies de rastreo ni publicidad.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">11. Contacto</h2>
          <p class="text-slate-600 leading-relaxed">Para consultas sobre privacidad o para ejercer tus derechos, escríbenos a: <a href="mailto:abelcastillotrabajo@gmail.com" class="text-brand-600 hover:underline font-medium">abelcastillotrabajo@gmail.com</a></p>
        </section>

      </div>

      <div class="mt-8 pt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-400">
        <Link href="/" class="hover:text-brand-600 transition-colors">Inicio</Link>
        <Link :href="route('legal.terms')" class="hover:text-brand-600 transition-colors">Términos y Condiciones</Link>
        <Link :href="route('register')" class="hover:text-brand-600 transition-colors">Crear cuenta</Link>
      </div>
    </main>

    <LandingFooter />
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'
import LandingNavbar from '@/Components/LandingNavbar.vue'
import LandingFooter from '@/Components/LandingFooter.vue'
</script>
