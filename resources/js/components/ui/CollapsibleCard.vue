<script setup>
import { ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

/**
 * A card whose body folds away under its heading. The whole heading row is
 * the toggle; a short summary (slot `summary`) stays visible while folded.
 * With `storageKey`, the open / folded choice is remembered in this browser.
 *
 *  props   title        String   the card heading
 *          defaultOpen  Boolean  open state when nothing is remembered
 *          storageKey   String   remembers the state under "collapsible:<key>" (optional)
 *  slots   summary      shown next to the heading, open or folded (counts, warnings)
 *          default      the body
 */
const props = defineProps({
    title: { type: String, required: true },
    defaultOpen: { type: Boolean, default: true },
    storageKey: { type: String, default: '' },
});

const uid = `collapsible-${Math.random().toString(36).slice(2, 8)}`;

function remembered() {
    if (!props.storageKey) {
        return null;
    }

    try {
        const value = localStorage.getItem(`collapsible:${props.storageKey}`);

        return value === null ? null : value === 'open';
    } catch {
        return null;
    }
}

const open = ref(remembered() ?? props.defaultOpen);

watch(open, (value) => {
    if (!props.storageKey) {
        return;
    }

    try {
        localStorage.setItem(`collapsible:${props.storageKey}`, value ? 'open' : 'closed');
    } catch {
        // Storage unavailable (private window, blocked site data): the card still works.
    }
});
</script>

<template>
    <section class="card collapsible" :class="{ folded: !open }">
        <button
            type="button"
            class="toggle"
            :aria-expanded="open"
            :aria-controls="`${uid}-body`"
            @click="open = !open"
        >
            <h2 class="card-title toggle-title">{{ title }}</h2>
            <span class="summary"><slot name="summary" /></span>
            <span class="chevron" :class="{ turned: open }" aria-hidden="true"><AppIcon name="chevron-down" /></span>
            <span class="sr-only">{{ open ? 'Hide' : 'Show' }}</span>
        </button>
        <div v-show="open" :id="`${uid}-body`" class="body">
            <slot />
        </div>
    </section>
</template>

<style scoped>
.toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 0;
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.toggle:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 4px;
    border-radius: 4px;
}

.toggle-title {
    margin: 0;
}

.summary {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 0.85rem;
}

.chevron {
    display: inline-flex;
    color: var(--muted);
    transition: transform 0.15s ease;
}

.chevron.turned {
    transform: rotate(180deg);
}

.toggle:hover .chevron {
    color: var(--text);
}

.body {
    margin-top: 14px;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

@media (prefers-reduced-motion: reduce) {
    .chevron {
        transition: none;
    }
}
</style>
