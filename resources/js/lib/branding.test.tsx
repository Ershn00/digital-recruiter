import { createElement } from 'react';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vitest';
import AppLogo from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import AuthCardLayout from '@/layouts/auth/auth-card-layout';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: ReactNode }) =>
        createElement('a', { href: '/' }, children),
}));

test('shared brand uses the approved transparent logo with proportional sizing', () => {
    const html = renderToStaticMarkup(
        createElement(AppLogoIcon, { className: 'h-16 w-24' }),
    );
    expect(html).toContain('src="/images/be-logo.png"');
    expect(html).toContain('alt="BE technologies"');
    expect(html).toContain('object-contain');
    expect(html).toContain('width="1536"');
    expect(html).toContain('height="1024"');
    expect(html).not.toContain('<svg');
});

test('sidebar branding keeps the product name beside the BE logo', () => {
    const html = renderToStaticMarkup(createElement(AppLogo));
    expect(html).toContain('DigitalRecruiter');
    expect(html).toContain('src="/images/be-logo.png"');
    expect(html).not.toContain('bg-sidebar-primary');
});

test.each([AuthSimpleLayout, AuthCardLayout, AuthSplitLayout])(
    'authentication layouts use BE branding and retain their content: %s',
    (Layout) => {
        const html = renderToStaticMarkup(
            createElement(Layout, {
                title: 'Sign in',
                description: 'Access your account',
                children: createElement('form', null, 'Authentication form'),
            }),
        );
        expect(html).toContain('src="/images/be-logo.png"');
        expect(html).toContain('DigitalRecruiter');
        expect(html).toContain('Sign in');
        expect(html).toContain('Access your account');
        expect(html).toContain('<form>Authentication form</form>');
        expect(html).not.toContain('<svg');
    },
);
