import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { teachingService } from '../services';
import { notify } from './notifications';

/**
 * The signed-in teacher's requests for open class slots (a subject of a
 * section nobody teaches yet), decided by the school admin.
 *
 *  items                    ref<TeachingAssignmentRequest[]>  pending first, newest first
 *  loading / loaded         ref<boolean>
 *  pending                  computed<TeachingAssignmentRequest[]>
 *  pendingCount             computed<number>
 *  fetch()                  reload items (toasts on failure) -> Promise<TeachingAssignmentRequest[]>
 *  request({sectionId, subjectId, message}) -> Promise<TeachingAssignmentRequest> (throws ApiError)
 *  cancel(id)               withdraw a pending request -> Promise<void> (throws ApiError)
 *  pendingFor(sectionId, subjectId) -> TeachingAssignmentRequest|undefined
 *  reset()
 */
export const useTeachingStore = defineStore('teaching', () => {
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

    function pendingFor(sectionId, subjectId) {
        const section = Number(sectionId);
        const subject = Number(subjectId);

        return pending.value.find((request) => request.sectionId === section && request.subjectId === subject);
    }

    async function fetch() {
        loading.value = true;

        try {
            items.value = await teachingService.requests();
            sortItems();
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load your class requests.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    async function request(payload) {
        const created = await teachingService.request(payload);
        upsert(created);
        return created;
    }

    async function cancel(id) {
        await teachingService.cancelRequest(id);
        await fetch();
    }

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
        request,
        cancel,
        pendingFor,
        upsert,
        reset,
    };
});
