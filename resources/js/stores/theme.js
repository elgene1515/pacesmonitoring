import { computed, ref, watch } from 'vue';
import { defineStore } from 'pinia';

/** The choices offered in the account menu. */
export const THEME_OPTIONS = Object.freeze([
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'System default', icon: 'monitor' },
]);

/** Must match the inline script in resources/views/layouts/app.blade.php. */
export const THEME_STORAGE_KEY = 'paces.theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

function readPreference() {
    try {
        const saved = localStorage.getItem(THEME_STORAGE_KEY);
        return THEME_OPTIONS.some((option) => option.value === saved) ? saved : 'system';
    } catch {
        return 'system';
    }
}

/**
 * Colour theme: light, dark, or whatever the device prefers.
 *
 *  preference   ref<'light'|'dark'|'system'>   what the user picked (kept in localStorage per browser)
 *  resolved     computed<'light'|'dark'>        what is actually shown
 *  isDark       computed<boolean>
 *  options      THEME_OPTIONS
 *  set(value)   change the preference
 */
export const useThemeStore = defineStore('theme', () => {
    const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia(DARK_QUERY) : null;

    const preference = ref(readPreference());
    const systemDark = ref(Boolean(media?.matches));

    const resolved = computed(() => (preference.value === 'system' ? (systemDark.value ? 'dark' : 'light') : preference.value));
    const isDark = computed(() => resolved.value === 'dark');

    function set(value) {
        if (!THEME_OPTIONS.some((option) => option.value === value)) {
            return;
        }

        preference.value = value;

        try {
            localStorage.setItem(THEME_STORAGE_KEY, value);
        } catch {
            // Storage may be unavailable (private mode); the choice then lasts for this page load only.
        }
    }

    media?.addEventListener('change', (event) => {
        systemDark.value = event.matches;
    });

    watch(resolved, (value) => {
        document.documentElement.dataset.theme = value;
    }, { immediate: true });

    return { preference, resolved, isDark, options: THEME_OPTIONS, set };
});
