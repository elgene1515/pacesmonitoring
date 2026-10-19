import Organization from '../models/Organization';
import Plan from '../models/Plan';
import PlanRequest from '../models/PlanRequest';
import Subscription from '../models/Subscription';
import User from '../models/User';
import JoinRequest from '../models/JoinRequest';
import UnassignedUser from '../models/UnassignedUser';

/**
 * /api/owner/* — the platform area (owner and vice admins).
 *
 *  getDashboardSummary()                 -> { totals, subscriptionsByStatus, expiringSoon, expiringSoonDays, asOf,
 *                                            recentOrganizations, recentUsers, pendingJoinRequests, pendingPlanRequests }
 *  listOrganizations(filters)            filters: { search?, status?, trashed? } -> Organization[]
 *  getOrganization(id)                   -> { organization, members: User[], joinRequests: JoinRequest[], planRequests: PlanRequest[] }
 *  updateOrganization(id, payload)       payload incl. admin_password -> Organization
 *  deleteOrganization(id, adminPassword) owner only: deactivate (soft delete) -> void
 *  restoreOrganization(id)               owner only -> Organization
 *  listSubscriptions(organizationId)     -> Subscription[]
 *  createSubscription(organizationId, p) payload: {plan_id, status, starts_at, ends_at?, notes?} -> Subscription
 *  updateSubscription(id, payload)       -> Subscription
 *  listPlanRequests(filters)             filters: { status?, organization_id? } -> PlanRequest[]
 *  approvePlanRequest(id, payload)       payload: {starts_at?, ends_at?, notes?, decision_note?} -> PlanRequest
 *  declinePlanRequest(id, note?)         -> PlanRequest
 *  listPlans() / createPlan(p) / updatePlan(id, p)
 *  deletePlan(id, adminPassword)         owner only -> void
 *  listUnassignedUsers(filters)          filters: { search?, from?, to? } (from / to: day the account was created, YYYY-MM-DD)
 *                                        -> { users: UnassignedUser[], total, overall, limit }  (longest without a school first)
 *  deleteUnassignedUser(id, adminPassword) -> void (throws ApiError; refused while the account owns records)
 *  updateUserRole(id, role)              role: 'admin' | 'teacher' -> User
 *  listStaff() / createStaff(p) / updateStaffRole(id, role) / deleteStaff(id)   owner only
 */
export default class OwnerService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    async getDashboardSummary() {
        const body = await this.api.get('/owner/dashboard/summary');
        const data = body?.data ?? {};
        const totals = data.totals ?? {};
        const count = (value) => Number(value ?? 0);
        const byStatus = data.subscriptions_by_status ?? {};

