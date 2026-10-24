import { computed, reactive, ref } from 'vue';
import { defineStore } from 'pinia';
import { paceService } from '../services';
import { Pace } from '../models';
import { notify } from './notifications';

/**
 * The branch's PACE catalog as the teacher sees it (read-only), with the
 * teacher's own booklets on each PACE.
 *
 *  items                    ref<Pace[]>
 *  loading                  ref<boolean>
 *  loaded                   ref<boolean>
 *  filters                  reactive<{ search: string }>
 *  count                    computed<number>
 *  held                     computed<Pace[]>   the PACEs the teacher holds booklets of
 *  fetch(overrides?)        merges overrides into filters, reloads items (toasts on failure) -> Promise<Pace[]>
 *  byId(id)                 -> Pace|undefined (from the loaded list)
 *  upsert(pace)             insert or replace in items (keeps list sorted by number)
 *  resetFilters()
 */
export const usePacesStore = defineStore('paces', () => {
    const items = ref([]);
    const loading = ref(false);
    const loaded = ref(false);
    const filters = reactive({ search: '' });

    const count = computed(() => items.value.length);
    const held = computed(() => items.value.filter((pace) => pace.stock > 0));

    function sortItems() {
        items.value.sort(Pace.compare);
    }

    function byId(id) {
        const numeric = Number(id);
        return items.value.find((pace) => pace.id === numeric);
    }

    function upsert(pace) {
        if (!pace) {
            return;
        }

        const index = items.value.findIndex((item) => item.id === pace.id);

        if (index === -1) {
            items.value.push(pace);
        } else {
            items.value.splice(index, 1, pace);
        }

        sortItems();
    }

    function resetFilters() {
        filters.search = '';
    }

    async function fetch(overrides = {}) {
        Object.assign(filters, overrides);
        loading.value = true;

        try {
            items.value = await paceService.list({ ...filters });
            sortItems();
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load PACEs.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    /** Forget the loaded list (called when the session ends so another user never sees it). */
    function reset() {
        items.value = [];
        loaded.value = false;
        loading.value = false;
        resetFilters();
    }

    return {
        items,
        loading,
        loaded,
        filters,
        count,
        held,
        fetch,
        byId,
        upsert,
        resetFilters,
        reset,
    };
});
