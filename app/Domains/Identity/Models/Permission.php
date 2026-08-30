<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A permission row mirrors one case of the Permission enum (spec §7).
 *
 * The enum is the source of truth in code; this table exists so roles can
 * reference permissions relationally and so administrators can see the full
 * catalogue.
 *
 * @property int $id
 * @property string $name
 * @property string $group
 * @property bool $is_privileged
 */
class Permission extends Model
{
    protected $fillable = [
        'name',
        'group',
        'description',
        'is_privileged',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_privileged' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
