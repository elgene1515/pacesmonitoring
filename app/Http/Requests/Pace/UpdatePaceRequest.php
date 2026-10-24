<?php

namespace App\Http\Requests\Pace;

use App\Models\Pace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename or renumber a PACE (it stays in its branch; the pool only changes
 * through the stock ledger).
 */
class UpdatePaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'number' => ['required', 'digits_between:1,50', $this->notInCatalog()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.digits_between' => 'The PACE number must contain digits only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paceData(): array
    {
        $data = $this->safe()->only(['name', 'number']);
        $data['name'] = trim((string) $data['name']);

        return $data;
    }

    protected function notInCatalog(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $pace = Pace::query()->find((int) $this->route('pace'));

            if ($pace === null) {
                return;
            }

            $exists = Pace::query()
                ->where('branch_id', $pace->branch_id)
                ->where('number', (string) $value)
                ->where('name', trim((string) $this->input('name')))
                ->whereKeyNot($pace->id)
                ->exists();

            if ($exists) {
                $fail("This PACE is already in the branch's catalog.");
            }
        };
    }
}
