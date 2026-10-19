<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { adminService, ownerService } from '../../../services';
import { SEX_OPTIONS } from '../../../models';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { formatDate, formatScore, pluralize } from '../../../core/format';
import AppField from '../../ui/AppField.vue';
import PasswordField from '../../ui/PasswordField.vue';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import PaceRecordsBar from '../../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../../charts/PaceRecordsLegend.vue';

/** Bar segments and legend read tested / issued / assigned, left to right (same as the teacher pages). */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const props = defineProps({
    id: { type: Number, required: true },
});

const auth = useAuthStore();
const router = useRouter();

const user = ref(null);
const loading = ref(true);
const loadError = ref('');

const isSelf = computed(() => Boolean(user.value) && auth.user?.id === user.value.id);

/* ---------- Membership: branch, role, removal ---------- */

const branches = ref([]);
const branchId = ref('');
const movingBranch = ref(false);
const roleValue = ref('');
const savingRole = ref(false);
const confirmingRemoval = ref(false);
const removing = ref(false);

/** Branches of the member's own organization (the owner's branch list may span several). */
const memberBranches = computed(() => branches.value.filter((branch) => Number(branch.organizationId) === Number(user.value?.organizationId)));

const canChangeRole = computed(() => auth.isPlatform && Boolean(user.value) && !user.value.isOwner && user.value.hasOrganization);
const canRemove = computed(() => Boolean(user.value) && user.value.isTeacher && user.value.hasOrganization && !isSelf.value);

async function loadBranches() {
    if (!user.value?.hasOrganization) {
        branches.value = [];
        return;
    }

    try {
        branches.value = await adminService.listBranches();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load branches.');
    }
}

async function moveBranch() {
    if (movingBranch.value || !branchId.value || Number(branchId.value) === Number(user.value?.branchId)) {
        return;
    }

    movingBranch.value = true;

    try {
        user.value = await adminService.moveUserToBranch(props.id, Number(branchId.value));
        notify.success(`${user.value.name} moved to ${user.value.branchName}.`);

        if (isSelf.value) {
            auth.refresh().catch(() => {});
        }
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('branch_id') ?? err.message) : (err?.message ?? 'Unable to move the member.'));
        branchId.value = user.value?.branchId ?? '';
    } finally {
        movingBranch.value = false;
    }
}

async function saveRole() {
    if (savingRole.value || !roleValue.value || roleValue.value === user.value?.role) {
        return;
    }

    savingRole.value = true;

    try {
        user.value = await ownerService.updateUserRole(props.id, roleValue.value);
        notify.success(`${user.value.name} is now ${user.value.roleLabel.toLowerCase() === 'admin' ? 'an administrator' : 'a teacher'}.`);
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('role') ?? err.message) : (err?.message ?? 'Unable to change the role.'));
        roleValue.value = user.value?.role ?? '';
    } finally {
        savingRole.value = false;
    }
}

async function removeMember() {
    removing.value = true;

    try {
        await adminService.removeMember(props.id);
        notify.success(`${user.value.name} was removed from the organization.`);
        confirmingRemoval.value = false;
        auth.refresh().catch(() => {});
        await router.push({ name: 'admin-users' });
    } catch (err) {
        notify.error(err?.message ?? 'Unable to remove the member.');
    } finally {
        removing.value = false;
    }
}

const profileForm = reactive({ name: '', email: '', admin_password: '' });
const profileErrors = ref({});
const savingProfile = ref(false);

const passwordForm = reactive({ password: '', password_confirmation: '', admin_password: '', current_password: '' });
const passwordErrors = ref({});
const savingPassword = ref(false);

const TABS = [
    { key: 'profile', label: 'Profile' },
    { key: 'grade-levels', label: 'Grade levels' },
    { key: 'students', label: 'Students' },
    { key: 'paces', label: 'PACEs' },
];
const activeTab = ref('profile');
const tabLoading = ref(false);

const gradeLevels = ref([]);
const gradeLevelsLoaded = ref(false);
const teacherStudents = ref([]);
const studentsLoaded = ref(false);
const teacherPaces = ref([]);
const pacesLoaded = ref(false);

/* ---------- Grade levels tab: search by grade level / subject name, narrow to one school year ---------- */

const gradeLevelSearch = ref('');
const gradeLevelSchoolYearId = ref('');

const gradeLevelSearchTerm = computed(() => gradeLevelSearch.value.trim().toLowerCase());

const gradeLevelHasFilters = computed(() => gradeLevelSearchTerm.value !== '' || gradeLevelSchoolYearId.value !== '');

/** School years present among this teacher's grade levels, newest first. */
const gradeLevelYearOptions = computed(() => {
    const map = new Map();

    for (const level of gradeLevels.value) {
        const key = level.schoolYearId ?? 'none';

        if (!map.has(key)) {
            map.set(key, { value: key, label: level.schoolYearName || 'No school year' });
        }
    }

    return [...map.values()].sort((a, b) => String(b.label).localeCompare(String(a.label)));
});

function matchesGradeLevelSearch(text) {
    return String(text ?? '').toLowerCase().includes(gradeLevelSearchTerm.value);
}

