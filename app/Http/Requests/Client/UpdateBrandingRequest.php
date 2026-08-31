<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Tenant\Services\TenantContext;
use App\Domains\Tenant\Values\Branding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Changing a workspace's branding (spec §43).
 *
 * The colour rule is checked here as well as in the value object so the person
 * choosing it gets a message beside the field rather than a failed save. The
 * value object stays the authority — this is the same rule asked earlier.
 */
final class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant !== null
            && $tenant->canWhiteLabel()
            && ($this->user()?->hasPermissionTo(Permission::BrandingManage) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:64'],
            'logo_url' => ['nullable', 'url:https', 'max:2048'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'support_email' => ['nullable', 'email:rfc,strict', 'max:255'],
            'custom_domain' => ['nullable', 'string', 'max:253'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $color = $this->input('primary_color');

            if (! is_string($color) || trim($color) === '') {
                return;
            }

            if (Branding::normaliseHex($color) === null) {
                $validator->errors()->add(
                    'primary_color',
                    'A brand colour must be a hex value such as #2158a7.',
                );

                return;
            }

            if (! Branding::isLegible($color)) {
                $validator->errors()->add('primary_color', sprintf(
                    'That colour is too light for white text to be read on it. '
                    .'It needs a contrast of at least %.1f to 1, and this is %.1f.',
                    Branding::minimumContrast(),
                    Branding::contrastWithWhite($color),
                ));
            }
        });
    }
}
