import { AlertCircle } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/Utils/cn';

export interface FieldProps {
    label: string;
    /** Guidance shown under the label, before the user makes a mistake. */
    hint?: ReactNode;
    error?: string;
    required?: boolean;
    className?: string;
    children: (props: {
        id: string;
        'aria-invalid': boolean;
        'aria-describedby': string | undefined;
        required: boolean;
    }) => ReactNode;
}

/**
 * A labelled form control with hint and error slots (spec §72).
 *
 * The render prop wires `id`, `aria-invalid` and `aria-describedby` onto the
 * control so every field is announced correctly without each form repeating
 * the plumbing (spec §74).
 */
export function Field({ label, hint, error, required = false, className, children }: FieldProps) {
    const id = useId();
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;

    return (
        <div className={cn('space-y-1.5', className)}>
            <label htmlFor={id} className="block text-sm font-medium">
                {label}
                {required ? (
                    <span className="ml-0.5 text-danger" aria-hidden="true">
                        *
                    </span>
                ) : null}
            </label>

            {children({
                id,
                'aria-invalid': Boolean(error),
                'aria-describedby': describedBy,
                required,
            })}

            {hint && !error ? (
                <p id={hintId} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            ) : null}

            {error ? (
                <p id={errorId} role="alert" className="flex items-center gap-1.5 text-xs text-danger">
                    <AlertCircle className="size-3.5 shrink-0" aria-hidden="true" />
                    {error}
                </p>
            ) : null}
        </div>
    );
}
