<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Models;

use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Database\Factories\VerificationProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's business verification submission (spec §11).
 *
 * @property int $organization_id
 * @property VerificationStatus $status
 * @property \Illuminate\Database\Eloquent\Collection<int, VerificationDocument> $documents
 */
class VerificationProfile extends Model
{
    /** @use HasFactory<VerificationProfileFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'legal_business_name',
        'trading_name',
        'business_type',
        'website',
        'facebook_page',
        'contact_number',
        'business_email',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'authorized_person_name',
        'authorized_person_designation',
        'authorized_person_email',
        'authorized_person_phone',
        'trade_license_number',
        'tin',
        'bin_vat_number',
        'expected_monthly_spend_minor',
        'expected_monthly_spend_currency',
        'advertising_category',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'expected_monthly_spend_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<VerificationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    /**
     * @return HasMany<VerificationReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(VerificationReview::class);
    }

    public function isVerified(): bool
    {
        return $this->status->isVerified();
    }

    public function isEditableByClient(): bool
    {
        return $this->status->isEditableByClient();
    }

    /** Expected monthly spend as a Money value, when the client declared one. */
    public function expectedMonthlySpend(): ?Money
    {
        if ($this->expected_monthly_spend_minor === null
            || $this->expected_monthly_spend_currency === null) {
            return null;
        }

        return Money::ofMinor($this->expected_monthly_spend_minor, $this->expected_monthly_spend_currency);
    }

    /**
     * Document types the submission is still missing.
     *
     * Identity is satisfied by either a national ID or a passport, so the two
     * are treated as one requirement rather than each being mandatory.
     *
     * @return list<DocumentType>
     */
    public function missingRequiredDocuments(): array
    {
        $present = $this->documents->pluck('type')->all();

        $missing = [];

        foreach (DocumentType::cases() as $type) {
            if ($type->isRequired() && ! in_array($type, $present, true)) {
                $missing[] = $type;
            }
        }

        $hasIdentity = array_filter(
            DocumentType::identityDocuments(),
            static fn (DocumentType $type): bool => in_array($type, $present, true),
        );

        if ($hasIdentity === []) {
            $missing[] = DocumentType::NationalId;
        }

        return $missing;
    }

    protected static function newFactory(): VerificationProfileFactory
    {
        return VerificationProfileFactory::new();
    }
}
