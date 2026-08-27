import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Selector manual (no solo prefers-color-scheme): useTheme.js escribe
    // data-theme="dark" en <html> cuando el usuario elige explícitamente,
    // y app.css ya cubre el caso "sistema oscuro sin elección manual" con
    // su propio @media — ver resources/css/app.css.
    darkMode: ['selector', '[data-theme="dark"]'],

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Tipografía corporativa MOVA (manual de marca). Auto-hospedada
                // — ver @font-face en resources/css/app.css.
                sans: ['Plus Jakarta Sans', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // ── Azul MOVA ───────────────────────────────────────────────
                // Rampa anclada en los colores REALES de la marca, no inventados:
                //   600 #1F5AA6 → hex del "MOVA MINI MANUAL DE MARCA.pdf"
                //   700 #0D409A → azul del logo (píxel exacto de Isotipo/Imagotipo)
                //   800 #0D346D → navy del apretón de manos del isotipo
                // El manual y el logo traían azules distintos; resultó que no se
                // contradicen: mismo tono (H≈215) en tres niveles de luminosidad
                // consecutivos, así que ambos viven en la rampa en el rol que les
                // toca (600 = botones, 700 = hover/logo, 800 = fondos profundos).
                // Los pasos 50–500 y 900–950 se extrapolaron manteniendo H≈215.
                // Contraste con texto blanco: 500=5.2:1 AA · 600=6.8:1 AA ·
                // 700=9.5:1 AAA · 800=12.1:1 AAA · 900=15.1:1 AAA.
                brand: {
                    50:  '#F0F5FD',
                    100: '#DEEAF9',
                    200: '#BDD4F2',
                    300: '#8FB5E8',
                    400: '#4F8ADB',
                    500: '#246ACC',
                    600: '#1F5AA6',
                    700: '#0D409A',
                    800: '#0D346D',
                    900: '#092550',
                    950: '#051733',
                },
                // ── Naranja MOVA ────────────────────────────────────────────
                // #F59E0B (manual + píxel del logo, coinciden exacto) es
                // literalmente amber-500 de Tailwind, así que se reutiliza esa
                // escala completa en vez de inventar uno: mismos pasos ya
                // calibrados. Es el color de acento del isotipo (la persona
                // naranja), no un color decorativo — usar con intención.
                accent: {
                    50:  '#FFFBEB',
                    100: '#FEF3C7',
                    200: '#FDE68A',
                    300: '#FCD34D',
                    400: '#FBBF24',
                    500: '#F59E0B',
                    600: '#D97706',
                    700: '#B45309',
                    800: '#92400E',
                    900: '#78350F',
                    950: '#451A03',
                },

                // ── Tokens semánticos ────────────────────────────────────────
                // Fuente de verdad: variables CSS en resources/css/app.css.
                // Estas entradas son SOLO el enchufe de Tailwind hacia esas
                // variables — nunca redefinir un valor de color aquí que ya
                // vive en app.css, y ningún componente usa `dark:` porque la
                // variable ya cambia sola según el tema. La sintaxis
                // `rgb(var(--x) / <alpha-value>)` permite además cosas como
                // `bg-canvas/50` con opacidad real.
                canvas:          'rgb(var(--canvas) / <alpha-value>)',
                surface:         'rgb(var(--surface) / <alpha-value>)',
                'surface-raised':'rgb(var(--surface-raised) / <alpha-value>)',
                ink:             'rgb(var(--ink) / <alpha-value>)',
                'ink-muted':     'rgb(var(--ink-muted) / <alpha-value>)',
                'ink-subtle':    'rgb(var(--ink-subtle) / <alpha-value>)',
                line:            'rgb(var(--line) / <alpha-value>)',
                'line-strong':   'rgb(var(--line-strong) / <alpha-value>)',
                'focus-ring':    'rgb(var(--focus-ring) / <alpha-value>)',

                success: {
                    bg:     'rgb(var(--success-bg) / <alpha-value>)',
                    border: 'rgb(var(--success-border) / <alpha-value>)',
                    text:   'rgb(var(--success-text) / <alpha-value>)',
                },
                warning: {
                    bg:     'rgb(var(--warning-bg) / <alpha-value>)',
                    border: 'rgb(var(--warning-border) / <alpha-value>)',
                    text:   'rgb(var(--warning-text) / <alpha-value>)',
                },
                danger: {
                    bg:     'rgb(var(--danger-bg) / <alpha-value>)',
                    border: 'rgb(var(--danger-border) / <alpha-value>)',
                    text:   'rgb(var(--danger-text) / <alpha-value>)',
                },
                info: {
                    bg:     'rgb(var(--info-bg) / <alpha-value>)',
                    border: 'rgb(var(--info-border) / <alpha-value>)',
                    text:   'rgb(var(--info-text) / <alpha-value>)',
                },
            },

            // Escala de radio con nombre — deliberadamente NO usa las claves
            // sm/md/lg/xl de Tailwind: `extend` fusiona por nombre de clave,
            // así que reusar esos nombres habría sobrescrito silenciosamente
            // `rounded-sm/md/lg/xl` en los 231+140+108+5 usos que YA existen
            // en toda la app, sin tocar una sola página — exactamente el
            // "cambio de alcance no pedido" que este proyecto evita. Nombres
            // propios → cero colisión; `rounded-xl` etc. siguen significando
            // lo mismo que siempre hasta que una página se migre a propósito.
            borderRadius: {
                chip: '0.5rem',      // 8px  — chips, badges pequeños
                control: '0.75rem',  // 12px — inputs, botones secundarios
                card: '1rem',        // 16px — tarjetas, botones primarios
                elevated: '1.25rem', // 20px — tarjetas elevadas, modales
                pill: '9999px',      // botones tipo pastilla, avatares (no colisiona: Tailwind no trae "pill")
            },

            // Elevación por capas (Fase 4): canvas plano → contenido con
            // elevación discreta → capa funcional. Nunca valores de sombra
            // sueltos por componente.
            boxShadow: {
                'elevation-1': '0 1px 2px 0 rgb(15 23 42 / 0.06)',
                'elevation-2': '0 4px 12px -2px rgb(15 23 42 / 0.10)',
                'elevation-3': '0 12px 32px -8px rgb(15 23 42 / 0.18)',
            },

            // Jerarquía de movimiento (Fase 5 del plan de rediseño) — nunca
            // una duración plana para toda la app. Nivel 0 (instantáneo) no
            // necesita token; niveles 1–4 sí.
            transitionDuration: {
                micro: '100ms',   // nivel 1: presión, hover, focus
                ui: '250ms',      // nivel 2: componente (sheet, modal, tarjeta)
                nav: '350ms',     // nivel 3: transición de página
            },
            transitionTimingFunction: {
                'out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',   // niveles 1–3
                sheet: 'cubic-bezier(0.32, 0.72, 0, 1)',        // hojas contextuales, estilo iOS
            },

            animation: {
                'float': 'float 6s ease-in-out infinite',
                'float-delayed': 'float 6s ease-in-out 2s infinite',
                'float-slow': 'float 8s ease-in-out 1s infinite',
                'fade-up': 'fadeUp 0.6s ease-out forwards',
                'spin-slow': 'spin 20s linear infinite',
            },
            keyframes: {
                float: {
                    '0%, 100%': { transform: 'translateY(0px) rotate(0deg)' },
                    '50%': { transform: 'translateY(-20px) rotate(5deg)' },
                },
                fadeUp: {
                    '0%': { opacity: '0', transform: 'translateY(30px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },

    plugins: [forms],
};
