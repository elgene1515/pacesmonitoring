<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useScopeStore } from '../../stores/scope';
import { useThemeStore } from '../../stores/theme';
import { notify } from '../../stores/notifications';
import { homeRouteFor } from '../../router';
import { APP_NAME } from '../../core/constants';
import { formatDate, pluralize } from '../../core/format';
import AppIcon from '../ui/AppIcon.vue';
import AppToasts from '../ui/AppToasts.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import StatusBadge from '../ui/StatusBadge.vue';

const auth = useAuthStore();
const scope = useScopeStore();
const theme = useThemeStore();
const router = useRouter();
const route = useRoute();
const loggingOut = ref(false);
const confirmingLogout = ref(false);

const showNav = computed(() => !route.meta.hideNav);

/*
 * Small screens: the tabs, the signed-in member and the buttons move into a
 * hamburger menu. COMPACT_QUERY must match the max-width in the styles below.
 */
const COMPACT_QUERY = '(max-width: 1120px)';
const menuOpen = ref(false);

function closeMenu() {
    menuOpen.value = false;
}

function toggleMenu() {
    menuOpen.value = !menuOpen.value;
}

/*
 * Wide screens: the account menu is a dropdown under the member's name. Inside
 * the hamburger panel it is laid out inline instead (see the styles), so the
 * open state only matters on wide screens.
 */
const userRef = ref(null);
const userMenuOpen = ref(false);

function closeUserMenu() {
    userMenuOpen.value = false;
}

function toggleUserMenu() {
    userMenuOpen.value = !userMenuOpen.value;
}

function onDocumentClick(event) {
    if (userMenuOpen.value && userRef.value && !userRef.value.contains(event.target)) {
        closeUserMenu();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        closeMenu();
        closeUserMenu();
    }
}

/** Leaving the compact layout (e.g. rotating a tablet) must not leave the menu flagged open. */
function onLayoutChange(event) {
    if (!event.matches) {
        closeMenu();
    }
}

let compactMedia = null;

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('click', onDocumentClick);

    if (typeof window.matchMedia === 'function') {
        compactMedia = window.matchMedia(COMPACT_QUERY);
        compactMedia.addEventListener('change', onLayoutChange);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('click', onDocumentClick);
    compactMedia?.removeEventListener('change', onLayoutChange);
});

// Going to another page (a tab, the brand, a link in a page) closes the menus.
watch(() => route.fullPath, () => {
    closeMenu();
    closeUserMenu();
});

const teacherNavLinks = [
    { path: '/', label: 'Home' },
    { path: '/students', label: 'Students' },
    { path: '/paces', label: 'PACEs' },
    { path: '/grade-levels', label: 'Grade levels' },
    { path: '/school-years', label: 'School years' },
];

const adminNavLinks = [
    { path: '/admin', label: 'Home' },
    { path: '/admin/users', label: 'Users' },
    { path: '/admin/students', label: 'Students' },
    { path: '/admin/pace-requests', label: 'PACE requests', badge: 'pendingPaceRequests' },
    { path: '/admin/school-years', label: 'School years' },
    { path: '/admin/organization', label: 'Organization', badge: 'pendingJoinRequests' },
];

const ownerNavLinks = [
    { path: '/owner', label: 'Home' },
    { path: '/owner/organizations', label: 'Organizations' },
    { path: '/admin', label: 'Analytics' },
    { path: '/admin/users', label: 'Users' },
    { path: '/admin/students', label: 'Students' },
    { path: '/admin/pace-requests', label: 'PACE requests' },
    { path: '/owner/plans', label: 'Plans' },
    { path: '/owner/staff', label: 'Staff', ownerOnly: true },
];

const navLinks = computed(() => {
    if (!auth.user || auth.needsSetup) {
        return [];
    }

    if (auth.isPlatform) {
        return ownerNavLinks.filter((link) => !link.ownerOnly || auth.isOwner);
    }

    return auth.isAdmin ? adminNavLinks : teacherNavLinks;
});

const brandTarget = computed(() => homeRouteFor(auth.user));

