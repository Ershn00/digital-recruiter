import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import MarkdownContent from '@/components/markdown-content';
import CandidateActions from '@/components/candidates/candidate-actions';
import type { CandidateApplication } from '@/types/candidates';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Position } from '@/types/positions';
import { label } from '@/types/positions';
export default function Show({
    position,
    applications = [],
    candidateFilter = 'active',
    activeCandidateCount = applications.length,
    archivedCandidateCount = 0,
}: {
    position: Position;
    applications?: CandidateApplication[];
    candidateFilter?: 'active' | 'archived';
    activeCandidateCount?: number;
    archivedCandidateCount?: number;
}) {
    const candidate = useForm({ name: '' });
    const [archiving, setArchiving] = useState(false);
    return (
        <div className="max-w-5xl space-y-6 p-4 sm:p-6">
            <Head title={position.title} />
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title={position.title}
                    description={`${position.location} · ${label(position.employment_type)} · ${label(position.interview_language)}`}
                />
                <Badge variant="secondary">{label(position.status)}</Badge>
            </div>
            {position.status === 'archived' ? (
                <p className="rounded-lg border bg-muted p-4 text-sm">
                    This position is archived and cannot be edited.
                </p>
            ) : (
                <div className="flex gap-3">
                    <Button asChild>
                        <Link href={`/positions/${position.id}/edit`}>
                            Edit position
                        </Link>
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="outline">Archive position</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    Archive this position?
                                </DialogTitle>
                                <DialogDescription>
                                    Archived positions remain available to view
                                    and cannot be edited or reactivated.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button
                                    disabled={archiving}
                                    onClick={() => {
                                        setArchiving(true);
                                        router.post(
                                            `/positions/${position.id}/archive`,
                                            {},
                                            {
                                                onFinish: () =>
                                                    setArchiving(false),
                                            },
                                        );
                                    }}
                                >
                                    {archiving
                                        ? 'Archiving…'
                                        : 'Confirm archive'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            )}
            <section
                id="candidates"
                className="scroll-mt-6 space-y-3 rounded-lg border p-4"
            >
                <h2 className="text-lg font-semibold">
                    Fictional candidates · AI interview demo
                </h2>
                <nav
                    aria-label="Candidate lists"
                    className="flex flex-wrap gap-4 text-sm"
                >
                    <Link
                        href={`/positions/${position.id}#candidates`}
                        aria-current={
                            candidateFilter === 'active' ? 'page' : undefined
                        }
                        className="underline underline-offset-4"
                    >
                        Active candidates ({activeCandidateCount})
                    </Link>
                    <Link
                        href={`/positions/${position.id}?candidates=archived#candidates`}
                        aria-current={
                            candidateFilter === 'archived' ? 'page' : undefined
                        }
                        className="underline underline-offset-4"
                    >
                        Archived candidates ({archivedCandidateCount})
                    </Link>
                </nav>
                {candidateFilter === 'archived' && (
                    <p className="text-sm text-muted-foreground">
                        Archived candidates keep their interview history.
                        Restore a candidate, then generate a new interview link
                        if eligible.
                    </p>
                )}
                {!applications.length && (
                    <p className="text-sm text-muted-foreground">
                        {candidateFilter === 'archived'
                            ? 'No archived candidates.'
                            : 'No active candidates.'}
                    </p>
                )}
                {position.status === 'active' &&
                    candidateFilter === 'active' && (
                        <form
                            id="add-candidate"
                            className="flex scroll-mt-6 flex-wrap gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                candidate.post(
                                    `/positions/${position.id}/interviews`,
                                );
                            }}
                        >
                            <label htmlFor="candidate-name" className="sr-only">
                                Fictional candidate name
                            </label>
                            <input
                                id="candidate-name"
                                className="rounded-md border p-2"
                                required
                                maxLength={200}
                                placeholder="Fictional candidate name"
                                value={candidate.data.name}
                                onChange={(event) =>
                                    candidate.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                            <Button disabled={candidate.processing}>
                                Add candidate and create interview link
                            </Button>
                            {candidate.errors.name && (
                                <p role="alert" className="text-destructive">
                                    {candidate.errors.name}
                                </p>
                            )}
                        </form>
                    )}
                {position.status !== 'active' && (
                    <p>Activate this position to start interviews.</p>
                )}
                {applications.map((application) => (
                    <div
                        key={application.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                    >
                        {application.interview_url && application.interview ? (
                            <>
                                <div className="space-y-1">
                                    <Link
                                        className="font-medium underline underline-offset-4"
                                        href={application.interview_url}
                                    >
                                        {application.name}
                                    </Link>
                                    <p className="text-sm text-muted-foreground">
                                        Interview #{application.interview.id} ·{' '}
                                        {label(application.interview.status)}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button asChild variant="outline">
                                        <Link
                                            href={application.interview_url}
                                            aria-label={`View interview for ${application.name}`}
                                        >
                                            View Interview
                                        </Link>
                                    </Button>
                                    <CandidateActions candidate={application} />
                                </div>
                            </>
                        ) : (
                            <div>
                                <p className="font-medium">
                                    {application.name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    No interview available.
                                </p>
                            </div>
                        )}
                        {!application.interview && (
                            <CandidateActions candidate={application} />
                        )}
                    </div>
                ))}
            </section>
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Job description</h2>
                <MarkdownContent content={position.description} />
            </section>
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Requirements</h2>
                {position.requirements.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No requirements defined.
                    </p>
                ) : (
                    <ol className="space-y-3">
                        {position.requirements.map((item, index) => (
                            <li
                                key={item.id}
                                className="space-y-2 rounded-lg border p-4"
                            >
                                <Badge variant="outline">
                                    {label(item.category)}
                                </Badge>
                                <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                                    {index + 1}. {item.text}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </section>
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">
                    Screening topics and questions
                </h2>
                {position.screening_topics.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No screening topics defined.
                    </p>
                ) : (
                    <ol className="space-y-3">
                        {position.screening_topics.map((item, index) => (
                            <li
                                key={item.id}
                                className="space-y-2 rounded-lg border p-4"
                            >
                                <Badge variant="outline">
                                    {item.required ? 'Required' : 'Optional'}
                                </Badge>
                                <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                                    {index + 1}. {item.text}
                                </p>
                                {item.requirement_id && (
                                    <p className="text-sm text-muted-foreground">
                                        Requirement:{' '}
                                        {
                                            position.requirements.find(
                                                (row) =>
                                                    row.id ===
                                                    item.requirement_id,
                                            )?.text
                                        }
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </section>
            <Link href="/positions" className="inline-block text-sm underline">
                Back to positions
            </Link>
        </div>
    );
}
Show.layout = {
    breadcrumbs: [
        { title: 'Positions', href: '/positions' },
        { title: 'Details', href: '#' },
    ],
};
