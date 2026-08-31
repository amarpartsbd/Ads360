<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Campaign\Enums\BidStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing an audience (spec §21).
 *
 * The targeting keys are validated for shape here and for meaning by the
 * Targeting value object. The second pass is the one that matters — this only
 * keeps obviously malformed input from reaching it.
 */
final class StoreAdSetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'bid_strategy' => ['required', Rule::enum(BidStrategy::class)],
            'bid_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'optimization_goal' => ['nullable', 'string', 'max:48'],

            'targeting' => ['required', 'array'],
            'targeting.countries' => ['sometimes', 'array'],
            'targeting.countries.*' => ['string', 'size:2'],
            'targeting.regions' => ['sometimes', 'array'],
            'targeting.regions.*' => ['string', 'max:128'],
            'targeting.cities' => ['sometimes', 'array'],
            'targeting.cities.*' => ['string', 'max:128'],
            'targeting.minimum_age' => ['sometimes', 'integer', 'min:18', 'max:65'],
            'targeting.maximum_age' => ['sometimes', 'integer', 'min:18', 'max:65'],
            'targeting.genders' => ['sometimes', 'array'],
            'targeting.genders.*' => ['string', Rule::in(['male', 'female'])],
            'targeting.languages' => ['sometimes', 'array'],
            'targeting.languages.*' => ['string', 'max:32'],
            'targeting.interests' => ['sometimes', 'array'],
            'targeting.interests.*' => ['string', 'max:128'],
            'targeting.excluded_interests' => ['sometimes', 'array'],
            'targeting.excluded_interests.*' => ['string', 'max:128'],
            'targeting.devices' => ['sometimes', 'array'],
            'targeting.devices.*' => ['string', Rule::in(['mobile', 'desktop', 'tablet'])],
            'targeting.custom_audiences' => ['sometimes', 'array'],
            'targeting.custom_audiences.*' => ['string', 'max:128'],
        ];
    }
}
