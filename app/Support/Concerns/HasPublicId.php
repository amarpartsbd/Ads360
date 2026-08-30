<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a ULID that is safe to expose in URLs and API payloads, while
 * the auto-increment primary key stays internal (spec §93).
 *
 * The identifier is unguessable, but nothing here relies on that: authorization
 * is still enforced on every lookup. Obscurity is not an access control.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    /** Route model binding resolves on the public identifier, never the key. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
