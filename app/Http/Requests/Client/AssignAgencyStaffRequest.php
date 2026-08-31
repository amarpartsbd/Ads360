<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Agency\Actions\AssignStaffToClient;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Tenant\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting an agency staff member on one client (spec §42).
 *
 * Only the two organization-scoped agency roles are accepted. Assigning an
 * agency-owner to a single client would read as narrowing their access while
 * actually widening it, because that role already spans the agency.
 */
final class AssignAgencyStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant !== null
            && $tenant->type->managesClients()
            && (bool) config('platform.features.agency_module')
            && ($this->user()?->hasPermissionTo(Permission::UsersManage) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user' => ['required', 'string', 'size:26'],
            'role' => ['required', 'string', Rule::in(AssignStaffToClient::ASSIGNABLE_ROLES)],
        ];
    }
}
