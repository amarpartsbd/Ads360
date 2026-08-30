import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/Types';

/**
 * Reads the current user's permissions from the shared Inertia props.
 *
 * This decides what the interface shows, never what the user may do — the
 * server authorizes every request independently (spec §7, Rule 9).
 */
export function usePermissions() {
    const user = usePage<SharedProps>().props.auth.user;
    const granted = user?.permissions ?? [];

    const can = (permission: string): boolean => granted.includes(permission);
    const canAny = (...permissions: string[]): boolean => permissions.some(can);
    const canAll = (...permissions: string[]): boolean => permissions.every(can);

    return { can, canAny, canAll, permissions: granted };
}
