<script setup>
import { computed, nextTick, ref, watch } from 'vue';

/**
 * A dropdown you can type into: click it (or tab to it) to see every option, type to narrow the
 * list, pick with the mouse or the arrow keys + Enter. Looks like a .input select.
 *
 *  props   modelValue   String|Number   the chosen option's value ('' = nothing)
 *          options      [{ value, label }]
 *          id           String          id of the input (for a label's `for`)
 *          ariaLabel    String          accessible name when there is no visible label (a filter)
 *          placeholder  String
 *          emptyText    String          shown when the typed text matches nothing
 *          disabled     Boolean
 *          invalid      Boolean
 *  emits   update:modelValue(value)
 *          change(value)                only when the person picks an option
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    id: { type: String, default: undefined },
    ariaLabel: { type: String, default: undefined },
    placeholder: { type: String, default: 'Select' },
    emptyText: { type: String, default: 'Nothing matches' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref(null);
const list = ref(null);

const listId = `search-select-${Math.random().toString(36).slice(2, 8)}`;

const selected = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)) ?? null);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return needle === '' ? props.options : props.options.filter((option) => String(option.label).toLowerCase().includes(needle));
});

/** While open the box holds what is being typed; closed, it shows the chosen option. */
const shown = computed(() => (open.value ? query.value : (selected.value?.label ?? '')));

function show() {
    if (props.disabled) {
        return;
    }

    open.value = true;
    query.value = '';
    active.value = Math.max(0, props.options.findIndex((option) => String(option.value) === String(props.modelValue)));
    scrollToActive();
}

function hide() {
    open.value = false;
    query.value = '';
}

function pick(option) {
    emit('update:modelValue', option.value);
    emit('change', option.value);
    hide();
    input.value?.blur();
}

function onInput(event) {
    if (!open.value) {
        open.value = true;
    }

    query.value = event.target.value;
    active.value = 0;
}

function move(step) {
    if (!open.value) {
        show();

        return;
    }

    if (filtered.value.length === 0) {
        return;
    }

    active.value = (active.value + step + filtered.value.length) % filtered.value.length;
    scrollToActive();
}

function onEnter(event) {
    if (!open.value) {
        return;
    }

    event.preventDefault();

    const option = filtered.value[active.value];

    if (option) {
        pick(option);
    }
}

/** Escape closes the list first; only a second press reaches a dialog around it. */
function onEscape(event) {
    if (open.value) {
        event.stopPropagation();
        hide();
    }
}

async function scrollToActive() {
    await nextTick();
    list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
}

watch(() => props.disabled, (disabled) => {
    if (disabled) {
        hide();
    }
});
</script>

<template>
    <div class="search-select">
        <input
            :id="id"
            ref="input"
            class="input search-select-input"
            :class="{ 'input-error': invalid }"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-label="ariaLabel"
            :placeholder="open && selected ? selected.label : placeholder"
            :value="shown"
            :disabled="disabled"
            @focus="show"
            @click="show"
            @input="onInput"
            @blur="hide"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter="onEnter"
            @keydown.esc="onEscape"
        />
        <ul v-if="open" :id="listId" ref="list" class="search-select-list" role="listbox">
            <li
                v-for="(option, index) in filtered"
                :key="option.value"
                class="search-select-option"
                :class="{ active: index === active, chosen: String(option.value) === String(modelValue) }"
                role="option"
                :aria-selected="index === active"
                @mousedown.prevent="pick(option)"
                @mousemove="active = index"
            >
                {{ option.label }}
            </li>
            <li v-if="filtered.length === 0" class="search-select-empty">{{ emptyText }}</li>
        </ul>
    </div>
</template>

<style scoped>
.search-select {
    position: relative;
}

.search-select-input {
    padding-right: 32px;
    text-overflow: ellipsis;
    background-image: linear-gradient(45deg, transparent 50%, var(--muted) 50%), linear-gradient(135deg, var(--muted) 50%, transparent 50%);
    background-position: calc(100% - 17px) 50%, calc(100% - 12px) 50%;
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
}

.search-select-list {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 224px;
    overflow-y: auto;
    margin: 0;
    padding: 4px;
    list-style: none;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
}

.search-select-option,
.search-select-empty {
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 0.95rem;
}

.search-select-option {
    cursor: pointer;
}

.search-select-option.active {
    background: rgba(var(--primary-rgb), 0.12);
}

.search-select-option.chosen {
    font-weight: 600;
}

.search-select-empty {
    color: var(--muted);
}
</style>