/**
 * Counts shown as badges on the admin's tabs: teachers waiting to join
 * (Organization) and PACE stock requests waiting for an answer (PACE requests).
 */
const badges = computed(() => ({
    pendingJoinRequests: {
        count: auth.organization?.pendingJoinRequestsCount ?? 0,
        noun: 'pending join request',
    },
    pendingPaceRequests: {
        count: auth.organization?.pendingPaceRequestsCount ?? 0,
        noun: 'pending PACE request',
    },
}));

function badgeFor(link) {
    const badge = link.badge ? badges.value[link.badge] : null;

    return badge && badge.count > 0 ? badge : null;
}

/** Waiting items behind the tabs: a dot on the hamburger tells there is something inside. */
const badgeTotal = computed(() => navLinks.value.reduce((sum, link) => sum + (badgeFor(link)?.count ?? 0), 0));

/** The signed-in member's school and branch, under their name. */
const membership = computed(() => {
    if (!auth.user || auth.isPlatform || !auth.user.hasOrganization) {
        return '';
    }

    return [auth.user.organizationName, auth.user.branchLabel].filter(Boolean).join(' · ');
});

/** Who the signed-in member is beyond their name: the school and branch, or the platform role. */
const identityLine = computed(() => membership.value || (auth.isPlatform ? auth.user.roleLabel : ''));

/* A school admin's plan and school code (teachers and platform staff do not get them here). */
const planName = computed(() => (auth.isAdmin ? (auth.subscription?.plan?.name ?? '') : ''));
const schoolCode = computed(() => (auth.isAdmin ? (auth.organization?.code ?? '') : ''));
const showSchool = computed(() => auth.isAdmin && Boolean(auth.organization));

/** Second line under the name in the bar: a school admin sees their plan first, then the school. */
const subtitle = computed(() => (showSchool.value
    ? [planName.value ? `${planName.value} plan` : 'No plan', membership.value].filter(Boolean).join(' · ')
    : identityLine.value));

const initials = computed(() => auth.user?.initials || '?');

const codeCopied = ref(false);
let codeCopiedTimer = null;

async function copySchoolCode() {
    try {
        await navigator.clipboard.writeText(schoolCode.value);
        codeCopied.value = true;
        clearTimeout(codeCopiedTimer);
        codeCopiedTimer = setTimeout(() => {
            codeCopied.value = false;
        }, 2000);
    } catch {
        notify.info(`School code: ${schoolCode.value}`);
    }
}

/** True on the admin pages the owner may narrow to one organization. */
const showScopeBar = computed(() => auth.isPlatform && route.path.startsWith('/admin'));

/**
 * Subscription notice for members of an organization: trial / expiring soon /
 * past due / read-only. Nothing while everything is in order.
 */
const subscriptionNotice = computed(() => {
    const subscription = auth.subscription;

    if (!auth.user || auth.isPlatform || auth.needsSetup) {
        return null;
    }

    if (!subscription) {
        return {
            tone: 'bad',
            text: 'Your organization has no subscription. Records can be viewed but not changed until one is set up.',
        };
    }

    if (subscription.isReadOnly) {
        return {
            tone: 'bad',
            text: `Your organization's subscription is ${subscription.statusLabel.toLowerCase()}. Records can be viewed but not changed until it is renewed.`,
        };
    }

    if (subscription.isPastDue) {
        return {
            tone: 'warn',
            text: 'Your organization\'s subscription payment is overdue. Please settle it to keep full access.',
        };
    }

    if (subscription.isTrial) {
        const days = subscription.daysLeft;
        const left = days === null ? '' : days <= 0 ? ' and ends today' : ` · ${pluralize(days, 'day')} left`;
        return {
            tone: 'info',
            text: `Your organization is on a trial of the ${subscription.plan?.name ?? ''} plan${left}.`,
        };
    }

    if (subscription.expiringSoon) {
        return {
            tone: 'warn',
            text: `Your organization's subscription ends on ${formatDate(subscription.endsAt)} (${pluralize(subscription.daysLeft ?? 0, 'day')} left).`,
        };
    }

    return null;
});

