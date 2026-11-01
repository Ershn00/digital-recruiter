import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import PositionForm from '@/components/positions/position-form';
import type { Position, PositionOptions } from '@/types/positions';
export default function Edit(props: PositionOptions & { position: Position }) {
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title={`Edit ${props.position.title}`} />
            <Heading
                title="Edit position"
                description="Changes update this position’s version for future interview snapshots."
            />
            <PositionForm {...props} />
        </div>
    );
}
Edit.layout = {
    breadcrumbs: [
        { title: 'Positions', href: '/positions' },
        { title: 'Edit', href: '#' },
    ],
};
