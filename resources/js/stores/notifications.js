import { ref } from 'vue';
import { defineStore } from 'pinia';
import { TOAST_TIMEOUT } from '../core/constants';

/**
 * Toast notifications.
 *
 *  toasts                       ref<{ id, type: 'success'|'error'|'info', message }[]>
 *  push(type, message, opts?)   opts.timeout ms (0 = sticky) -> id
 *  remove(id)
 *  clear()
 *  success(message) / error(message) / info(message) -> id
 */
export const useNotificationsStore = defineStore('notifications', () => {
    const toasts = ref([]);
    const timers = new Map();
    let sequence = 0;

    function remove(id) {
        const index = toasts.value.findIndex((toast) => toast.id === id);
        if (index !== -1) {
            toasts.value.splice(index, 1);
        }

        const timer = timers.get(id);
        if (timer) {
            clearTimeout(timer);
            timers.delete(id);
        }
    }

    function push(type, message, { timeout } = {}) {
        const id = ++sequence;
        const text = message instanceof Error ? message.message : String(message ?? '');
        const lifetime = timeout === undefined ? (TOAST_TIMEOUT[type] ?? TOAST_TIMEOUT.info) : timeout;

        toasts.value.push({ id, type, message: text });

        if (lifetime > 0) {
            timers.set(id, setTimeout(() => remove(id), lifetime));
        }

        return id;
    }

    function clear() {
        timers.forEach((timer) => clearTimeout(timer));
        timers.clear();
        toasts.value = [];
    }

    const success = (message, opts) => push('success', message, opts);
    const error = (message, opts) => push('error', message, opts);
    const info = (message, opts) => push('info', message, opts);

    return { toasts, push, remove, clear, success, error, info };
});

/**
 * Convenience helper usable from anywhere (components, stores, plain modules)
 * once Pinia is installed: notify.success('Saved'), notify.error(err).
 */
export const notify = {
    push: (type, message, opts) => useNotificationsStore().push(type, message, opts),
    success: (message, opts) => useNotificationsStore().success(message, opts),
    error: (message, opts) => useNotificationsStore().error(message, opts),
    info: (message, opts) => useNotificationsStore().info(message, opts),
    remove: (id) => useNotificationsStore().remove(id),
    clear: () => useNotificationsStore().clear(),
};
