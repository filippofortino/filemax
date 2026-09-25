import { Toast as ToastPrimitive } from '@base-ui/react/toast';
import {
    Alert02Icon,
    Cancel01Icon,
    Tick02Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { cn } from 'cn';
import { Button } from '@/components/ui/button';

const toast = ToastPrimitive.createToastManager();

function ToastProvider({ ...props }: ToastPrimitive.Provider.Props) {
    return <ToastPrimitive.Provider {...props} />;
}

function ToastPortal({ ...props }: ToastPrimitive.Portal.Props) {
    return <ToastPrimitive.Portal data-slot="toast-portal" {...props} />;
}

function ToastViewport({ className, ...props }: ToastPrimitive.Viewport.Props) {
    return (
        <ToastPrimitive.Viewport
            data-slot="toast-viewport"
            className={cn(
                'pointer-events-none fixed inset-x-5 bottom-4 z-60 mx-auto w-auto max-w-sm outline-none md:right-6 md:bottom-6 md:left-auto md:mx-0 md:w-full',
                className,
            )}
            {...props}
        />
    );
}

function Toast({ className, ...props }: ToastPrimitive.Root.Props) {
    return (
        <ToastPrimitive.Root
            data-slot="toast"
            className={cn(
                'group/toast pointer-events-auto absolute right-0 bottom-0 z-[calc(1000-var(--toast-index))] w-full origin-bottom rounded-lg bg-popover text-sm text-popover-foreground shadow-lg ring-1 ring-foreground/10 will-change-transform select-none',
                '[--gap:0.5rem] [--height:var(--toast-frontmost-height,var(--toast-height))] [--offset-y:calc(var(--toast-offset-y)*-1+calc(var(--toast-index)*var(--gap)*-1)+var(--toast-swipe-movement-y))] [--peek:0.75rem] [--scale:calc(max(0,1-(var(--toast-index)*0.1)))] [--shrink:calc(1-var(--scale))]',
                'h-(--height) [transform:translateX(var(--toast-swipe-movement-x))_translateY(calc(var(--toast-swipe-movement-y)-(var(--toast-index)*var(--peek))-(var(--shrink)*var(--height))))_scale(var(--scale))] [transition:transform_200ms_ease-out,translate_200ms_ease-out,opacity_200ms_ease-out,height_150ms_ease-out]',
                "after:absolute after:top-full after:left-0 after:h-[calc(var(--gap)+1px)] after:w-full after:content-['']",
                'data-expanded:h-(--toast-height) data-expanded:[transform:translateX(var(--toast-swipe-movement-x))_translateY(var(--offset-y))]',
                'data-limited:opacity-0 data-starting-style:translate-y-4 data-starting-style:opacity-0',
                'data-ending-style:opacity-0 data-ending-style:duration-150 [&[data-ending-style]:not([data-limited]):not([data-swipe-direction])]:translate-y-2',
                'data-ending-style:data-[swipe-direction=down]:[transform:translateY(calc(var(--toast-swipe-movement-y)+150%))]',
                'data-ending-style:data-[swipe-direction=left]:[transform:translateX(calc(var(--toast-swipe-movement-x)-150%))_translateY(var(--offset-y))]',
                'data-ending-style:data-[swipe-direction=right]:[transform:translateX(calc(var(--toast-swipe-movement-x)+150%))_translateY(var(--offset-y))]',
                'data-ending-style:data-[swipe-direction=up]:[transform:translateY(calc(var(--toast-swipe-movement-y)-150%))]',
                'data-expanded:data-ending-style:data-[swipe-direction=down]:[transform:translateY(calc(var(--toast-swipe-movement-y)+150%))]',
                'data-expanded:data-ending-style:data-[swipe-direction=left]:[transform:translateX(calc(var(--toast-swipe-movement-x)-150%))_translateY(var(--offset-y))]',
                'data-expanded:data-ending-style:data-[swipe-direction=right]:[transform:translateX(calc(var(--toast-swipe-movement-x)+150%))_translateY(var(--offset-y))]',
                'data-expanded:data-ending-style:data-[swipe-direction=up]:[transform:translateY(calc(var(--toast-swipe-movement-y)-150%))]',
                className,
            )}
            {...props}
        />
    );
}

function ToastContent({ className, ...props }: ToastPrimitive.Content.Props) {
    return (
        <ToastPrimitive.Content
            data-slot="toast-content"
            className={cn(
                'flex h-full items-start gap-3 overflow-hidden py-2 pr-2 pl-4 transition-opacity duration-200 ease-out data-behind:pointer-events-none data-behind:opacity-0 data-expanded:pointer-events-auto data-expanded:opacity-100',
                className,
            )}
            {...props}
        />
    );
}

function ToastTitle({ className, ...props }: ToastPrimitive.Title.Props) {
    return (
        <ToastPrimitive.Title
            data-slot="toast-title"
            className={cn(
                'font-sans text-sm font-semibold tracking-normal',
                className,
            )}
            {...props}
        />
    );
}

function ToastDescription({
    className,
    ...props
}: ToastPrimitive.Description.Props) {
    return (
        <ToastPrimitive.Description
            data-slot="toast-description"
            className={cn('text-muted-foreground', className)}
            {...props}
        />
    );
}

function ToastAction({
    className,
    render = <Button variant="outline" size="sm" />,
    ...props
}: ToastPrimitive.Action.Props) {
    return (
        <ToastPrimitive.Action
            data-slot="toast-action"
            render={render}
            className={cn('mt-2 self-start', className)}
            {...props}
        />
    );
}

function ToastClose({
    className,
    children,
    render = <Button variant="ghost" size="icon-sm" />,
    ...props
}: ToastPrimitive.Close.Props) {
    return (
        <ToastPrimitive.Close
            data-slot="toast-close"
            aria-label="Dismiss"
            render={render}
            className={cn(
                "relative shrink-0 text-muted-foreground after:absolute after:-inset-2 after:content-[''] hover:text-foreground",
                className,
            )}
            {...props}
        >
            {children ?? (
                <HugeiconsIcon
                    icon={Cancel01Icon}
                    size={16}
                    aria-hidden="true"
                />
            )}
        </ToastPrimitive.Close>
    );
}

function ToastIcon({ type }: { type: string | undefined }) {
    const error = type === 'error';

    return (
        <HugeiconsIcon
            icon={error ? Alert02Icon : Tick02Icon}
            size={18}
            strokeWidth={2}
            className={cn(
                'mt-1.75 shrink-0',
                error ? 'text-destructive' : 'text-emerald-700',
            )}
            aria-hidden="true"
        />
    );
}

function ToastList() {
    const { toasts } = ToastPrimitive.useToastManager();

    return toasts.map((toastItem) => (
        <Toast key={toastItem.id} toast={toastItem}>
            <ToastContent>
                <ToastIcon type={toastItem.type} />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5 py-1.5">
                    <ToastTitle />
                    <ToastDescription />
                    <ToastAction />
                </div>
                <ToastClose />
            </ToastContent>
        </Toast>
    ));
}

function Toaster({
    children,
    toastManager = toast,
    ...props
}: ToastPrimitive.Provider.Props) {
    return (
        <ToastProvider toastManager={toastManager} {...props}>
            {children}
            <ToastPortal>
                <ToastViewport>
                    <ToastList />
                </ToastViewport>
            </ToastPortal>
        </ToastProvider>
    );
}

const createToastManager = ToastPrimitive.createToastManager;
const useToastManager = ToastPrimitive.useToastManager;

export {
    Toaster,
    Toast,
    ToastAction,
    ToastClose,
    ToastContent,
    ToastDescription,
    ToastPortal,
    ToastProvider,
    ToastTitle,
    ToastViewport,
    createToastManager,
    toast,
    useToastManager,
};