/**
 * Subjects to show for a level: all of them, unless a search is active and only
 * some subjects match (the level itself does not), in which case just those.
 */
const visibleSubjectsByLevel = computed(() => {
    const map = new Map();

    for (const level of gradeLevels.value) {
        const key = level.schoolYearId ?? 'none';

        if (gradeLevelSchoolYearId.value && String(key) !== String(gradeLevelSchoolYearId.value)) {
            continue;
        }

        if (!gradeLevelSearchTerm.value || matchesGradeLevelSearch(level.name)) {
            map.set(level.id, level.subjects);
            continue;
        }

        const matching = level.subjects.filter((subject) => matchesGradeLevelSearch(subject.name));

        if (matching.length > 0) {
            map.set(level.id, matching);
        }
    }

    return map;
});

const filteredGradeLevels = computed(() => gradeLevels.value.filter((level) => visibleSubjectsByLevel.value.has(level.id)));

function visibleSubjects(level) {
    return visibleSubjectsByLevel.value.get(level.id) ?? level.subjects;
}

/** Grade levels grouped by school year (newest first), mirroring the teacher's own view. */
const gradeLevelGroups = computed(() => {
    const map = new Map();

    for (const level of filteredGradeLevels.value) {
        const key = level.schoolYearId ?? 'none';

        if (!map.has(key)) {
            map.set(key, { key, name: level.schoolYearName || 'No school year', levels: [] });
        }

        map.get(key).levels.push(level);
    }

    return [...map.values()];
});

function levelMeta(level) {
    return `${pluralize(level.subjects.length, 'subject')} · ${pluralize(level.studentsCount ?? 0, 'student')}`;
}

function clearGradeLevelFilters() {
    gradeLevelSearch.value = '';
    gradeLevelSchoolYearId.value = '';
}

/* ---------- Students tab: search by name, narrow by school year then (cascading) grade level ---------- */

const studentSearch = ref('');
const studentSchoolYears = ref([]);
const studentSchoolYearId = ref('');
const loadingStudentSchoolYears = ref(false);
const studentGradeLevels = ref([]);
const studentGradeLevelId = ref('');
const loadingStudentGradeLevels = ref(false);

/** '' (any) | 'male' | 'female': narrows the loaded students by sex. */
const studentSexFilter = ref('');
/** Average-score range (0–100, either end optional); students without a score drop out once a bound is set. */
const studentScoreMin = ref('');
const studentScoreMax = ref('');

/** The score bounds as numbers (null when blank), swapped when entered back to front. */
const studentScoreRange = computed(() => {
    const parse = (value) => {
        const text = String(value ?? '').trim();
        const number = Number(text);

        return text === '' || Number.isNaN(number) ? null : Math.min(100, Math.max(0, number));
    };

    let min = parse(studentScoreMin.value);
    let max = parse(studentScoreMax.value);

    if (min !== null && max !== null && min > max) {
        [min, max] = [max, min];
    }

    return { min, max };
});

const studentHasScoreRange = computed(() => studentScoreRange.value.min !== null || studentScoreRange.value.max !== null);

const studentHasFilters = computed(() => (
    studentSearch.value.trim() !== ''
    || studentSchoolYearId.value !== ''
    || studentGradeLevelId.value !== ''
    || studentSexFilter.value !== ''
    || studentHasScoreRange.value
));

/** The loaded students narrowed by the sex and score-range filters (the rest are filtered by the server). */
const visibleStudents = computed(() => {
    let list = teacherStudents.value;

    if (studentSexFilter.value !== '') {
        list = list.filter((student) => student.sex === studentSexFilter.value);
    }

    const { min, max } = studentScoreRange.value;

    if (min !== null || max !== null) {
        list = list.filter((student) => {
            const score = student.averageScore;

            return score !== null && score !== undefined
                && (min === null || score >= min)
                && (max === null || score <= max);
        });
    }

    return list;
});
const studentsEmptyMessage = computed(() => (
    studentHasFilters.value ? 'No students match your filters.' : 'This teacher has no students yet.'
));

async function loadStudents() {
    tabLoading.value = true;

    try {
        teacherStudents.value = await adminService.listStudents({
            teacher_id: props.id,
            search: studentSearch.value,
            school_year_id: studentSchoolYearId.value,
            grade_level_id: studentGradeLevelId.value,
        });
        studentsLoaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load students.');
    } finally {
        tabLoading.value = false;
    }
}

async function loadStudentSchoolYears() {
    loadingStudentSchoolYears.value = true;

    try {
        studentSchoolYears.value = await adminService.listSchoolYears(props.id);
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load school years.');
    } finally {
        loadingStudentSchoolYears.value = false;
    }
}

/** Changing the school year drops the grade level filter and loads that year's grade levels. */
async function onStudentSchoolYearChange() {
    studentGradeLevelId.value = '';
    studentGradeLevels.value = [];

    if (studentSchoolYearId.value) {
        loadingStudentGradeLevels.value = true;

        try {
            studentGradeLevels.value = await adminService.listGradeLevels(props.id, studentSchoolYearId.value);
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load grade levels.');
        } finally {
            loadingStudentGradeLevels.value = false;
        }
    }

    loadStudents();
}

