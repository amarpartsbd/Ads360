<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing a managed account's settings (spec §17).
 *
 * Spend figures are absent by design: they mirror what the provider reports
 * and are written only by the sync path.
 */
final class UpdateAdAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('adAccount')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone'],
            'daily_spend_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'monthly_spend_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'risk_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'allocation_priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
