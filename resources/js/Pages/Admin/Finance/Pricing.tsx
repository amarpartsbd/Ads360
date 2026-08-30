import { Badge } from '@/Components/UI/Badge';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Tag } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';

interface Rule {
    id: string;
    feeType: string;
    feeLabel: string;
    calculation: string;
    value: string;
    appliesFrom: string | null;
}

interface Plan {
    id: string;
    name: string;
    scope: string;
    scopeLabel: string;
    appliesTo: string;
    currency: string;
    isDefault: boolean;
    isActive: boolean;
    rules: Rule[];
}

export default function Pricing({ plans }: { plans: Plan[]; can: { manage: boolean } }) {
    return (
        <AdminLayout
            title="Pricing"
            description="A client override beats a tenant plan, which beats the platform default. The most specific active plan wins outright — plans are not merged."
        >
            {plans.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Tag}
                        title="No pricing plans configured"
                        description="A platform default is required before any client can be charged."
                    />
                </Card>
            ) : (
                plans.map((plan) => (
                    <Card key={plan.id}>
                        <CardHeader
                            title={plan.name}
                            description={`${plan.scopeLabel} · applies to ${plan.appliesTo}`}
                            action={
                                <div className="flex items-center gap-2">
                                    <Badge>{plan.currency}</Badge>
                                    {plan.isDefault ? <Badge tone="info">Default</Badge> : null}
                                    {plan.isActive ? (
                                        <Badge tone="success">Active</Badge>
                                    ) : (
                                        <Badge>Inactive</Badge>
                                    )}
                                </div>
                            }
                        />

                        {plan.rules.length === 0 ? (
                            <CardBody>
                                <p className="text-sm text-muted-foreground">
                                    This plan has no fee rules, so it charges nothing.
                                </p>
                            </CardBody>
                        ) : (
                            <Table caption={`Fee rules for ${plan.name}`}>
                                <thead>
                                    <tr>
                                        <Th>Fee</Th>
                                        <Th>Basis</Th>
                                        <Th className="text-right">Value</Th>
                                        <Th>Applies from</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {plan.rules.map((rule) => (
                                        <tr key={rule.id}>
                                            <Td className="font-medium">{rule.feeLabel}</Td>
                                            <Td className="text-muted-foreground">
                                                {rule.calculation === 'PERCENTAGE' ? 'Percentage' : 'Fixed'}
                                            </Td>
                                            <Td className="text-right font-medium tabular-nums">
                                                {rule.value}
                                            </Td>
                                            <Td className="text-muted-foreground">
                                                {rule.appliesFrom ?? 'Any amount'}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </Card>
                ))
            )}
        </AdminLayout>
    );
}