function clearStudentFilters() {
    studentSearch.value = '';
    studentSchoolYearId.value = '';
    studentGradeLevels.value = [];
    studentGradeLevelId.value = '';
    studentSexFilter.value = '';
    studentScoreMin.value = '';
    studentScoreMax.value = '';
    loadStudents();
}

let studentSearchTimer = null;

watch(studentSearch, () => {
    clearTimeout(studentSearchTimer);
    studentSearchTimer = setTimeout(loadStudents, 300);
});

/* ---------- PACEs tab: search by name or number, narrow by whether a PACE has records ---------- */

const paceSearch = ref('');

/** '' (all) | 'with' | 'without': narrows the loaded list by whether a PACE has records. */
const paceRecordsFilter = ref('');

const PACE_RECORDS_OPTIONS = [
    { value: '', label: 'All PACEs' },
    { value: 'with', label: 'With records' },
    { value: 'without', label: 'Without records' },
];

/** '' (any) | 'in' | 'low' | 'out': narrows the loaded list by stock level. */
const paceStockFilter = ref('');

const PACE_STOCK_OPTIONS = [
    { value: '', label: 'Any stock' },
    { value: 'in', label: 'In stock' },
    { value: 'low', label: 'Low stock' },
    { value: 'out', label: 'Out of stock' },
];

const paceHasFilters = computed(() => paceSearch.value.trim() !== '' || paceRecordsFilter.value !== '' || paceStockFilter.value !== '');

const visiblePaces = computed(() => {
    let list = teacherPaces.value;

    if (paceRecordsFilter.value === 'with') {
        list = list.filter((pace) => (pace.assignmentsCount ?? 0) > 0);
    } else if (paceRecordsFilter.value === 'without') {
        list = list.filter((pace) => (pace.assignmentsCount ?? 0) === 0);
    }

    if (paceStockFilter.value === 'in') {
        list = list.filter((pace) => !pace.isOutOfStock);
    } else if (paceStockFilter.value === 'low') {
        list = list.filter((pace) => pace.isLowStock);
    } else if (paceStockFilter.value === 'out') {
        list = list.filter((pace) => pace.isOutOfStock);
    }

    return list;
});

const pacesEmptyMessage = computed(() => (paceHasFilters.value ? 'No PACEs match your filters.' : 'This teacher has no PACEs yet.'));

async function loadPaces() {
    tabLoading.value = true;

    try {
        teacherPaces.value = await adminService.listPaces({ teacher_id: props.id, search: paceSearch.value });
        pacesLoaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load PACEs.');
    } finally {
        tabLoading.value = false;
    }
}

let paceSearchTimer = null;

watch(paceSearch, () => {
    clearTimeout(paceSearchTimer);
    paceSearchTimer = setTimeout(loadPaces, 300);
});

function clearPaceFilters() {
    paceSearch.value = '';
    paceRecordsFilter.value = '';
    paceStockFilter.value = '';
    loadPaces();
}

/* ---------- Profile ---------- */

watch(user, (value) => {
    profileForm.name = value?.name ?? '';
    profileForm.email = value?.email ?? '';
    branchId.value = value?.branchId ?? '';
    roleValue.value = value?.role ?? '';
});

async function load() {
    loading.value = true;
    loadError.value = '';
    activeTab.value = 'profile';
    gradeLevelsLoaded.value = false;
    studentsLoaded.value = false;
    pacesLoaded.value = false;
    gradeLevelSearch.value = '';
    gradeLevelSchoolYearId.value = '';
    studentSearch.value = '';
    studentSchoolYears.value = [];
    studentSchoolYearId.value = '';
    studentGradeLevels.value = [];
    studentGradeLevelId.value = '';
    studentSexFilter.value = '';
    studentScoreMin.value = '';
    studentScoreMax.value = '';
    paceSearch.value = '';
    paceRecordsFilter.value = '';
    paceStockFilter.value = '';

    try {
        user.value = await adminService.getUser(props.id);
        loadBranches();
    } catch (err) {
        user.value = null;
        loadError.value = err?.isNotFound ? 'This user could not be found.' : (err?.message ?? 'Failed to load the user.');
    } finally {
        loading.value = false;
    }
}

async function selectTab(tab) {
    activeTab.value = tab;

    if (tab === 'grade-levels' && !gradeLevelsLoaded.value) {
        tabLoading.value = true;

        try {
            gradeLevels.value = await adminService.listGradeLevels(props.id);
            gradeLevelsLoaded.value = true;
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load grade levels.');
        } finally {
            tabLoading.value = false;
        }
    }

    if (tab === 'students' && !studentsLoaded.value) {
        await loadStudents();
        loadStudentSchoolYears();
    }

    if (tab === 'paces' && !pacesLoaded.value) {
        await loadPaces();
    }
}

