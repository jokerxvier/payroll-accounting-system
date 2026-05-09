import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';

type Props = {
    status?: string;
    canRegister: boolean;
    showDemoLogin?: boolean;
};

const DEMO_ACCOUNTS = [
    // Payroll Admin = payroll-native (lms_user_id NULL, role `platform-admin`).
    // Cross-tenant: sees the school switcher, manages /admin/schools.
    // Listed first so the multi-tenant operator persona is the default click.
    {
        label: 'Payroll Admin',
        email: 'admin@payroll.test',
        password: 'password',
    },
    // The four below are LMS-derived demo accounts — pinned to school_id=1
    // (default school). They keep their school-scoped roles but no longer
    // see the switcher or /admin/schools after the platform-admin split.
    {
        label: 'Super Admin',
        email: 'super-admin@demo.test',
        password: 'password',
    },
    {
        label: 'Payroll Officer',
        email: 'payroll-officer@demo.test',
        password: 'password',
    },
    { label: 'HR', email: 'hr@demo.test', password: 'password' },
    { label: 'Auditor', email: 'auditor@demo.test', password: 'password' },
] as const;

export default function Login({
    status,
    canRegister,
    showDemoLogin = false,
}: Props) {
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);

    function fillCredentials(email: string, password: string) {
        if (emailRef.current) {
            emailRef.current.value = email;
        }

        if (passwordRef.current) {
            passwordRef.current.value = password;
            passwordRef.current.focus();
        }
    }

    return (
        <>
            <Head title="Log in" />

            <Form
                action={store().url}
                method="post"
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    ref={emailRef}
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                </div>
                                <PasswordInput
                                    ref={passwordRef}
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                Don't have an account?{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    Sign up
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {showDemoLogin && (
                <div className="mt-6 space-y-3 rounded-lg border border-dashed border-border p-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        Demo accounts — fill credentials with one click
                    </p>
                    {/* Payroll Admin (platform-native) sits on its own row to
                        signal the cross-tenant persona — sees the switcher
                        and manages /admin/schools. */}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full"
                        onClick={() =>
                            fillCredentials(
                                DEMO_ACCOUNTS[0].email,
                                DEMO_ACCOUNTS[0].password,
                            )
                        }
                    >
                        {DEMO_ACCOUNTS[0].label}
                        <span className="ml-2 rounded bg-amber-100 px-1 text-[9px] font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                            PLATFORM
                        </span>
                    </Button>
                    {/* LMS-derived accounts in a 2x2 grid below. Pinned to the
                        default school; no switcher; no schools admin. */}
                    <div className="grid grid-cols-2 gap-2">
                        {DEMO_ACCOUNTS.slice(1).map((account) => (
                            <Button
                                key={account.email}
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    fillCredentials(
                                        account.email,
                                        account.password,
                                    )
                                }
                            >
                                {account.label}
                            </Button>
                        ))}
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                        Hidden in production. Run{' '}
                        <code className="rounded bg-muted px-1 py-0.5 font-mono text-[10px]">
                            php artisan db:seed --class=DemoUsersSeeder
                        </code>{' '}
                        once to create the accounts.
                    </p>
                </div>
            )}

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
