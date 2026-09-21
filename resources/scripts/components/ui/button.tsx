import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import * as React from 'react';
import { cn } from '@/lib/utils';
const buttonVariants = cva(
    'button inline-flex shrink-0 items-center justify-center gap-2 font-semibold whitespace-nowrap transition-colors disabled:pointer-events-none [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'button-primary',
                outline: 'button-outline',
                secondary: 'button-secondary',
                ghost: 'button-ghost',
                destructive: 'button-destructive',
                link: 'button-link',
            },
            size: {
                default: 'button-default',
                sm: 'button-sm',
                lg: 'button-lg',
                icon: 'button-icon',
                'icon-sm': 'button-icon-sm',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);
function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot.Root : 'button';
    return (
        <Comp
            data-slot="button"
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}
export { Button, buttonVariants };