function validateProfile() {
    const errors = {};

    if (!profileForm.name.trim()) {
        errors.name = ['Name is required.'];
    }

    if (!profileForm.email.trim()) {
        errors.email = ['Email is required.'];
    }

    if (!profileForm.admin_password) {
        errors.admin_password = ['Your password is required to confirm this change.'];
    }

    profileErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function saveProfile() {
    if (savingProfile.value || !validateProfile()) {
        return;
    }

    savingProfile.value = true;

    try {
        const updated = await adminService.updateUser(props.id, {
            name: profileForm.name.trim(),
            email: profileForm.email.trim(),
            admin_password: profileForm.admin_password,
        });

        user.value = updated;
        profileForm.admin_password = '';
        profileErrors.value = {};
        notify.success('User updated.');

        if (auth.user?.id === updated.id) {
            auth.setUser(updated);
        }
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            profileErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to update this user.');
        }
    } finally {
        savingProfile.value = false;
    }
}

function validatePassword() {
    const errors = {};

    if (!passwordForm.password) {
        errors.password = ['New password is required.'];
    } else if (passwordForm.password.length < 8) {
        errors.password = ['Password must be at least 8 characters.'];
    } else if (passwordForm.password !== passwordForm.password_confirmation) {
        errors.password_confirmation = ['Passwords do not match.'];
    }

    if (isSelf.value && !passwordForm.current_password) {
        errors.current_password = ['Your current password is required.'];
    }

    if (!isSelf.value && !passwordForm.admin_password) {
        errors.admin_password = ['Your password is required to confirm this change.'];
    }

    passwordErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function resetPassword() {
    if (savingPassword.value || !validatePassword()) {
        return;
    }

    savingPassword.value = true;

    try {
        const payload = { password: passwordForm.password, password_confirmation: passwordForm.password_confirmation };

        if (isSelf.value) {
            payload.current_password = passwordForm.current_password;
        } else {
            payload.admin_password = passwordForm.admin_password;
        }

        await adminService.resetUserPassword(props.id, payload);
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        passwordForm.admin_password = '';
        passwordForm.current_password = '';
        passwordErrors.value = {};
        notify.success(isSelf.value ? 'Your password has been changed.' : `${user.value?.name ?? 'User'}'s password has been reset.`);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            passwordErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to reset this password.');
        }
    } finally {
        savingPassword.value = false;
    }
}

watch(() => props.id, load);

onMounted(load);

onBeforeUnmount(() => {
    clearTimeout(studentSearchTimer);
    clearTimeout(paceSearchTimer);
});
</script>

