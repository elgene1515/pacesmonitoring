import User from '../models/User';
import JoinRequest from '../models/JoinRequest';
import Plan from '../models/Plan';

/**
 * Onboarding: registering a school (admin) or joining one (teacher).
 *
 *  lookupOrganization(code)        GET  /api/organizations/lookup?code= -> { id, name, code, branches: [{id, name, code, isMain}] }
 *  setupOrganization(payload)      POST /api/organizations {name, address?, contact_email?, contact_phone?, branches?: string[]}
 *                                  -> User (refreshed, now with an organization)
 *  submitJoinRequest(payload)      POST /api/join-requests {organization_code, branch_id, message?} -> JoinRequest
 *  cancelJoinRequest(id)           DELETE /api/join-requests/{id} -> void
 *  listPlans()                     GET  /api/plans -> Plan[] (the active plans a school can pick)
 */
export default class TenantService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    async lookupOrganization(code) {
        const body = await this.api.get('/organizations/lookup', { code });
        const data = body?.data ?? {};

        return {
            id: data.id ?? null,
            name: data.name ?? '',
            code: data.code ?? '',
            branches: (Array.isArray(data.branches) ? data.branches : []).map((branch) => ({
                id: branch.id,
                name: branch.name ?? '',
                code: branch.code ?? '',
                isMain: Boolean(branch.is_main),
            })),
        };
    }

    async setupOrganization(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/organizations', payload);
        return User.fromJson(body?.data);
    }

    async submitJoinRequest(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/join-requests', payload);
        return JoinRequest.fromJson(body?.data);
    }

    async cancelJoinRequest(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`/join-requests/${encodeURIComponent(id)}`);
    }

    async listPlans() {
        const body = await this.api.get('/plans');
        return Plan.collection(body?.data);
    }
}
