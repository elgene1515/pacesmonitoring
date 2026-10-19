import User from '../models/User';

/**
 * Sanctum SPA authentication (cookie / session based).
 */
export default class AuthService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    /**
     * POST /api/register {name, email, password, password_confirmation, role: 'admin'|'teacher'} -> User (already logged in)
     */
    async register(payload) {
        await this.api.csrf();
        const body = await this.api.post('/register', payload);
        return User.fromJson(body?.data);
    }

    /**
     * POST /api/login {email, password} -> User
     */
    async login(credentials) {
        await this.api.csrf();
        const body = await this.api.post('/login', credentials);
        return User.fromJson(body?.data);
    }

    /**
     * POST /api/logout -> void
     */
    async logout() {
        await this.api.ensureCsrf();
        await this.api.post('/logout');
    }

    /**
     * GET /api/user -> User (with organization, branch, subscription and join request; throws ApiError 401 when not authenticated)
     */
    async me() {
        const body = await this.api.get('/user');
        return User.fromJson(body?.data);
    }

    /**
     * PUT /api/profile {name, email} -> User
     */
    async updateProfile(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put('/profile', payload);
        return User.fromJson(body?.data);
    }

    /**
     * PUT /api/password {current_password, password, password_confirmation} -> void
     */
    async updatePassword(payload) {
        await this.api.ensureCsrf();
        await this.api.put('/password', payload);
    }
}
