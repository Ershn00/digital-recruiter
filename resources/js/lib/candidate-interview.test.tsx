import { Children, createElement, isValidElement } from 'react';
import type {
    ComponentProps,
    FormEvent,
    KeyboardEvent,
    ReactElement,
    ReactNode,
} from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import type { router } from '@inertiajs/react';
import type { ActiveVisit } from '@inertiajs/core';
import InterviewQuestion from '@/components/candidate/interview-question';
import type { InterviewQuestionProps } from '@/components/candidate/interview-question';
import Interview from '@/pages/candidate/interview';
import {
    interviewContent,
    readInterviewDraft,
    storeInterviewDraft,
} from './candidate-interview';
import type {
    CandidateInterviewState,
    InterviewDraft,
} from './candidate-interview';

const requests = vi.hoisted(() => ({
    post: vi.fn<typeof router.post>(),
    reload: vi.fn<typeof router.reload>(),
}));
const captured = vi.hoisted(() => ({
    buttons: [] as ComponentProps<'button'>[],
    questions: [] as InterviewQuestionProps[],
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: requests.post, reload: requests.reload },
}));
vi.mock('@/components/ui/button', async (original) => {
    const actual = await original<typeof import('@/components/ui/button')>();
    return {
        ...actual,
        Button: (props: ComponentProps<typeof actual.Button>) => {
            captured.buttons.push(props);
            return createElement(actual.Button, props);
        },
    };
});
vi.mock('@/components/candidate/interview-question', async (original) => {
    const actual =
        await original<
            typeof import('@/components/candidate/interview-question')
        >();
    return {
        default: (props: InterviewQuestionProps) => {
            captured.questions.push(props);
            return createElement(actual.default, props);
        },
    };
});

const storage = new Map<string, string>();
const key = 'digital-recruiter:interview-draft:7';
const draft: InterviewDraft = {
    questionId: 12,
    content: 'My retained answer',
    submissionId: 'e7f8c76a-b518-4d31-b8c2-9ea6e7249c03',
    submitted: false,
};
const state: CandidateInterviewState = {
    id: 7,
    title: 'Senior Laravel Developer',
    language: 'en',
    status: 'active',
    current_question: { id: 12, content: 'How did you handle queue failures?' },
    question_number: 2,
    started: true,
    answer_saved: false,
    last_submission_id: null,
    completed_topics: 0,
    total_topics: 3,
};
function renderInterview(interview: CandidateInterviewState = state) {
    return renderToStaticMarkup(
        createElement(Interview, { interview, token: 'private-token' }),
    );
}
function questionProps(
    overrides: Partial<InterviewQuestionProps> = {},
): InterviewQuestionProps {
    return {
        language: 'en',
        question: state.current_question!,
        questionNumber: 2,
        answer: draft.content,
        locked: false,
        busy: false,
        onChange: vi.fn(),
        onSubmit: vi.fn(),
        ...overrides,
    };
}
function findElement<P extends { children?: ReactNode }>(
    node: ReactNode,
    type: string,
): ReactElement<P> | undefined {
    for (const child of Children.toArray(node)) {
        if (!isValidElement<P>(child)) continue;
        if (child.type === type) return child;
        const found = findElement<P>(child.props.children, type);
        if (found) return found;
    }
}

beforeEach(() => {
    storage.clear();
    captured.buttons.length = 0;
    captured.questions.length = 0;
    vi.clearAllMocks();
    vi.stubGlobal('window', {
        sessionStorage: {
            getItem: (name: string) => storage.get(name) ?? null,
            setItem: (name: string, value: string) => storage.set(name, value),
            removeItem: (name: string) => storage.delete(name),
        },
    });
});
afterEach(() => vi.unstubAllGlobals());

