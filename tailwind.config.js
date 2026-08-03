import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
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
