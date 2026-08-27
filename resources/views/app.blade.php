<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'MOVA') }}</title>

        <!-- Identidad de marca: favicon e iconos generados desde el isotipo oficial -->
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/favicon-32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/images/brand/favicon-16.png">
        <link rel="apple-touch-icon" href="/images/brand/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#0D409A">

        <!-- Aplica el tema ANTES del primer paint — evita el flash de tema
             equivocado que ocurriría si esto se hiciera desde Vue montado.
             Debe ser el mismo storage key y la misma lógica que
             resources/js/composables/useTheme.js (fuente única de verdad). -->
        <script>
            (function () {
                try {
                    var stored = window.localStorage.getItem('mova-theme');
                    if (stored === 'light' || stored === 'dark') {
                        document.documentElement.setAttribute('data-theme', stored);
                    }
                } catch (e) {
                    // localStorage inaccesible — se degrada a prefers-color-scheme,
                    // que resources/css/app.css ya cubre sin necesitar el atributo.
                }
            })();
        </script>

        <!-- Plus Jakarta Sans auto-hospedada (ver resources/css/app.css). Solo se
             precargan los 2 pesos que aparecen sobre el pliegue en toda página:
             Regular (cuerpo) y ExtraBold (títulos). Precargar los 6 pesos
             desperdiciaría ancho de banda en móvil. -->
        <link rel="preload" href="/fonts/PlusJakartaSans-Regular.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/PlusJakartaSans-ExtraBold.woff2" as="font" type="font/woff2" crossorigin>

        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-canvas text-ink">
        @inertia
    </body>
</html>
