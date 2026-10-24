import { computed, reactive, ref } from 'vue';
import { defineStore } from 'pinia';
import { studentService } from '../services';
import { notify } from './notifications';

/**
 * The students of the teacher's sections.
 *
 *  items                    ref<Student[]>
 *  loading                  ref<boolean>
 *  loaded                   ref<boolean>
 *  filters                  reactive<{ search: string, section_id, grade_level_id, school_year_id: number|'' }>
 *  count                    computed<number>
 *  fetch(overrides?)        merges overrides into filters, reloads items (toasts on failure) -> Promise<Student[]>
 *  create(payload)          payload: Student | snake_case object -> Promise<Student>   (throws ApiError)
 *  byId(id)                 -> Student|undefined (from the loaded list)
 *  upsert(student)          insert or replace in items (keeps list sorted)
 *  resetFilters()
 *
 * Teachers cannot edit or remove students: they propose changes through the
 * studentChangeRequests store, and the admin edits, moves and removes.
 */
export const useStudentsStore = defineStore('students', () => {
    const items = ref([]);
    const loading = ref(false);
    const loaded = ref(false);
    const filters = reactive({ search: '', section_id: '', grade_level_id: '', school_year_id: '' });

    const count = computed(() => items.value.length);

    function sortItems() {
        items.value.sort(
            (a, b) => a.lastName.localeCompare(b.lastName) || a.firstName.localeCompare(b.firstName),
        );
    }

    function byId(id) {
        const numeric = Number(id);
        return items.value.find((student) => student.id === numeric);
    }

    function upsert(student) {
        if (!student) {
            return;
        }

        const index = items.value.findIndex((item) => item.id === student.id);

        if (index === -1) {
            items.value.push(student);
        } else {
            items.value.splice(index, 1, student);
        }

        sortItems();
    }

    function resetFilters() {
        filters.search = '';
        filters.section_id = '';
        filters.grade_level_id = '';
        filters.school_year_id = '';
    }

    async function fetch(overrides = {}) {
        Object.assign(filters, overrides);
        loading.value = true;

        try {
            items.value = await studentService.list({ ...filters });
            loaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load students.');
        } finally {
            loading.value = false;
        }

        return items.value;
    }

    async function create(payload) {
        const student = await studentService.create(payload);
        upsert(student);
        return student;
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
        byId,
        upsert,
        resetFilters,
        reset,
    };
});
