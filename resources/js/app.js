import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import CookieConsent from './Components/CookieConsent.vue';

// MOVA es el nombre fijo del producto — nunca el default 'Laravel' del
// scaffold, que un build sin VITE_APP_NAME definido (p. ej. la imagen de
// producción, que no pasa esa variable al paso de build de Vite) dejaba
// filtrarse al título de cada pestaña ("... - Laravel"). Resuelto en build
// time (import.meta.env), no en runtime — VITE_APP_NAME sigue pudiendo
// sobreescribirlo para un entorno que de verdad lo necesite.
const appName = import.meta.env.VITE_APP_NAME || 'MOVA';

createInertiaApp({
    title: (title) => (title.includes(appName) ? title : `${title} - ${appName}`),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // CookieConsent va como hermano de <App>, no dentro de ningún layout de
        // página: Welcome.vue no usa layout, AppLayout y GuestLayout son
        // distintos — este es el único punto que garantiza montarse en
        // cualquier ruta, autenticada o no.
        return createApp({ render: () => h('div', [h(App, props), h(CookieConsent)]) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
