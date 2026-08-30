<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's membership of one pool (spec §18).
 *
 * Modelled explicitly rather than left as an anonymous pivot because the
 * membership carries its own data — the weight the pool distributes by, and
 * who added it — and because allocation will want to query it directly.
 *
 * @property int $weight
 */
class AdAccountPoolMember extends Model
{
    protected $table = 'ad_account_pool_members';

    /** @var list<string> */
    protected $fillable = [
        'ad_account_pool_id',
        'ad_account_id',
        'weight',
        'added_by',
    ];

    /** @var array<string, int> */
    protected $attributes = [
        'weight' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AdAccountPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(AdAccountPool::class, 'ad_account_pool_id');
    }

    /**
     * @return BelongsTo<AdAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