test.each(['tr', 'en'] as const)(
    'renders the %s introduction before any question and prevents duplicate start clicks',
    (language) => {
        const html = renderInterview({
            ...state,
            language,
            started: false,
            current_question: null,
            question_number: 0,
        });
        expect(html).toContain(interviewContent[language].introduction);
        expect(html).toContain(interviewContent[language].start);
        expect(html).not.toContain('min-h-80');
        expect(html).not.toContain(interviewContent[language].check);
        expect(html).not.toContain('Mülakatı tamamla');
        expect(html).not.toContain('Complete interview');
        expect(html).not.toContain('<textarea');
        const start = captured.buttons.find(
            (button) => button.children === interviewContent[language].start,
        )!;
        start.onClick?.({} as never);
        start.onClick?.({} as never);
        expect(requests.post).toHaveBeenCalledOnce();
        expect(requests.post.mock.calls[0][1]).toEqual({
            operation: 'resume',
        });
    },
);

test.each(['tr', 'en'] as const)(
    'shows only the current question in %s with persisted followup numbering and topic progress',
    (language) => {
        const withOldMessages = {
            ...state,
            language,
            messages: [
                { role: 'assistant', content: 'Hidden old question' },
                { role: 'candidate', content: 'Hidden saved answer' },
            ],
        };
        const html = renderInterview(withOldMessages);
        expect(html).toContain(`${interviewContent[language].question} 2`);
        expect(html).toContain(state.current_question!.content);
        expect(html).toContain(interviewContent[language].send);
        expect(html).toContain(interviewContent[language].immutableAnswer);
        expect(html).toContain(interviewContent[language].shortcut);
        expect(html).not.toContain(interviewContent[language].check);
        expect(html).not.toContain('Mülakatı tamamla');
        expect(html).not.toContain('Complete interview');
        expect(html).toContain(interviewContent[language].topics(0, 3));
        expect(html.match(/<textarea\b/g)).toHaveLength(1);
        expect(html).not.toContain('Hidden old question');
        expect(html).not.toContain('Hidden saved answer');
        expect(html).toContain('role="progressbar"');
        expect(html).toContain('aria-valuemax="3"');
        expect(html).toContain('aria-valuenow="0"');
    },
);

test.each(['tr', 'en'] as const)(
    'shows a saved-answer recovery screen in %s without replaying the answered question',
    (language) => {
        const html = renderInterview({
            ...state,
            language,
            current_question: null,
            answer_saved: true,
            last_submission_id: draft.submissionId,
        });
        expect(html).toContain(interviewContent[language].saved);
        expect(html).toContain(interviewContent[language].resume);
        expect(html).not.toContain(state.current_question!.content);
        expect(html).not.toContain('<textarea');
    },
);

test.each(['tr', 'en'] as const)(
    'loading locks the answer and disables submit without clearing its text in %s',
    (language) => {
        const html = renderToStaticMarkup(
            createElement(
                InterviewQuestion,
                questionProps({ language, busy: true, locked: true }),
            ),
        );
        expect(html).toContain(interviewContent[language].preparing);
        expect(html).toContain(draft.content);
        expect(html).toContain('readOnly=""');
        expect(html).toContain('disabled=""');
    },
);

test('retains the same answer and submission UUID across network failure retry and refresh', () => {
    storeInterviewDraft(key, draft);
    renderInterview();
    captured.questions.at(-1)!.onSubmit();
    captured.questions.at(-1)!.onSubmit();
    expect(requests.post).toHaveBeenCalledOnce();
    const payload = requests.post.mock.calls[0][1];
    expect(payload).toEqual({
        operation: 'answer',
        content: draft.content,
        submission_id: draft.submissionId,
        reply_to: draft.questionId,
    });
    const options = requests.post.mock.calls[0][2]!;
    options.onNetworkError?.(new Error('offline'));
    options.onFinish?.({} as ActiveVisit);
    expect(readInterviewDraft(key)).toEqual({ ...draft, submitted: true });
    const html = renderInterview(); // Fresh render restores the submitted answer as read-only.
    expect(html).toContain(draft.content);
    expect(html).toContain('readOnly=""');
    expect(html).toContain(interviewContent.en.check);
    captured.questions.at(-1)!.onSubmit();
    expect(requests.post.mock.calls[1][1]).toEqual(payload);
});

