import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { authService, tenantService } from '../services';
import { useReferenceStore } from './reference';
import { useSchoolYearsStore } from './schoolYears';
import { useStudentsStore } from './students';
import { usePacesStore } from './paces';
import { usePaceRequestsStore } from './paceRequests';
import { useTeachingStore } from './teaching';
import { useStudentChangeRequestsStore } from './studentChangeRequests';
import { useScopeStore } from './scope';

/**
 * Every record is private to one user, so cached lists must never survive a
 * change of session (logout, expiry, or signing in as somebody else).
 */
function resetDataStores() {
    useReferenceStore().reset();
    useSchoolYearsStore().reset();
    useStudentsStore().reset();
    usePacesStore().reset();
    usePaceRequestsStore().reset();
    useTeachingStore().reset();
    useStudentChangeRequestsStore().reset();
    useScopeStore().reset();
}

/**
 * Authentication state (Sanctum session).
 *
 *  user              ref<User|null>
 *  ready             ref<boolean>   true once bootstrap() has completed at least once
 *  loading           ref<boolean>   true while login/register/logout is in flight
 *  isAuthenticated   computed<boolean>
 *  isOwner / isPlatform / isAdmin / isTeacher   computed<boolean>   (isPlatform = owner or vice admin; isAdmin = school administrator)
 *  isSuspended       computed<boolean>  the member's organization was deactivated by the owner
 *  needsSetup        computed<boolean>  an admin without an organization, or a teacher not admitted yet
 *  organization      computed<Organization|null>
 *  subscription      computed<Subscription|null>
 *  canWrite          computed<boolean>  false while the organization's subscription is lapsed (read-only)
 *  userName          computed<string>
 *  bootstrap()       fetch /api/user; swallows 401 (and network errors) -> Promise<User|null>
 *  refresh()         re-fetch /api/user (after setup / join / subscription changes) -> Promise<User|null>
 *  login({email, password})                                        -> Promise<User>  (throws ApiError)
 *  register({first_name, middle_name?, last_name, suffix?, phone, address?, email, password, password_confirmation, role, terms})
 *                                                                  -> Promise<User>  (throws ApiError)
 *  forgotPassword(email)                                           -> Promise<void>  (throws ApiError)
 *  resetPassword({token, email, password, password_confirmation})  -> Promise<void>  (throws ApiError)
 *  verifyEmail({id, hash, expires, signature})                     -> Promise<string> message; refreshes the user when it is theirs
 *  resendVerification()                                            -> Promise<string> message (throws ApiError)
 *  setupOrganization(payload)                                      -> Promise<User>  (throws ApiError)
 *  submitJoinRequest(payload) / cancelJoinRequest()                -> Promise<User>  (throws ApiError)
 *  logout()          -> Promise<void>; always clears local user, rethrows non-401 failures
 *  updateProfile({first_name, middle_name, last_name, suffix, phone, address, email, current_password}) -> Promise<User>  (throws ApiError)
 *  updatePassword({current_password, password, password_confirmation}) -> Promise<void> (throws ApiError)
 *  setUser(user) / clear()
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const ready = ref(false);
    const loading = ref(false);
    let bootstrapping = null;

    const isAuthenticated = computed(() => user.value !== null);
    const isOwner = computed(() => Boolean(user.value?.isOwner));
    const isPlatform = computed(() => Boolean(user.value?.isPlatform));
    const isSuspended = computed(() => Boolean(user.value?.isSuspended));
    const isAdmin = computed(() => Boolean(user.value?.isAdmin));
    const isTeacher = computed(() => Boolean(user.value?.isTeacher));
    const needsSetup = computed(() => Boolean(user.value?.needsSetup));
    const organization = computed(() => user.value?.organization ?? null);
    const subscription = computed(() => user.value?.subscription ?? null);
    const canWrite = computed(() => Boolean(user.value?.canWrite));
    const userName = computed(() => user.value?.name ?? '');

    function setUser(value) {
        const previousId = user.value?.id ?? null;
        user.value = value ?? null;

        if (previousId !== null && previousId !== (user.value?.id ?? null)) {
            resetDataStores();
        }
    }

    function clear() {
        user.value = null;
        resetDataStores();
    }

    async function bootstrap() {
        if (ready.value) {
            return user.value;
        }

        if (!bootstrapping) {
            bootstrapping = (async () => {
                try {
                    setUser(await authService.me());
                } catch (err) {
                    clear();

                    if (!err?.isUnauthenticated) {
                        console.warn('Auth bootstrap failed:', err?.message ?? err);
                    }
                } finally {
                    ready.value = true;
                    bootstrapping = null;
                }

                return user.value;
            })();
        }

        return bootstrapping;
    }

    /** Re-read the signed-in user (membership / subscription may have changed server-side). */
    async function refresh() {
        try {
            setUser(await authService.me());
        } catch (err) {
            if (err?.isUnauthenticated) {
                clear();
            } else {
                throw err;
            }
        }

        return user.value;
    }

    async function login(credentials) {
        loading.value = true;

        try {
            const authenticated = await authService.login(credentials);
            setUser(authenticated);
            ready.value = true;
            return authenticated;
        } finally {
            loading.value = false;
        }
    }

    async function register(payload) {
        loading.value = true;

        try {
            const registered = await authService.register(payload);
            setUser(registered);
            ready.value = true;
            return registered;
        } finally {
            loading.value = false;
        }
    }

    async function forgotPassword(email) {
        await authService.forgotPassword(email);
    }

    async function resetPassword(payload) {
        await authService.resetPassword(payload);
    }

    async function verifyEmail(params) {
        const body = await authService.verifyEmail(params);

        if (user.value && Number(params.id) === user.value.id) {
            await refresh();
        }

        return body?.message ?? 'Your email address has been verified.';
    }

    async function resendVerification() {
        const body = await authService.resendVerification();
        return body?.message ?? 'A new verification link has been sent.';
    }

    async function setupOrganization(payload) {
        const updated = await tenantService.setupOrganization(payload);
        setUser(updated);
        return updated;
    }

    async function submitJoinRequest(payload) {
        await tenantService.submitJoinRequest(payload);
        return refresh();
    }

    async function cancelJoinRequest() {
        const request = user.value?.joinRequest;

        if (request?.id) {
            await tenantService.cancelJoinRequest(request.id);
        }

        return refresh();
    }

    async function logout() {
        loading.value = true;

        try {
            await authService.logout();
        } catch (err) {
            if (!err?.isUnauthenticated) {
                throw err;
            }
        } finally {
            clear();
            loading.value = false;
        }
    }

    async function updateProfile(payload) {
        const updated = await authService.updateProfile(payload);
        // PUT /profile answers with the bare user; keep the membership details we already have.
        if (user.value && updated) {
            updated.organization = updated.organization ?? user.value.organization;
            updated.branch = updated.branch ?? user.value.branch;
            updated.joinRequest = updated.joinRequest ?? user.value.joinRequest;
        }
        setUser(updated);
        return updated;
    }

    async function updatePassword(payload) {
        await authService.updatePassword(payload);
    }

    return {
        user,
        ready,
        loading,
        isAuthenticated,
        isOwner,
        isPlatform,
        isSuspended,
        isAdmin,
        isTeacher,
        needsSetup,
        organization,
        subscription,
        canWrite,
        userName,
        bootstrap,
        refresh,
        login,
        register,
        forgotPassword,
        resetPassword,
        verifyEmail,
        resendVerification,
        setupOrganization,
        submitJoinRequest,
        cancelJoinRequest,
        logout,
        updateProfile,
        updatePassword,
        setUser,
        clear,
    };
});
