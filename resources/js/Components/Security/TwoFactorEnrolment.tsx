import { router, useForm } from '@inertiajs/react';
import { Check, Copy, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';

interface Props {
    /** A secret exists, whether or not a code has confirmed it yet. */
    enabled: boolean;
    /** A code has been entered, so the authenticator is actually in use. */
    confirmed: boolean;
    /**
     * Whether this account may not use the platform without it. Administrators
     * may not, and are held on this screen until they enrol (spec §9), so they
     * are not offered a way to turn it off from here.
     */
    required?: boolean;
}

/**
 * Enrolling an authenticator (spec §9).
 *
 * The three steps are separate screens rather than one form, because each has a
 * different failure: a wrong password, an authenticator whose clock has drifted,
 * and recovery codes nobody wrote down. Collapsing them would make one error
 * message answer for all three.
 *
 * The QR code and the secret come from Fortify's own endpoints rather than
 * being rendered into the page, so the secret is fetched once, over the same
 * authenticated session, and is never in the document for a page cache or a
 * browser extension to pick up.
 */
export function TwoFactorEnrolment({ enabled, confirmed, required = false }: Props) {
    const [qr, setQr] = useState<string | null>(null);
    const [secret, setSecret] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [copied, setCopied] = useState(false);
    const [starting, setStarting] = useState(false);
    const [needsPassword, setNeedsPassword] = useState(false);
    const [passwordError, setPasswordError] = useState<string | null>(null);
    const [password, setPassword] = useState('');

    const form = useForm({ code: '' });

    const load = useCallback(async () => {
        const [qrResponse, secretResponse] = await Promise.all([
            fetch(route('two-factor.qr-code'), { headers: { Accept: 'application/json' } }),
            fetch(route('two-factor.secret-key'), { headers: { Accept: 'application/json' } }),
        ]);

        if (qrResponse.ok) {
            setQr((await qrResponse.json()).svg);
        }

        if (secretResponse.ok) {
            setSecret((await secretResponse.json()).secretKey);
        }
    }, []);

    const loadRecoveryCodes = useCallback(async () => {
        const response = await fetch(route('two-factor.recovery-codes'), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            setRecoveryCodes(await response.json());
        }
    }, []);

    useEffect(() => {
        if (enabled && !confirmed) {
            void load();
        }

        if (confirmed) {
            void loadRecoveryCodes();
        }
    }, [enabled, confirmed, load, loadRecoveryCodes]);

    const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const enable = () => {
        setStarting(true);
        router.post(
            route('two-factor.enable'),
            {},
            { preserveScroll: true, onFinish: () => setStarting(false) },
        );
    };

    /*
     * Enrolment sits behind password confirmation. Asked for here rather than by
     * following the redirect that middleware would otherwise issue: that
     * redirect remembers the address it interrupted, which for this action is a
     * POST endpoint, and sending the browser back to it as a GET lands on a
     * method-not-allowed page instead of on the setup screen.
     */
    const start = async () => {
        setStarting(true);

        const status = await fetch(route('password.confirmation'), {
            headers: { Accept: 'application/json' },
        }).then((response) => response.json());

        setStarting(false);

        if (status.confirmed) {
            enable();

            return;
        }

        setNeedsPassword(true);
    };

    const submitPassword = async (event: FormEvent) => {
        event.preventDefault();
        setPasswordError(null);
        setStarting(true);

        const response = await fetch(route('password.confirm.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ password }),
        });

        setStarting(false);

        if (!response.ok) {
            setPasswordError('That password was not correct.');

            return;
        }

        setPassword('');
        setNeedsPassword(false);
        enable();
    };

    const confirm = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('two-factor.confirm'), {
            preserveScroll: true,
            onSuccess: () => form.reset('code'),
        });
    };

    const copySecret = async () => {
        if (!secret) {
            return;
        }

        await navigator.clipboard.writeText(secret);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    if (confirmed) {
        return (
            <Card>
                <CardHeader
                    title="Two-factor authentication is on"
                    description="You will be asked for a code from your authenticator at every sign-in."
                />
                <CardBody className="space-y-4">
                    <Alert tone="success" title="Your account is protected">
                        <span className="inline-flex items-center gap-2">
                            <ShieldCheck aria-hidden="true" />
                            An authenticator is enrolled on this account.
                        </span>
                    </Alert>

                    {recoveryCodes ? (
                        <div className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                Recovery codes let you sign in if you lose your phone. Each one works once.
                                Store them somewhere other than the phone itself.
                            </p>
                            <ul className="grid grid-cols-2 gap-2 rounded-[var(--radius-control)] border border-border bg-surface-muted p-4 font-mono text-sm">
                                {recoveryCodes.map((code) => (
                                    <li key={code}>{code}</li>
                                ))}
                            </ul>
                        </div>
                    ) : null}

                    {required ? (
                        <p className="text-sm text-muted-foreground">
                            Administrator accounts must keep an authenticator enrolled, so this cannot be
                            turned off here.
                        </p>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete(route('two-factor.disable'), { preserveScroll: true })
                            }
                        >
                            Turn off two-factor authentication
                        </Button>
                    )}
                </CardBody>
            </Card>
        );
    }

    if (!enabled) {
        return (
            <Card>
                <CardHeader
                    title="Set up your authenticator"
                    description="You will need an authenticator app such as Google Authenticator, Authy or 1Password."
                />
                <CardBody className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Setting this up takes a minute. You will be asked to confirm your password, scan a
                        code, and then enter the six digits your app shows.
                    </p>

                    {needsPassword ? (
                        <form onSubmit={submitPassword} className="max-w-xs space-y-4">
                            <Field
                                label="Confirm your password"
                                error={passwordError ?? undefined}
                                hint="Enrolling an authenticator is a privileged action."
                                required
                            >
                                {(field) => (
                                    <Input
                                        {...field}
                                        type="password"
                                        name="password"
                                        value={password}
                                        onChange={(event) => setPassword(event.target.value)}
                                        autoComplete="current-password"
                                        autoFocus
                                    />
                                )}
                            </Field>
                            <Button type="submit" loading={starting}>
                                Continue
                            </Button>
                        </form>
                    ) : (
                        <Button onClick={start} loading={starting}>
                            Begin setup
                        </Button>
                    )}
                </CardBody>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader
                title="Scan this with your authenticator"
                description="Then enter the six-digit code it shows to finish."
            />
            <CardBody className="space-y-6">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
                    {qr ? (
                        // Fortify's own SVG. It carries no script, and the
                        // content security policy would refuse one if it did.
                        <div
                            className="shrink-0 rounded-[var(--radius-control)] bg-white p-3"
                            data-testid="two-factor-qr"
                            dangerouslySetInnerHTML={{ __html: qr }}
                        />
                    ) : (
                        <div className="h-44 w-44 shrink-0 animate-pulse rounded-[var(--radius-control)] bg-surface-muted" />
                    )}

                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            Cannot scan it? Enter this key into your app by hand instead.
                        </p>
                        {secret ? (
                            <div className="flex items-center gap-2">
                                <code
                                    className="rounded-[var(--radius-control)] border border-border bg-surface-muted px-3 py-2 font-mono text-sm"
                                    data-testid="two-factor-secret"
                                >
                                    {secret}
                                </code>
                                <Button variant="ghost" size="sm" onClick={copySecret}>
                                    {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                        ) : null}
                    </div>
                </div>

                <form onSubmit={confirm} className="max-w-xs space-y-4">
                    <Field
                        label="Six-digit code"
                        error={form.errors.code}
                        hint="From your authenticator app. It changes every thirty seconds."
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                name="code"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                placeholder="000000"
                            />
                        )}
                    </Field>

                    <Button type="submit" loading={form.processing}>
                        Confirm and turn on
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}
