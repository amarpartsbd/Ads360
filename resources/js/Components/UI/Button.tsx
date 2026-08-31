import { cva, type VariantProps } from 'class-variance-authority';
import { Slot, Slottable } from '@radix-ui/react-slot';
import { Loader2 } from 'lucide-react';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/Utils/cn';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[var(--radius-control)] text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-primary-foreground hover:bg-primary-hover',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-muted',
                outline: 'border border-border bg-surface hover:bg-surface-muted',
                ghost: 'hover:bg-surface-muted',
                danger: 'bg-danger text-danger-foreground hover:opacity-90',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                sm: 'h-8 px-3 text-xs',
                md: 'h-9 px-4',
                lg: 'h-10 px-6',
                icon: 'size-9',
            },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

export interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
    /** Shows a spinner and blocks further clicks, preventing double submission (spec §72). */
    loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild = false, loading = false, disabled, children, ...props }, ref) => {
        const Comp = asChild ? Slot : 'button';

        return (
            <Comp
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {/*
                    `Slottable` marks which of these two children is the element
                    `asChild` should merge into. Without it Slot sees two
                    children — the spinner's slot counts even when it holds
                    null — and throws rather than rendering, which took every
                    screen with a linked button down with it.
                */}
                {loading ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                <Slottable>{children}</Slottable>
            </Comp>
        );
    },
);

Button.displayName = 'Button';
