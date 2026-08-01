/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Echo/Pusher (real-time notifications) se cargan bajo demanda desde
// resources/js/echo.js — solo las páginas autenticadas (AppLayout) los
// necesitan, así que no viajan en el bundle principal para invitados
// (landing, login, marketplace público, etc). Ver AppLayout.vue.
