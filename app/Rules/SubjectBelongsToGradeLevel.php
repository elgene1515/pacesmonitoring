<?php

namespace App\Rules;

use App\Models\Subject;
use Illuminate\Contracts\Validation\InvokableRule;

class SubjectBelongsToGradeLevel implements InvokableRule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private readonly ?int $gradeLevelId)
    {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     * @return void
     */
    public function __invoke($attribute, $value, $fail)
    {
        if ($value === null || $value === '') {
            return;
        }

        $subject = Subject::query()->find($value);

        if ($subject === null) {
            $fail('The selected subject is invalid.');

            return;
        }

        if ($this->gradeLevelId === null || (int) $subject->grade_level_id !== $this->gradeLevelId) {
            $fail("The selected subject does not belong to the student's grade level.");
        }
    }
}
