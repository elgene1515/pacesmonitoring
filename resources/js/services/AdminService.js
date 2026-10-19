import User from '../models/User';
import Student from '../models/Student';
import SchoolYear from '../models/SchoolYear';
import GradeLevel from '../models/GradeLevel';
import Subject from '../models/Subject';
import Pace from '../models/Pace';
import Organization from '../models/Organization';
import Branch from '../models/Branch';
import JoinRequest from '../models/JoinRequest';
import PlanRequest from '../models/PlanRequest';
import PaceRequest from '../models/PaceRequest';

/**
 * /api/admin/* — the organization admin's area (the owner may use it too,
 * narrowed to one organization through ApiClient.setScope()).
 *
 *  listUsers(filters)              filters: { search?, role?, branch_id? } -> User[] (members of the organization)
 *  getUser(id)                     -> User
 *  updateUser(id, payload)         payload: {name, email, admin_password} -> User   (throws ApiError)
 *  resetUserPassword(id, payload)  payload: {password, password_confirmation, admin_password?} -> void (throws ApiError)
 *                                  (admin_password is only required when resetting someone else's password)
 *  moveUserToBranch(id, branchId)  -> User
 *  removeMember(id)                -> void (the teacher leaves the organization; their records stay theirs)
 *  listStudents(filters)           filters: { search?, teacher_id?, branch_id?, grade_level_id?, school_year_id? } -> Student[]
 *  getStudent(id)                  -> { student: Student, schoolYears: SchoolYear[] } (the organization's school years)
 *  listGradeLevels(teacherId, schoolYearId?) -> GradeLevel[]
 *  listSchoolYears(teacherId?)     -> SchoolYear[] (the organization's school years)
 *  listPaces(filters)              filters: { search?, teacher_id?, branch_id? } -> Pace[]
 *  getPace(id)                     -> Pace (with assignments[student, subject, pace])
 *  getOrganization()               -> Organization (branches, subscription, counts)
 *  updateOrganization(payload)     payload: {name?, address?, contact_email?, contact_phone?} -> Organization
 *  listBranches()                  -> Branch[]
 *  createBranch(payload)           payload: {name, address?, is_main?} -> Branch
 *  updateBranch(id, payload)       -> Branch
 *  removeBranch(id)                -> void
 *  listJoinRequests(status?)       -> JoinRequest[] (pending first)
 *  acceptJoinRequest(id)           -> JoinRequest
 *  declineJoinRequest(id, reason?) -> JoinRequest
 *  listPlanRequests()              -> PlanRequest[] (pending first)
 *  createPlanRequest(payload)      payload: {action: 'change'|'cancel', plan_id?, message?} -> PlanRequest
 *  cancelPlanRequest(id)           -> void (withdraw the pending request)
 *  listPaceRequests(filters)       filters: { status?, teacher_id?, branch_id? } -> PaceRequest[] (pending first)
 *  updatePaceRequest(id, payload)  payload: {pace_number?, pace_name?, quantity?, needed_by?} -> PaceRequest (pending only)
 *  givePaceRequest(id, payload?)   payload: {quantity?, decision_note?} -> PaceRequest (adds the quantity to the linked PACE's stock)
 *  declinePaceRequest(id, note?)   -> PaceRequest
 *  getDashboardSummary(filters)    filters: { branchId?, teacherId?, schoolYearId?, gradeLevelId?, subjectId?, paceId?, organizationId? } -> summary
 */
