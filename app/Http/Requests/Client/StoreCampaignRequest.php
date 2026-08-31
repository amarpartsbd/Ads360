<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a campaign (spec §21).
 *
 * The budget is validated as a decimal string and converted to minor units by
 * the action. Nothing here accepts a minor-unit figure, because a browser that
 * could send one could set a budget of one paisa (Rule 8).
 */
final class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Campaign::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::enum(Provider::class)],
            'objective' => ['required', Rule::enum(CampaignObjective::class)],
            'budget_type' => ['required', Rule::enum(BudgetType::class)],
            // Decimal, as typed. Two places, and a ceiling that stops a
            // fat-fingered extra digit from reaching the pricing engine.
            'budget_amount' => ['required', 'numeric', 'min:1', 'max:99999999', 'decimal:0,2'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.after' => 'Choose a start date in the future.',
            'ends_at.after' => 'The end date must be after the start date.',
            'budget_amount.decimal' => 'Enter an amount with at most two decimal places.',
        ];
    }
}
