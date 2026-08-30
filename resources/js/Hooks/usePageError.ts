import { usePage } from '@inertiajs/react';

/**
 * Reads a validation error that is not tied to one of a form's own fields.
 *
 * The server can reject a submission for a reason that has no matching input —
 * missing documents on a verification, or an invitation token that expired
 * between the page loading and the form being posted. Inertia's typed
 * `errors` object only covers declared form fields, so those arrive through the
 * shared error bag instead.
 */
export function usePageError(key: string): string | undefined {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return errors?.[key];
}
