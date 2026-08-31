<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Tenant\Enums\TenantType;
use App\Support\Values\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Platform staff provisioning an agency or reseller (spec §41, §42).
 *
 * Deliberately not self-service: registration always produces a direct client,
 * and being an agency is a commercial decision the platform makes.
 */
final class ProvisionAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isPlatformUser()
            && $user->hasPermissionTo(Permission::ClientsCreate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([TenantType::Agency->value, TenantType::Reseller->value])],
            'billing_email' => ['required', 'email:rfc,strict', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currency::codes())],

            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email:rfc,strict', 'max:255', 'unique:users,email'],

            /*
             * The same password rules as any other account. An agency owner
             * reaches every client the agency manages, so a weak one here is
             * the widest credential on the platform outside staff.
             */
            'owner_password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
