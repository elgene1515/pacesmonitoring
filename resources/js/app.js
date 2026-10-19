import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './components/templates/App.vue';
import router from './router';
import { api } from './services';
import { useAuthStore } from './stores/auth';
import { useThemeStore } from './stores/theme';

async function start() {
    const app = createApp(App);
    const pinia = createPinia();

    app.use(pinia);

    // Apply the saved colour theme (and follow the device setting) from the start.
    useThemeStore(pinia);

    // Restore the session (GET /api/user) before the first route resolves; 401 is swallowed.
    const auth = useAuthStore(pinia);
    await auth.bootstrap();

    // If the session expires mid-use, drop local state and send the user back to the login page.
    api.onUnauthenticated(() => {
        if (!auth.isAuthenticated) {
            return;
        }

        auth.clear();

        const current = router.currentRoute.value;
        if (current.meta.requiresAuth) {
            router.push({ name: 'login', query: { redirect: current.fullPath } });
        }
    });

    app.use(router);
    await router.isReady();

    app.mount('#app');
}

start();
