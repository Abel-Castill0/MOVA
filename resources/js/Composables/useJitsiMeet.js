import { onBeforeUnmount, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const JAAS_DOMAIN = '8x8.vc'
const SCRIPT_LOAD_TIMEOUT_MS = 20000

const LOAD_ERROR_MESSAGE = 'No pudimos cargar la videollamada. Revisa tu conexión a Internet (o si tu red bloquea 8x8.vc) y vuelve a intentarlo.'

// Errores de cámara/micrófono que reporta JaaS (`cameraError` / `micError`).
// No bloquean la clase —la persona puede seguir entrando sin cámara o sin
// micrófono—, así que se muestran como aviso con la acción concreta, no como error.
function describeMediaError(device, event) {
  const label = device === 'camera' ? 'la cámara' : 'el micrófono'
  const type = String(event?.type || event?.error?.name || '').toLowerCase()

  if (type.includes('permission') || type.includes('denied') || type.includes('notallowed')) {
    return `No tenemos permiso para usar ${label}. Permite el acceso desde el icono del candado junto a la dirección del navegador y recarga la sala.`
  }
  if (type.includes('not_found') || type.includes('notfound') || type.includes('devicesnotfound')) {
    return `No encontramos ${label} en tu dispositivo. Conéctalo y vuelve a entrar, o continúa sin él.`
  }
  if (type.includes('in_use') || type.includes('inuse') || type.includes('notreadable') || type.includes('track')) {
    const used = device === 'camera' ? 'La cámara está siendo usada' : 'El micrófono está siendo usado'
    return `${used} por otra aplicación. Ciérrala y vuelve a entrar.`
  }

  return `No pudimos activar ${label}. Revisa los permisos del navegador y vuelve a entrar.`
}

let scriptPromise = null

// external_api.js de JaaS se sirve bajo el path del propio App ID (a
// diferencia del meet.jit.si público, que lo sirve en la raíz) — no el
// meet.jit.si, cuyo embed se corta a los 5 minutos en producción
// ("Embedding meet.jit.si is only meant for demo purposes").
function loadJitsiScript(appId) {
  if (window.JitsiMeetExternalAPI) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `https://${JAAS_DOMAIN}/${appId}/external_api.js`
    script.async = true
    // Sin límite, una red que nunca responde dejaba el modal en "Conectando…" para siempre.
    const timer = setTimeout(() => {
      scriptPromise = null
      script.remove()
      reject(new Error('Tiempo de espera agotado cargando Jitsi.'))
    }, SCRIPT_LOAD_TIMEOUT_MS)
    script.onload = () => { clearTimeout(timer); resolve() }
    script.onerror = () => { clearTimeout(timer); scriptPromise = null; script.remove(); reject(new Error('No se pudo cargar Jitsi.')) }
    document.head.appendChild(script)
  })

  return scriptPromise
}