export default class AdminService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    /* ---------------------------------------------------------------- users */

    async listUsers(filters = {}) {
        const body = await this.api.get('/admin/users', filters);
        return User.collection(body?.data);
    }

    /** Only the teacher accounts (the users who own students, PACEs and grade levels). */
    async listTeachers(filters = {}) {
        return this.listUsers({ ...filters, role: 'teacher' });
    }

    async getUser(id) {
        const body = await this.api.get(`/admin/users/${encodeURIComponent(id)}`);
        return User.fromJson(body?.data);
    }

    async updateUser(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/admin/users/${encodeURIComponent(id)}`, payload);
        return User.fromJson(body?.data);
    }

    async resetUserPassword(id, payload) {
        await this.api.ensureCsrf();
        await this.api.put(`/admin/users/${encodeURIComponent(id)}/password`, payload);
    }

    async moveUserToBranch(id, branchId) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/admin/users/${encodeURIComponent(id)}/branch`, { branch_id: branchId });
        return User.fromJson(body?.data);
    }

    async removeMember(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`/admin/users/${encodeURIComponent(id)}/membership`);
    }

    /* ------------------------------------------------------------- records */

    async listStudents(filters = {}) {
        const body = await this.api.get('/admin/students', filters);
        return Student.collection(body?.data);
    }

    async getStudent(id) {
        const body = await this.api.get(`/admin/students/${encodeURIComponent(id)}`);

        return {
            student: Student.fromJson(body?.data),
            schoolYears: SchoolYear.collection(body?.school_years),
        };
    }

    async listGradeLevels(teacherId, schoolYearId = null) {
        const body = await this.api.get('/admin/grade-levels', { teacher_id: teacherId, school_year_id: schoolYearId });
        return GradeLevel.collection(body?.data);
    }

    /** A teacher's grade level with its subjects and every record (read-only). */
    async getGradeLevel(id) {
        const body = await this.api.get(`/admin/grade-levels/${encodeURIComponent(id)}`);
        return GradeLevel.fromJson(body?.data);
    }

    /** A teacher's subject with its grade level and every record (read-only). */
    async getSubject(id) {
        const body = await this.api.get(`/admin/subjects/${encodeURIComponent(id)}`);
        return Subject.fromJson(body?.data);
    }

    async listSchoolYears(teacherId = null, filters = {}) {
        const body = await this.api.get('/admin/school-years', { teacher_id: teacherId, ...filters });
        return SchoolYear.collection(body?.data);
    }

    async listPaces(filters = {}) {
        const body = await this.api.get('/admin/paces', filters);
        return Pace.collection(body?.data);
    }

    async getPace(id) {
        const body = await this.api.get(`/admin/paces/${encodeURIComponent(id)}`);
        return Pace.fromJson(body?.data);
    }

    /* -------------------------------------------------------- organization */

    async getOrganization() {
        const body = await this.api.get('/admin/organization');
        return Organization.fromJson(body?.data);
    }

    async updateOrganization(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put('/admin/organization', payload);
        return Organization.fromJson(body?.data);
    }

    async listBranches(filters = {}) {
        const body = await this.api.get('/admin/branches', filters);
        return Branch.collection(body?.data);
    }

    async createBranch(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/branches', payload);
        return Branch.fromJson(body?.data);
    }

    async updateBranch(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/admin/branches/${encodeURIComponent(id)}`, payload);
        return Branch.fromJson(body?.data);
    }

    async removeBranch(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`/admin/branches/${encodeURIComponent(id)}`);
    }

    async listJoinRequests(status = null) {
        const body = await this.api.get('/admin/join-requests', { status });
        return JoinRequest.collection(body?.data);
    }

    async acceptJoinRequest(id) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/admin/join-requests/${encodeURIComponent(id)}/accept`);
        return JoinRequest.fromJson(body?.data);
    }

    async declineJoinRequest(id, reason = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/admin/join-requests/${encodeURIComponent(id)}/decline`, { reason: reason || null });
        return JoinRequest.fromJson(body?.data);
    }

    async listPlanRequests() {
        const body = await this.api.get('/admin/plan-requests');
        return PlanRequest.collection(body?.data);
    }

    async createPlanRequest(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/plan-requests', payload);
        return PlanRequest.fromJson(body?.data);
    }

    async cancelPlanRequest(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`/admin/plan-requests/${encodeURIComponent(id)}`);
    }

    /* ------------------------------------------------------ PACE requests */

    async listPaceRequests(filters = {}) {
        const body = await this.api.get('/admin/pace-requests', filters);
        return PaceRequest.collection(body?.data);
    }

    async updatePaceRequest(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`/admin/pace-requests/${encodeURIComponent(id)}`, payload);
        return PaceRequest.fromJson(body?.data);
    }

    async givePaceRequest(id, payload = {}) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/admin/pace-requests/${encodeURIComponent(id)}/give`, payload);
        return PaceRequest.fromJson(body?.data);
    }

    async declinePaceRequest(id, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`/admin/pace-requests/${encodeURIComponent(id)}/decline`, { decision_note: note || null });
        return PaceRequest.fromJson(body?.data);
    }

    /* ----------------------------------------------------------- dashboard */

    async getDashboardSummary(filters = {}) {
        const body = await this.api.get('/admin/dashboard/summary', {
            branch_id: filters.branchId ?? '',
            teacher_id: filters.teacherId ?? '',
            school_year_id: filters.schoolYearId ?? '',
            grade_level_id: filters.gradeLevelId ?? '',
            subject_id: filters.subjectId ?? '',
            pace_id: filters.paceId ?? '',
            organization_id: filters.organizationId ?? '',
        });
        const data = body?.data ?? {};
        const totals = data.totals ?? {};
        const count = (value) => Number(value ?? 0);
        const list = (value) => (Array.isArray(value) ? value : []);
        const nullable = (value) => (value === null || value === undefined ? null : Number(value));

        return {
            organizationId: nullable(data.organization_id),
            branchId: nullable(data.branch_id),
            teacherId: nullable(data.teacher_id),
            schoolYear: SchoolYear.fromJson(data.school_year),
            teachers: count(data.teachers),
            gradeLevels: count(data.grade_levels),
            students: count(data.students),
            paces: count(data.paces),
            assignments: count(data.assignments),
            tested: count(data.tested),
            scored: count(data.scored),
            passed: count(data.passed),
            issued: count(data.issued),
            assigned: count(data.assigned),
            retakes: count(data.retakes),
            failedCount: count(data.failed_count),
            byQuarter: list(data.by_quarter).map((row) => ({
                quarter: count(row.quarter),
                total: count(row.total),
                tested: count(row.tested),
                issued: count(row.issued),
                assigned: count(row.assigned),
                passed: count(row.passed),
                failed: count(row.failed),
                retakes: count(row.retakes),
            })),
            bySubject: list(data.by_subject).map((row) => ({
                id: count(row.id),
                name: row.name ?? '',
                gradeLevelName: row.grade_level_name ?? '',
                total: count(row.total),
                tested: count(row.tested),
                issued: count(row.issued),
                assigned: count(row.assigned),
                averageScore: nullable(row.average_score),
            })),
            byGradeLevel: list(data.by_grade_level).map((row) => ({
                id: count(row.id),
                name: row.name ?? '',
                teacherName: row.teacher_name ?? '',
                total: count(row.total),
                male: count(row.male),
                female: count(row.female),
            })),
            failed: list(data.failed).map((row) => ({
                id: row.id,
                failed: Boolean(row.failed),
                retake: Boolean(row.retake),
                studentId: count(row.student_id),
                studentName: row.student_name ?? '',
                teacherName: row.teacher_name ?? '',
                subjectName: row.subject_name ?? '',
                paceNumber: row.pace_number ?? '',
                paceName: row.pace_name ?? '',
                score: nullable(row.score),
                quarter: count(row.quarter),
                schoolYear: row.school_year ?? '',
                dateTested: row.date_tested ?? null,
            })),
            topPerformers: list(data.top_performers).map((row) => ({
                studentId: count(row.student_id),
                studentName: row.student_name ?? '',
                teacherName: row.teacher_name ?? '',
                averageScore: nullable(row.average_score),
                scoredCount: count(row.scored_count),
                passedCount: count(row.passed_count),
                failedCount: count(row.failed_count),
                retakeCount: count(row.retake_count),
            })),
            scoreDistribution: list(data.score_distribution).map((row) => ({
                label: row.label ?? '',
                total: count(row.total),
            })),
            hardestPaces: list(data.hardest_paces).map((row) => ({
                paceId: count(row.pace_id),
                paceNumber: row.pace_number ?? '',
                paceName: row.pace_name ?? '',
                totalCount: count(row.total_count),
                scoredCount: count(row.scored_count),
                failedCount: count(row.failed_count),
                failRate: row.fail_rate === null || row.fail_rate === undefined ? 0 : Number(row.fail_rate),
                retakeCount: count(row.retake_count),
                retakeRate: row.retake_rate === null || row.retake_rate === undefined ? 0 : Number(row.retake_rate),
            })),
            totals: {
                organizations: count(totals.organizations),
                branches: count(totals.branches),
                teachers: count(totals.teachers),
                admins: count(totals.admins),
                schoolYears: count(totals.school_years),
                gradeLevels: count(totals.grade_levels),
                students: count(totals.students),
                paces: count(totals.paces),
                assignments: count(totals.assignments),
                tested: count(totals.tested),
                scored: count(totals.scored),
                passed: count(totals.passed),
            },
            recentStudents: Student.collection(data.recent_students),
            recentUsers: User.collection(data.recent_users),
            schoolYears: list(data.school_years).map((year) => ({
                id: year.id,
                name: year.name,
                isCurrent: Boolean(year.is_current),
                organizationName: year.organization?.name ?? '',
                studentsCount: count(year.students_count),
                gradeLevelsCount: count(year.grade_levels_count),
            })),
            teacherActivity: list(data.teacher_activity).map((teacher) => ({
                id: teacher.id,
                name: teacher.name,
                branchName: teacher.branch_name ?? '',
                studentsCount: count(teacher.students_count),
                assignmentsCount: count(teacher.assignments_count),
                testedCount: count(teacher.tested_count),
                issuedCount: count(teacher.issued_count),
                assignedCount: count(teacher.assigned_count),
            })),
        };
    }

    /** Empty summary used as initial state before the first load. */
    static emptyDashboard() {
        return {
            organizationId: null,
            branchId: null,
            teacherId: null,
            schoolYear: null,
            teachers: 0,
            gradeLevels: 0,
            students: 0,
            paces: 0,
            assignments: 0,
            tested: 0,
            scored: 0,
            passed: 0,
            issued: 0,
            assigned: 0,
            retakes: 0,
            failedCount: 0,
            byQuarter: [],
            bySubject: [],
            byGradeLevel: [],
            failed: [],
            topPerformers: [],
            scoreDistribution: [],
            hardestPaces: [],
            totals: { organizations: 0, branches: 0, teachers: 0, admins: 0, schoolYears: 0, gradeLevels: 0, students: 0, paces: 0, assignments: 0, tested: 0, scored: 0, passed: 0 },
            recentStudents: [],
            recentUsers: [],
            schoolYears: [],
            teacherActivity: [],
        };
    }
}
