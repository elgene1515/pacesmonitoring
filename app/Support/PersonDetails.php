<?php

namespace App\Support;

/**
 * A member's own name parts and contact details, shared by sign-up and the
 * profile form: first name, optional middle name, surname, optional suffix,
 * a contact number and an optional address.
 */
final class PersonDetails
{
    /** Digits with the usual separators: +63 917 123 4567, (02) 8123-4567. */
    public const PHONE = '/^\+?[0-9 ()\-.]{7,30}$/';

    /** The fields this class handles, in form order. */
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'phone', 'address'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:30', 'regex:'.self::PHONE],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'middle_name' => 'middle name',
            'last_name' => 'surname',
            'phone' => 'contact number',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'phone.regex' => 'Enter a contact number using digits (spaces, dashes, brackets and a leading + are fine).',
        ];
    }

    /**
     * The validated parts, trimmed, with blanks as null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function clean(array $data): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $clean[$field] = $value === '' ? null : $value;
        }

        return $clean;
    }

    /**
     * "First Middle Surname Suffix", without the parts that are empty.
     */
    public static function fullName(?string $first, ?string $middle, ?string $last, ?string $suffix): string
    {
        return implode(' ', array_filter(
            [$first, $middle, $last, $suffix],
            static fn (?string $part): bool => $part !== null && trim($part) !== ''
        ));
    }
}
