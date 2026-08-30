<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Support\Values\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering a managed ad account (spec §17).
 *
 * Limits arrive as decimal strings and are converted to minor units by the
 * controller, never by the browser (Rule 8).
 */
final class StoreAdAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdAccount::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(Provider::class)],
            'external_account_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Currency::codes())],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'daily_spend_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'monthly_spend_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
