<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Tenant\Services\TenantContext;
use App\Support\Values\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An agency adding a client it manages (spec §42).
 *
 * The tenant is never taken from the request. It comes from the resolved
 * context, so a body naming another agency's identifier has nothing to bind to
 * (spec §5, Rule 6).
 */
final class StoreAgencyClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant !== null
            && $tenant->type->managesClients()
            && (bool) config('platform.features.agency_module')
            && ($this->user()?->hasPermissionTo(Permission::ClientsCreate) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],

            /*
             * Restricted to the currencies the ledger knows how to hold. An
             * unsupported one would create a wallet that cannot be summed.
             */
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currency::codes())],

            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_email' => ['nullable', 'email:rfc,strict', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:32'],
        ];
    }
}
