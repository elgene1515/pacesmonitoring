import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { schoolYearService } from '../services';
import { SchoolYear } from '../models';
import { notify } from './notifications';

/**
 * The organization's school years (cached for the session). `isCurrent` is what counts as
 * current for the signed-in user: a teacher's own pick, else the organization's default.
 *
 *  items                    ref<SchoolYear[]>            newest first
 *  loaded / loading         ref<boolean>
 *  hasItems                 computed<boolean>
 *  current                  computed<SchoolYear|null>    the one flagged current, else the newest
 *  selectedId               ref<number|'all'|null>       school year chosen on the Home page (defaults to current;
 *                                                        'all' means every school year, selected() is then null)
 *  selected                 computed<SchoolYear|null>
 *  load(force = false)      -> Promise<SchoolYear[]>     (toasts on failure, never throws)
 *  byId(id) / byName(name)  -> SchoolYear|null
 *  names                    computed<string[]>           labels, newest first
 *  select(id)
 *  create(payload)          -> Promise<SchoolYear>       (throws ApiError)
 *  update(id, payload)      -> Promise<SchoolYear>       (throws ApiError)
 *  reset()
 */
export const useSchoolYearsStore = defineStore('schoolYears', () => {
    const items = ref([]);
    const loaded = ref(false);
    const loading = ref(false);
    const selectedId = ref(null);
    let inFlight = null;

    const hasItems = computed(() => items.value.length > 0);

    const current = computed(() => items.value.find((year) => year.isCurrent) ?? items.value[0] ?? null);

    const selected = computed(() => (selectedId.value === 'all' ? null : byId(selectedId.value) ?? current.value));

    const names = computed(() => items.value.map((year) => year.name));

    function sortItems() {
        items.value.sort(SchoolYear.compare);
    }

    function byId(id) {
        if (id === null || id === undefined || id === '') {
            return null;
        }

        const numeric = Number(id);
        return items.value.find((year) => year.id === numeric) ?? null;
    }

    function byName(name) {
        return items.value.find((year) => year.name === name) ?? null;
    }

    function select(id) {
        if (id === 'all') {
            selectedId.value = 'all';
            return;
        }

        selectedId.value = id === null || id === undefined || id === '' ? null : Number(id);
    }

    function upsert(year) {
        if (!year) {
            return;
        }

        if (year.isCurrent) {
            items.value.forEach((item) => {
                if (item.id !== year.id) {
                    item.isCurrent = false;
                }
            });
        }

        const index = items.value.findIndex((item) => item.id === year.id);

        if (index === -1) {
            items.value.push(year);
        } else {
            items.value.splice(index, 1, year);
        }

        sortItems();
    }

    async function load(force = false) {
        if (loaded.value && !force) {
            return items.value;
        }

        if (inFlight) {
            return inFlight;
        }

        loading.value = true;

        inFlight = (async () => {
            try {
                items.value = await schoolYearService.list();
                loaded.value = true;
            } catch (err) {
                notify.error(err?.message ?? 'Failed to load school years.');
            } finally {
                loading.value = false;
                inFlight = null;
            }

            return items.value;
        })();

        return inFlight;
    }

    async function create(payload) {
        const year = await schoolYearService.create(payload);
        upsert(year);
        loaded.value = true;
        return year;
    }

    async function update(id, payload) {
        const year = await schoolYearService.update(id, payload);
        upsert(year);
        return year;
    }

    /** Teacher: pick the school year to work in; the flags of every cached year follow. */
    async function selectCurrent(id) {
        const year = await schoolYearService.select(id);
        upsert(year);
        return year;
    }

    function reset() {
        items.value = [];
        loaded.value = false;
        loading.value = false;
        selectedId.value = null;
        inFlight = null;
    }

    return {
        items,
        loaded,
        loading,
        hasItems,
        current,
        selectedId,
        selected,
        names,
        load,
        byId,
        byName,
        select,
        create,
        update,
        selectCurrent,
        upsert,
        reset,
    };
});
