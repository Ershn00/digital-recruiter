import { Children, createElement, isValidElement } from 'react';
import type { ComponentProps, ReactElement, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, expect, test, vi } from 'vitest';
import CandidateActions from '@/components/candidates/candidate-actions';
import type { CandidateApplication } from '@/types/candidates';

const screen = vi.hoisted(() => ({
    mode: null as string | null,
    confirmed: false,
    busy: false,
    error: '',
    forms: [] as ReactElement<ComponentProps<'form'>>[],
}));
const requests = vi.hoisted(() => ({
    patch: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));
vi.mock('react', async (original) => {
    const actual = await original<typeof import('react')>();
    return {
        ...actual,
        useState: (initial: unknown) =>
            actual.useState(initial === null ? screen.mode : initial),
    };
});
vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data:
            'confirm_delete' in initial
                ? { confirm_delete: screen.confirmed }
                : initial,
        processing: screen.busy,
        errors: screen.error ? { candidate: screen.error } : {},
        clearErrors: vi.fn(),
        setData: vi.fn(),
        patch: requests.patch,
        post: requests.post,
        delete: requests.delete,
    }),
}));
function findForm(
    node: ReactNode,
): ReactElement<ComponentProps<'form'>> | undefined {
    for (const child of Children.toArray(node)) {
        if (!isValidElement<ComponentProps<'form'>>(child)) continue;
        if (child.type === 'form') return child;
        const found = findForm(child.props.children);
        if (found) return found;
    }
}
vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ open, children }: { open: boolean; children: ReactNode }) =>
        open ? children : null,
    DialogContent: ({ children }: { children: ReactNode }) => {
        const form = findForm(children);
        if (form) screen.forms.push(form);
        return createElement('div', { role: 'dialog' }, children);
    },
    DialogHeader: ({ children }: { children: ReactNode }) => children,
    DialogFooter: ({ children }: { children: ReactNode }) => children,
    DialogTitle: ({ children }: { children: ReactNode }) =>
        createElement('h2', {}, children),
    DialogDescription: ({ children }: { children: ReactNode }) =>
        createElement('p', {}, children),
}));
vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: ReactNode }) => children,
    DropdownMenuTrigger: ({ children }: { children: ReactNode }) => children,
    DropdownMenuContent: ({ children }: { children: ReactNode }) =>
        createElement('div', { role: 'menu' }, children),
    DropdownMenuItem: ({
        children,
        disabled,
    }: {
        children: ReactNode;
        disabled?: boolean;
    }) => createElement('button', { role: 'menuitem', disabled }, children),
    DropdownMenuSeparator: () => createElement('hr'),
}));
vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: (props: ComponentProps<'input'>) =>
        createElement('input', {
            type: 'checkbox',
            checked: props.checked,
            required: props.required,
            disabled: props.disabled,
            readOnly: true,
        }),
}));
const candidate: CandidateApplication = {
    id: 22,
    name: 'Demo Candidate',
    archived_at: null,
    can_delete: true,
    interview: { id: 9, status: 'active' },
    interview_url: '/interviews/9',
};
function render(overrides: Partial<CandidateApplication> = {}) {
    return renderToStaticMarkup(
        createElement(CandidateActions, {
            candidate: { ...candidate, ...overrides },
        }),
    );
}
function submit() {
    screen.forms.at(-1)!.props.onSubmit?.({
        preventDefault: vi.fn(),
    } as unknown as Parameters<
        NonNullable<ComponentProps<'form'>['onSubmit']>
    >[0]);
}

beforeEach(() => {
    screen.mode = null;
    screen.confirmed = false;
    screen.busy = false;
    screen.error = '';
    screen.forms.length = 0;
    vi.clearAllMocks();
});

test('candidate menu is labelled and provides edit archive and deletion actions', () => {
    const html = render();
    expect(html).toContain('Actions for Demo Candidate');
    for (const label of [
        'Edit Candidate',
        'Archive Candidate',
        'Delete Candidate',
    ])
        expect(html).toContain(label);
    expect(html).not.toContain('Restore Candidate');
});

test('archived candidates offer restore and disable deletion when ineligible', () => {
    const html = render({ archived_at: '2026-11-07', can_delete: false });
    expect(html).toContain('Restore Candidate');
    expect(html).not.toContain('Archive Candidate');
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*>Delete Candidate/);
});

test('edit dialog permits only display name changes and patches the candidate endpoint', () => {
    screen.mode = 'edit';
    const html = render();
    expect(html).toContain('Display name');
    expect(html).toContain('maxLength="200"');
    expect(html).toContain(
        'Interview sessions, answers, evaluations and private links remain unchanged',
    );
    expect(html).not.toContain('<textarea');
    expect(html).not.toContain('<select');
    submit();
    expect(requests.patch).toHaveBeenCalledWith(
        '/candidates/22',
        expect.objectContaining({ preserveScroll: true }),
    );
    expect(requests.post).not.toHaveBeenCalled();
    expect(requests.delete).not.toHaveBeenCalled();
});

test.each(['archive', 'restore'])(
    '%s confirmation describes link revocation and submits the correct action',
    (mode) => {
        screen.mode = mode;
        const html = render();
        expect(html).toContain(
            mode === 'archive'
                ? 'invalidate their private interview link'
                : 'Old interview links stay invalid',
        );
        expect(html).toContain('role="dialog"');
        submit();
        expect(requests.post).toHaveBeenCalledWith(
            `/candidates/22/${mode}`,
            expect.any(Object),
        );
    },
);

test('delete requires an explicit confirmation checkbox before submitting', () => {
    screen.mode = 'delete';
    const html = render();
    expect(html).toContain('role="dialog"');
    expect(html).toContain(
        'I understand this permanently deletes this unused fictional candidate',
    );
    expect(html).toMatch(/<input[^>]*type="checkbox"[^>]*required=""/);
    expect(html).toMatch(/<button[^>]*type="submit"[^>]*disabled=""/);
    submit();
    expect(requests.delete).not.toHaveBeenCalled();
    screen.confirmed = true;
    render();
    submit();
    expect(requests.delete).toHaveBeenCalledWith(
        '/candidates/22',
        expect.any(Object),
    );
});

test('stale or forged delete dialog cannot submit ineligible candidates and displays server errors', () => {
    screen.mode = 'delete';
    screen.confirmed = true;
    screen.error = 'Archive candidates with interview history instead.';
    const html = render({ can_delete: false });
    submit();
    expect(requests.delete).not.toHaveBeenCalled();
    expect(html).toContain('role="alert"');
    expect(html).toContain(screen.error);
});

test('pending mutation disables actions and prevents another submission', () => {
    screen.mode = 'archive';
    screen.busy = true;
    const html = render();
    submit();
    expect(html).toContain('Saving…');
    expect(html).toContain('disabled=""');
    expect(requests.post).not.toHaveBeenCalled();
});
