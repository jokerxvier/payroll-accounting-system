import { Head } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { ErrorPage } from '@/components/errors/error-page';

export default function NotFoundPage() {
    return (
        <>
            <Head title="Page not found" />
            <ErrorPage
                status={404}
                icon={SearchX}
                title="We couldn't find that page"
                description="The page you tried to open doesn't exist, was renamed, or has been removed. Check the URL or jump back to the dashboard."
            />
        </>
    );
}
