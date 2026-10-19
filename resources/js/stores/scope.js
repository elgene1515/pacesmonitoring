import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { api } from '../services';

const STORAGE_KEY = 'paces.owner-scope';

/**
 * The organization the owner is currently looking at in the admin area
 * (null = every organization at once). Persisted per tab so a refresh keeps
 * the selection; applied to every GET under /api/admin by ApiClient.
 *
 *  organizationId     ref<number|null>
 *  organizationName   ref<string>
 *  isScoped           computed<boolean>
 *  select(id, name) / clearScope() / reset()
 */
export const useScopeStore = defineStore('scope', () => {
    const organizationId = ref(null);
    const organizationName = ref('');

    const isScoped = computed(() => organizationId.value !== null);

    function apply() {
        api.setScope({ organization_id: organizationId.value });
    }

    function persist() {
        try {
            if (organizationId.value === null) {
                sessionStorage.removeItem(STORAGE_KEY);
            } else {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ id: organizationId.value, name: organizationName.value }));
            }
        } catch {
            // Storage may be unavailable (private mode); the selection then lasts for this page load only.
        }
    }

    function select(id, name = '') {
        organizationId.value = id === null || id === undefined || id === '' ? null : Number(id);
        organizationName.value = organizationId.value === null ? '' : String(name ?? '');
        apply();
        persist();
    }

    function clearScope() {
        select(null);
    }

    function restore() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null');

            if (saved && saved.id) {
                organizationId.value = Number(saved.id);
                organizationName.value = String(saved.name ?? '');
            }
        } catch {
            organizationId.value = null;
            organizationName.value = '';
        }

        apply();
    }

    function reset() {
        organizationId.value = null;
        organizationName.value = '';
        apply();
        persist();
    }

    restore();

    return {
        organizationId,
        organizationName,
        isScoped,
        select,
        clearScope,
        reset,
    };
});
