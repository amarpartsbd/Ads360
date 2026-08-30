<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Domains\Client\Enums\DocumentMediaType;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Compliance\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * A KYC document upload.
 *
 * These rules are a first pass that produces friendly messages. The real
 * decision is made in DocumentStorage, which reads the file's bytes — Laravel's
 * mime rules trust the client's declared type, which is exactly what §55 says
 * not to do.
 */
final class UploadVerificationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'file' => [
                'required',
                File::types(DocumentMediaType::allowedExtensions())
                    ->max(DocumentStorage::MAX_BYTES / 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'type.required' => 'Select what kind of document this is.',
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::from($this->string('type')->toString());
    }
}
