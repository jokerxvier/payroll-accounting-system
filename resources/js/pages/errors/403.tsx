import { Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { ErrorPage } from '@/components/errors/error-page';

export default function ForbiddenPage() {
    return (
        <>
            <Head title="Access denied" />
            <ErrorPage
                status={403}
                icon={Lock}
                title="You don't have access to this page"
                description="Your account doesn't have permission to view or perform this action. If you think this is a mistake, ask a super-admin to update your role."
                hint={
                    <>
                        Need elevated access? Ping your super-admin so they can
                        adjust the role assignment.
                    </>
                }
            />
        </>
    );
}
