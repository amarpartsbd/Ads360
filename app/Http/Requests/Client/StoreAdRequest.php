<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or editing an ad (spec §21, §23).
 *
 * The creative and the identity are given as public identifiers and resolved
 * by the controller against the current organization, so a request cannot
 * point an ad at another tenant's page (spec §7).
 */
final class StoreAdRequest extends FormRequest
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
            'headline' => ['required', 'string', 'max:255'],
            'primary_text' => ['required', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:255'],
            'call_to_action' => ['nullable', 'string', 'max:48'],
            // A link the provider will follow, so it has to be a real one.
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'creative' => ['nullable', 'string', 'size:26'],
            'identity' => ['nullable', 'string', 'size:26'],
        ];
    }
}
