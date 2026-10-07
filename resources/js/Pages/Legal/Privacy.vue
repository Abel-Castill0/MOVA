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
        <p class="text-white/70">Versión vigente: {{ version }}</p>
      </div>
    </section>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 -mt-8 pb-20">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-xl shadow-brand-900/5 p-6 sm:p-10 space-y-8">

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">1. Responsable del tratamiento</h2>
          <p class="text-slate-600 leading-relaxed">MOVA es la plataforma responsable del tratamiento de los datos personales que recopila a través de este sitio.</p>
          <ul class="list-none space-y-1 mt-2 text-slate-600 text-sm">
            <li>Razón social: <strong class="text-slate-800">{{ provider.business_name || 'Pendiente de publicación' }}</strong></li>
            <li>RUC: <strong class="text-slate-800">{{ provider.ruc || 'Pendiente de publicación' }}</strong></li>
            <li>Domicilio: <strong class="text-slate-800">{{ provider.address || 'Pendiente de publicación' }}</strong></li>
            <li>Contacto: <a :href="`mailto:${$page.props.support.email}`" class="text-brand-600 hover:underline font-medium">{{ $page.props.support.email }}</a></li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">2. Datos que recopilamos</h2>
          <ul class="list-disc pl-5 space-y-1.5 mt-2 text-slate-600">
            <li><strong class="text-slate-800">Cuenta:</strong> nombre, correo electrónico, contraseña (guardada cifrada), rol (padre/apoderado o profesor), número de celular (opcional) y foto de perfil (opcional). Si inicias sesión con Google, recibimos de Google tu nombre y tu correo verificado.</li>
            <li><strong class="text-slate-800">Perfil de profesor:</strong> biografía, materias que enseña, cupos de mentoría, código de profesor y, si decide registrarlos, sus números de Yape o Plin.</li>
            <li><strong class="text-slate-800">Datos del alumno (menor de edad):</strong> nombre, apellidos, nivel educativo y, si el padre o apoderado los ingresa, fecha de nacimiento y centro educativo.</li>
            <li><strong class="text-slate-800">Solicitudes y clases:</strong> materia, descripción de la necesidad académica, horarios preferidos, diagnósticos académicos, clases agendadas, cancelaciones y reprogramaciones, reportes de aprendizaje y reseñas.</li>
            <li><strong class="text-slate-800">Evidencia de aceptación y consentimiento:</strong> versión aceptada de estos documentos y de la autorización para cada alumno, con fecha, dirección IP y navegador.</li>
            <li><strong class="text-slate-800">Créditos del profesor:</strong> ver sección 8.</li>
            <li><strong class="text-slate-800">Libro de Reclamaciones:</strong> nombre, documento de identidad, domicilio, teléfono, correo y el detalle del reclamo o queja.</li>
            <li><strong class="text-slate-800">Datos técnicos:</strong> dirección IP, navegador y registros técnicos de errores y seguridad.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">3. Para qué usamos tus datos</h2>
          <ul class="list-disc pl-5 space-y-1.5 text-slate-600">
            <li>Gestionar tu cuenta y conectar familias con profesores según la materia y el nivel del alumno.</li>
            <li>Agendar y realizar las clases por videollamada, y registrar reportes y reseñas.</li>
            <li>Administrar los créditos que los profesores usan para aceptar solicitudes.</li>
            <li>Enviarte avisos por correo y dentro de la plataforma, y por WhatsApp solo si lo autorizas y el servicio está habilitado.</li>
            <li>Atender reclamos, solicitudes de derechos y soporte, y proteger la seguridad de la plataforma.</li>
            <li>Cumplir obligaciones legales.</li>
          </ul>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">4. Datos de menores de edad</h2>
          <p class="text-slate-600 leading-relaxed">El alumno nunca crea ni administra una cuenta: sus datos los ingresa y gestiona el padre o apoderado registrado. Al registrar a cada alumno, el padre o apoderado marca una autorización específica para ese alumno, y MOVA guarda la fecha, la versión de esta política y los datos técnicos de ese acto. MOVA no usa los datos de menores para publicidad.</p>
          <p class="text-slate-600 leading-relaxed mt-2"><strong class="text-slate-800">Qué ve un profesor antes de tener una clase agendada:</strong> los profesores verificados que pueden atender la solicitud (los de esa materia o, si se usó su código, solo ese profesor) ven el nombre de pila del alumno, su nivel educativo, la materia, la descripción de la necesidad académica y los horarios preferidos.</p>
          <p class="text-slate-600 leading-relaxed mt-2"><strong class="text-slate-800">Cuando la clase ya está agendada,</strong> el profesor asignado ve además el apellido del alumno. MOVA no comparte con ningún profesor la fecha de nacimiento, el centro educativo ni el correo o teléfono del padre o apoderado.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">5. Inteligencia artificial</h2>
          <p class="text-slate-600 leading-relaxed">Actualmente MOVA <strong class="text-slate-800">no envía datos a servicios de inteligencia artificial</strong>. El asistente Movi responde con textos preparados por MOVA que se procesan en nuestros propios servidores: no es inteligencia artificial generativa, no envía lo que escribes a terceros y no guardamos las conversaciones (evita escribir datos personales en el chat). El enriquecimiento automático del diagnóstico académico está desactivado. Antes de activar cualquier función que use un proveedor de inteligencia artificial publicaremos una nueva versión de esta política que indique el proveedor y los datos que se envían, y te pediremos aceptarla.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">6. Reseñas</h2>
          <p class="text-slate-600 leading-relaxed">Las reseñas que los padres dejan sobre los profesores se muestran sin el nombre del padre ni del alumno. Quedan vinculadas internamente al padre y a la clase para fines de verificación y moderación.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">7. Proveedores y transferencia internacional</h2>
          <p class="text-slate-600 leading-relaxed">No vendemos tus datos personales. Los compartimos solo en la medida necesaria con estos proveedores, cada uno únicamente cuando la función correspondiente está activa:</p>
          <ul class="list-disc pl-5 space-y-1.5 mt-2 text-slate-600">
            <li><strong class="text-slate-800">Microsoft Azure</strong> — alojamiento de la aplicación y de la base de datos (región México).</li>
            <li><strong class="text-slate-800">Google</strong> — envío de correos (Gmail) e inicio de sesión con Google, si lo eliges.</li>
            <li><strong class="text-slate-800">8x8 (JaaS)</strong> — videollamadas de clase. El acceso a cada sala usa un token temporal emitido por MOVA solo para el profesor y la familia de esa clase.</li>
            <li><strong class="text-slate-800">Cloudinary</strong> — almacenamiento de fotos de perfil.</li>
            <li><strong class="text-slate-800">Mercado Pago</strong> — pago en línea de créditos de profesores (ver sección 8).</li>
            <li><strong class="text-slate-800">Meta (WhatsApp Business)</strong> — códigos de verificación y avisos por WhatsApp, cuando ese servicio esté habilitado. Recibe el número y el contenido del mensaje.</li>
            <li><strong class="text-slate-800">Pusher</strong> — avisos en tiempo real dentro de la plataforma.</li>
            <li><strong class="text-slate-800">Sentry</strong> — registro de errores técnicos, configurado para no adjuntar datos de identificación del usuario (como su IP o su sesión).</li>
            <li>Autoridades, cuando la ley peruana o una resolución judicial lo exija.</li>
          </ul>
          <p class="text-slate-600 leading-relaxed mt-3">Estos proveedores y los servidores de MOVA se encuentran fuera del Perú, por lo que el uso de MOVA implica una transferencia internacional de tus datos para las finalidades descritas.</p>
          <p class="text-sm text-slate-500 mt-3 leading-relaxed">MOVA no usa Meta Pixel, Google Analytics ni otras herramientas de analítica o publicidad de terceros. Si alguna vez se integra una, esta política se actualizará antes de activarla.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">8. Pagos</h2>
          <p class="text-slate-600 leading-relaxed"><strong class="text-slate-800">Pago de las clases:</strong> se coordina y realiza directamente entre el padre y el profesor por medios externos (Yape, Plin u otros). MOVA no interviene en ese pago. Si el profesor registró su número de Yape o Plin, MOVA lo muestra a las familias que tienen clases con él.</p>
          <p class="text-slate-600 leading-relaxed mt-2"><strong class="text-slate-800">Créditos del profesor:</strong> cuando un profesor recarga créditos, MOVA registra el paquete, el monto, el estado y las fechas de la operación. En una recarga manual guarda además el medio de pago y el número de operación que el profesor informa. En un pago en línea con Mercado Pago, MOVA envía a Mercado Pago el correo del profesor, un identificador del medio de pago generado por Mercado Pago y, si se paga con tarjeta, el documento de identidad del titular; y guarda los identificadores y el estado de la operación. MOVA no recibe ni guarda el número completo de la tarjeta, su código de seguridad ni el código de aprobación de Yape.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">9. Seguridad</h2>
          <p class="text-slate-600 leading-relaxed">MOVA usa HTTPS, contraseñas cifradas con bcrypt, sesiones seguras, protección CSRF, controles de acceso por rol y verificación en dos pasos obligatoria para el equipo administrador.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">10. Tus derechos (ARCO)</h2>
          <p class="text-slate-600 leading-relaxed">Conforme a la Ley N° 29733, puedes ejercer tus derechos de acceso, rectificación, cancelación y oposición sobre tus datos y los de tus hijos escribiéndonos a <a :href="`mailto:${$page.props.support.email}`" class="text-brand-600 hover:underline font-medium">{{ $page.props.support.email }}</a>. Responderemos en un plazo máximo de 15 días hábiles.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">11. Conservación y eliminación</h2>
          <p class="text-slate-600 leading-relaxed">Conservamos tus datos mientras tu cuenta esté activa. Puedes eliminar tu cuenta desde tu perfil. Si la cuenta tiene historial de clases o de créditos, en lugar de borrarse se anonimiza: se eliminan tu nombre, correo, teléfono y foto, y se conservan solo los registros necesarios para la integridad de ese historial. Las hojas del Libro de Reclamaciones se conservan aunque elimines tu cuenta. Los registros de pagos y créditos (sin tus datos de contacto una vez anonimizada la cuenta) se conservan hasta 5 años por obligaciones contables y tributarias, y las hojas del Libro de Reclamaciones, al menos 2 años; vencidos esos plazos se eliminan. Si tu cuenta permanece sin actividad por más de 2 años, podemos anonimizarla.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">12. Cookies y almacenamiento local</h2>
          <p class="text-slate-600 leading-relaxed">MOVA solo usa lo necesario para funcionar: una cookie de sesión y una cookie de seguridad contra falsificación de solicitudes (CSRF), y almacenamiento local del navegador para recordar que cerraste el aviso de cookies y tus preferencias de interfaz. No usamos cookies de publicidad ni de analítica. Cuando usas la videollamada (8x8) o el pago en línea (Mercado Pago), esos servicios pueden usar sus propias cookies o almacenamiento para funcionar y prevenir fraude.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">13. Cambios en esta política</h2>
          <p class="text-slate-600 leading-relaxed">La versión vigente se indica al inicio de esta página. Si la cambiamos de forma relevante, te pediremos aceptar la nueva versión la próxima vez que ingreses a tu cuenta; las versiones que aceptaste antes quedan registradas.</p>
        </section>

        <section>
          <h2 class="text-lg font-black text-slate-900 mb-2">14. Contacto</h2>
          <p class="text-slate-600 leading-relaxed">Para consultas sobre privacidad o para ejercer tus derechos, escríbenos a: <a :href="`mailto:${$page.props.support.email}`" class="text-brand-600 hover:underline font-medium">{{ $page.props.support.email }}</a></p>
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

defineProps({
  version:  { type: String, default: '' },
  provider: { type: Object, default: () => ({}) },
})
</script>
