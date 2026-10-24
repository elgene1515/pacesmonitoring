import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { APP_NAME } from '../core/constants';

const Login = () => import('../components/pages/Login.vue');
const Register = () => import('../components/pages/Register.vue');
const ForgotPassword = () => import('../components/pages/ForgotPassword.vue');
const ResetPassword = () => import('../components/pages/ResetPassword.vue');
const VerifyEmail = () => import('../components/pages/VerifyEmail.vue');
const OrganizationSetup = () => import('../components/pages/setup/OrganizationSetup.vue');
const JoinOrganization = () => import('../components/pages/setup/JoinOrganization.vue');
const Suspended = () => import('../components/pages/setup/Suspended.vue');
const Home = () => import('../components/pages/Home.vue');
const GradeLevels = () => import('../components/pages/GradeLevels.vue');
const GradeLevelDetail = () => import('../components/pages/GradeLevelDetail.vue');
const SubjectDetail = () => import('../components/pages/SubjectDetail.vue');
const SectionDetail = () => import('../components/pages/SectionDetail.vue');
const TeachingRequests = () => import('../components/pages/TeachingRequests.vue');
const StudentChangeRequests = () => import('../components/pages/StudentChangeRequests.vue');
const SchoolYears = () => import('../components/pages/SchoolYears.vue');
const Students = () => import('../components/pages/Students.vue');
const StudentDetail = () => import('../components/pages/StudentDetail.vue');
const Paces = () => import('../components/pages/Paces.vue');
const PaceDetail = () => import('../components/pages/PaceDetail.vue');
const PaceRequests = () => import('../components/pages/PaceRequests.vue');
const Settings = () => import('../components/pages/Settings.vue');
const AdminHome = () => import('../components/pages/admin/AdminHome.vue');
const AdminUsers = () => import('../components/pages/admin/AdminUsers.vue');
const AdminUserDetail = () => import('../components/pages/admin/AdminUserDetail.vue');
const AdminStudents = () => import('../components/pages/admin/AdminStudents.vue');
const AdminStudentDetail = () => import('../components/pages/admin/AdminStudentDetail.vue');
const AdminStudentChangeRequests = () => import('../components/pages/admin/AdminStudentChangeRequests.vue');
const AdminGradeLevels = () => import('../components/pages/admin/AdminGradeLevels.vue');
const AdminPaces = () => import('../components/pages/admin/AdminPaces.vue');
const AdminPaceDetail = () => import('../components/pages/admin/AdminPaceDetail.vue');
const AdminPaceRequests = () => import('../components/pages/admin/AdminPaceRequests.vue');
const AdminOrganization = () => import('../components/pages/admin/AdminOrganization.vue');
const OwnerHome = () => import('../components/pages/owner/OwnerHome.vue');
const OwnerOrganizations = () => import('../components/pages/owner/OwnerOrganizations.vue');
const OwnerOrganizationDetail = () => import('../components/pages/owner/OwnerOrganizationDetail.vue');
const OwnerPlans = () => import('../components/pages/owner/OwnerPlans.vue');
const OwnerStaff = () => import('../components/pages/owner/OwnerStaff.vue');

const numericId = (route) => ({ id: Number(route.params.id) });

