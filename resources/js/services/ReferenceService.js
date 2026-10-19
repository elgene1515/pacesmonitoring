import GradeLevel from '../models/GradeLevel';
import Subject from '../models/Subject';

/**
 * Grade levels and subjects. Every call only ever sees the signed-in user's own records.
 *
 *  gradeLevels()                 GET    /api/grade-levels               -> GradeLevel[] (each with subjects)
 *  gradeLevel(id)                GET    /api/grade-levels/{id}          -> GradeLevel (with subjects, assignments[student, subject, pace])
 *  subjects(gradeLevelId?)       GET    /api/subjects?grade_level_id=   -> Subject[]
 *  subject(id)                   GET    /api/subjects/{id}              -> Subject (with gradeLevel, assignments[student, pace])
 *  createGradeLevel(payload)     POST   /api/grade-levels               -> GradeLevel
 *  updateGradeLevel(id, payload) PUT    /api/grade-levels/{id}          -> GradeLevel
 *  removeGradeLevel(id)          DELETE /api/grade-levels/{id}
 *  createSubject(payload)        POST   /api/subjects                   -> Subject   payload: { grade_level_id, name, sort_order? }
 *  updateSubject(id, payload)    PUT    /api/subjects/{id}              -> Subject   payload: { name, sort_order? }
 *  removeSubject(id)             DELETE /api/subjects/{id}
 *
 * Payloads may be model instances (with toPayload) or plain snake_case objects.
 */
export default class ReferenceService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    gradeLevelPath(id) {
        return `/grade-levels/${encodeURIComponent(id)}`;
    }

    subjectPath(id) {
        return `/subjects/${encodeURIComponent(id)}`;
    }

    /** -> GradeLevel[] (each with subjects) */
    async gradeLevels() {
        const body = await this.api.get('/grade-levels');
        return GradeLevel.collection(body?.data);
    }

    /** -> GradeLevel (with its subjects and every PACE record filed under them) */
    async gradeLevel(id) {
        const body = await this.api.get(this.gradeLevelPath(id));
        return GradeLevel.fromJson(body?.data);
    }

    /** -> Subject[] */
    async subjects(gradeLevelId) {
        const body = await this.api.get('/subjects', { grade_level_id: gradeLevelId });
        return Subject.collection(body?.data);
    }

    /** -> Subject (with its grade level and every PACE record filed under it) */
    async subject(id) {
        const body = await this.api.get(this.subjectPath(id));
        return Subject.fromJson(body?.data);
    }

    /** -> GradeLevel */
    async createGradeLevel(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/grade-levels', this.normalize(payload));
        return GradeLevel.fromJson(body?.data);
    }

    /** -> GradeLevel */
    async updateGradeLevel(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(this.gradeLevelPath(id), this.normalize(payload));
        return GradeLevel.fromJson(body?.data);
    }

    /** -> void */
    async removeGradeLevel(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.gradeLevelPath(id));
    }

    /** -> Subject */
    async createSubject(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/subjects', this.normalize(payload));
        return Subject.fromJson(body?.data);
    }

    /** -> Subject */
    async updateSubject(id, payload) {
        await this.api.ensureCsrf();
        const data = this.normalize(payload);
        delete data.grade_level_id;
        const body = await this.api.put(this.subjectPath(id), data);
        return Subject.fromJson(body?.data);
    }

    /** -> void */
    async removeSubject(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.subjectPath(id));
    }

    /** Accept either a model instance (with toPayload) or a plain object. */
    normalize(payload) {
        if (payload && typeof payload.toPayload === 'function') {
            return payload.toPayload();
        }

        return { ...(payload ?? {}) };
    }
}
