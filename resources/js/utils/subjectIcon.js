import {
  Calculator, Sigma, Triangle, Puzzle, ChartColumn, Zap, FlaskConical, Microscope, Leaf, BookOpen, MessageSquare,
  Landmark, Globe, Brain, Languages, Code, Monitor, Table, Bot, Wallet, Music, Palette, Library,
} from 'lucide-vue-next'

/**
 * Icono Lucide + tono pastel por materia. Una sola fuente para la landing, el flujo de solicitudes y el selector de
 * materias del profesor. Se devuelve el componente (no un string) para renderizarlo con <component :is>.
 *
 * Las materias del catálogo maestro (App\Support\SubjectCatalog) traen una clave `icon`; si falta (filas antiguas),
 * se deduce por el nombre.
 */
const BY_KEY = {
  calculator: [Calculator, 'bg-blue-50 text-blue-600'],
  sigma: [Sigma, 'bg-blue-50 text-blue-600'],
  triangle: [Triangle, 'bg-indigo-50 text-indigo-600'],
  puzzle: [Puzzle, 'bg-violet-50 text-violet-600'],
  chart: [ChartColumn, 'bg-teal-50 text-teal-700'],
  zap: [Zap, 'bg-orange-50 text-orange-600'],
  flask: [FlaskConical, 'bg-cyan-50 text-cyan-700'],
  microscope: [Microscope, 'bg-emerald-50 text-emerald-700'],
  leaf: [Leaf, 'bg-emerald-50 text-emerald-600'],
  book: [BookOpen, 'bg-fuchsia-50 text-fuchsia-600'],
  message: [MessageSquare, 'bg-fuchsia-50 text-fuchsia-600'],
  landmark: [Landmark, 'bg-amber-50 text-amber-700'],
  globe: [Globe, 'bg-sky-50 text-sky-700'],
  brain: [Brain, 'bg-indigo-50 text-indigo-600'],
  languages: [Languages, 'bg-blue-50 text-blue-600'],
  code: [Code, 'bg-sky-50 text-sky-600'],
  monitor: [Monitor, 'bg-sky-50 text-sky-600'],
  table: [Table, 'bg-green-50 text-green-700'],
  bot: [Bot, 'bg-sky-50 text-sky-700'],
  wallet: [Wallet, 'bg-teal-50 text-teal-700'],
  music: [Music, 'bg-violet-50 text-violet-600'],
  palette: [Palette, 'bg-pink-50 text-pink-600'],
}

const RULES = [
  [/ciencias|natural/i, 'leaf'],
  [/f[ií]sica/i, 'zap'],
  [/ingl[eé]s|franc[eé]s|idioma|portugu|quechua/i, 'languages'],
  [/lengua|comunic|literat|espa[nñ]ol/i, 'book'],
  [/matem[aá]tic|c[aá]lculo|[aá]lgebra|geometr|aritm/i, 'calculator'],
  [/program|comput|sistem/i, 'code'],
  [/qu[ií]mica/i, 'flask'],
  [/histor|social/i, 'landmark'],
  [/biolog/i, 'microscope'],
  [/m[uú]sic/i, 'music'],
  [/arte|dibujo/i, 'palette'],
  [/filoso|psicol/i, 'brain'],
]

export function subjectIcon(name, key = null) {
  const resolved = (key && BY_KEY[key] && key) || RULES.find(([pattern]) => pattern.test(name || ''))?.[1]
  const [icon, bg] = BY_KEY[resolved] ?? [Library, 'bg-blue-50 text-blue-600']
  return { icon, bg }
}