/*
| Route meta:
|  guest          only for visitors (signed-in users are sent to their home)
|  requiresAuth   must be signed in
|  setup          onboarding pages for users without an organization yet
|  teacherOnly    the teacher area (what their teaching assignments cover)
|  requiresAdmin  the admin area (organization admins and the owner)
|  requiresOwner  the platform area (owner and vice admins)
|  ownerOnly      the owner alone (staff management)
|  hideNav        no top bar
*/
export const routes = [
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guest: true, title: 'Sign in', hideNav: true },
    },
    {
        path: '/register',
        name: 'register',
        component: Register,
        meta: { guest: true, title: 'Create account', hideNav: true },
    },

    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: ForgotPassword,
        meta: { guest: true, title: 'Forgot password', hideNav: true },
    },
    {
        path: '/reset-password',
        name: 'reset-password',
        component: ResetPassword,
        meta: { guest: true, title: 'Reset password', hideNav: true },
    },
    {
        // Opened from the verification email; works signed in or not.
        path: '/verify-email',
        name: 'verify-email',
        component: VerifyEmail,
        meta: { title: 'Verify email', hideNav: true },
    },
    {
        path: '/setup/organization',
        name: 'setup-organization',
        component: OrganizationSetup,
        meta: { requiresAuth: true, setup: true, title: 'Register your school' },
    },
    {
        path: '/setup/join',
        name: 'setup-join',
        component: JoinOrganization,
        meta: { requiresAuth: true, setup: true, title: 'Join your school' },
    },
    {
        path: '/setup/suspended',
        name: 'setup-suspended',
        component: Suspended,
        meta: { requiresAuth: true, setup: true, title: 'School deactivated' },
    },
    {
        path: '/',
        name: 'home',
        component: Home,
        meta: { requiresAuth: true, title: 'Home', teacherOnly: true },
    },
    {
        path: '/classes',
        name: 'classes',
        component: GradeLevels,
        meta: { requiresAuth: true, title: 'My classes', teacherOnly: true },
    },
    {
        // The old address of the teacher's grade levels.
        path: '/grade-levels',
        redirect: { name: 'classes' },
    },
    {
        // Nested under /classes so the "My classes" nav tab stays highlighted.
        path: '/classes/requests',
        name: 'teaching-requests',
        component: TeachingRequests,
        meta: { requiresAuth: true, title: 'Class requests', teacherOnly: true },
    },
    {
        path: '/classes/grade-levels/:id(\\d+)',
        name: 'grade-level-detail',
        component: GradeLevelDetail,
        props: numericId,
        meta: { requiresAuth: true, title: 'Grade level', teacherOnly: true },
    },
    {
        path: '/classes/sections/:id(\\d+)',
        name: 'section-detail',
        component: SectionDetail,
        props: numericId,
        meta: { requiresAuth: true, title: 'Section', teacherOnly: true },
    },
    {
        path: '/classes/subjects/:id(\\d+)',
        name: 'subject-detail',
        component: SubjectDetail,
        props: numericId,
        meta: { requiresAuth: true, title: 'Subject', teacherOnly: true },
    },
    {
        path: '/school-years',
        name: 'school-years',
        component: SchoolYears,
        meta: { requiresAuth: true, title: 'School years', teacherOnly: true },
    },
    {
        path: '/students',
        name: 'students',
        component: Students,
        meta: { requiresAuth: true, title: 'Students', teacherOnly: true },
    },
    {
        // Nested under /students so the "Students" nav tab stays highlighted.
        path: '/students/changes',
        name: 'student-change-requests',
        component: StudentChangeRequests,
        meta: { requiresAuth: true, title: 'Proposed changes', teacherOnly: true },
    },
    {
        path: '/students/:id(\\d+)',
        name: 'student-detail',
        component: StudentDetail,
        props: numericId,
        meta: { requiresAuth: true, title: 'Student', teacherOnly: true },
    },
    {
        path: '/paces',
        name: 'paces',
        component: Paces,
        meta: { requiresAuth: true, title: 'PACEs', teacherOnly: true },
    },
    {
        // Nested under /paces so the "PACEs" nav tab stays highlighted.
        path: '/paces/requests',
        name: 'pace-requests',
        component: PaceRequests,
        meta: { requiresAuth: true, title: 'Stock requests', teacherOnly: true },
    },
    {
        path: '/paces/:id(\\d+)',
        name: 'pace-detail',
        component: PaceDetail,
        props: numericId,
        meta: { requiresAuth: true, title: 'PACE', teacherOnly: true },
    },
    {
        path: '/settings',
        name: 'settings',
        component: Settings,
        meta: { requiresAuth: true, title: 'My account' },
    },
    {
        path: '/admin',
        name: 'admin-home',
        component: AdminHome,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin' },
    },
    {
        path: '/admin/users',
        name: 'admin-users',
        component: AdminUsers,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Teachers' },
    },
    {
        path: '/admin/users/:id(\\d+)',
        name: 'admin-user-detail',
        component: AdminUserDetail,
        props: numericId,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · User' },
    },
    {
        path: '/admin/students',
        name: 'admin-students',
        component: AdminStudents,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Students' },
    },
    {
        path: '/admin/students/changes',
        name: 'admin-student-change-requests',
        component: AdminStudentChangeRequests,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Proposed changes' },
    },
    {
        path: '/admin/students/:id(\\d+)',
        name: 'admin-student-detail',
        component: AdminStudentDetail,
        props: numericId,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Student' },
    },
    {
        path: '/admin/paces',
        name: 'admin-paces',
        component: AdminPaces,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · PACEs' },
    },
    {
        // Nested under /admin/paces so the "PACEs" nav tab stays highlighted.
        path: '/admin/paces/requests',
        name: 'admin-pace-requests',
        component: AdminPaceRequests,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Stock requests' },
    },
    {
        // The old address of the stock requests.
        path: '/admin/pace-requests',
        redirect: { name: 'admin-pace-requests' },
    },
    {
        path: '/admin/paces/:id(\\d+)',
        name: 'admin-pace-detail',
        component: AdminPaceDetail,
        props: numericId,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · PACE' },
    },
    {
        path: '/admin/grade-levels',
        name: 'admin-grade-levels',
        component: AdminGradeLevels,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Grade levels' },
    },
    {
        // Nested under /admin/grade-levels so the "Grade Levels" nav tab stays highlighted.
        path: '/admin/grade-levels/:id(\\d+)',
        name: 'admin-grade-level-detail',
        component: GradeLevelDetail,
        props: (route) => ({ id: Number(route.params.id), admin: true }),
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Grade level' },
    },
    {
        path: '/admin/grade-levels/sections/:id(\\d+)',
        name: 'admin-section-detail',
        component: SectionDetail,
        props: (route) => ({ id: Number(route.params.id), admin: true }),
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Section' },
    },
    {
        path: '/admin/grade-levels/subjects/:id(\\d+)',
        name: 'admin-subject-detail',
        component: SubjectDetail,
        props: (route) => ({ id: Number(route.params.id), admin: true }),
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Subject' },
    },
    {
        path: '/admin/school-years',
        name: 'admin-school-years',
        component: SchoolYears,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · School years' },
    },
    {
        path: '/admin/organization',
        name: 'admin-organization',
        component: AdminOrganization,
        meta: { requiresAuth: true, requiresAdmin: true, title: 'Admin · Organization' },
    },
    {
        path: '/owner',
        name: 'owner-home',
        component: OwnerHome,
        meta: { requiresAuth: true, requiresOwner: true, title: 'Owner' },
    },
    {
        path: '/owner/organizations',
        name: 'owner-organizations',
        component: OwnerOrganizations,
        meta: { requiresAuth: true, requiresOwner: true, title: 'Owner · Organizations' },
    },
    {
        path: '/owner/organizations/:id(\\d+)',
        name: 'owner-organization-detail',
        component: OwnerOrganizationDetail,
        props: numericId,
        meta: { requiresAuth: true, requiresOwner: true, title: 'Owner · Organization' },
    },
    {
        path: '/owner/plans',
        name: 'owner-plans',
        component: OwnerPlans,
        meta: { requiresAuth: true, requiresOwner: true, title: 'Owner · Plans' },
    },
    {
        path: '/owner/staff',
        name: 'owner-staff',
        component: OwnerStaff,
        meta: { requiresAuth: true, requiresOwner: true, ownerOnly: true, title: 'Owner · Staff' },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'home' },
    },
];

