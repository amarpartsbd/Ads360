<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Analytics\Enums\ExportStatus;
use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A generated report file (spec §39, §55).
 *
 * The storage path is `$hidden`, like every other private-disk path in this
 * system: it is a location on a disk nobody should be trying to reach
 * directly, and the only way to the bytes is the authorised download route.
 *
 * @property ReportType $type
 * @property ExportStatus $status
 */
class ReportExport extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'type',
        'status',
        'filters',
        'period_start',
        'period_end',
        'requested_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'storage_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'byte_size' => 'integer',
            'row_count' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whether the file is genuinely available now.
     *
     * Checks the expiry as well as the status, because the sweep that marks
     * files expired runs on a schedule and a file can be past its date before
     * the sweep next runs.
     */
    public function isDownloadable(): bool
    {
        if (! $this->status->isDownloadable() || $this->storage_path === null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** The name the client's browser will save it as. */
    public function downloadName(): string
    {
        return sprintf(
            'ads360-%s-%s-to-%s.csv',
            $this->type->filename(),
            $this->period_start?->toDateString() ?? 'start',
            $this->period_end?->toDateString() ?? 'end',
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('status', ExportStatus::Ready)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now());
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'period' => $this->period_start?->toDateString().' to '.$this->period_end?->toDateString(),
            'rows' => $this->row_count,
        ];
    }
}