export function useJitsiMeet() {
  const showingJitsiModal = ref(false)
  const joinError = ref('')
  const activeLesson = ref(null)
  // Distingue "la modal está abierta" de "el usuario ya puede ver el
  // contenido" — antes no había ningún estado entre ambas cosas: la modal
  // se abría a pantalla dividida en negro (solo el header) hasta que
  // JitsiMeetExternalAPI terminaba de montar, sin ningún indicio de que
  // algo estaba pasando. No es una señal de "la llamada ya conectó de
  // verdad" (JaaS puede tardar más en renderizar video real) — es
  // deliberadamente honesto: solo cubre el trabajo propio de MOVA (pedir
  // credenciales + cargar el script + construir el iframe), no lo que pase
  // dentro de JaaS después de eso.
  const connecting = ref(false)
  // Aviso NO bloqueante (cámara/micrófono). Distinto de joinError, que sí
  // impide la clase: aquí la llamada sigue activa.
  const mediaNotice = ref('')
  let api = null

  // usePage() debe llamarse en el momento síncrono de setup() — se guarda la
  // referencia reactiva aquí y se lee auth.user.name más tarde, dentro de
  // openJitsi() (async), en vez de volver a llamar usePage() ahí.
  const page = usePage()

  // Si la página se desmonta con la sala abierta (navegación de Inertia, botón
  // atrás), la instancia del SDK no debe quedar huérfana consumiendo
  // cámara/micrófono/red, ni el body bloqueado sin scroll.
  onBeforeUnmount(() => {
    if (api) {
      api.dispose()
      api = null
    }
    document.body.style.overflow = ''
  })

  // El modal de Jitsi es a pantalla completa — igual que el Modal genérico,
  // bloqueamos el scroll del body mientras está abierto.
  watch(showingJitsiModal, (show) => {
    document.body.style.overflow = show ? 'hidden' : ''
  })

  // lesson.jitsi_room nunca viaja en el listado de clases (Lesson::$hidden)
  // — se pide aquí, justo antes de entrar, contra GET /lessons/{id}/join,
  // que revalida dueño/profesor asignado y estado de la clase en el
  // servidor, y devuelve también el JWT de JaaS firmado por el backend (la
  // private key nunca llega al navegador). Si alguien adivina o comparte la
  // URL de la sala sin pasar por esta verificación, no obtiene ni el nombre
  // de sala ni un token válido para entrar.
  async function openJitsi(lesson, containerId = 'jitsi-container') {
    // Guarda de reentrancia (mismo patrón que `cancelling`/`rescheduling`/
    // `payingId` en TeacherIndex.vue/ParentIndex.vue — "F-12"). Ni
    // TeacherIndex.vue ni ParentIndex.vue deshabilitan el botón "Unirse"
    // mientras la petición está en vuelo, así que sin esta guarda un doble
    // click reentra aquí antes del primer `await`: la segunda llamada pisaría
    // la variable `api` con una nueva instancia de JitsiMeetExternalAPI sin
    // haber hecho dispose() de la primera, dejando una llamada huérfana
    // consumiendo cámara/micrófono/red. El chequeo va antes que cualquier
    // `await`, así que corre síncrono y gana la primera invocación —dos
    // clicks reales llegan como dos eventos separados, no dentro del mismo
    // stack síncrono.
    if (showingJitsiModal.value) return

    joinError.value = ''
    mediaNotice.value = ''
    activeLesson.value = lesson
    showingJitsiModal.value = true
    connecting.value = true

    let credentials
    try {
      const response = await window.axios.get(route('lessons.join', lesson.id))
      credentials = response.data
    } catch (error) {
      // El modal se queda abierto mostrando el error (ver joinError en las
      // vistas) — cerrarlo aquí lo haría desaparecer antes de que el usuario
      // pueda leer por qué no pudo entrar.
      joinError.value = error.response?.data?.message || 'No se pudo verificar el acceso a esta clase.'
      connecting.value = false
      return
    }

    try {
      await loadJitsiScript(credentials.jaas_app_id)
    } catch {
      joinError.value = LOAD_ERROR_MESSAGE
      connecting.value = false
      return
    }

    const container = document.getElementById(containerId)
    if (!container || !window.JitsiMeetExternalAPI) {
      // Antes: salía en silencio y el modal quedaba vacío sin explicación.
      joinError.value = LOAD_ERROR_MESSAGE
      connecting.value = false
      return
    }

    api = new window.JitsiMeetExternalAPI(JAAS_DOMAIN, {
      // JaaS exige el room name con el prefijo del tenant (App ID).
      roomName: `${credentials.jaas_app_id}/${credentials.jitsi_room}`,
      jwt: credentials.jitsi_token,
      width: '100%',
      height: '100%',
      parentNode: container,
      userInfo: { displayName: page.props.auth?.user?.name || 'Invitado MOVA' },
      configOverwrite: {
        prejoinPageEnabled: false,
        disableDeepLinking: true,
      },
    })

    connecting.value = false

    // El botón nativo de "colgar" dentro del iframe dispara este evento
    // antes de que nosotros hagamos nada — lo tratamos igual que nuestro
    // botón "Cerrar sala", así el regreso a MOVA es automático sin importar
    // cómo la persona salga de la llamada.
    api.addEventListener('readyToClose', closeJitsi)

    // Cámara/micrófono: avisar con la acción concreta; la llamada sigue.
    api.addEventListener('cameraError', (event) => { mediaNotice.value = describeMediaError('camera', event) })
    api.addEventListener('micError', (event) => { mediaNotice.value = describeMediaError('mic', event) })

    // Error fatal de la conferencia (p. ej. el JWT ya no es válido): es un
    // error de acceso, no una clase que "terminó" — ver closeJitsi().
    api.addEventListener('errorOccurred', (event) => {
      if (event?.error?.isFatal) {
        joinError.value = 'La videollamada se interrumpió. Cierra la sala y vuelve a entrar.'
      }
    })
  }

  function closeJitsi() {
    const lesson = activeLesson.value
    const hadError = !!joinError.value

    showingJitsiModal.value = false
    joinError.value = ''
    mediaNotice.value = ''
    activeLesson.value = null
    connecting.value = false
    if (api) {
      api.dispose()
      api = null
    }

    // Si el cierre viene de un error de acceso, no hubo clase real que
    // "terminó" — no tiene sentido mandar al padre/profesor a confirmar pago
    // o escribir un reporte sobre algo a lo que nunca llegaron a entrar.
    // end_time viaja en la URL para que el dashboard sepa si la clase ya
    // terminó de verdad o si solo salieron de la llamada antes de tiempo
    // (la persona puede colgar minutos antes del horario programado).
    if (lesson && !hadError) {
      router.visit(route('dashboard', { post_class: lesson.id, post_class_ends_at: lesson.end_time }))
    }
  }

  return { showingJitsiModal, joinError, connecting, mediaNotice, activeLesson, openJitsi, closeJitsi }
}
