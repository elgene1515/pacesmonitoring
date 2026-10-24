<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Student;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The optional contact, emergency and health details of a student, shared by
 * the enrollment / edit form and the teacher's change proposals.
 */
final class StudentDetails
{
    /** Blood types offered (ABO and Rh). */
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** The optional detail fields, in form order. */
    public const FIELDS = [
        'student_number',
        'email',
        'mobile',
        'address',
        'guardian_name',
        'guardian_relationship',
        'guardian_phone',
        'guardian_email',
        'blood_type',
        'medical_notes',
    ];

    /** Digits with the usual separators: +63 917 123 4567, (02) 8123-4567. */
    private const PHONE = PersonDetails::PHONE;

    /**
     * Validation rules for the optional details (`sometimes` keeps partial updates partial).
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $partial = false): array
    {
        $base = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'student_number' => [...$base, 'string', 'max:50'],
            'email' => [...$base, 'email', 'max:255'],
            'mobile' => [...$base, 'string', 'max:30', 'regex:'.self::PHONE],
            'address' => [...$base, 'string', 'max:500'],
            'guardian_name' => [...$base, 'string', 'max:255'],
            'guardian_relationship' => [...$base, 'string', 'max:50'],
            'guardian_phone' => [...$base, 'string', 'max:30', 'regex:'.self::PHONE],
            'guardian_email' => [...$base, 'email', 'max:255'],
            'blood_type' => [...$base, 'string', Rule::in(self::BLOOD_TYPES)],
            'medical_notes' => [...$base, 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'student_number' => 'student ID number',
            'email' => 'student email',
            'mobile' => 'student mobile number',
            'guardian_name' => 'emergency contact name',
            'guardian_relationship' => 'relationship to the student',
            'guardian_phone' => 'emergency contact phone',
            'guardian_email' => 'emergency contact email',
            'blood_type' => 'blood type',
            'medical_notes' => 'medical and health information',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'mobile.regex' => 'Enter a phone number using digits (spaces, dashes, brackets and a leading + are fine).',
            'guardian_phone.regex' => 'Enter a phone number using digits (spaces, dashes, brackets and a leading + are fine).',
            'blood_type.in' => 'Pick a blood type from the list.',
        ];
    }

    /**
     * Blank strings become null and text is trimmed, so "" never lands in the database.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            $data[$field] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * Fails when another student of the same school already has this ID number.
     * `$section` names the school; `$ignore` is the student being edited.
     */
    public static function uniqueNumber(?Section $section, ?int $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($section, $ignore): void {
            $number = trim((string) $value);

            if ($number === '' || $section === null) {
                return;
            }

            $section->loadMissing('gradeLevel.branch');
            $organizationId = $section->gradeLevel?->branch?->organization_id;

            $taken = Student::query()
                ->forOrganization($organizationId)
                ->where('student_number', $number)
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
                ->exists();

            if ($taken) {
                $fail('Another student of this school already has this ID number.');
            }
        };
    }
}
