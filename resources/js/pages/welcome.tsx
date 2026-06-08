import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="flex w-full items-center justify-between p-6">
                    <div className="flex items-center gap-2">
                        <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                            <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                        </div>
                        <span className="font-semibold tracking-tight">
                            Payroll and Accounting
                        </span>
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                {canRegister && (
                                    <Button asChild variant="outline">
                                        <Link href={register()}>Register</Link>
                                    </Button>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                <main className="flex flex-1 items-center justify-center px-6 pb-12">
                    <div className="max-w-2xl text-center">
                        <h1 className="font-serif text-4xl leading-tight font-semibold tracking-tight sm:text-5xl">
                            Payroll and Accounting
                        </h1>
                        <p className="mt-4 text-lg text-muted-foreground">
                            Run accurate Philippine payroll with statutory
                            contributions, batch processing, and a verifiable
                            audit trail.
                        </p>
                        {!auth.user && (
                            <div className="mt-8 flex items-center justify-center gap-3">
                                <Button asChild size="lg">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                {canRegister && (
                                    <Button asChild size="lg" variant="outline">
                                        <Link href={register()}>
                                            Create account
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}
