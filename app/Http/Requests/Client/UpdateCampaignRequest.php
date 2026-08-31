<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a campaign draft (spec §21).
 *
 * The provider is absent: changing it would invalidate every audience and ad
 * already built underneath, so a different provider means a different campaign.
 */
final class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('campaign')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'objective' => ['sometimes', Rule::enum(CampaignObjective::class)],
            'budget_type' => ['sometimes', Rule::enum(BudgetType::class)],
            'budget_amount' => ['sometimes', 'numeric', 'min:1', 'max:99999999', 'decimal:0,2'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ];
    }
}