        return {
            asOf: data.as_of ?? null,
            totals: {
                organizations: count(totals.organizations),
                deactivatedOrganizations: count(totals.deactivated_organizations),
                branches: count(totals.branches),
                owners: count(totals.owners),
                viceAdmins: count(totals.vice_admins),
                admins: count(totals.admins),
                teachers: count(totals.teachers),
                unassignedUsers: count(totals.unassigned_users),
                students: count(totals.students),
                assignments: count(totals.assignments),
                pendingJoinRequests: count(totals.pending_join_requests),
                pendingPlanRequests: count(totals.pending_plan_requests),
            },
            subscriptionsByStatus: {
                trial: count(byStatus.trial),
                active: count(byStatus.active),
                pastDue: count(byStatus.past_due),
                expired: count(byStatus.expired),
                cancelled: count(byStatus.cancelled),
                none: count(byStatus.none),
            },
            expiringSoon: (Array.isArray(data.expiring_soon) ? data.expiring_soon : []).map((row) => ({
                organizationId: row.organization_id,
                organizationName: row.organization_name ?? '',
                planName: row.plan_name ?? '',
                status: row.status ?? '',
                endsAt: row.ends_at ?? null,
                daysLeft: row.days_left === null || row.days_left === undefined ? null : Number(row.days_left),
            })),
            expiringSoonDays: count(data.expiring_soon_days ?? 30),
            recentOrganizations: Organization.collection(data.recent_organizations),
            recentUsers: User.collection(data.recent_users),
            pendingJoinRequests: JoinRequest.collection(data.pending_join_requests),
            pendingPlanRequests: PlanRequest.collection(data.pending_plan_requests),
        };
    }

    static emptyDashboard() {
        return {
            asOf: null,
            totals: { organizations: 0, deactivatedOrganizations: 0, branches: 0, owners: 0, viceAdmins: 0, admins: 0, teachers: 0, unassignedUsers: 0, students: 0, assignments: 0, pendingJoinRequests: 0, pendingPlanRequests: 0 },
            subscriptionsByStatus: { trial: 0, active: 0, pastDue: 0, expired: 0, cancelled: 0, none: 0 },
            expiringSoon: [],
            expiringSoonDays: 30,
            recentOrganizations: [],
            recentUsers: [],
            pendingJoinRequests: [],
            pendingPlanRequests: [],
        };
    }

    /* --------------------------------------------- accounts without a school */

    async listUnassignedUsers(filters = {}) {
        const body = await this.api.get('/owner/unassigned-users', filters);
        const meta = body?.meta ?? {};

        return {
            users: UnassignedUser.collection(body?.data),
            total: Number(meta.total ?? 0),
            overall: Number(meta.overall ?? 0),
            limit: Number(meta.limit ?? 100),
        };
    }

    async deleteUnassignedUser(id, adminPassword) {
        await this.api.ensureCsrf();
        await this.api.delete(`/owner/unassigned-users/${encodeURIComponent(id)}`, { admin_password: adminPassword });
    }

    /* -------------------------------------------------------- organizations */

    async listOrganizations(filters = {}) {
        const body = await this.api.get('/owner/organizations', { ...filters, trashed: filters.trashed ? 1 : '' });
        return Organization.collection(body?.data);
    }

    async getOrganization(id) {
        const body = await this.api.get(`/owner/organizations/${encodeURIComponent(id)}`);

        return {
            organization: Organization.fromJson(body?.data),
            members: User.collection(body?.members),
            joinRequests: JoinRequest.collection(body?.join_requests),
            planRequests: PlanRequest.collection(body?.plan_requests),
        };
    }

    async updateOrganization(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/owner/organizations/${encodeURIComponent(id)}`, payload);
        return Organization.fromJson(body?.data);
    }

    async deleteOrganization(id, adminPassword) {
        await this.api.ensureCsrf();
        await this.api.delete(`/owner/organizations/${encodeURIComponent(id)}`, { admin_password: adminPassword });
    }

    async restoreOrganization(id) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/owner/organizations/${encodeURIComponent(id)}/restore`);
        return Organization.fromJson(body?.data);
    }

    /* -------------------------------------------------------- subscriptions */

    async listSubscriptions(organizationId) {
        const body = await this.api.get(`/owner/organizations/${encodeURIComponent(organizationId)}/subscriptions`);
        return Subscription.collection(body?.data);
    }

    async createSubscription(organizationId, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/owner/organizations/${encodeURIComponent(organizationId)}/subscriptions`, this.normalize(payload));
        return Subscription.fromJson(body?.data);
    }

    async updateSubscription(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/owner/subscriptions/${encodeURIComponent(id)}`, this.normalize(payload));
        return Subscription.fromJson(body?.data);
    }

    /* -------------------------------------------------------- plan requests */

    async listPlanRequests(filters = {}) {
        const body = await this.api.get('/owner/plan-requests', filters);
        return PlanRequest.collection(body?.data);
    }

    async approvePlanRequest(id, payload = {}) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/owner/plan-requests/${encodeURIComponent(id)}/approve`, payload);
        return PlanRequest.fromJson(body?.data);
    }

    async declinePlanRequest(id, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/owner/plan-requests/${encodeURIComponent(id)}/decline`, { decision_note: note || null });
        return PlanRequest.fromJson(body?.data);
    }

    /* ---------------------------------------------------------------- plans */

    async listPlans() {
        const body = await this.api.get('/owner/plans');
        return Plan.collection(body?.data);
    }

    async createPlan(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/owner/plans', this.normalize(payload));
        return Plan.fromJson(body?.data);
    }

    async updatePlan(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/owner/plans/${encodeURIComponent(id)}`, this.normalize(payload));
        return Plan.fromJson(body?.data);
    }

    async deletePlan(id, adminPassword) {
        await this.api.ensureCsrf();
        await this.api.delete(`/owner/plans/${encodeURIComponent(id)}`, { admin_password: adminPassword });
    }

    /* ---------------------------------------------------------------- users */

    async updateUserRole(id, role) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/owner/users/${encodeURIComponent(id)}/role`, { role });
        return User.fromJson(body?.data);
    }

    /* ---------------------------------------------------------------- staff */

    async listStaff() {
        const body = await this.api.get('/owner/staff');
        return User.collection(body?.data);
    }

    async createStaff(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/owner/staff', payload);
        return User.fromJson(body?.data);
    }

    async updateStaffRole(id, role) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/owner/staff/${encodeURIComponent(id)}/role`, { role });
        return User.fromJson(body?.data);
    }

    async deleteStaff(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`/owner/staff/${encodeURIComponent(id)}`);
    }

    /** Accept either a model instance (with toPayload) or a plain object. */
    normalize(payload) {
        if (payload && typeof payload.toPayload === 'function') {
            return payload.toPayload();
        }

        return { ...(payload ?? {}) };
    }
}
