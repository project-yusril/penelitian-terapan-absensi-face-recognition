import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from './Layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Absensi Mahasiswa';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const page = resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        );
        // Auto-apply the persistent AppLayout unless a page opts out
        // (e.g. the Auth/Login page sets `layout = null`).
        page.then((module) => {
            if (module.default.layout === undefined && !name.startsWith('Auth/')) {
                module.default.layout = AppLayout;
            }
        });
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#6366f1',
        showSpinner: false,
    },
});
