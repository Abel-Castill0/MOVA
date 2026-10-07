import { Calculator, Zap, FlaskConical, Languages, BookOpen, Leaf, Code, Landmark, Microscope, Music, Palette, Brain, Library } from 'lucide-vue-next'

/**
 * Icono Lucide + tono pastel por materia. Una sola fuente para la landing y el flujo de solicitudes (antes cada página
 * traía su propio mapa de emojis, con resultados distintos según el sistema operativo).
 * Se devuelve el componente (no un string) para renderizarlo con <component :is>.
 */
const RULES = [
  [/ciencias|natural/i, Leaf, 'bg-emerald-50 text-emerald-600'],
  [/f[ií]sica/i, Zap, 'bg-orange-50 text-orange-600'],
  [/ingl[eé]s|franc[eé]s|idioma/i, Languages, 'bg-blue-50 text-blue-600'],
  [/lengua|comunic|literat|espa[nñ]ol/i, BookOpen, 'bg-fuchsia-50 text-fuchsia-600'],
  [/matem[aá]tic|c[aá]lculo|[aá]lgebra|geometr/i, Calculator, 'bg-blue-50 text-blue-600'],
  [/program|comput|sistem/i, Code, 'bg-sky-50 text-sky-600'],
  [/qu[ií]mica/i, FlaskConical, 'bg-cyan-50 text-cyan-700'],
  [/histor|social/i, Landmark, 'bg-amber-50 text-amber-700'],
  [/biolog/i, Microscope, 'bg-emerald-50 text-emerald-700'],
  [/m[uú]sic/i, Music, 'bg-violet-50 text-violet-600'],
  [/arte|dibujo/i, Palette, 'bg-pink-50 text-pink-600'],
  [/filoso|psicol/i, Brain, 'bg-indigo-50 text-indigo-600'],
]

export function subjectIcon(name) {
  const text = name || ''
  for (const [pattern, icon, bg] of RULES) {
    if (pattern.test(text)) return { icon, bg }
  }
  return { icon: Library, bg: 'bg-blue-50 text-blue-600' }
}
