import { Switch as SwitchPrimitive } from '@base-ui/react/switch';
import { cn } from '@/lib/utils';

function Switch({
    className,
    size = 'default',
    ...props
}: SwitchPrimitive.Root.Props & {
    size?: 'sm' | 'default';
}) {
    return (
        <SwitchPrimitive.Root
            data-slot="switch"
            data-size={size}
            className={cn(
                'peer group/switch relative inline-flex shrink-0 items-center rounded-full border border-transparent transition-colors duration-160 ease-out after:absolute after:-inset-x-3 after:-inset-y-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 data-[size=default]:h-6 data-[size=default]:w-11 data-[size=default]:p-px data-[size=sm]:h-[14px] data-[size=sm]:w-[24px] motion-reduce:transition-none data-checked:bg-primary data-unchecked:bg-input data-disabled:cursor-not-allowed data-disabled:opacity-50',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb
                data-slot="switch-thumb"
                className="pointer-events-none block rounded-full bg-background shadow-[0_1px_2px_#0f172b33] transition-transform duration-160 ease-out group-data-[size=default]/switch:size-5 group-data-[size=sm]/switch:size-3 motion-reduce:transition-none group-data-[size=default]/switch:data-checked:translate-x-5 group-data-[size=sm]/switch:data-checked:translate-x-[calc(100%-2px)] data-unchecked:translate-x-0"
            />
        </SwitchPrimitive.Root>
    );
}

export { Switch };
