<?php

namespace App\Http\Requests\Student;

class UpdateStudentRequest extends StoreStudentRequest
{
    /**
     * Updating a student requires the same full payload as creating one;
     * only middle_name and extension remain optional.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules();
    }
}
