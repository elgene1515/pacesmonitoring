import { computed, reactive, ref } from 'vue';
import { defineStore } from 'pinia';
import { paceService } from '../services';
import { notify } from './notifications';

/**
 * PACE master list.
 *
 *  items                    ref<Pace[]>
 *  loading                  ref<boolean>
 *  loaded                   ref<boolean>
 *  filters                  reactive<{ search: string }>
 *  count                    computed<number>
 *  fetch(overrides?)        merges overrides into filters, reloads items (toasts on failure) -> Promise<Pace[]>
 *  create(payload)          payload: Pace | {name, number} -> Promise<Pace>   (throws ApiError)
 *  createRange(payload)     payload: {name, start, end, school_year_id} -> Promise<Pace[]> (throws ApiError)
 *  update(id, payload)      -> Promise<Pace>                                  (throws ApiError)
 *  adjustStock(id, delta)   add (or, when negative, remove) booklets -> Promise<Pace> (throws ApiError)
 *  remove(id)               -> Promise<void>                                  (throws ApiError)
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

    function sortItems() {
        items.value.sort((a, b) => {
            const left = String(a.number);
            const right = String(b.number);
            return left.length - right.length || left.localeCompare(right);
        });
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
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load PACEs.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    async function create(payload) {
        const pace = await paceService.create(payload);
        upsert(pace);
        return pace;
    }

    async function createRange(payload) {
        const created = await paceService.createRange(payload);
        created.forEach(upsert);
        return created;
    }

    async function update(id, payload) {
        const pace = await paceService.update(id, payload);
        upsert(pace);
        return pace;
    }

    async function adjustStock(id, delta) {
        const pace = await paceService.adjustStock(id, delta);
        upsert(pace);
        return pace;
    }

    async function remove(id) {
        await paceService.remove(id);
        const numeric = Number(id);
        items.value = items.value.filter((pace) => pace.id !== numeric);
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
        fetch,
        create,
        createRange,
        update,
        adjustStock,
        remove,
        byId,
        upsert,
        resetFilters,
        reset,
    };
});
