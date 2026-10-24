<script setup>
/**
 * Small inline line icon (16x16, drawn in the current text color).
 *
 * Decorative by default: pair it with a title / aria-label on the button it sits in.
 */
defineProps({
    /**
     * 'eye' | 'pencil' | 'trash' | 'star' | 'star-filled' | 'retake' | 'plus' | 'minus' | 'box'
     * | 'chevron-down' | 'gear' | 'logout' | 'sun' | 'moon' | 'monitor' | 'check' | 'close' | 'copy'
     */
    name: { type: String, required: true },
});
</script>

<template>
    <svg class="app-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <template v-if="name === 'eye'">
            <path d="M1.5 8s2.3-4.5 6.5-4.5S14.5 8 14.5 8s-2.3 4.5-6.5 4.5S1.5 8 1.5 8z" />
            <circle cx="8" cy="8" r="2" />
        </template>
        <template v-else-if="name === 'pencil'">
            <path d="M11.3 2.5l2.2 2.2L5.2 13H3v-2.2z" />
            <path d="M9.8 4l2.2 2.2" />
        </template>
        <template v-else-if="name === 'trash'">
            <path d="M2.5 4h11" />
            <path d="M6 4V2.5h4V4" />
            <path d="M4 4l.7 9.5h6.6L12 4" />
            <path d="M6.5 7v4M9.5 7v4" />
        </template>
        <template v-else-if="name === 'star'">
            <path d="M8 1.8l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2 .7-4.3-3.1-3 4.3-.6z" />
        </template>
        <template v-else-if="name === 'retake'">
            <path d="M13.2 8.6A5.3 5.3 0 1 1 11.7 4.4" />
            <path d="M12.2 1.9v3h-3" />
        </template>
        <template v-else-if="name === 'star-filled'">
            <path d="M8 1.8l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2 .7-4.3-3.1-3 4.3-.6z" fill="currentColor" />
        </template>
        <template v-else-if="name === 'plus'">
            <path d="M8 3v10M3 8h10" />
        </template>
        <template v-else-if="name === 'minus'">
            <path d="M3 8h10" />
        </template>
        <template v-else-if="name === 'box'">
            <path d="M2 5.5L8 2.5l6 3v5.5l-6 3-6-3z" />
            <path d="M2 5.5l6 3 6-3M8 8.5V14" />
        </template>
        <template v-else-if="name === 'chevron-down'">
            <path d="M4 6l4 4 4-4" />
        </template>
        <template v-else-if="name === 'gear'">
            <circle cx="8" cy="8" r="2.2" />
            <path d="M8 1.8v1.7M8 12.5v1.7M1.8 8h1.7M12.5 8h1.7M3.6 3.6l1.2 1.2M11.2 11.2l1.2 1.2M12.4 3.6l-1.2 1.2M4.8 11.2l-1.2 1.2" />
        </template>
        <template v-else-if="name === 'logout'">
            <path d="M6.5 2.5h-3a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3" />
            <path d="M10 5l3 3-3 3M13 8H6" />
        </template>
        <template v-else-if="name === 'sun'">
            <circle cx="8" cy="8" r="2.8" />
            <path d="M8 1.5v1.7M8 12.8v1.7M1.5 8h1.7M12.8 8h1.7M3.4 3.4l1.2 1.2M11.4 11.4l1.2 1.2M12.6 3.4l-1.2 1.2M4.6 11.4l-1.2 1.2" />
        </template>
        <template v-else-if="name === 'moon'">
            <path d="M13.5 9.6A5.6 5.6 0 0 1 6.4 2.5a5.6 5.6 0 1 0 7.1 7.1z" />
        </template>
        <template v-else-if="name === 'monitor'">
            <rect x="1.8" y="2.8" width="12.4" height="8" rx="1.2" />
            <path d="M5.5 13.5h5M8 10.8v2.7" />
        </template>
        <template v-else-if="name === 'check'">
            <path d="M3 8.5l3.2 3L13 4.5" />
        </template>
        <template v-else-if="name === 'close'">
            <path d="M4 4l8 8M12 4l-8 8" />
        </template>
        <template v-else-if="name === 'copy'">
            <rect x="5.5" y="5.5" width="8" height="8" rx="1.3" />
            <path d="M10.5 5.5V3.8a1.3 1.3 0 0 0-1.3-1.3H3.8a1.3 1.3 0 0 0-1.3 1.3v5.4a1.3 1.3 0 0 0 1.3 1.3h1.7" />
        </template>
    </svg>
</template>

<style scoped>
.app-icon {
    width: 1em;
    height: 1em;
    vertical-align: -0.125em;
}
</style>
