<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Compliance\Enums\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing an existing pool (spec §18).
 *
 * Provider and currency are absent deliberately: a pool that changed either
 * would be holding members it can no longer compare, so the way to change them
 * is to build a new pool.
 */
final class UpdateAdAccountPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('adAccountPool')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'selection_strategy' => ['sometimes', Rule::enum(SelectionStrategy::class)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],

            'rules' => ['sometimes', 'array'],
            'rules.required_verification_status' => ['sometimes', Rule::enum(VerificationStatus::class)],
            'rules.minimum_wallet_balance_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rules.allowed_countries' => ['sometimes', 'nullable', 'array'],
            'rules.allowed_countries.*' => ['string', 'size:2'],
            'rules.allowed_categories' => ['sometimes', 'nullable', 'array'],
            'rules.allowed_categories.*' => ['string', 'max:128'],
            'rules.blocked_categories' => ['sometimes', 'array'],
            'rules.blocked_categories.*' => ['string', 'max:128'],
            'rules.max_account_risk_score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'rules.max_daily_utilisation_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'rules.reserve_headroom_minor' => ['sometimes', 'integer', 'min:0'],
            'rules.require_healthy_account' => ['sometimes', 'boolean'],
            'rules.max_clients_per_account' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
