import { Head, Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useRef, useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { copyInterviewLink } from '@/lib/copy-interview-link';

export default function Created({
    interviewId,
    candidateUrl,
    position,
    candidateName,
    regenerated = false,
    expiresAt,
}: {
    interviewId: number;
    candidateUrl: string;
    position: { id: number; title: string };
    candidateName: string;
    regenerated?: boolean;
    expiresAt?: string;
}) {
    const field = useRef<HTMLInputElement>(null);
    const [copyState, setCopyState] = useState<
        'idle' | 'copying' | 'copied' | 'manual'
    >('idle');
    return (
        <main className="max-w-3xl space-y-6 p-4 sm:p-6">
            <Head title="Candidate interview link" />
            <Breadcrumbs
                breadcrumbs={[
                    { title: 'Positions', href: '/positions' },
                    {
                        title: position.title,
                        href: `/positions/${position.id}#candidates`,
                    },
                    {
                        title: regenerated
                            ? 'New interview link'
                            : 'Candidate added',
                        href: '#',
                    },
                ]}
            />
            <header className="space-y-2">
                <h1 className="text-2xl font-semibold">
                    {regenerated
                        ? 'New interview link generated'
                        : 'Fictional candidate added'}
                </h1>
                <p className="text-muted-foreground">
                    {candidateName} · {position.title}
                </p>
            </header>
            <section
                aria-labelledby="private-link-heading"
                className="space-y-4 rounded-xl border p-5"
            >
                <h2 id="private-link-heading" className="font-semibold">
                    Save the private interview link before leaving
                </h2>
                <p className="text-sm text-muted-foreground">
                    This link expires in 24 hours. It is only displayed on this
                    confirmation screen. The original token cannot be recovered.
                    For an unfinished interview, you can generate a new link
                    from the interview detail page without creating another
                    candidate. Copy it now and share it securely with the
                    intended candidate. Anyone with the link can access this
                    interview.
                </p>
                {regenerated && (
                    <p
                        role="status"
                        className="rounded-lg border bg-muted/50 p-3 text-sm font-medium"
                    >
                        The previous interview link is now invalid. The
                        candidate and interview progress have been preserved.
                    </p>
                )}
                {expiresAt && (
                    <p className="text-sm text-muted-foreground">
                        Link expires:{' '}
                        <time dateTime={expiresAt}>
                            {new Date(expiresAt).toLocaleString()}
                        </time>
                    </p>
                )}
                <label htmlFor="private-interview-link" className="sr-only">
                    Private interview link
                </label>
                <input
                    ref={field}
                    id="private-interview-link"
                    readOnly
                    value={candidateUrl}
                    className="w-full rounded-md border bg-muted/40 p-3 text-sm"
                    onFocus={(event) => event.currentTarget.select()}
                />
                <div className="flex flex-wrap items-center gap-4">
                    <Button
                        type="button"
                        className="min-h-11"
                        disabled={copyState === 'copying'}
                        onClick={async () => {
                            setCopyState('copying');
                            setCopyState(
                                (await copyInterviewLink(
                                    candidateUrl,
                                    field.current,
                                ))
                                    ? 'copied'
                                    : 'manual',
                            );
                        }}
                    >
                        {copyState === 'copied' ? (
                            <Check aria-hidden="true" />
                        ) : (
                            <Copy aria-hidden="true" />
                        )}
                        {copyState === 'copied'
                            ? 'Interview Link Copied'
                            : copyState === 'copying'
                              ? 'Copying…'
                              : 'Copy Interview Link'}
                    </Button>
                    <a
                        className="text-sm underline"
                        href={candidateUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open candidate interview
                    </a>
                </div>
                <p
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {copyState === 'copied'
                        ? 'Interview link copied. Save it somewhere secure before leaving this screen.'
                        : copyState === 'manual'
                          ? 'Automatic copying is unavailable. The link is selected: press Ctrl/Cmd+C, or touch and hold to copy it manually.'
                          : 'Use fictional answers only for this demo.'}
                </p>
            </section>
            <div className="flex flex-wrap gap-3">
                <Button asChild variant="outline" className="min-h-11">
                    <Link href={`/interviews/${interviewId}`}>
                        View recruiter interview detail
                    </Link>
                </Button>
                <Button asChild variant="outline" className="min-h-11">
                    <Link href={`/positions/${position.id}#add-candidate`}>
                        Create Another Candidate
                    </Link>
                </Button>
            </div>
        </main>
    );
}
