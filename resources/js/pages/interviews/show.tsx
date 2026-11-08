import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import type { Evaluation, InterviewMessage } from '@/types/interviews';
import type { Requirement } from '@/types/positions';
type Detail = {
    id: number;
    status: string;
    completed_at: string | null;
    expires_at: string;
    application: {
        name: string;
        job_position_id: number;
        archived_at: string | null;
    };
    criteria_snapshot: {
        title: string;
        version: number;
        interview_language?: 'tr' | 'en';
        requirements: Requirement[];
    };
    messages: InterviewMessage[];
    evaluation: { result: Evaluation; model: string } | null;
};
export default function Show({
    interview,
    position,
    linkVersion,
    canRegenerateLink,
    linkExpired,
}: {
    interview: Detail;
    position: { id: number; title: string };
    linkVersion: string;
    canRegenerateLink: boolean;
    linkExpired: boolean;
}) {
    const turkish = interview.criteria_snapshot.interview_language === 'tr';
    const labels = turkish
        ? {
              question: 'Soru',
              answer: 'Yanıt',
              candidate: 'Aday',
              status: 'Görüşme durumu',
              active: 'Devam ediyor',
              completed: 'Tamamlandı',
              criteria: 'Kriter sürümü',
              snapshot: 'Görüşmenin oluşturulduğu pozisyon',
          }
        : {
              question: 'Question',
              answer: 'Answer',
              candidate: 'Candidate',
              status: 'Interview status',
              active: 'In progress',
              completed: 'Completed',
              criteria: 'Criteria version',
              snapshot: 'Position at interview creation',
          };
    const counts = { assistant: 0, candidate: 0 };
    const transcript = interview.messages.map((message) => ({
        ...message,
        label: `${message.role === 'assistant' ? labels.question : labels.answer} ${++counts[message.role]} · ${message.role === 'assistant' ? 'AI' : labels.candidate}`,
    }));
    const messageLabels = new Map(
        transcript.map((message) => [message.id, message.label]),
    );
    const status =
        interview.status === 'active'
            ? labels.active
            : interview.status === 'completed'
              ? labels.completed
              : interview.status;
    const [requestError, setRequestError] = useState('');
    const [linkError, setLinkError] = useState('');
    const linkForm = useForm({ link_version: linkVersion });
    const form = useForm<{ interview?: string }>({});
    return (
        <main className="max-w-4xl space-y-6 p-6">
            <Head title={`Interview: ${interview.application.name}`} />
            <Breadcrumbs
                breadcrumbs={[
                    { title: 'Positions', href: '/positions' },
                    {
                        title: position.title,
                        href: `/positions/${position.id}`,
                    },
                    {
                        title: interview.application.name,
                        href: `/interviews/${interview.id}`,
                    },
                ]}
            />
            <h1 className="text-2xl font-semibold">
                {interview.application.name} — fictional candidate
            </h1>
            {interview.application.archived_at && (
                <p className="rounded-lg border bg-muted p-3 text-sm">
                    Archived candidate. Interview history is preserved and
                    candidate access is disabled.
                </p>
            )}
            <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                <div>
                    <dt className="text-muted-foreground">{labels.status}</dt>
                    <dd className="font-medium">{status}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">{labels.criteria}</dt>
                    <dd className="font-medium">
                        {interview.criteria_snapshot.version}
                    </dd>
                </div>
                {interview.criteria_snapshot.title !== position.title && (
                    <div>
                        <dt className="text-muted-foreground">
                            {labels.snapshot}
                        </dt>
                        <dd className="font-medium">
                            {interview.criteria_snapshot.title}
                        </dd>
                    </div>
                )}
            </dl>
            <section className="space-y-4">
                <h2 className="text-xl font-semibold">
                    Evidence-based AI evaluation
                </h2>
                <p>
                    This is decision support for recruiter review. No candidate
                    is automatically approved or rejected. Evidence confirms
                    what was said, not whether a claim is true.
                </p>
                {requestError && (
                    <p role="alert" className="text-destructive">
                        {requestError}
                    </p>
                )}
                {Object.values(form.errors).map((error) => (
                    <p role="alert" key={error} className="text-destructive">
                        {error}
                    </p>
                ))}
                {!interview.evaluation && (
                    <Button
                        disabled={
                            interview.status !== 'completed' || form.processing
                        }
                        onClick={() =>
                            form.post(`/interviews/${interview.id}/evaluate`, {
                                onStart: () => setRequestError(''),
                                onNetworkError: () => {
                                    setRequestError(
                                        'Connection interrupted. Refresh to check whether evaluation finished, then retry if needed.',
                                    );
                                    return false;
                                },
                                onHttpException: () => {
                                    setRequestError(
                                        'Request failed. Refresh the page, sign in again if needed, then retry. The transcript is saved.',
                                    );
                                    return false;
                                },
                            })
                        }
                    >
                        {form.processing
                            ? 'Generating evaluation…'
                            : 'Generate evaluation / retry'}
                    </Button>
                )}
                {interview.status !== 'completed' && (
                    <p>
                        Complete the candidate interview before generating an
                        evaluation.
                    </p>
                )}
                {interview.evaluation && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Model: {interview.evaluation.model}
                        </p>
                        {interview.evaluation.result.criteria.map(
                            (criterion) => (
                                <article
                                    key={criterion.criterion_id}
                                    className="space-y-2 rounded-lg border p-4"
                                >
                                    <h3 className="font-semibold">
                                        {
                                            interview.criteria_snapshot.requirements.find(
                                                (row) =>
                                                    row.id ===
                                                    criterion.criterion_id,
                                            )?.text
                                        }
                                    </h3>
                                    <p>
                                        {criterion.state ===
                                        'insufficient_evidence'
                                            ? 'Insufficient evidence / Yetersiz kanıt'
                                            : criterion.state}
                                    </p>
                                    <p>{criterion.rationale}</p>
                                    {criterion.evidence.map(
                                        (evidence, index) => (
                                            <blockquote
                                                key={index}
                                                className="border-l-2 pl-3"
                                            >
                                                <a
                                                    className="underline"
                                                    href={`#message-${evidence.message_id}`}
                                                >
                                                    {messageLabels.get(
                                                        evidence.message_id,
                                                    ) ??
                                                        `Message #${evidence.message_id}`}
                                                </a>
                                                : {evidence.quote}
                                            </blockquote>
                                        ),
                                    )}
                                </article>
                            ),
                        )}
                        <h3 className="font-semibold">Strengths</h3>
                        {interview.evaluation.result.strengths.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {turkish
                                    ? 'Hiçbir kriter desteklenmiş olarak değerlendirilmedi.'
                                    : 'No criteria were assessed as supported.'}
                            </p>
                        )}
                        <ul className="list-disc pl-6">
                            {interview.evaluation.result.strengths.map(
                                (item, index) => (
                                    <li key={index}>{item}</li>
                                ),
                            )}
                        </ul>
                        <h3 className="font-semibold">Gaps</h3>
                        {interview.evaluation.result.gaps.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {turkish
                                    ? 'Hiçbir kriter eksiklik olarak değerlendirilmedi. Yetersiz kanıt bulunan kriterler için yukarıdaki değerlendirmelere bakın.'
                                    : 'No criteria were assessed as a gap. See individual criteria above for insufficient evidence.'}
                            </p>
                        )}
                        <ul className="list-disc pl-6">
                            {interview.evaluation.result.gaps.map(
                                (item, index) => (
                                    <li key={index}>{item}</li>
                                ),
                            )}
                        </ul>
                    </>
                )}
            </section>
            <section className="space-y-3">
                <h2 className="text-xl font-semibold">Transcript</h2>
                {!interview.messages.length && (
                    <p>The candidate has not started yet.</p>
                )}
                {transcript.map((message) => (
                    <article
                        id={`message-${message.id}`}
                        key={message.id}
                        className="rounded-lg border p-4"
                    >
                        <strong>{message.label}</strong>
                        <p className="mt-2 wrap-anywhere whitespace-pre-wrap">
                            {message.content}
                        </p>
                    </article>
                ))}
            </section>
            <div className="flex flex-wrap items-center gap-4">
                <Link
                    className="underline"
                    href={`/positions/${interview.application.job_position_id}${interview.application.archived_at ? '?candidates=archived' : ''}#candidates`}
                >
                    Back to position and candidates
                </Link>
                <Button
                    variant="outline"
                    onClick={() => window.location.reload()}
                >
                    Refresh transcript
                </Button>
            </div>
            <section
                className="space-y-3 rounded-xl border p-4"
                aria-labelledby="interview-access"
            >
                <h2 id="interview-access" className="font-semibold">
                    Candidate interview access
                </h2>
                <p className="text-sm text-muted-foreground">
                    {linkExpired ? 'Link expired: ' : 'Link expires: '}
                    <time dateTime={interview.expires_at}>
                        {new Date(interview.expires_at).toLocaleString()}
                    </time>
                </p>
                {canRegenerateLink ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            If the private link was lost or expired, generate a
                            replacement for this interview. The previous link
                            will stop working; candidate progress and saved
                            answers will be preserved.
                        </p>
                        <Button
                            disabled={linkForm.processing}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        'Generate a new link? The previous link will immediately stop working. Saved interview progress will be preserved.',
                                    )
                                )
                                    return;
                                linkForm.transform((data) => ({
                                    ...data,
                                    link_version: linkVersion,
                                }));
                                linkForm.post(
                                    `/interviews/${interview.id}/regenerate-link`,
                                    {
                                        onStart: () => setLinkError(''),
                                        onNetworkError: () => {
                                            setLinkError(
                                                'Connection interrupted. Refresh this interview detail before trying again. A new link may already have been generated.',
                                            );
                                            return false;
                                        },
                                        onHttpException: () => {
                                            setLinkError(
                                                'The link could not be generated. Refresh this page and try again.',
                                            );
                                            return false;
                                        },
                                    },
                                );
                            }}
                        >
                            {linkForm.processing
                                ? 'Generating new link…'
                                : 'Generate New Interview Link'}
                        </Button>
                        {Object.values(linkForm.errors).map((error) => (
                            <p
                                role="alert"
                                key={error}
                                className="text-sm text-destructive"
                            >
                                {error}
                            </p>
                        ))}
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {interview.application.archived_at
                            ? 'Restore this candidate from the position’s archived candidate list before generating a new link, if the interview is unfinished.'
                            : 'New links cannot be generated for completed interviews.'}
                    </p>
                )}
                {linkError && (
                    <p role="alert" className="text-sm text-destructive">
                        {linkError}
                    </p>
                )}
            </section>
        </main>
    );
}