<template>
    <div class="admin-user-detail">
        <p class="back">
            <RouterLink :to="{ name: 'admin-users' }">&larr; Users</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!user" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'admin-users' }" class="btn btn-secondary btn-sm">Back to users</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ user.name }}</h1>
                    <p class="muted subtitle">
                        {{ user.roleLabel }} · {{ user.email }}
                        <template v-if="user.isPlatform"> · {{ user.organizationLabel }}</template>
                        <template v-else-if="user.hasOrganization"> · {{ user.organizationName }}<template v-if="user.branchLabel"> ({{ user.branchLabel }})</template></template>
                        <template v-else> · Not in a school yet</template>
                        · Registered {{ formatDate(user.createdAt) || '—' }}
                    </p>
                </div>
            </div>

            <div class="tabs" role="tablist" aria-label="Section">
                <template v-for="tab in TABS" :key="tab.key">
                    <button
                        v-if="tab.key === 'profile' || user.isTeacher"
                        type="button"
                        role="tab"
                        class="tab"
                        :class="{ active: activeTab === tab.key }"
                        :aria-selected="activeTab === tab.key"
                        @click="selectTab(tab.key)"
                    >
                        {{ tab.label }}
                    </button>
                </template>
            </div>

            <!-- ============ Profile ============ -->
            <template v-if="activeTab === 'profile'">
                <section class="card">
                    <h2 class="card-title">User information</h2>
                    <dl class="info">
                        <div class="info-item">
                            <dt>Name</dt>
                            <dd>{{ user.name }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Email</dt>
                            <dd>{{ user.email }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Role</dt>
                            <dd><StatusBadge :tone="user.isPlatform ? 'bad' : user.isAdmin ? 'info' : 'muted'">{{ user.roleLabel }}</StatusBadge></dd>
                        </div>
                        <div class="info-item">
                            <dt>{{ user.isPlatform ? 'Organization' : 'School' }}</dt>
                            <dd>{{ user.organizationLabel || '—' }}</dd>
                        </div>
                        <!-- School admins manage every branch, so only teachers show one. -->
                        <div v-if="user.isTeacher && user.hasOrganization" class="info-item">
                            <dt>Branch</dt>
                            <dd>{{ user.branchName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Registered</dt>
                            <dd>{{ formatDate(user.createdAt) || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Membership: move between branches, change the role (owner), remove from the school. -->
                <section v-if="user.hasOrganization && (user.isTeacher || canChangeRole)" class="card">
                    <h2 class="card-title">Membership</h2>
                    <div class="membership">
                        <AppField v-if="user.isTeacher" label="Branch" for="admin-user-branch" hint="Moving a teacher does not change their records.">
                            <div class="inline-row">
                                <select id="admin-user-branch" v-model="branchId" class="input" :disabled="movingBranch || !auth.canWrite">
                                    <option v-for="branch in memberBranches" :key="branch.id" :value="branch.id">{{ branch.name }}{{ branch.isMain ? ' (main)' : '' }}</option>
                                </select>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="movingBranch || !auth.canWrite || Number(branchId) === Number(user.branchId)"
                                    @click="moveBranch"
                                >
                                    {{ movingBranch ? 'Moving…' : 'Move' }}
                                </button>
                            </div>
                        </AppField>

                        <AppField v-if="canChangeRole" label="Role" for="admin-user-role" hint="Administrators manage the school; teachers own records.">
                            <div class="inline-row">
                                <select id="admin-user-role" v-model="roleValue" class="input" :disabled="savingRole">
                                    <option value="admin">Admin</option>
                                    <option value="teacher">Teacher</option>
                                </select>
                                <button type="button" class="btn btn-secondary" :disabled="savingRole || roleValue === user.role" @click="saveRole">
                                    {{ savingRole ? 'Saving…' : 'Change role' }}
                                </button>
                            </div>
                        </AppField>

                        <div v-if="canRemove" class="remove">
                            <p class="muted hint">
                                Removing {{ user.name }} takes away their access to the school. Their students and PACE records stay
                                theirs and become reachable again if they join a school later.
                            </p>
                            <button type="button" class="btn btn-danger btn-sm" :disabled="removing || !auth.canWrite" @click="confirmingRemoval = true">
                                Remove from organization
                            </button>
                        </div>
                    </div>
                </section>

                <div class="grid-2 pair">
                    <section class="card">
                        <h2 class="card-title">Edit details</h2>
                        <form class="form" novalidate @submit.prevent="saveProfile">
                            <AppField label="Name" for="admin-user-name" :error="profileErrors.name" required>
                                <input
                                    id="admin-user-name"
                                    v-model="profileForm.name"
                                    class="input"
                                    type="text"
                                    autocomplete="name"
                                    :disabled="savingProfile"
                                />
                            </AppField>

                            <AppField label="Email" for="admin-user-email" :error="profileErrors.email" required>
                                <input
                                    id="admin-user-email"
                                    v-model="profileForm.email"
                                    class="input"
                                    type="email"
                                    autocomplete="email"
                                    :disabled="savingProfile"
                                />
                            </AppField>

                            <AppField
                                label="Your password"
                                for="admin-user-confirm"
                                :error="profileErrors.admin_password"
                                hint="Confirm it's you making this change."
                                required
                            >
                                <PasswordField
                                    id="admin-user-confirm"
                                    v-model="profileForm.admin_password"
                                    autocomplete="current-password"
                                    :disabled="savingProfile"
                                />
                            </AppField>

                            <div class="actions">
                                <button type="submit" class="btn" :disabled="savingProfile">
                                    {{ savingProfile ? 'Saving…' : 'Save changes' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="card">
                        <h2 class="card-title">{{ isSelf ? 'Change password' : 'Reset password' }}</h2>
                        <p class="muted hint">
                            {{ isSelf ? 'Enter your current password, then the new one.' : `${user.name}'s current password is not needed to set a new one.` }}
                        </p>
                        <form class="form" novalidate @submit.prevent="resetPassword">
                            <AppField
                                v-if="isSelf"
                                label="Current password"
                                for="admin-user-current-password"
                                :error="passwordErrors.current_password"
                                required
                            >
                                <PasswordField
                                    id="admin-user-current-password"
                                    v-model="passwordForm.current_password"
                                    autocomplete="current-password"
                                    :disabled="savingPassword"
                                />
                            </AppField>

                            <AppField
                                label="New password"
                                for="admin-user-password"
                                :error="passwordErrors.password"
                                hint="At least 8 characters."
                                required
                            >
                                <PasswordField
                                    id="admin-user-password"
                                    v-model="passwordForm.password"
                                    autocomplete="new-password"
                                    :disabled="savingPassword"
                                />
                            </AppField>

                            <AppField
                                label="Confirm new password"
                                for="admin-user-password-confirmation"
                                :error="passwordErrors.password_confirmation"
                                required
                            >
                                <PasswordField
                                    id="admin-user-password-confirmation"
                                    v-model="passwordForm.password_confirmation"
                                    autocomplete="new-password"
                                    :disabled="savingPassword"
                                />
                            </AppField>

                            <AppField
                                v-if="!isSelf"
                                label="Your password"
                                for="admin-user-password-confirm"
                                :error="passwordErrors.admin_password"
                                hint="Confirm it's you making this change."
                                required
                            >
                                <PasswordField
                                    id="admin-user-password-confirm"
                                    v-model="passwordForm.admin_password"
                                    autocomplete="current-password"
                                    :disabled="savingPassword"
                                />
                            </AppField>

                            <div class="actions">
                                <button type="submit" class="btn" :disabled="savingPassword">
                                    {{ savingPassword ? 'Saving…' : (isSelf ? 'Change password' : 'Reset password') }}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            </template>

            <!-- ============ Grade levels ============ -->
            <template v-else-if="activeTab === 'grade-levels'">
                <!-- Filters on the left; the grade level count and the colour key for the bars on the right. -->
                <div class="toolbar">
                    <div class="toolbar-controls">
                        <input
                            v-model="gradeLevelSearch"
                            class="input search"
                            type="search"
                            placeholder="Search grade level or subject"
                            aria-label="Search grade levels and subjects"
                            :disabled="gradeLevels.length === 0"
                        />
                        <label class="year">
                            <span class="muted">School year</span>
                            <select v-model="gradeLevelSchoolYearId" class="input" aria-label="Filter by school year" :disabled="gradeLevels.length === 0">
                                <option value="">All school years</option>
                                <option v-for="option in gradeLevelYearOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <button v-if="gradeLevelHasFilters" type="button" class="btn btn-link btn-sm" @click="clearGradeLevelFilters">
                            Clear
                        </button>
                    </div>
                    <div v-if="!tabLoading && filteredGradeLevels.length > 0" class="toolbar-meta">
                        <span class="muted count nowrap">{{ pluralize(filteredGradeLevels.length, 'grade level') }}</span>
                        <PaceRecordsLegend :order="RECORD_ORDER" />
                    </div>
                </div>

                <p v-if="tabLoading" class="muted">Loading…</p>

                <EmptyState v-else-if="gradeLevels.length === 0" message="This teacher has no grade levels yet." />

                <EmptyState v-else-if="gradeLevelGroups.length === 0" message="No grade levels or subjects match your filters.">
                    <template #action>
                        <button type="button" class="btn btn-secondary btn-sm" @click="clearGradeLevelFilters">Clear filters</button>
                    </template>
                </EmptyState>

                <div v-else class="groups">
                    <section v-for="group in gradeLevelGroups" :key="group.key" class="group">
                        <h2 class="group-title">
                            <span class="muted">School year</span> <span class="mono">{{ group.name }}</span>
                            <span class="muted group-count">· {{ pluralize(group.levels.length, 'grade level') }}</span>
                        </h2>

                        <div class="levels">
                            <section v-for="level in group.levels" :key="level.id" class="card level">
                                <div class="level-header">
                                    <div>
                                        <h3 class="level-name">
                                            <RouterLink :to="{ name: 'admin-grade-level-detail', params: { id: level.id } }">{{ level.name }}</RouterLink>
                                        </h3>
                                        <p class="muted level-meta">
                                            {{ levelMeta(level) }}
                                            <template v-if="gradeLevelHasFilters && visibleSubjects(level).length !== level.subjects.length">
                                                · showing {{ visibleSubjects(level).length }} of {{ level.subjects.length }}
                                            </template>
                                        </p>
                                    </div>
                                    <RouterLink
                                        :to="{ name: 'admin-grade-level-detail', params: { id: level.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${level.name}`"
                                        :aria-label="`View ${level.name}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                </div>

                                <EmptyState v-if="visibleSubjects(level).length === 0" compact message="No subjects yet." />

                                <table v-else class="table subjects">
                                    <!-- Fixed column widths so the columns line up across every grade level card. -->
                                    <colgroup>
                                        <col class="col-subject" />
                                        <col class="col-records" />
                                        <col class="col-actions" />
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>PACE records</th>
                                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="subject in visibleSubjects(level)" :key="subject.id">
                                            <td class="subject-name">
                                                <RouterLink :to="{ name: 'admin-subject-detail', params: { id: subject.id } }">{{ subject.name }}</RouterLink>
                                            </td>
                                            <td class="records">
                                                <PaceRecordsBar
                                                    :total="subject.assignmentsCount"
                                                    :tested="subject.testedCount"
                                                    :issued="subject.issuedCount"
                                                    :assigned="subject.assignedCount"
                                                    :order="RECORD_ORDER"
                                                    tooltip
                                                />
                                            </td>
                                            <td class="table-actions">
                                                <RouterLink
                                                    :to="{ name: 'admin-subject-detail', params: { id: subject.id } }"
                                                    class="btn btn-link btn-sm btn-icon"
                                                    :title="`View ${subject.name}`"
                                                    :aria-label="`View ${subject.name}`"
                                                >
                                                    <AppIcon name="eye" />
                                                </RouterLink>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </section>
                        </div>
                    </section>
                </div>
            </template>

            <!-- ============ Students ============ -->
            <section v-else-if="activeTab === 'students'" class="card list-section">
                <!-- Filters on the left; the student count and the colour key for the bars on the right. -->
                <div class="toolbar">
                    <div class="toolbar-controls">
                        <input
                            v-model="studentSearch"
                            class="input search"
                            type="search"
                            placeholder="Search by name"
                            aria-label="Search students"
                        />
                        <select
                            v-model="studentSchoolYearId"
                            class="input select"
                            aria-label="Filter by school year"
                            :disabled="loadingStudentSchoolYears"
                            @change="onStudentSchoolYearChange"
                        >
                            <option value="">All school years</option>
                            <option v-for="year in studentSchoolYears" :key="year.id" :value="year.id">
                                {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                            </option>
                        </select>
                        <select
                            v-model="studentGradeLevelId"
                            class="input select"
                            aria-label="Filter by grade level"
                            :disabled="!studentSchoolYearId || loadingStudentGradeLevels"
                            @change="loadStudents"
                        >
                            <option value="">
                                {{ studentSchoolYearId ? 'All grade levels' : 'Select a school year first' }}
                            </option>
                            <option v-for="level in studentGradeLevels" :key="level.id" :value="level.id">
                                {{ level.name }}
                            </option>
                        </select>
                        <select v-model="studentSexFilter" class="input select select-sm" aria-label="Filter by sex">
                            <option value="">All sexes</option>
                            <option v-for="option in SEX_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <div class="score-range" role="group" aria-label="Filter by average score">
                            <span class="muted score-range-label">Score</span>
                            <input
                                v-model="studentScoreMin"
                                class="input score-input"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                inputmode="decimal"
                                placeholder="Min"
                                aria-label="Minimum average score"
                            />
                            <span class="muted" aria-hidden="true">–</span>
                            <input
                                v-model="studentScoreMax"
                                class="input score-input"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                inputmode="decimal"
                                placeholder="Max"
                                aria-label="Maximum average score"
                            />
                        </div>
                        <button v-if="studentHasFilters" type="button" class="btn btn-link btn-sm" @click="clearStudentFilters">
                            Clear
                        </button>
                    </div>
                    <div class="toolbar-meta">
                        <span class="muted count nowrap">
                            {{ tabLoading ? 'Loading…' : pluralize(visibleStudents.length, 'student') }}
                        </span>
                        <PaceRecordsLegend v-if="visibleStudents.length > 0" :order="RECORD_ORDER" />
                    </div>
                </div>

                <p v-if="tabLoading" class="muted state">Loading…</p>

                <EmptyState v-else-if="visibleStudents.length === 0" :message="studentsEmptyMessage">
                    <template v-if="studentHasFilters" #action>
                        <button type="button" class="btn btn-secondary btn-sm" @click="clearStudentFilters">Clear filters</button>
                    </template>
                </EmptyState>

                <div v-else class="table-wrap">
                    <table class="table">
                        <!-- Fixed column widths so every column lines up down the page. -->
                        <colgroup>
                            <col class="col-name" />
                            <col class="col-grade" />
                            <col class="col-year" />
                            <col class="col-sex" />
                            <col class="col-age" />
                            <col class="col-birth" />
                            <col class="col-records" />
                            <col class="col-average" />
                            <col class="col-actions" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Grade level</th>
                                <th>School year</th>
                                <th>Sex</th>
                                <th>Age</th>
                                <th>Birth date</th>
                                <th>PACE records</th>
                                <th class="text-right">Average score</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in visibleStudents" :key="student.id">
                                <td class="ellipsis">
                                    <RouterLink :to="{ name: 'admin-student-detail', params: { id: student.id } }" class="name">
                                        {{ student.fullName }}
                                    </RouterLink>
                                </td>
                                <td class="ellipsis">{{ student.gradeLevelName || '—' }}</td>
                                <td class="mono">{{ student.schoolYearName || '—' }}</td>
                                <td>{{ student.sexLabel || '—' }}</td>
                                <td class="mono">{{ student.age ?? '—' }}</td>
                                <td class="nowrap">{{ formatDate(student.birthDate) || '—' }}</td>
                                <td class="records">
                                    <PaceRecordsBar
                                        :total="student.assignmentsCount"
                                        :tested="student.testedCount"
                                        :issued="student.issuedCount"
                                        :assigned="student.assignedCount"
                                        :order="RECORD_ORDER"
                                        tooltip
                                    />
                                </td>
                                <td class="mono text-right" :title="student.averageScore === null ? '' : 'Across all PACE records of the enrolled school year; untested PACEs count as 0'">
                                    <span v-if="student.averageScore !== null">{{ formatScore(student.averageScore) }}</span>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td class="table-actions">
                                    <RouterLink
                                        :to="{ name: 'admin-student-detail', params: { id: student.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${student.displayName}`"
                                        :aria-label="`View ${student.displayName}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============ PACEs ============ -->
            <section v-else-if="activeTab === 'paces'" class="card list-section">
                <!-- Filters on the left; the PACE count and the colour key for the bars on the right. -->
                <div class="toolbar">
                    <div class="toolbar-controls">
                        <input
                            v-model="paceSearch"
                            class="input search"
                            type="search"
                            placeholder="Search by number or name"
                            aria-label="Search PACEs"
                        />
                        <select v-model="paceRecordsFilter" class="input select" aria-label="Filter by PACE records">
                            <option v-for="option in PACE_RECORDS_OPTIONS" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <select v-model="paceStockFilter" class="input select" aria-label="Filter by stock">
                            <option v-for="option in PACE_STOCK_OPTIONS" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <button v-if="paceHasFilters" type="button" class="btn btn-link btn-sm" @click="clearPaceFilters">
                            Clear
                        </button>
                    </div>
                    <div class="toolbar-meta">
                        <span class="muted count nowrap">
                            {{ tabLoading ? 'Loading…' : pluralize(visiblePaces.length, 'PACE') }}
                        </span>
                        <PaceRecordsLegend v-if="visiblePaces.length > 0" :order="RECORD_ORDER" />
                    </div>
                </div>

                <p v-if="tabLoading" class="muted state">Loading…</p>

                <EmptyState v-else-if="visiblePaces.length === 0" :message="pacesEmptyMessage">
                    <template v-if="paceHasFilters" #action>
                        <button type="button" class="btn btn-secondary btn-sm" @click="clearPaceFilters">Clear filters</button>
                    </template>
                </EmptyState>

                <div v-else class="table-wrap">
                    <table class="table">
                        <!-- Fixed column widths so every column lines up down the page. -->
                        <colgroup>
                            <col class="col-number" />
                            <col class="col-pace-name" />
                            <col class="col-stock" />
                            <col class="col-records" />
                            <col class="col-actions" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Name</th>
                                <th>Stock</th>
                                <th>PACE records</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="pace in visiblePaces" :key="pace.id">
                                <td class="mono">
                                    <RouterLink :to="{ name: 'admin-pace-detail', params: { id: pace.id } }" class="name">
                                        #{{ pace.number }}
                                    </RouterLink>
                                </td>
                                <td class="ellipsis">{{ pace.name }}</td>
                                <td class="stock-cell nowrap">
                                    <span class="mono stock-value">{{ pace.stock }}</span>
                                    <StatusBadge :tone="pace.stockTone">{{ pace.stockLabel }}</StatusBadge>
                                    <RouterLink
                                        v-if="pace.hasPendingRequest"
                                        :to="{ name: 'admin-pace-requests' }"
                                        class="small"
                                        :title="pluralize(pace.pendingRequestsCount, 'stock request') + ' pending'"
                                    >
                                        Request pending
                                    </RouterLink>
                                </td>
                                <td class="records">
                                    <PaceRecordsBar
                                        :total="pace.assignmentsCount"
                                        :tested="pace.testedCount"
                                        :issued="pace.issuedCount"
                                        :assigned="pace.assignedCount"
                                        :order="RECORD_ORDER"
                                        tooltip
                                    />
                                </td>
                                <td class="table-actions">
                                    <RouterLink
                                        :to="{ name: 'admin-pace-detail', params: { id: pace.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View PACE ${pace.label}`"
                                        :aria-label="`View PACE ${pace.label}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <ConfirmDialog
            v-model="confirmingRemoval"
            title="Remove from organization"
            confirm-text="Remove"
            :busy="removing"
            @confirm="removeMember"
        >
            Remove <strong>{{ user?.name }}</strong> from {{ user?.organizationName }}? They will have to ask to join again.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.membership {
    display: flex;
    flex-direction: column;
    gap: 14px;
    max-width: 520px;
}

.inline-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
}

.remove {
    padding-top: 6px;
    border-top: 1px solid var(--border);
}

.admin-user-detail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

/* Stays pinned under the top bar while the page scrolls, so the user's name and details remain in view. */
.page-header {
    position: sticky;
    top: var(--nav-height, 0px);
    z-index: 20;
    padding: 12px 0;
    margin-bottom: 0;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.subtitle {
    margin: 4px 0 0;
}

.tabs {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--border);
}

.tab {
    display: inline-flex;
    align-items: center;
    padding: 8px 14px;
    margin-bottom: -1px;
    border: none;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    white-space: nowrap;
}

.tab:hover {
    color: var(--text);
}

.tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 500;
}

.tab:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
    border-radius: 4px;
}

.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
}

.pair > .card {
    min-width: 0;
}

.hint {
    margin: -4px 0 14px;
    font-size: 0.85rem;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 6px;
}

/* Toolbar: controls on the left, count + legend on the right (inside a card for the lists, bare for grade levels). */
.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
}

.list-section {
    padding: 0;
    overflow: hidden;
}

.list-section .toolbar {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1 1 auto;
}

.toolbar-controls .input {
    width: auto;
}

.search {
    flex: 1 1 200px;
    max-width: 280px;
}

.select {
    flex: 0 1 190px;
}

.select-sm {
    flex-basis: 130px;
}

.score-range {
    display: flex;
    align-items: center;
    gap: 6px;
}

.score-range-label {
    font-size: 0.9rem;
}

.toolbar-controls .score-input {
    width: 78px;
}

.year {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.92rem;
}

.year .input {
    min-width: 170px;
}

.toolbar-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-left: auto;
}

.count {
    font-size: 0.92rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.groups {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.group-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.group-count {
    font-weight: 400;
    font-size: 0.9rem;
}

.levels {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.level {
    padding: 0;
    overflow: hidden;
}

.level-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.level-name {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0;
}

.level-meta {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.subjects {
    table-layout: fixed;
}

.subjects .col-records {
    width: 160px;
}

.subject-name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.records {
    line-height: 1;
}

.table-wrap {
    overflow-x: auto;
}

.list-section .table {
    table-layout: fixed;
    min-width: 1000px;
}

.col-grade {
    width: 130px;
}

.col-year {
    width: 110px;
}

.col-sex {
    width: 80px;
}

.col-age {
    width: 60px;
}

.col-birth {
    width: 120px;
}

.col-records {
    width: 150px;
}

.col-stock {
    width: 190px;
}

.stock-cell .stock-value {
    font-weight: 600;
    margin-right: 6px;
}

.stock-cell .small {
    display: block;
    margin-top: 2px;
    font-size: 0.8rem;
}

.col-average {
    width: 120px;
}

.col-actions {
    width: 60px;
}

.col-number {
    width: 120px;
}

.name {
    font-weight: 500;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
</style>
