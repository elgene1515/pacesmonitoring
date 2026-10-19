import ResourceService from './ResourceService';
import Student from '../models/Student';

/**
 * /api/students
 *
 *  list(filters)        filters: { search?: string, grade_level_id?: number|string } -> Student[]
 *  get(id)              -> Student (with gradeLevel.subjects and assignments[pace, subject])
 *  create(payload)      payload: Student | snake_case object -> Student
 *  update(id, payload)  -> Student
 *  remove(id)           -> void
 */
export default class StudentService extends ResourceService {
    constructor(api) {
        super(api, '/students', Student);
    }
}
