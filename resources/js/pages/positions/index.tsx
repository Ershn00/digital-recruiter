import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Position } from '@/types/positions';
import { label } from '@/types/positions';
type Page = {
    data: Position[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};
export default function Index({ positions }: { positions: Page }) {
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Positions" />
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title="Job positions"
                    description="Manage your roles and screening plans."
                />
                <Button asChild>
                    <Link href="/positions/create">Create position</Link>
                </Button>
            </div>
            {positions.data.length === 0 ? (
                <div className="rounded-xl border border-dashed p-10 text-center">
                    <h2 className="font-semibold">No positions yet</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Create your first position to define its requirements
                        and screening topics.
                    </p>
                </div>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {positions.data.map((position) => (
                        <Link
                            key={position.id}
                            href={`/positions/${position.id}`}
                            className="space-y-3 rounded-xl border p-5 transition-colors hover:bg-muted/50"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h2 className="font-semibold wrap-anywhere">
                                    {position.title}
                                </h2>
                                <Badge variant="secondary">
                                    {label(position.status)}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {position.location} ·{' '}
                                {label(position.employment_type)}
                            </p>
                        </Link>
                    ))}
                </div>
            )}
            {positions.last_page > 1 && (
                <nav
                    aria-label="Pagination"
                    className="flex items-center justify-between gap-4 text-sm"
                >
                    {positions.prev_page_url ? (
                        <Link href={positions.prev_page_url}>Previous</Link>
                    ) : (
                        <span className="text-muted-foreground">Previous</span>
                    )}
                    <span>
                        Page {positions.current_page} of {positions.last_page}
                    </span>
                    {positions.next_page_url ? (
                        <Link href={positions.next_page_url}>Next</Link>
                    ) : (
                        <span className="text-muted-foreground">Next</span>
                    )}
                </nav>
            )}
        </div>
    );
}
Index.layout = { breadcrumbs: [{ title: 'Positions', href: '/positions' }] };
