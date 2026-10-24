import Student from '../models/Student';
import StudentChangeRequest from '../models/StudentChangeRequest';

/**
 * The students of the teacher's sections. Teachers enroll students and
 * propose changes to their details; the admin edits, moves and removes.
 *
 *  list(filters)              GET    /api/students?search=&section_id=&grade_level_id=&school_year_id= -> Student[]
 *  get(id)                    GET    /api/students/{id}                     -> Student (with section.gradeLevel.subjects, assignments[pace, subject])
 *  create(payload)            POST   /api/students                          -> Student   payload: Student | snake_case object (section_id required)
 *  proposeChange(id, changes, message?) POST /api/students/{id}/change-requests -> StudentChangeRequest
 *                             changes: only the fields being changed (first_name, middle_name, last_name, extension, birth_date, sex)
 *  changeRequests()           GET    /api/student-change-requests           -> StudentChangeRequest[] (the teacher's own, pending first)
 *  cancelChangeRequest(id)    DELETE /api/student-change-requests/{id}      -> void (withdraw a pending proposal)
 */
export default class StudentService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    itemPath(id) {
        return `/students/${encodeURIComponent(id)}`;
    }

    changeRequestPath(id) {
        return `/student-change-requests/${encodeURIComponent(id)}`;
    }

    async list(filters = {}) {
        const body = await this.api.get('/students', filters);
        return Student.collection(body?.data);
    }

    async get(id) {
        const body = await this.api.get(this.itemPath(id));
        return Student.fromJson(body?.data);
    }

    async create(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/students', this.normalize(payload));
        return Student.fromJson(body?.data);
    }

    async proposeChange(id, changes, message = '') {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${this.itemPath(id)}/change-requests`, {
            ...changes,
            message: message ? String(message).trim() : null,
        });

        return StudentChangeRequest.fromJson(body?.data);
    }

    async changeRequests() {
        const body = await this.api.get('/student-change-requests');
        return StudentChangeRequest.collection(body?.data);
    }

    async cancelChangeRequest(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.changeRequestPath(id));
    }

    normalize(payload) {
        return payload && typeof payload.toPayload === 'function' ? payload.toPayload() : { ...(payload ?? {}) };
    }
}
