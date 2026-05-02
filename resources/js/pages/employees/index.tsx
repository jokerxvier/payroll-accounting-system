import { Head } from '@inertiajs/react';

export default function EmployeesIndex(props: Record<string, unknown>) {
    // TODO(slice-3b): replace with real UI (PageHeader, StatCard, table, filters, Money)
    return (
        <>
            <Head title="Employees" />
            <pre>{JSON.stringify(props, null, 2)}</pre>
        </>
    );
}
