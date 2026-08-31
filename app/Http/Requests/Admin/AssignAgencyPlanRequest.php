<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Putting an agency on a fee schedule (spec §36, §42).
 *
 * Pricing is what the platform charges, so this needs the pricing permission
 * rather than the client one: someone who may create an agency should not
 * thereby be able to decide what it pays.
 */
final class AssignAgencyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isPlatformUser()
            && $user->hasPermissionTo(Permission::PricingManage);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'size:26'],
        ];
    }
}
