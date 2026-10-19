import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { paceRequestService } from '../services';
import { notify } from './notifications';

/**
 * The signed-in teacher's PACE stock requests.
 *
 *  items                ref<PaceRequest[]>  pending first, newest first
 *  loading / loaded     ref<boolean>
 *  pending              computed<PaceRequest[]>
 *  pendingCount         computed<number>
 *  fetch()              reload items (toasts on failure) -> Promise<PaceRequest[]>
 *  create(payload)      -> Promise<PaceRequest>  (throws ApiError)
 *  update(id, payload)  -> Promise<PaceRequest>  (throws ApiError)
 *  cancel(id)           withdraw a pending request -> Promise<void> (throws ApiError)
 *  pendingFor(paceId)   -> PaceRequest|undefined (the pending request of one PACE, from the loaded list)
 *  reset()
 */
export const usePaceRequestsStore = defineStore('paceRequests', () => {
    const items = ref([]);
    const loading = ref(false);
    const loaded = ref(false);

    const pending = computed(() => items.value.filter((request) => request.isPending));
    const pendingCount = computed(() => pending.value.length);

    function sortItems() {
        items.value.sort((a, b) => {
            if (a.isPending !== b.isPending) {
                return a.isPending ? -1 : 1;
            }

            return String(b.createdAt ?? '').localeCompare(String(a.createdAt ?? '')) || (b.id ?? 0) - (a.id ?? 0);
        });
    }

    function upsert(request) {
        if (!request) {
            return;
        }

        const index = items.value.findIndex((item) => item.id === request.id);

        if (index === -1) {
            items.value.push(request);
        } else {
            items.value.splice(index, 1, request);
        }

        sortItems();
    }

    function pendingFor(paceId) {
        const numeric = Number(paceId);
        return pending.value.find((request) => request.paceId === numeric);
    }

    async function fetch() {
        loading.value = true;

        try {
            items.value = await paceRequestService.list();
            sortItems();
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load the stock requests.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    async function create(payload) {
        const request = await paceRequestService.create(payload);
        upsert(request);
        return request;
    }

    async function update(id, payload) {
        const request = await paceRequestService.update(id, payload);
        upsert(request);
        return request;
    }

    async function cancel(id) {
        await paceRequestService.cancel(id);
        await fetch();
    }

    /** Forget the loaded list (called when the session ends so another user never sees it). */
    function reset() {
        items.value = [];
        loaded.value = false;
        loading.value = false;
    }

    return {
        items,
        loading,
        loaded,
        pending,
        pendingCount,
        fetch,
        create,
        update,
        cancel,
        pendingFor,
        upsert,
        reset,
    };
});
