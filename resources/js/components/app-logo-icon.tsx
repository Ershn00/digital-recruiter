import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({
    className,
    ...props
}: ComponentProps<'img'>) {
    return (
        <img
            {...props}
            src="/images/be-logo.png"
            alt="BE technologies"
            width={1536}
            height={1024}
            className={cn('shrink-0 object-contain', className)}
        />
    );
}
