import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
export default function Show({ position }: { position: Position }) {
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
            <section className="space-y-3">
                <h2 className="text-lg font-semibold">Job description</h2>
                <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                    {position.description}
                </p>
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
