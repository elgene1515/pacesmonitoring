<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { homeRouteFor } from '../../../router';

/**
 * Shown to members of a school the owner has deactivated. Nothing is
 * deleted; access comes back as soon as the owner restores the school.
 */
const auth = useAuthStore();
const router = useRouter();
const checking = ref(false);

async function check() {
    checking.value = true;

    try {
        const user = await auth.refresh();

        if (user && !user.isSuspended) {
            notify.success('Your school is active again.');
            await router.replace(homeRouteFor(user));
        } else {
            notify.info('Your school is still deactivated.');
        }
    } catch (err) {
        notify.error(err?.message ?? 'Unable to check right now.');
    } finally {
        checking.value = false;
    }
}
</script>

<template>
    <div class="setup">
        <div class="card setup-card">
            <h1>Your school has been deactivated</h1>
            <p class="muted intro">
                Hi {{ auth.user?.firstName }}. Access to your school's pages is paused because the system provider has
                deactivated the school's account. Nothing has been deleted: students, PACEs and records come back as
                soon as the school is reactivated. Please get in touch with your school administrator or the provider.
            </p>
            <div class="actions">
                <button type="button" class="btn" :disabled="checking" @click="check">
                    {{ checking ? 'Checking…' : 'Check again' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.setup {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.setup-card {
    width: 100%;
    max-width: 560px;
    padding: 32px;
}

.intro {
    margin: 10px 0 20px;
}

.actions {
    display: flex;
    gap: 8px;
}
</style>
