<?php

declare(strict_types=1);

namespace App\Domains\System\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's vote on an approval request (spec §25).
 *
 * @property string $decision
 */
class ApprovalDecision extends Model
{
    public const UPDATED_AT = null;

    public const APPROVE = 'APPROVE';

    public const REJECT = 'REJECT';

    protected $fillable = [
        'approval_request_id',
        'approver_id',
        'decision',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
