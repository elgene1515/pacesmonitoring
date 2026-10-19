<?php

namespace App\Http\Requests\Organization;

class UpdateBranchRequest extends StoreBranchRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_main' => ['sometimes', 'boolean'],
        ];
    }
}
