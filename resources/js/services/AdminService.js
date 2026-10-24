import User from '../models/User';
import Student from '../models/Student';
import StudentChangeRequest from '../models/StudentChangeRequest';
import SchoolYear from '../models/SchoolYear';
import GradeLevel from '../models/GradeLevel';
import Subject from '../models/Subject';
import Section from '../models/Section';
import TeachingAssignment from '../models/TeachingAssignment';
import TeachingAssignmentRequest from '../models/TeachingAssignmentRequest';
import Pace from '../models/Pace';
import PaceStockMovement from '../models/PaceStockMovement';
import Organization from '../models/Organization';
import Branch from '../models/Branch';
import JoinRequest from '../models/JoinRequest';
import PlanRequest from '../models/PlanRequest';
import PaceRequest from '../models/PaceRequest';

/**
 * /api/admin/* — the organization admin's area (platform staff may use it too,
 * narrowed to one organization through ApiClient.setScope()).
 *
 * Users
 *  listUsers(filters)              filters: { search?, role?, branch_id? } -> User[] (members of the organization)
 *  listTeachers(filters)           -> User[] (role teacher)
 *  getUser(id)                     -> User
 *  updateUser(id, payload)         payload: {name, email, admin_password} -> User   (throws ApiError)
 *  resetUserPassword(id, payload)  payload: {password, password_confirmation, admin_password?} -> void
 *  moveUserToBranch(id, branchId)  -> User
 *  updateUserRole(id, role)        role: 'admin' | 'teacher' -> User  (not your own role; admins within the plan's limit)
 *  removeMember(id)                -> void (the teacher leaves the organization)
 *
 * Curriculum (grade levels belong to a branch and a school year; subjects and sections to a grade level)
 *  listGradeLevels(filters)        filters: { branch_id?, teacher_id?, school_year_id? } -> GradeLevel[] (with branch, schoolYear, counts)
 *  getGradeLevel(id)               -> GradeLevel (with subjects, sections[teaching_assignments.teacher], assignments)
 *  createGradeLevel(payload)       payload: GradeLevel | {branch_id, school_year_id, name, sort_order?} -> GradeLevel
 *  updateGradeLevel(id, payload)   payload: {name, sort_order?} -> GradeLevel
 *  removeGradeLevel(id)            -> void (only without students)
 *  createSubject(gradeLevelId, payload)  payload: Subject | {name, sort_order?} -> Subject
 *  getSubject(id)                  -> Subject (with gradeLevel, teaching assignments, assignments)
 *  updateSubject(id, payload)      -> Subject
 *  removeSubject(id)               -> void
 *  createSection(gradeLevelId, payload)  payload: Section | {name, sort_order?} -> Section
 *  getSection(id)                  -> Section (with gradeLevel.subjects, teaching assignments, students)
 *  updateSection(id, payload)      -> Section
 *  removeSection(id)               -> void (only without students)
 *  promoteSection(id, moves)       moves: [{student_id, section_id|null}] -> {moved, stayed, students: Student[]} (null = stays)
 *
 * Who teaches what
 *  listTeachingAssignments(filters) filters: { branch_id?, teacher_id?, section_id? } -> TeachingAssignment[]
 *  assignTeacher(sectionId, subjectId, teacherId) PUT /sections/{s}/subjects/{sub}/teacher -> TeachingAssignment (replaces the current teacher)
 *  unassignTeacher(sectionId, subjectId)          DELETE ...                              -> void
 *  offerSubject(sectionId, subjectId)             PUT /sections/{s}/subjects/{sub}/offer    -> Section (the section now offers the subject)
 *  withdrawSubject(sectionId, subjectId)          DELETE ...                              -> Section (422 while a teacher is assigned or students take it)
 *  listTeachingRequests(filters)   filters: { status?, branch_id? } -> TeachingAssignmentRequest[] (pending first)
 *  acceptTeachingRequest(id)       -> TeachingAssignmentRequest (the slot is assigned to the teacher)
 *  declineTeachingRequest(id, reason?) -> TeachingAssignmentRequest
 *
 * Students
 *  listStudents(filters)           filters: { search?, teacher_id?, branch_id?, grade_level_id?, section_id?, school_year_id? } -> Student[]
 *  getStudent(id)                  -> { student: Student, schoolYears: SchoolYear[] }
 *  createStudent(payload)          payload: Student | snake_case object (section_id required) -> Student
 *  updateStudent(id, payload)      -> Student (section_id moves the student)
 *  removeStudent(id)               -> void
 *  listStudentChangeRequests(status?) -> StudentChangeRequest[] (pending first)
 *  acceptStudentChangeRequest(id)  -> StudentChangeRequest (the changes are applied)
 *  declineStudentChangeRequest(id, reason?) -> StudentChangeRequest
 *
 * School years
 *  listSchoolYears(teacherId?, filters?) -> SchoolYear[]
 *
 * PACE catalogs and stock
 *  listPaces(filters)              filters: { search?, branch_id?, teacher_id?, organization_id? } -> Pace[] (teacher_id: only PACEs that teacher holds, with my_stock; organization_id: platform owner only)
 *  getPace(id)                     -> Pace (with holdings[teacher], movements, assignments[student, subject])
 *  createPace(payload)             payload: Pace | {branch_id, name, number, pool_stock?} -> Pace
 *  createPaceRange(payload)        payload: {branch_id, name, start, end, pool_stock?} -> Pace[]
 *  updatePace(id, payload)         payload: {name, number} -> Pace
 *  removePace(id)                  -> void
 *  receiveStock(id, quantity, note?)            -> Pace (adds to the pool)
 *  adjustPool(id, delta, note?)                 -> Pace (corrects the pool; delta ≠ 0)
 *  distributeStock(id, teacherId, quantity, note?) -> Pace (pool → teacher)
 *  setHolding(id, teacherId, quantity)          -> Pace (the difference moves to/from the pool)
 *  listMovements(id)               -> PaceStockMovement[] (newest first)
 *  batchStock(paceIds, mode, quantity, note?) -> Pace[]  one pool change for many PACEs, all or nothing
 *                                  mode: 'receive' (booklets arrived, quantity >= 1), 'adjust' (add / remove a signed number),
 *                                  'set' (every pool becomes exactly quantity: a recount)
 *
 * Organization, branches, join / plan / stock requests, dashboard
 *  getOrganization()               -> Organization (branches, subscription, counts)
 *  updateOrganization(payload)     payload: {name?, address?, contact_email?, contact_phone?} -> Organization
 *  listBranches()                  -> Branch[]
 *  createBranch(payload)           payload: {name, address?, is_main?} -> Branch
 *  updateBranch(id, payload)       -> Branch
 *  removeBranch(id)                -> void
 *  listJoinRequests(status?, filters?)  filters: { organization_id? (platform owner) } -> JoinRequest[] (pending first)
 *  acceptJoinRequest(id, role?)    role: 'admin' | 'teacher' (default: what they asked for) -> JoinRequest
 *  declineJoinRequest(id, reason?) -> JoinRequest
 *  listPlanRequests()              -> PlanRequest[] (pending first)
 *  createPlanRequest(payload)      payload: {action: 'change'|'cancel', plan_id?, message?} -> PlanRequest
 *  cancelPlanRequest(id)           -> void
 *  listPaceRequests(filters)       filters: { status?, teacher_id?, branch_id?, search?, needed_from?, needed_to? } -> PaceRequest[]
 *  updatePaceRequest(id, payload)  payload: {pace_number?, pace_name?, quantity?, needed_by?} -> PaceRequest (pending only)
 *  givePaceRequest(id, payload?)   payload: {quantity?, decision_note?} -> PaceRequest (moves the quantity from the pool to the teacher;
 *                                  quantity 0 only registers the PACE in the catalog; 422 on `quantity` when the pool is short)
 *  declinePaceRequest(id, note?)   -> PaceRequest
 *  decidePaceRequests(action, ids, note?)  action: 'give' | 'decline' -> {done: PaceRequest[], skipped: [{id, teacherName, label, quantity, reason}]}
 *                                  (oldest first, each on its own: a request the pool cannot cover stays pending and comes back under `skipped`)
 *  getDashboardSummary(filters)    filters: { branchId?, teacherId?, schoolYearId?, gradeLevelId?, sectionId?, subjectId?, paceId?, organizationId? } -> summary
 */
