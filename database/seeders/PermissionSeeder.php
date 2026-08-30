<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Mirrors the Permission enum into the permissions table.
 *
 * Idempotent: it upserts by name, so running it again after new permissions are
 * added to the enum only inserts the new ones and leaves existing grants alone.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = array_map(fn (PermissionEnum $permission): array => [
            'name' => $permission->value,
            'group' => $permission->group(),
            'description' => $permission->description(),
            'is_privileged' => $permission->isPrivileged(),
            'created_at' => $now,
            'updated_at' => $now,
        ], PermissionEnum::cases());

        Permission::query()->upsert(
            $rows,
            uniqueBy: ['name'],
            update: ['group', 'description', 'is_privileged', 'updated_at'],
        );
    }
}
