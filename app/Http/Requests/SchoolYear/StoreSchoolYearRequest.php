<?php

namespace App\Http\Requests\SchoolYear;

use App\Models\SchoolYear;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreSchoolYearRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:'.SchoolYear::NAME_PATTERN, $this->consecutiveYears(), $this->uniqueName()],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'is_current' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The school year must be in the format YYYY-YYYY, e.g. 2026-2027.',
            'name.unique' => 'Your organization already has this school year.',
        ];
    }

    /**
     * Custom attribute names used in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'school year',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'is_current' => 'current flag',
        ];
    }

    /**
     * Cross-field validation: the end date must come after the start date.
     * Dates not present in the payload fall back to the stored values (update).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = $this->effectiveDate('start_date');
            $end = $this->effectiveDate('end_date');

            if ($start !== null && $end !== null && $end->lte($start)) {
                $validator->errors()->add('end_date', 'The end date must be after the start date.');
            }
        });
    }

    /**
     * The validated attributes that may be persisted on a school year.
     *
     * @return array<string, mixed>
     */
    public function schoolYearData(): array
    {
        $data = $this->safe()->only(['name', 'start_date', 'end_date', 'is_current']);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('is_current', $data)) {
            $data['is_current'] = $this->boolean('is_current');
        }

        return $data;
    }

    /**
     * School year labels only need to be unique within the organization.
     */
    protected function uniqueName(): Unique
    {
        return Rule::unique('school_years', 'name')->where('organization_id', $this->user()?->organization_id);
    }

    /**
     * "2026-2027" is valid; "2026-2028" or "2027-2026" is not.
     *
     * @return \Closure(string, mixed, \Closure(string): void): void
     */
    protected function consecutiveYears(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! preg_match(SchoolYear::NAME_PATTERN, $value)) {
                return;
            }

            [$start, $end] = array_map('intval', explode('-', $value));

            if ($end !== $start + 1) {
                $fail('The school year must span two consecutive years, e.g. 2026-2027.');
            }
        };
    }

    /**
     * Resolve a date field from the request when present, otherwise from the bound school year.
     */
    protected function effectiveDate(string $field): ?Carbon
    {
        if ($this->has($field)) {
            $value = $this->input($field);

            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        }

        $year = $this->route('schoolYear');
        $stored = $year instanceof SchoolYear ? $year->{$field} : null;

        return $stored instanceof Carbon ? $stored->copy()->startOfDay() : null;
    }
}
