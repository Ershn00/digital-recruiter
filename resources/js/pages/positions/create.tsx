import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import PositionForm from '@/components/positions/position-form';
import type { PositionOptions } from '@/types/positions';
export default function Create(props: PositionOptions) {
    return (
        <div className="space-y-6 p-4 sm:p-6">
            <Head title="Create position" />
            <Heading
                title="Create position"
                description="Define the role, requirements, and screening topics."
            />
            <PositionForm {...props} />
        </div>
    );
}
Create.layout = {
    breadcrumbs: [
        { title: 'Positions', href: '/positions' },
        { title: 'Create', href: '/positions/create' },
    ],
};
