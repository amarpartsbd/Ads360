import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useMemo, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import type { SerialisedMoney } from '@/Components/Finance/MoneyValue';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { PendingDeposit, WalletSummary } from './Overview';

interface MethodOption {
    value: string;
    label: string;
    requiresReference: boolean;
    requiresProof: boolean;
    manual: boolean;
}

export default function AddFunds({
    wallet,
    methods,
    minimumDeposit,
    upload,
    recent,
}: {
    wallet: WalletSummary;
    methods: MethodOption[];
    minimumDeposit: SerialisedMoney;
    upload: { maxBytes: number; acceptedExtensions: string[] };
    recent: PendingDeposit[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        amount: string;
        method: string;
        external_reference: string;
        paid_at: string;
        proof: File | null;
    }>({
        amount: '',
        method: methods[0]?.value ?? '',
        external_reference: '',
        paid_at: '',
        proof: null,
    });

    const selected = useMemo(
        () => methods.find((method) => method.value === data.method),
        [methods, data.method],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('client.wallet.deposits.store'), {
            forceFormData: true,
            onSuccess: () => reset(),
        });
    };

    const maxMegabytes = Math.round(upload.maxBytes / 1_048_576);

    return (
        <ClientLayout
            title="Add funds"
            description={`Top up your ${wallet.currency} wallet. Deposits are credited once our finance team confirms them.`}
        >
            <Alert tone="info" title="How this works">
                Send the money using one of the methods below, then record the transfer here with its
                reference. We confirm it against our own records before the balance appears — usually within
                one business day.
            </Alert>

            <form onSubmit={submit}>
                <Card>
                    <CardHeader
                        title="Record a transfer"
                        description={`Minimum deposit ${minimumDeposit.formatted}.`}
                    />
                    <CardBody className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={`Amount (${wallet.currency})`}
                            error={errors.amount}
                            required
                            hint="Exactly what you transferred."
                        >
                            {(props) => (
                                <Input
                                    {...props}
                                    type="text"
                                    inputMode="decimal"
                                    placeholder="25000.00"
                                    value={data.amount}
                                    onChange={(event) => setData('amount', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label="Method" error={errors.method} required>
                            {(props) => (
                                <Select
                                    {...props}
                                    value={data.method}
                                    onChange={(event) => setData('method', event.target.value)}
                                >
                                    {methods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>

                        <Field
                            label="Transaction reference"
                            error={errors.external_reference}
                            required={selected?.requiresReference}
                            hint="The reference from your bank or wallet app."
                        >
                            {(props) => (
                                <Input
                                    {...props}
                                    value={data.external_reference}
                                    onChange={(event) => setData('external_reference', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label="Date sent" error={errors.paid_at}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="date"
                                    value={data.paid_at}
                                    onChange={(event) => setData('paid_at', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label="Proof of payment"
                            error={errors.proof}
                            required={selected?.requiresProof}
                            hint={`Receipt or screenshot. ${upload.acceptedExtensions.join(', ')}, up to ${maxMegabytes} MB.`}
                            className="sm:col-span-2"
                        >
                            {(props) => (
                                <Input
                                    {...props}
                                    type="file"
                                    accept={upload.acceptedExtensions.map((ext) => `.${ext}`).join(',')}
                                    onChange={(event) => setData('proof', event.target.files?.[0] ?? null)}
                                    className="file:mr-3 file:rounded file:border-0 file:bg-secondary file:px-3 file:py-1 file:text-sm"
                                />
                            )}
                        </Field>
                    </CardBody>

                    <CardBody className="flex justify-end border-t border-border">
                        <Button type="submit" loading={processing}>
                            <Upload aria-hidden="true" />
                            Submit deposit
                        </Button>
                    </CardBody>
                </Card>
            </form>

            {recent.length > 0 ? (
                <Card>
                    <CardHeader title="Your recent deposits" />
                    <Table caption="Recent deposits">
                        <thead>
                            <tr>
                                <Th>Reference</Th>
                                <Th>Method</Th>
                                <Th className="text-right">Amount</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent.map((deposit) => (
                                <tr key={deposit.id}>
                                    <Td className="font-mono text-xs">{deposit.reference}</Td>
                                    <Td>{deposit.methodLabel}</Td>
                                    <Td className="text-right tabular-nums">{deposit.amount}</Td>
                                    <Td>
                                        <StatusBadge
                                            status={
                                                deposit.status === 'AWAITING_VERIFICATION'
                                                    ? 'PENDING'
                                                    : deposit.status
                                            }
                                            label={deposit.statusLabel}
                                        />
                                        {deposit.rejectionReason ? (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {deposit.rejectionReason}
                                            </p>
                                        ) : null}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            ) : null}
        </ClientLayout>
    );
}
