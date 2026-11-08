import { Head, router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import InterviewQuestion from '@/components/candidate/interview-question';
import {
    interviewContent,
    readInterviewDraft,
    storeInterviewDraft,
} from '@/lib/candidate-interview';
import type {
    CandidateInterviewState,
    InterviewDraft,
} from '@/lib/candidate-interview';
import { createUuid } from '@/lib/uuid';

export default function Interview({
    interview,
    token,
}: {
    interview: CandidateInterviewState;
    token: string;
}) {
    const copy = interviewContent[interview.language];
    const endpoint = `/candidate/interviews/${token}`;
    const storageKey = `digital-recruiter:interview-draft:${interview.id}`;
    const [draft, setDraft] = useState<InterviewDraft | null>(() =>
        readInterviewDraft(storageKey),
    );
    const draftRef = useRef(draft);
    const inFlight = useRef(false);
    const [activity, setActivity] = useState<'prepare' | 'check' | null>(null);
    const [error, setError] = useState('');
    const [storageError, setStorageError] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const focusedQuestion = useRef<number | null>(null);
    const question = interview.current_question;
    // A persisted receipt acknowledges an answer even if AI generation failed afterward.
    const unconfirmed =
        draft?.submissionId === interview.last_submission_id ? null : draft;
    const staleDraft = unconfirmed && unconfirmed.questionId !== question?.id;
    const answer = unconfirmed?.content ?? '';
    const locked = unconfirmed?.submitted ?? false;
    const busy = activity !== null;

    const persistDraft = useCallback(
        (next: InterviewDraft | null) => {
            draftRef.current = next;
            setDraft(next);
            setStorageError(!storeInterviewDraft(storageKey, next));
        },
        [storageKey],
    );

    useEffect(() => {
        if (draftRef.current?.submissionId === interview.last_submission_id)
            persistDraft(null);
    }, [interview.last_submission_id, persistDraft]);

    useEffect(() => {
        const field = textarea.current;
        if (!field) return;
        field.style.height = 'auto';
        field.style.height = `${Math.min(field.scrollHeight, 480)}px`;
    }, [answer, question?.id, staleDraft]);

    useEffect(() => {
        if (!question || staleDraft || focusedQuestion.current === question.id)
            return;
        // Avoid bringing up a mobile keyboard on initial load or a question transition.
        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            textarea.current?.focus({ preventScroll: true });
        }
        focusedQuestion.current = question.id;
    }, [question, staleDraft]);

    function visit(
        operation: 'answer' | 'resume' | 'check',
        submittedDraft?: InterviewDraft,
    ) {
        if (inFlight.current) return;
        inFlight.current = true;
        setActivity(operation === 'check' ? 'check' : 'prepare');
        setError('');
        const options = {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) =>
                setError(errors.content ? copy.answerError : copy.error),
            onNetworkError: () => {
                setError(copy.networkError);
                return false;
            },
            onHttpException: () => {
                setError(copy.httpError);
                return false;
            },
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const saved = page.props.interview;
                const current = draftRef.current;
                if (
                    current &&
                    saved &&
                    typeof saved === 'object' &&
                    'last_submission_id' in saved &&
                    saved.last_submission_id === current.submissionId
                )
                    persistDraft(null);
            },
            onFinish: () => {
                inFlight.current = false;
                setActivity(null);
            },
        };
        if (operation === 'check') {
            router.reload(options);
        } else {
            router.post(
                endpoint,
                submittedDraft
                    ? {
                          operation: 'answer',
                          content: submittedDraft.content,
                          submission_id: submittedDraft.submissionId,
                          reply_to: submittedDraft.questionId,
                      }
                    : { operation },
                options,
            );
        }
    }

    function submitAnswer() {
        if (inFlight.current || !question || staleDraft) return;
        const current = draftRef.current;
        if (!current?.content.trim() || current.content.length > 6000) {
            setError(copy.answerError);
            return;
        }
        const submitted = { ...current, submitted: true };
        persistDraft(submitted);
        visit('answer', submitted);
    }

    return (
        <main
            lang={interview.language}
            className="min-h-svh bg-background px-4 py-8 sm:px-6 sm:py-12"
        >
            <Head title={`${interview.title} — ${copy.interview}`} />
            <div className="mx-auto max-w-2xl space-y-6">
                <header className="space-y-2">
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Sparkles aria-hidden="true" className="size-4" />
                        {copy.interview}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight wrap-anywhere sm:text-3xl">
                        {interview.title}
                    </h1>
                    {interview.started && (
                        <section
                            aria-labelledby="interview-progress"
                            className="space-y-2 pt-3"
                        >
                            <h2
                                id="interview-progress"
                                className="text-sm font-medium"
                            >
                                {copy.progress}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {copy.topics(
                                    interview.completed_topics,
                                    interview.total_topics,
                                )}
                            </p>
                            <div
                                role="progressbar"
                                aria-labelledby="interview-progress"
                                aria-valuemin={0}
                                aria-valuemax={interview.total_topics}
                                aria-valuenow={interview.completed_topics}
                                aria-valuetext={copy.topics(
                                    interview.completed_topics,
                                    interview.total_topics,
                                )}
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full rounded-full bg-primary motion-safe:transition-[width]"
                                    style={{
                                        width: `${interview.total_topics > 0 ? Math.min(100, Math.max(0, (interview.completed_topics / interview.total_topics) * 100)) : 0}%`,
                                    }}
                                />
                            </div>
                        </section>
                    )}
                </header>
                <section
                    key={question?.id ?? 'waiting'}
                    aria-busy={busy}
                    className="space-y-6 rounded-2xl border bg-card p-5 text-card-foreground shadow-sm motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in sm:p-8"
                >
                    {staleDraft ? (
                        <div className="space-y-4">
                            <p role="alert">{copy.stale}</p>
                            <label
                                htmlFor="unconfirmed-answer"
                                className="block text-sm font-medium"
                            >
                                {copy.unconfirmed}
                            </label>
                            <textarea
                                id="unconfirmed-answer"
                                readOnly
                                value={answer}
                                rows={5}
                                className="w-full rounded-lg border bg-background p-3 text-base"
                            />
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => {
                                    if (window.confirm(copy.confirmDiscard))
                                        persistDraft(null);
                                }}
                            >
                                {copy.discard}
                            </Button>
                        </div>
                    ) : !interview.started ? (
                        <div className="space-y-6">
                            <p className="leading-relaxed text-muted-foreground">
                                {copy.introduction}
                            </p>
                            <Button
                                size="lg"
                                className="min-h-11 w-full sm:w-auto"
                                disabled={busy}
                                onClick={() => visit('resume')}
                            >
                                {busy ? copy.preparing : copy.start}
                            </Button>
                        </div>
                    ) : question ? (
                        <InterviewQuestion
                            language={interview.language}
                            question={question}
                            questionNumber={interview.question_number}
                            answer={answer}
                            locked={locked}
                            busy={busy}
                            textareaRef={textarea}
                            onSubmit={submitAnswer}
                            onChange={(content) => {
                                if (!locked && !inFlight.current)
                                    persistDraft({
                                        questionId: question.id,
                                        content,
                                        submissionId:
                                            unconfirmed?.submissionId ??
                                            createUuid(),
                                        submitted: false,
                                    });
                            }}
                        />
                    ) : (
                        <div className="space-y-5">
                            {interview.answer_saved && (
                                <p className="font-medium">{copy.saved}</p>
                            )}
                            <p className="text-muted-foreground">
                                {busy ? copy.preparing : copy.pending}
                            </p>
                            <Button
                                size="lg"
                                className="min-h-11 w-full sm:w-auto"
                                disabled={busy}
                                onClick={() => visit('resume')}
                            >
                                {copy.resume}
                            </Button>
                        </div>
                    )}
                    {(busy ||
                        (question &&
                            interview.last_submission_id &&
                            !unconfirmed)) && (
                        <div role="status" aria-live="polite">
                            {busy && (
                                <p className="text-sm text-muted-foreground">
                                    {activity === 'check'
                                        ? copy.checking
                                        : copy.preparing}
                                </p>
                            )}
                            {!busy &&
                                question &&
                                interview.last_submission_id &&
                                !unconfirmed && (
                                    <p className="text-sm text-muted-foreground">
                                        {copy.saved}
                                    </p>
                                )}
                        </div>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {storageError && (
                        <p
                            role="alert"
                            className="text-sm text-muted-foreground"
                        >
                            {copy.storageError}
                        </p>
                    )}
                    {!busy &&
                        (error || staleDraft || locked) &&
                        (question || staleDraft || !interview.started) && (
                            <div className="border-t pt-5">
                                <Button
                                    variant="outline"
                                    type="button"
                                    className="min-h-11"
                                    onClick={() => visit('check')}
                                >
                                    {copy.check}
                                </Button>
                            </div>
                        )}
                </section>
            </div>
        </main>
    );
}
