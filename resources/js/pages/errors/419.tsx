import { Head } from '@inertiajs/react';
import { Timer } from 'lucide-react';
import { ErrorPage } from '@/components/errors/error-page';

export default function PageExpiredPage() {
    return (
        <>
            <Head title="Session expired" />
            <ErrorPage
                status={419}
                icon={Timer}
                title="Your session expired"
                description="For security, we logged your session out after a period of inactivity. Refresh the page or sign in again to continue."
            />
        </>
    );
}
