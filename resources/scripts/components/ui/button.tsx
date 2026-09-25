import { Button as ButtonPrimitive } from '@base-ui/react/button';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
const buttonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-transparent text-sm font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:cursor-not-allowed disabled:border-transparent disabled:bg-slate-200 disabled:text-slate-500 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground',
                outline:
                    'border-input bg-background text-foreground hover:bg-muted hover:text-foreground',
                secondary:
                    'bg-accent text-accent-foreground hover:bg-accent/80',
                ghost: 'text-foreground hover:bg-muted hover:text-foreground',
                destructive:
                    'border-red-200 bg-background text-destructive hover:bg-red-50 hover:text-destructive',
                link: 'text-primary hover:text-primary/90',
            },
            size: {
                default: 'min-h-11 px-5',
                sm: 'min-h-9 px-3 text-sm',
                lg: 'min-h-12 px-6 text-base',
                icon: 'size-11 p-0',
                'icon-sm': 'size-8 p-0',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);
function Button({
    className,
    variant,
    size,
    ...props
}: ButtonPrimitive.Props & VariantProps<typeof buttonVariants>) {
    return (
        <ButtonPrimitive
            data-slot="button"
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        />
    );
}
export { Button, buttonVariants };