function isActive(link) {
    // Exact-match only: these are "root" links that would otherwise prefix-match
    // every other tab's path too (e.g. "/admin" matching "/admin/users").
    if (link.path === '/' || link.path === '/admin' || link.path === '/owner') {
        return route.path === link.path;
    }

    return route.path === link.path || route.path.startsWith(`${link.path}/`);
}

/** The admin pages watch the scope and reload their lists themselves. */
function clearScope() {
    scope.clearScope();
}

function askLogout() {
    closeMenu();
    closeUserMenu();
    confirmingLogout.value = true;
}

async function logout() {
    if (loggingOut.value) {
        return;
    }

    loggingOut.value = true;

    try {
        await auth.logout();
        confirmingLogout.value = false;
        await router.push({ name: 'login' });
    } catch (err) {
        notify.error(err?.message ?? 'Unable to sign out.');
    } finally {
        loggingOut.value = false;
    }
}
</script>

<template>
    <div class="app">
        <header v-if="showNav" class="topbar">
            <div class="topbar-inner">
                <RouterLink :to="brandTarget" class="brand">
                    {{ APP_NAME }}
                </RouterLink>

                <template v-if="auth.isAuthenticated">
                    <button
                        type="button"
                        class="menu-toggle"
                        :class="{ open: menuOpen }"
                        aria-controls="main-menu"
                        :aria-expanded="menuOpen"
                        :aria-label="menuOpen ? 'Close menu' : 'Open menu'"
                        @click="toggleMenu"
                    >
                        <span class="menu-toggle-bar" aria-hidden="true"></span>
                        <span v-if="!menuOpen && badgeTotal > 0" class="menu-toggle-dot" aria-hidden="true"></span>
                    </button>

                    <div id="main-menu" class="menu" :class="{ open: menuOpen }">
                        <nav class="nav" aria-label="Main">
                            <RouterLink
                                v-for="link in navLinks"
                                :key="link.path"
                                :to="link.path"
                                class="nav-link"
                                :class="{ active: isActive(link) }"
                                active-class=""
                                exact-active-class=""
                            >
                                {{ link.label }}
                                <span
                                    v-if="badgeFor(link)"
                                    class="nav-badge"
                                    :title="pluralize(badgeFor(link).count, badgeFor(link).noun)"
                                >
                                    {{ badgeFor(link).count }}
                                </span>
                            </RouterLink>
                        </nav>

                        <!-- Account menu: who is signed in, Settings (teachers), the theme and Sign out. -->
                        <div ref="userRef" class="user" :class="{ open: userMenuOpen }">
                            <button
                                type="button"
                                class="user-trigger"
                                aria-haspopup="menu"
                                aria-controls="user-menu"
                                :aria-expanded="userMenuOpen"
                                :title="auth.user?.email"
                                @click="toggleUserMenu"
                            >
                                <span class="avatar" aria-hidden="true">{{ initials }}</span>
                                <span class="user-block">
                                    <span class="user-name">{{ auth.userName }}</span>
                                    <span v-if="subtitle" class="user-org">{{ subtitle }}</span>
                                </span>
                                <AppIcon name="chevron-down" class="user-chevron" />
                            </button>

                            <div id="user-menu" class="user-menu" role="menu" aria-label="Account">
                                <!-- The signed-in member: opens My account (own details, name / email / password). -->
                                <RouterLink
                                    :to="{ name: 'settings' }"
                                    class="user-menu-header"
                                    role="menuitem"
                                    title="My account"
                                    @click="closeUserMenu(); closeMenu();"
                                >
                                    <span class="avatar avatar-lg" aria-hidden="true">{{ initials }}</span>
                                    <span class="user-menu-identity">
                                        <span class="user-menu-name">{{ auth.userName }}</span>
                                        <span class="user-menu-detail">{{ auth.user?.email }}</span>
                                        <span v-if="identityLine" class="user-menu-detail">{{ identityLine }}</span>
                                    </span>
                                </RouterLink>

                                <!-- School admins: the school's plan and the code teachers use to join it. -->
                                <div v-if="showSchool" class="user-menu-school">
                                    <div class="school-row">
                                        <span class="user-menu-label">Plan</span>
                                        <span class="school-value">
                                            {{ planName || 'No plan' }}
                                            <StatusBadge v-if="auth.subscription" :tone="auth.subscription.tone">{{ auth.subscription.statusLabel }}</StatusBadge>
                                        </span>
                                    </div>
                                    <div class="school-row">
                                        <span class="user-menu-label">School code</span>
                                        <span class="school-value">
                                            <span class="mono school-code">{{ schoolCode }}</span>
                                            <button
                                                type="button"
                                                class="copy-code"
                                                :class="{ copied: codeCopied }"
                                                :title="codeCopied ? 'Copied to the clipboard' : 'Copy the school code'"
                                                :aria-label="codeCopied ? 'School code copied' : 'Copy school code'"
                                                @click="copySchoolCode"
                                            >
                                                <AppIcon :name="codeCopied ? 'check' : 'copy'" />
                                                {{ codeCopied ? 'Copied' : 'Copy' }}
                                            </button>
                                        </span>
                                    </div>
                                </div>

                                <div class="user-menu-section">
                                    <span class="user-menu-label">Theme</span>
                                    <div class="theme-options" role="radiogroup" aria-label="Theme">
                                        <button
                                            v-for="option in theme.options"
                                            :key="option.value"
                                            type="button"
                                            class="theme-option"
                                            :class="{ active: theme.preference === option.value }"
                                            role="radio"
                                            :aria-checked="theme.preference === option.value"
                                            @click="theme.set(option.value)"
                                        >
                                            <AppIcon :name="option.icon" />
                                            <span class="theme-option-label">{{ option.label }}</span>
                                            <AppIcon v-if="theme.preference === option.value" name="check" class="theme-check" />
                                        </button>
                                    </div>
                                </div>

                                <button type="button" class="user-menu-item danger" role="menuitem" :disabled="loggingOut" @click="askLogout">
                                    <AppIcon name="logout" />
                                    {{ loggingOut ? 'Signing out…' : 'Sign out' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Tapping outside the open menu closes it (small screens only). -->
            <div v-if="menuOpen" class="menu-backdrop" @click="closeMenu"></div>
        </header>

        <!-- Owner: which organization the admin pages are showing. -->
        <div v-if="showNav && showScopeBar" class="scope-bar" :class="{ scoped: scope.isScoped }">
            <div class="scope-inner">
                <template v-if="scope.isScoped">
                    <span>
                        Viewing <strong>{{ scope.organizationName || `organization #${scope.organizationId}` }}</strong>
                    </span>
                    <RouterLink :to="{ name: 'owner-organization-detail', params: { id: scope.organizationId } }" class="scope-link">
                        Organization page
                    </RouterLink>
                    <button type="button" class="btn btn-link btn-sm" @click="clearScope">Show every organization</button>
                </template>
                <template v-else>
                    <span>Viewing <strong>every organization</strong>.</span>
                    <RouterLink :to="{ name: 'owner-organizations' }" class="scope-link">Pick one to narrow the view</RouterLink>
                </template>
            </div>
        </div>

        <!-- Members: the state of the organization's subscription when it needs attention. -->
        <div v-if="showNav && subscriptionNotice" class="notice" :class="subscriptionNotice.tone" role="status">
            <div class="notice-inner">
                <span>{{ subscriptionNotice.text }}</span>
                <RouterLink v-if="auth.isAdmin" :to="{ name: 'admin-organization' }" class="notice-link">Subscription details</RouterLink>
            </div>
        </div>

        <main class="page">
            <RouterView />
        </main>

        <AppToasts />

        <ConfirmDialog
            v-model="confirmingLogout"
            title="Sign out"
            confirm-text="Sign out"
            :danger="false"
            :busy="loggingOut"
            @confirm="logout"
        >
            Are you sure you want to sign out?
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.app {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Stays pinned to the top of the viewport while the page scrolls. */
.topbar {
    position: sticky;
    top: 0;
    z-index: 30;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.topbar-inner {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 24px;
    height: 56px;
    display: flex;
    align-items: center;
    gap: 24px;
}

.brand {
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
}

.brand:hover {
    text-decoration: none;
    color: var(--primary);
}

/* Wide screens: the menu is just the row of tabs and the member on the right. */
.menu {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 24px;
}

.menu-toggle,
.menu-backdrop {
    display: none;
}

.nav {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 1;
}

.nav-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: var(--radius);
    color: var(--muted);
    white-space: nowrap;
}

.nav-link:hover {
    color: var(--text);
    background: var(--bg);
    text-decoration: none;
}

.nav-link.active {
    color: var(--primary);
    background: rgba(var(--primary-rgb), 0.08);
}

.nav-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--danger);
    color: var(--on-danger);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
}

/* Account menu (wide screens: a dropdown under the member's name). */
.user {
    position: relative;
    margin-left: auto;
}

.user-trigger {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    max-width: 320px;
    padding: 4px 10px 4px 4px;
    border: 1px solid transparent;
    border-radius: 999px;
    background: transparent;
    color: var(--text);
    cursor: pointer;
    text-align: left;
}

.user-trigger:hover,
.user.open .user-trigger {
    background: var(--bg);
    border-color: var(--border);
}

.user-trigger:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(var(--primary-rgb), 0.14);
    color: var(--primary);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.avatar-lg {
    width: 40px;
    height: 40px;
    font-size: 0.95rem;
}

.user-block {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.2;
}

.user-name {
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-org {
    color: var(--muted);
    font-size: 0.8rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-chevron {
    flex-shrink: 0;
    width: 14px;
    height: 14px;
    color: var(--muted);
    transition: transform 0.15s ease;
}

.user.open .user-chevron {
    transform: rotate(180deg);
}

.user-menu {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 3;
    width: 300px;
    padding: 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.14);
}

.user.open .user-menu {
    display: block;
}

.user-menu-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
    padding: 10px 10px 12px;
    border-bottom: 1px solid var(--border);
    border-radius: var(--radius) var(--radius) 0 0;
    color: var(--text);
}

.user-menu-header:hover,
.user-menu-header:focus-visible {
    background: var(--bg);
    color: var(--text);
    text-decoration: none;
}

.user-menu-identity {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.25;
}

.user-menu-name {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-menu-detail {
    color: var(--muted);
    font-size: 0.82rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Plan and school code of a school admin. */
.user-menu-school {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0 0 6px;
    padding: 0 10px 12px;
    border-bottom: 1px solid var(--border);
}

.school-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.school-row .user-menu-label {
    margin: 0;
    white-space: nowrap;
}

.school-value {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    font-weight: 500;
}

.school-code {
    font-weight: 600;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.copy-code {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    cursor: pointer;
}

.copy-code:hover {
    background: rgba(var(--primary-rgb), 0.08);
    border-color: rgba(var(--primary-rgb), 0.35);
}

.copy-code:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.copy-code.copied {
    color: var(--success);
    border-color: rgba(var(--success-rgb), 0.35);
}

.copy-code .app-icon {
    width: 13px;
    height: 13px;
}

.user-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px 10px;
    border: none;
    border-radius: var(--radius);
    background: transparent;
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.user-menu-item:hover {
    background: var(--bg);
    color: var(--text);
    text-decoration: none;
}

.user-menu-item:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.user-menu-item .app-icon {
    width: 16px;
    height: 16px;
    color: var(--muted);
}

.user-menu-item.danger {
    color: var(--danger);
}

.user-menu-item.danger .app-icon {
    color: inherit;
}

.user-menu-item.danger:hover {
    background: rgba(var(--danger-rgb), 0.08);
}

.user-menu-section {
    margin: 6px 0;
    padding: 8px 10px 10px;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.user-menu-label {
    display: block;
    margin-bottom: 6px;
    font-size: 0.78rem;
    color: var(--muted);
}

.theme-options {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.theme-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 7px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius);
    background: transparent;
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.theme-option:hover {
    background: var(--bg);
}

.theme-option.active {
    background: rgba(var(--primary-rgb), 0.08);
    border-color: rgba(var(--primary-rgb), 0.35);
    color: var(--primary);
}

.theme-option .app-icon {
    width: 16px;
    height: 16px;
}

.theme-option-label {
    flex: 1;
}

.theme-check {
    color: var(--primary);
}

.scope-bar {
    background: rgba(var(--primary-rgb), 0.06);
    border-bottom: 1px solid rgba(var(--primary-rgb), 0.2);
    font-size: 0.92rem;
}

.scope-bar.scoped {
    background: rgba(var(--primary-rgb), 0.1);
}

.scope-inner,
.notice-inner {
    max-width: 1100px;
    margin: 0 auto;
    padding: 6px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.scope-link,
.notice-link {
    font-weight: 500;
}

.notice {
    font-size: 0.92rem;
    border-bottom: 1px solid var(--border);
}

.notice.info {
    background: rgba(var(--primary-rgb), 0.06);
    color: var(--text);
}

.notice.warn {
    background: rgba(var(--warn-rgb), 0.1);
    color: var(--warn-text);
}

.notice.bad {
    background: rgba(var(--danger-rgb), 0.08);
    color: var(--danger);
}

main.page {
    flex: 1;
    width: 100%;
}

/*
 * Small screens (keep the max-width in step with COMPACT_QUERY in the script):
 * the brand and a hamburger button stay in the bar; the tabs, the member and
 * the buttons drop down in a panel under it.
 */
@media (max-width: 1120px) {
    .topbar-inner {
        justify-content: space-between;
    }

    .menu-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        margin-right: -8px;
        padding: 0;
        border: 1px solid transparent;
        border-radius: var(--radius);
        background: transparent;
        color: var(--text);
        cursor: pointer;
    }

    .menu-toggle:hover {
        background: var(--bg);
    }

    .menu-toggle:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Three bars (the middle one is the element, the others its pseudo-elements) that turn into a cross. */
    .menu-toggle-bar,
    .menu-toggle-bar::before,
    .menu-toggle-bar::after {
        display: block;
        width: 20px;
        height: 2px;
        border-radius: 1px;
        background: currentColor;
        transition: transform 0.15s ease, background-color 0.15s ease;
    }

    .menu-toggle-bar {
        position: relative;
    }

    .menu-toggle-bar::before,
    .menu-toggle-bar::after {
        content: '';
        position: absolute;
        left: 0;
    }

    .menu-toggle-bar::before {
        top: -6px;
    }

    .menu-toggle-bar::after {
        top: 6px;
    }

    .menu-toggle.open .menu-toggle-bar {
        background: transparent;
    }

    .menu-toggle.open .menu-toggle-bar::before {
        transform: translateY(6px) rotate(45deg);
    }

    .menu-toggle.open .menu-toggle-bar::after {
        transform: translateY(-6px) rotate(-45deg);
    }

    .menu-toggle-dot {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 9px;
        height: 9px;
        border: 2px solid var(--surface);
        border-radius: 50%;
        background: var(--danger);
    }

    .menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 2;
        flex: none;
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        max-height: calc(100vh - var(--nav-height));
        overflow-y: auto;
        padding: 8px 16px 16px;
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
    }

    .menu.open {
        display: flex;
    }

    .menu-backdrop {
        display: block;
        position: fixed;
        top: var(--nav-height);
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 1;
        background: var(--backdrop);
    }

    .nav {
        flex: none;
        flex-direction: column;
        align-items: stretch;
        gap: 2px;
    }

    .nav-link {
        justify-content: space-between;
        padding: 10px 12px;
    }

    /* Inside the panel the account menu is always open and laid out inline. */
    .user {
        margin-left: 0;
        padding-top: 12px;
        border-top: 1px solid var(--border);
    }

    .user-trigger {
        display: none;
    }

    .user-menu {
        display: block;
        position: static;
        width: auto;
        padding: 0;
        border: none;
        box-shadow: none;
    }

    .user-menu-header {
        padding: 6px 8px 12px;
        margin: 0 -8px 6px;
        border-radius: var(--radius);
    }

    .user-menu-item,
    .theme-option {
        padding-top: 10px;
        padding-bottom: 10px;
    }
}

@media (max-width: 640px) {
    .topbar-inner {
        padding: 0 16px;
    }

    .scope-inner,
    .notice-inner {
        padding: 6px 16px;
    }
}
</style>
