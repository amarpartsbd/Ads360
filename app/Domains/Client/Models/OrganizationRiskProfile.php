<?php

declare(strict_types=1);

namespace App\Domains\Client\Models;

use App\Domains\Client\DTOs\RiskContribution;
use App\Domains\Client\Enums\RiskLevel;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Database\Factories\OrganizationRiskProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How risky one organization looks, and why (spec §12).
 *
 * Nothing here is mass assignable. A risk score that a request could set would
 * be a risk score that means nothing — every field is written by the assessor
 * or by an attributed decision from a person.
 *
 * @property int $score
 * @property RiskLevel $level
 * @property array<int, array<string, mixed>> $factors
 * @property bool $manual_flag
 */
class OrganizationRiskProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrganizationRiskProfileFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Deliberately empty. See the class docblock: there is no request in this
     * platform that should be able to fill a risk field.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, int|bool|string>
     */
    protected $attributes = [
        'score' => 0,
        'level' => 'LOW',
        'manual_flag' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'level' => RiskLevel::class,
            'factors' => 'array',
            'manual_flag' => 'boolean',
            'assessed_at' => 'immutable_datetime',
            'manual_flagged_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
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
    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_flag_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The stored reasons, as objects.
     *
     * @return list<RiskContribution>
     */
    public function contributions(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $row): ?RiskContribution => is_array($row)
                ? RiskContribution::fromArray($row)
                : null,
            $this->factors ?? [],
        )));
    }

    /**
     * Whether financial actions on this organization need a second approver.
     *
     * The only automatic consequence of a score anywhere in the platform, and
     * it adds a person rather than removing one (spec §12).
     */
    public function requiresSecondApprover(): bool
    {
        return $this->level->requiresSecondApprover();
    }

    public function needsReview(): bool
    {
        return $this->level->needsReview() && $this->reviewed_at === null;
    }

    /** Whether the assessment is old enough to be worth distrusting. */
    public function isStale(int $withinHours = 24): bool
    {
        return $this->assessed_at === null
            || $this->assessed_at->addHours($withinHours)->isPast();
    }

    protected static function newFactory(): OrganizationRiskProfileFactory
    {
        return OrganizationRiskProfileFactory::new();
    }
}
