/**
 * The optional contact, emergency and health details of a student, shared by
 * the Student model, the enrollment / edit form, the teacher's change
 * proposals and the student pages. `key` is the API field, `prop` the model
 * property; `group` places the field on the form and on the student page.
 */
export const STUDENT_DETAIL_FIELDS = Object.freeze([
    { key: 'student_number', prop: 'studentNumber', label: 'Student ID number', group: 'identity' },
    { key: 'email', prop: 'email', label: 'Student email', group: 'contact' },
    { key: 'mobile', prop: 'mobile', label: 'Student mobile number', group: 'contact' },
    { key: 'address', prop: 'address', label: 'Address', group: 'contact' },
    { key: 'guardian_name', prop: 'guardianName', label: 'Emergency contact', group: 'emergency' },
    { key: 'guardian_relationship', prop: 'guardianRelationship', label: 'Relationship to the student', group: 'emergency' },
    { key: 'guardian_phone', prop: 'guardianPhone', label: 'Emergency contact phone', group: 'emergency' },
    { key: 'guardian_email', prop: 'guardianEmail', label: 'Emergency contact email', group: 'emergency' },
    { key: 'blood_type', prop: 'bloodType', label: 'Blood type', group: 'health' },
    { key: 'medical_notes', prop: 'medicalNotes', label: 'Allergies and medical conditions', group: 'health' },
]);

/** ABO / Rh blood types the school can record (the API accepts these only). */
export const BLOOD_TYPES = Object.freeze(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);

/** Suggestions for the relationship field (free text is fine too). */
export const GUARDIAN_RELATIONSHIPS = Object.freeze(['Mother', 'Father', 'Guardian', 'Grandmother', 'Grandfather', 'Aunt', 'Uncle', 'Sister', 'Brother']);

/** An empty details object (camelCase props, all ''). */
export function blankStudentDetails() {
    return Object.fromEntries(STUDENT_DETAIL_FIELDS.map((field) => [field.prop, '']));
}

/** The details of a Student (or anything with the same props), '' for missing values. */
export function studentDetailsOf(student) {
    return Object.fromEntries(STUDENT_DETAIL_FIELDS.map((field) => [field.prop, student?.[field.prop] ?? '']));
}

/** Trimmed text, or null for a blank value. */
export function cleanDetail(value) {
    const text = value === null || value === undefined ? '' : String(value).trim();

    return text === '' ? null : text;
}
