import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

const BUSINESS_TYPES = [
    'Retail',
    'E-commerce',
    'Services',
    'Manufacturing',
    'Education',
    'Healthcare',
    'Real estate',
    'Technology',
    'Other',
];

/**
 * Client self-registration (spec §10).
 *
 * Everything here is re-validated on the server; the client-side form only
 * shapes the request and reports what came back.
 */
export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        mobile_number: '',
        company_name: '',
        business_type: '',
        country: 'BD',
        password: '',
        password_confirmation: '',
        terms: false as boolean,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Create your account" description="Set up your business workspace.">
            <Head title="Create your account" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Full name" error={errors.name} required>
                    {(props) => (
                        <Input
                            {...props}
                            name="name"
                            autoComplete="name"
                            autoFocus
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Business email" error={errors.email} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="email"
                            name="email"
                            autoComplete="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Mobile number" error={errors.mobile_number} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="tel"
                            name="mobile_number"
                            autoComplete="tel"
                            placeholder="+8801XXXXXXXXX"
                            value={data.mobile_number}
                            onChange={(event) => setData('mobile_number', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Company name" error={errors.company_name} required>
                    {(props) => (
                        <Input
                            {...props}
                            name="company_name"
                            autoComplete="organization"
                            value={data.company_name}
                            onChange={(event) => setData('company_name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Business type" error={errors.business_type} required>
                    {(props) => (
                        <select
                            {...props}
                            name="business_type"
                            className="h-9 w-full rounded-[var(--radius-control)] border border-input bg-surface px-3 text-sm"
                            value={data.business_type}
                            onChange={(event) => setData('business_type', event.target.value)}
                        >
                            <option value="">Select a business type</option>
                            {BUSINESS_TYPES.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                    )}
                </Field>

                <Field label="Country" error={errors.country} required>
                    {(props) => (
                        <select
                            {...props}
                            name="country"
                            className="h-9 w-full rounded-[var(--radius-control)] border border-input bg-surface px-3 text-sm"
                            value={data.country}
                            onChange={(event) => setData('country', event.target.value)}
                        >
                            <option value="BD">Bangladesh</option>
                            <option value="IN">India</option>
                            <option value="MY">Malaysia</option>
                            <option value="SG">Singapore</option>
                            <option value="AE">United Arab Emirates</option>
                            <option value="GB">United Kingdom</option>
                            <option value="US">United States</option>
                        </select>
                    )}
                </Field>

                <Field
                    label="Password"
                    error={errors.password}
                    required
                    hint="At least 12 characters, with upper and lower case, a number and a symbol."
                >
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Confirm password" error={errors.password_confirmation} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                        />
                    )}
                </Field>

                <div className="space-y-1.5">
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 rounded border-input"
                            checked={data.terms}
                            onChange={(event) => setData('terms', event.target.checked)}
                            aria-invalid={Boolean(errors.terms)}
                        />
                        <span>I accept the terms of service and privacy policy.</span>
                    </label>
                    {errors.terms ? (
                        <p role="alert" className="text-xs text-danger">
                            {errors.terms}
                        </p>
                    ) : null}
                </div>

                <Button type="submit" loading={processing} className="w-full">
                    Create account
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                Already registered?{' '}
                <Link href={route('login')} className="text-primary underline-offset-4 hover:underline">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
