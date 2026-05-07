import { Head } from '@inertiajs/react';
import { Timer } from 'lucide-react';
import { ErrorPage } from '@/components/errors/error-page';

export default function ServiceUnavailablePage() {
    return (
        <>
            <Head title="Maintenance in progress" />
            <ErrorPage
                status={503}
                icon={Timer}
                title="The system is briefly unavailable"
                description="We're performing scheduled maintenance and will be back shortly. Refresh the page in a few minutes."
            />
        </>
    );
}