test('a persisted receipt hides an acknowledged draft and shows the next question with an empty answer', () => {
    storeInterviewDraft(key, { ...draft, submitted: true });
    const html = renderInterview({
        ...state,
        current_question: { id: 14, content: 'Next question' },
        question_number: 3,
        last_submission_id: draft.submissionId,
        completed_topics: 1,
    });
    expect(html).toContain('Question 3');
    expect(html).toContain('Next question');
    expect(html).not.toContain(draft.content);
    expect(captured.questions.at(-1)!.answer).toBe('');
});

test('an unacknowledged draft from another question is retained and cannot be sent as a new answer', () => {
    storeInterviewDraft(key, draft);
    const html = renderInterview({
        ...state,
        current_question: { id: 14, content: 'Next question' },
        question_number: 3,
    });
    expect(html).toContain(interviewContent.en.stale);
    expect(html).toContain(draft.content);
    expect(html).not.toContain('Next question');
    expect(captured.questions).toHaveLength(0);
    expect(storage.has(key)).toBe(true);
});

test('draft persistence tolerates unavailable storage and rejects malformed values', () => {
    storage.set(key, '{invalid');
    expect(readInterviewDraft(key)).toBeNull();
    storage.set(key, JSON.stringify({ ...draft, submissionId: 'invalid' }));
    expect(readInterviewDraft(key)).toBeNull();
    vi.stubGlobal('window', {
        get sessionStorage() {
            throw new Error('blocked');
        },
    });
    expect(readInterviewDraft(key)).toBeNull();
    expect(storeInterviewDraft(key, draft)).toBe(false);
});

test('the question form uses normal submission and blocks its callback while busy', async () => {
    const { default: ActualQuestion } = await vi.importActual<
        typeof import('@/components/candidate/interview-question')
    >('@/components/candidate/interview-question');
    const props = questionProps();
    const event = {
        preventDefault: vi.fn(),
    } as unknown as FormEvent<HTMLFormElement>;
    ActualQuestion(props).props.onSubmit(event);
    expect(props.onSubmit).toHaveBeenCalledOnce();
    ActualQuestion({ ...props, busy: true }).props.onSubmit(event);
    expect(props.onSubmit).toHaveBeenCalledOnce();
});

test.each([
    { ctrl: true, meta: false, composing: false, submitted: true },
    { ctrl: false, meta: true, composing: false, submitted: true },
    { ctrl: false, meta: false, composing: false, submitted: false },
    { ctrl: true, meta: false, composing: true, submitted: false },
])(
    'keyboard shortcut respects modifier keys and composition: $ctrl / $meta / $composing',
    async ({ ctrl, meta, composing, submitted }) => {
        const { default: ActualQuestion } = await vi.importActual<
            typeof import('@/components/candidate/interview-question')
        >('@/components/candidate/interview-question');
        const form = ActualQuestion(questionProps());
        const field = findElement<ComponentProps<'textarea'>>(
            form,
            'textarea',
        )!;
        const requestSubmit = vi.fn();
        const preventDefault = vi.fn();
        field.props.onKeyDown?.({
            ctrlKey: ctrl,
            metaKey: meta,
            key: 'Enter',
            nativeEvent: { isComposing: composing },
            currentTarget: { form: { requestSubmit } },
            preventDefault,
        } as unknown as KeyboardEvent<HTMLTextAreaElement>);
        expect(requestSubmit).toHaveBeenCalledTimes(submitted ? 1 : 0);
        expect(preventDefault).toHaveBeenCalledTimes(submitted ? 1 : 0);
    },
);

test.each(['tr', 'en'] as const)(
    'topic progress in %s uses persisted topic counts without a fixed question total',
    (language) => {
        const html = renderInterview({
            ...state,
            language,
            completed_topics: 2,
            total_topics: 4,
            question_number: 6,
        });
        expect(html).toContain(interviewContent[language].progress);
        expect(html).toContain(interviewContent[language].topics(2, 4));
        expect(html).toContain('aria-valuenow="2"');
        expect(html).toContain('aria-valuemax="4"');
        expect(html).toContain('width:50%');
        expect(html).toContain(`${interviewContent[language].question} 6`);
        expect(html).not.toContain('6 /');
    },
);