export default class AdminService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    static path(...segments) {
        return `/admin/${segments.map((segment) => encodeURIComponent(segment)).join('/')}`;
    }

    normalize(payload) {
        return payload && typeof payload.toPayload === 'function' ? payload.toPayload() : { ...(payload ?? {}) };
    }

    /* ---------------------------------------------------------------- users */

    async listUsers(filters = {}) {
        const body = await this.api.get('/admin/users', filters);
        return User.collection(body?.data);
    }

    /** Only the teacher accounts. */
    async listTeachers(filters = {}) {
        return this.listUsers({ ...filters, role: 'teacher' });
    }

    async getUser(id) {
        const body = await this.api.get(AdminService.path('users', id));
        return User.fromJson(body?.data);
    }

    async updateUser(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(AdminService.path('users', id), payload);
        return User.fromJson(body?.data);
    }

    async resetUserPassword(id, payload) {
        await this.api.ensureCsrf();
        await this.api.put(`${AdminService.path('users', id)}/password`, payload);
    }

    async updateUserRole(id, role) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`${AdminService.path('users', id)}/role`, { role });
        return User.fromJson(body?.data);
    }

    async moveUserToBranch(id, branchId) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`${AdminService.path('users', id)}/branch`, { branch_id: branchId });
        return User.fromJson(body?.data);
    }

    async removeMember(id) {
        await this.api.ensureCsrf();
        await this.api.delete(`${AdminService.path('users', id)}/membership`);
    }

    /* ----------------------------------------------------------- curriculum */

    async promoteSection(id, moves) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('sections', id)}/promote`, { moves });
        const data = body?.data ?? {};

        return { moved: Number(data.moved ?? 0), stayed: Number(data.stayed ?? 0), students: Student.collection(data.students) };
    }

    async listGradeLevels(filters = {}) {
        const body = await this.api.get('/admin/grade-levels', filters);
        return GradeLevel.collection(body?.data);
    }

    async getGradeLevel(id) {
        const body = await this.api.get(AdminService.path('grade-levels', id));
        return GradeLevel.fromJson(body?.data);
    }

    async createGradeLevel(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/grade-levels', this.normalize(payload));
        return GradeLevel.fromJson(body?.data);
    }

    async updateGradeLevel(id, payload) {
        await this.api.ensureCsrf();
        const data = this.normalize(payload);
        delete data.branch_id;
        delete data.school_year_id;
        const body = await this.api.put(AdminService.path('grade-levels', id), data);
        return GradeLevel.fromJson(body?.data);
    }

    async removeGradeLevel(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('grade-levels', id));
    }

    async createSubject(gradeLevelId, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('grade-levels', gradeLevelId)}/subjects`, this.normalize(payload));
        return Subject.fromJson(body?.data);
    }

    async getSubject(id) {
        const body = await this.api.get(AdminService.path('subjects', id));
        return Subject.fromJson(body?.data);
    }

    async updateSubject(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(AdminService.path('subjects', id), this.normalize(payload));
        return Subject.fromJson(body?.data);
    }

    async removeSubject(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('subjects', id));
    }

    async createSection(gradeLevelId, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('grade-levels', gradeLevelId)}/sections`, this.normalize(payload));
        return Section.fromJson(body?.data);
    }

    async getSection(id) {
        const body = await this.api.get(AdminService.path('sections', id));
        return Section.fromJson(body?.data);
    }

    async updateSection(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(AdminService.path('sections', id), this.normalize(payload));
        return Section.fromJson(body?.data);
    }

    async removeSection(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('sections', id));
    }

    /* ----------------------------------------------------- who teaches what */

    async listTeachingAssignments(filters = {}) {
        const body = await this.api.get('/admin/teaching-assignments', filters);
        return TeachingAssignment.collection(body?.data);
    }

    async assignTeacher(sectionId, subjectId, teacherId) {
        await this.api.ensureCsrf();
        const body = await this.api.put(
            `${AdminService.path('sections', sectionId)}/subjects/${encodeURIComponent(subjectId)}/teacher`,
            { teacher_id: Number(teacherId) },
        );

        return TeachingAssignment.fromJson(body?.data);
    }

    async offerSubject(sectionId, subjectId) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`${AdminService.path('sections', sectionId)}/subjects/${encodeURIComponent(subjectId)}/offer`);
        return Section.fromJson(body?.data);
    }

    async withdrawSubject(sectionId, subjectId) {
        await this.api.ensureCsrf();
        const body = await this.api.delete(`${AdminService.path('sections', sectionId)}/subjects/${encodeURIComponent(subjectId)}/offer`);
        return Section.fromJson(body?.data);
    }

    async unassignTeacher(sectionId, subjectId) {
        await this.api.ensureCsrf();
        await this.api.delete(`${AdminService.path('sections', sectionId)}/subjects/${encodeURIComponent(subjectId)}/teacher`);
    }

    async listTeachingRequests(filters = {}) {
        const body = await this.api.get('/admin/teaching-assignment-requests', filters);
        return TeachingAssignmentRequest.collection(body?.data);
    }

    async acceptTeachingRequest(id) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('teaching-assignment-requests', id)}/accept`);
        return TeachingAssignmentRequest.fromJson(body?.data);
    }

    async declineTeachingRequest(id, reason = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('teaching-assignment-requests', id)}/decline`, { reason: reason || null });
        return TeachingAssignmentRequest.fromJson(body?.data);
    }

    /* ------------------------------------------------------------- students */

    async listStudents(filters = {}) {
        const body = await this.api.get('/admin/students', filters);
        return Student.collection(body?.data);
    }

    async getStudent(id) {
        const body = await this.api.get(AdminService.path('students', id));

        return {
            student: Student.fromJson(body?.data),
            schoolYears: SchoolYear.collection(body?.school_years),
        };
    }

    async createStudent(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/students', this.normalize(payload));
        return Student.fromJson(body?.data);
    }

    async updateStudent(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(AdminService.path('students', id), this.normalize(payload));
        return Student.fromJson(body?.data);
    }

    async removeStudent(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('students', id));
    }

    async listStudentChangeRequests(status = null) {
        const body = await this.api.get('/admin/student-change-requests', { status });
        return StudentChangeRequest.collection(body?.data);
    }

    async acceptStudentChangeRequest(id) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('student-change-requests', id)}/accept`);
        return StudentChangeRequest.fromJson(body?.data);
    }

    async declineStudentChangeRequest(id, reason = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('student-change-requests', id)}/decline`, { reason: reason || null });
        return StudentChangeRequest.fromJson(body?.data);
    }

    /* --------------------------------------------------------- school years */

    async listSchoolYears(teacherId = null, filters = {}) {
        const body = await this.api.get('/admin/school-years', { teacher_id: teacherId, ...filters });
        return SchoolYear.collection(body?.data);
    }

    /* ---------------------------------------------------- PACEs and stock */

    async listPaces(filters = {}) {
        const body = await this.api.get('/admin/paces', filters);
        return Pace.collection(body?.data);
    }

    async getPace(id) {
        const body = await this.api.get(AdminService.path('paces', id));
        return Pace.fromJson(body?.data);
    }

    async createPace(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/paces', this.normalize(payload));
        return Pace.fromJson(body?.data);
    }

    async createPaceRange(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/paces/range', payload);
        return Pace.collection(body?.data);
    }

    async updatePace(id, payload) {
        await this.api.ensureCsrf();
        const data = this.normalize(payload);
        const body = await this.api.put(AdminService.path('paces', id), { name: data.name, number: data.number });
        return Pace.fromJson(body?.data);
    }

    async removePace(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('paces', id));
    }

    async receiveStock(id, quantity, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('paces', id)}/stock/receive`, {
            quantity: Number(quantity),
            note: note || null,
        });

        return Pace.fromJson(body?.data);
    }

    async adjustPool(id, delta, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.patch(`${AdminService.path('paces', id)}/stock/pool`, {
            delta: Number(delta),
            note: note || null,
        });

        return Pace.fromJson(body?.data);
    }

    async distributeStock(id, teacherId, quantity, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('paces', id)}/stock/distribute`, {
            teacher_id: Number(teacherId),
            quantity: Number(quantity),
            note: note || null,
        });

        return Pace.fromJson(body?.data);
    }

    async setHolding(id, teacherId, quantity) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`${AdminService.path('paces', id)}/stock/holdings/${encodeURIComponent(teacherId)}`, {
            quantity: Number(quantity),
        });

        return Pace.fromJson(body?.data);
    }

    async batchStock(paceIds, mode, quantity, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post('/admin/paces/stock/batch', {
            pace_ids: paceIds.map((id) => Number(id)),
            mode,
            quantity: Number(quantity),
            note: note || null,
        });

        return Pace.collection(body?.data);
    }

    async listMovements(id) {
        const body = await this.api.get(`${AdminService.path('paces', id)}/movements`);
        return PaceStockMovement.collection(body?.data);
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
        const body = await this.api.put(AdminService.path('branches', id), payload);
        return Branch.fromJson(body?.data);
    }

    async removeBranch(id) {
        await this.api.ensureCsrf();
        await this.api.delete(AdminService.path('branches', id));
    }

    async listJoinRequests(status = null, filters = {}) {
        const body = await this.api.get('/admin/join-requests', { status, ...filters });
        return JoinRequest.collection(body?.data);
    }

    async acceptJoinRequest(id, role = null) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('join-requests', id)}/accept`, role ? { role } : {});
        return JoinRequest.fromJson(body?.data);
    }

    async declineJoinRequest(id, reason = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('join-requests', id)}/decline`, { reason: reason || null });
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
        await this.api.delete(AdminService.path('plan-requests', id));
    }

    /* ------------------------------------------------------ PACE requests */

    async listPaceRequests(filters = {}) {
        const body = await this.api.get('/admin/pace-requests', filters);
        return PaceRequest.collection(body?.data);
    }

    async updatePaceRequest(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(AdminService.path('pace-requests', id), payload);
        return PaceRequest.fromJson(body?.data);
    }

    async givePaceRequest(id, payload = {}) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('pace-requests', id)}/give`, payload);
        return PaceRequest.fromJson(body?.data);
    }

    async declinePaceRequest(id, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('pace-requests', id)}/decline`, { decision_note: note || null });
        return PaceRequest.fromJson(body?.data);
    }

    async decidePaceRequests(action, ids, note = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${AdminService.path('pace-requests')}/batch`, { action, ids, decision_note: note || null });
        const data = body?.data ?? {};

        return {
            done: PaceRequest.collection(data.done),
            skipped: (Array.isArray(data.skipped) ? data.skipped : []).map((row) => ({
                id: row.id,
                teacherName: row.teacher_name ?? '',
                label: `#${row.pace_number} · ${row.pace_name}`,
                quantity: Number(row.quantity ?? 0),
                reason: row.reason ?? '',
            })),
        };
    }

    /* ----------------------------------------------------------- dashboard */

    async getDashboardSummary(filters = {}) {
        const body = await this.api.get('/admin/dashboard/summary', {
            branch_id: filters.branchId ?? '',
            teacher_id: filters.teacherId ?? '',
            school_year_id: filters.schoolYearId ?? '',
            grade_level_id: filters.gradeLevelId ?? '',
            section_id: filters.sectionId ?? '',
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
            sections: count(data.sections),
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
                branchName: row.branch_name ?? '',
                organizationName: row.organization_name ?? '',
                total: count(row.total),
                tested: count(row.tested),
                issued: count(row.issued),
                assigned: count(row.assigned),
                averageScore: nullable(row.average_score),
            })),
            byGradeLevel: list(data.by_grade_level).map((row) => ({
                id: count(row.id),
                name: row.name ?? '',
                branchName: row.branch_name ?? '',
                organizationName: row.organization_name ?? '',
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
                sectionName: row.section_name ?? '',
                gradeLevelName: row.grade_level_name ?? '',
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
                branchName: row.branch_name ?? '',
                organizationName: row.organization_name ?? '',
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
                sections: count(totals.sections),
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
                organizationName: teacher.organization_name ?? '',
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
            sections: 0,
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
            totals: { organizations: 0, branches: 0, teachers: 0, admins: 0, schoolYears: 0, gradeLevels: 0, sections: 0, students: 0, paces: 0, assignments: 0, tested: 0, scored: 0, passed: 0 },
            recentStudents: [],
            recentUsers: [],
            schoolYears: [],
            teacherActivity: [],
        };
    }
}
