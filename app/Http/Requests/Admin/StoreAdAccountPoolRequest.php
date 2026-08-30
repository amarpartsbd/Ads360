<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Support\Values\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an ad account pool and its allocation rules (spec §18).
 *
 * The rules are validated twice over: shape here, meaning in AllocationRules.
 * The second pass is the one that matters — this one only keeps obviously
 * malformed input from reaching it.
 */
final class StoreAdAccountPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdAccountPool::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'provider' => ['required', Rule::enum(Provider::class)],
            'currency' => ['required', 'string', 'size:3', Rule::in(Currency::codes())],
            'selection_strategy' => ['required', Rule::enum(SelectionStrategy::class)],
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
