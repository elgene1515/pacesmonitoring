<script setup>
import { ref } from 'vue';
import { usePacesStore } from '../../stores/paces';
import { notify } from '../../stores/notifications';
import StatusBadge from '../ui/StatusBadge.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * A PACE's stock with − / + buttons that save one booklet at a time.
 *
 *  props   pace      Pace
 *          compact   Boolean   tighter spacing for table cells
 *          disabled  Boolean
 *  emits   adjusted(Pace)      the PACE returned by the API after a change
 */
const props = defineProps({
    pace: { type: Object, required: true },
    compact: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['adjusted']);

const paces = usePacesStore();
const saving = ref(false);

async function adjust(delta) {
    if (saving.value || props.disabled || (delta < 0 && props.pace.isOutOfStock)) {
        return;
    }

    saving.value = true;

    try {
        const updated = await paces.adjustStock(props.pace.id, delta);
        emit('adjusted', updated);
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('stock') ?? err.message) : (err?.message ?? 'Unable to change the stock.'));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="stock" :class="{ compact }">
        <button
            type="button"
            class="btn btn-secondary btn-sm btn-icon stock-btn"
            :disabled="saving || disabled || pace.isOutOfStock"
            :title="`Remove one booklet from PACE ${pace.label}`"
            :aria-label="`Remove one booklet from PACE ${pace.label}`"
            @click="adjust(-1)"
        >
            <AppIcon name="minus" />
        </button>
        <span class="mono stock-value" :class="`level-${pace.stockLevel}`" :title="pace.stockLabel">{{ pace.stock }}</span>
        <button
            type="button"
            class="btn btn-secondary btn-sm btn-icon stock-btn"
            :disabled="saving || disabled"
            :title="`Add one booklet to PACE ${pace.label}`"
            :aria-label="`Add one booklet to PACE ${pace.label}`"
            @click="adjust(1)"
        >
            <AppIcon name="plus" />
        </button>
        <StatusBadge :tone="pace.stockTone" class="stock-badge">{{ pace.stockLabel }}</StatusBadge>
    </div>
</template>

<style scoped>
.stock {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.stock.compact {
    gap: 4px;
}

.stock-btn {
    padding: 2px 6px;
    min-width: 0;
}

.stock-value {
    min-width: 2.5ch;
    text-align: center;
    font-weight: 600;
}

.stock-value.level-out {
    color: var(--danger);
}

.stock-value.level-low {
    color: var(--warn-text);
}

.stock-badge {
    margin-left: 2px;
}

@media (max-width: 560px) {
    .stock.compact .stock-badge {
        display: none;
    }
}
</style>