const router = createRouter({
    history: createWebHistory('/pacesmonitoring'), //local
    // history: createWebHistory('/pacesmonitoring'), //deployment
    routes,
    scrollBehavior(to, from, savedPosition) {
        return savedPosition ?? { top: 0 };
    },
});

/**
 * Where a signed-in user lands: the onboarding step they still owe, else the
 * home page of their role.
 */
export function homeRouteFor(user) {
    if (!user) {
        return { name: 'login' };
    }

    if (user.needsOrganizationSetup) {
        return { name: 'setup-organization' };
    }

    if (user.needsToJoin) {
        return { name: 'setup-join' };
    }

    if (user.isSuspended) {
        return { name: 'setup-suspended' };
    }

    if (user.isPlatform) {
        return { name: 'owner-home' };
    }

    if (user.isAdmin) {
        return { name: 'admin-home' };
    }

    return { name: 'home' };
}

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.ready) {
        await auth.bootstrap();
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        const query = to.fullPath && to.fullPath !== '/' ? { redirect: to.fullPath } : {};
        return { name: 'login', query };
    }

    if (to.meta.guest && auth.isAuthenticated) {
        return homeRouteFor(auth.user);
    }

    if (!auth.isAuthenticated) {
        return true;
    }

    const user = auth.user;

    if (to.name === 'verify-email') {
        return true;
    }

    // Onboarding first: until an admin has registered their school, or a teacher has been
    // admitted into one, the only pages are the setup step (and Settings).
    if (user.needsSetup) {
        // An admin without a school may go either way: register one, or join one by its code.
        if (user.mayJoinByCode && (to.name === 'setup-join' || to.name === 'setup-organization')) {
            return true;
        }

        if (to.meta.setup || to.name === 'settings') {
            const expected = homeRouteFor(user);
            return to.meta.setup && to.name !== expected.name ? expected : true;
        }

        return homeRouteFor(user);
    }

    if (to.meta.setup) {
        return homeRouteFor(user);
    }

    if (to.meta.requiresOwner && !user.isPlatform) {
        return homeRouteFor(user);
    }

    if (to.meta.ownerOnly && !user.isOwner) {
        return homeRouteFor(user);
    }

    if (to.meta.requiresAdmin && !(user.isAdmin || user.isPlatform)) {
        return homeRouteFor(user);
    }

    if (to.meta.teacherOnly && !user.isTeacher) {
        return homeRouteFor(user);
    }

    return true;
});

router.afterEach((to) => {
    document.title = to.meta.title ? `${to.meta.title} · ${APP_NAME}` : APP_NAME;
});

/**
 * Resolve a safe post-login destination from `?redirect=`; only same-app paths are honoured.
 */
export function resolveRedirect(redirect, user = null) {
    if (typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')) {
        return redirect;
    }

    return homeRouteFor(user);
}

export default router;
