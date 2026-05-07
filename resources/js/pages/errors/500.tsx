import { Head } from '@inertiajs/react';
import { ServerCrash } from 'lucide-react';
import { ErrorPage } from '@/components/errors/error-page';

export default function ServerErrorPage() {
    return (
        <>
            <Head title="Something went wrong" />
            <ErrorPage
                status={500}
                icon={ServerCrash}
                title="Something went wrong on our end"
                description="An unexpected error stopped the request from completing. The team has been notified — try again in a moment, or head back to the dashboard."
            />
        </>
    );
}
