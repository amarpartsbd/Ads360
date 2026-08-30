<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The business details a client declares for verification (spec §11).
 *
 * Authorization is the policy's job; this class only decides whether the
 * submitted values are well formed.
 */
final class SubmitVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller resolves the profile and authorizes it explicitly,
        // because the profile may not exist yet on a first submission.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_business_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:64'],
            'website' => ['nullable', 'url', 'max:255'],
            'facebook_page' => ['nullable', 'url', 'max:255'],

            'contact_number' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'business_email' => ['required', 'email:rfc,strict', 'max:255'],

            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'size:2'],

            'authorized_person_name' => ['required', 'string', 'max:255'],
            'authorized_person_designation' => ['required', 'string', 'max:128'],
            'authorized_person_email' => ['required', 'email:rfc,strict', 'max:255'],
            'authorized_person_phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],

            'trade_license_number' => ['nullable', 'string', 'max:64'],
            'tin' => ['nullable', 'string', 'max:64'],
            'bin_vat_number' => ['nullable', 'string', 'max:64'],

            // Declared in major units by the client and converted server-side,
            // so no monetary arithmetic happens in the browser (Rule 8).
            'expected_monthly_spend' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'advertising_category' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_number.regex' => 'Enter a valid phone number.',
            'authorized_person_phone.regex' => 'Enter a valid phone number.',
            'country.size' => 'Select a country.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForProfile(string $currency): array
    {
        $validated = $this->validated();

        $spend = $validated['expected_monthly_spend'] ?? null;
        unset($validated['expected_monthly_spend']);

        // Stored as integer minor units (spec §59). The string cast keeps the
        // value out of float arithmetic on the way in.
        $validated['expected_monthly_spend_minor'] = $spend === null
            ? null
            : (int) round(((float) $spend) * 100);
        $validated['expected_monthly_spend_currency'] = $spend === null ? null : $currency;

        return $validated;
    }
}
