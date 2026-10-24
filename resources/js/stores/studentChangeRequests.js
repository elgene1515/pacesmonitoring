import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { studentService } from '../services';
import { notify } from './notifications';

/**
 * The signed-in teacher's proposed edits to students' details, applied once
 * the school admin accepts them.
 *
 *  items                    ref<StudentChangeRequest[]>  pending first, newest first
 *  loading / loaded         ref<boolean>
 *  pending                  computed<StudentChangeRequest[]>
 *  pendingCount             computed<number>
 *  fetch()                  reload items (toasts on failure) -> Promise<StudentChangeRequest[]>
 *  propose(studentId, changes, message?) -> Promise<StudentChangeRequest> (throws ApiError)
 *  cancel(id)               withdraw a pending proposal -> Promise<void> (throws ApiError)
 *  pendingFor(studentId)    -> StudentChangeRequest|undefined
 *  reset()
 */
export const useStudentChangeRequestsStore = defineStore('studentChangeRequests', () => {
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

    function pendingFor(studentId) {
        const numeric = Number(studentId);
        return pending.value.find((request) => request.studentId === numeric);
    }

    async function fetch() {
        loading.value = true;

        try {
            items.value = await studentService.changeRequests();
            sortItems();
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load your change proposals.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    async function propose(studentId, changes, message = '') {
        const created = await studentService.proposeChange(studentId, changes, message);
        upsert(created);
        return created;
    }

    async function cancel(id) {
        await studentService.cancelChangeRequest(id);
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
        propose,
        cancel,
        pendingFor,
        upsert,
        reset,
    };
});
