/**
 * Mapa único nombre-semántico → icono de Lucide. Ninguna página importa un
 * icono de Lucide directamente — todas pasan por aquí y por <Icon>, para que
 * la familia de iconos no se pueda fragmentar otra vez (el problema real que
 * esto reemplaza: 270 emoji repartidos en 46 archivos, cada uno dibujado
 * distinto por cada sistema operativo).
 *
 * Las claves son semánticas por SIGNIFICADO EN CONTEXTO, no una traducción
 * mecánica de "este emoji = este icono": 📅 aparece en el código actual con
 * al menos tres sentidos distintos (listado de clases, horario, reserva
 * confirmada), así que existen tres claves separadas para eso — ver el
 * comentario en cada grupo.
 */
import {
  LayoutDashboard,
  Users,
  BadgeCheck,
  ClipboardList,
  Calendar,
  CreditCard,
  Star,
  Bot,
  Home,
  User,
  Backpack,
  GraduationCap,
  FileText,
  Bell,
  CircleCheck,
  CircleX,
  ArrowRight,
  Menu,
  X,
  LogOut,
  Construction,
  Clock,
  TriangleAlert,
  Target,
  Video,
  BookOpen,
  CalendarX,
  PenLine,
  Check,
} from 'lucide-vue-next'

export const icons = {
  // Navegación — AppLayout.vue (los 3 roles)
  dashboard: LayoutDashboard,
  users: Users,
  'verify-teachers': BadgeCheck,   // ✅ "Verificar profesores" — validación/aprobación, no un check genérico
  requests: ClipboardList,         // 📋 "Solicitudes" (los 3 roles)
  classes: Calendar,               // 📅 "Clases"/"Mis clases" — listado, no un horario específico
  credits: CreditCard,             // 💳 "Recargas"/"Mis créditos" — reemplaza también el 'C' literal que usaba Teacher nav
  reviews: Star,                   // ⭐ "Reseñas"
  'ai-usage': Bot,                 // 🤖 "Uso de IA"
  home: Home,                      // 🏠 "Inicio" (parent/teacher)
  // "Mis hijos" y "Profesores" conviven en el MISMO nav del rol padre — no
  // pueden compartir icono (el usuario ya no podría distinguirlos de un
  // vistazo). Backpack = los hijos/alumnos gestionados; GraduationCap =
  // los profesores del marketplace.
  'my-students': Backpack,         // 🎒 "Mis hijos"
  'my-reports': FileText,          // 📝 "Mis reportes"
  teachers: GraduationCap,         // 👩‍🏫 "Profesores" (marketplace)
  profile: User,                   // 👤 "Mi perfil"

  // Selector de rol — Register.vue (paso 1, "¿Cómo usarás MOVA?")
  'role-parent': Users,            // 👪 "Soy padre"
  'role-teacher': GraduationCap,   // 🎓 "Soy profesor" — mismo icono semántico que "teachers", contexto distinto
  pending: Construction,           // 🚧 "Opción temporalmente no disponible" (modal de Google en Login/Register)

  // Feedback / estado
  'flash-success': CircleCheck,    // ✅ en AppLayout.vue flash messages
  'flash-error': CircleX,          // ❌ en AppLayout.vue flash messages
  notification: Bell,              // 🔔 toast de notificación en tiempo real

  // UI genérico
  'arrow-right': ArrowRight,       // "→" escrito como texto en botones/links
  menu: Menu,                      // hamburguesa móvil
  close: X,                        // cerrar sidebar/modal
  logout: LogOut,                  // "Cerrar sesión"
  check: Check,                    // "✓ Ya pagué" — confirmación de acción, no el mismo rol que flash-success

  // Dashboard/Parent.vue
  'in-progress': Clock,            // 🕐 "La clase está en curso"
  warning: TriangleAlert,          // ⚠️ "solicitudes esperan tu aprobación"
  target: Target,                  // 🎯 "diagnóstico"/"próximo paso" — objetivo, meta
  'join-room': Video,              // 🎥 "Unirse a la sala"
  topic: BookOpen,                 // 📚 "Tema" (reporte) / "Pasadas" (historial) — contenido de estudio
  'no-classes': CalendarX,         // 📭 "No hay clases próximas"
  'new-request': PenLine,          // ✏️ "Solicitar una clase"
}

/**
 * @param {keyof typeof icons} name
 */
export function iconFor(name) {
  const component = icons[name]
  if (!component && import.meta.env.DEV) {
    // eslint-disable-next-line no-console
    console.warn(`[icons] no existe un icono mapeado para "${name}"`)
  }
  return component
}
