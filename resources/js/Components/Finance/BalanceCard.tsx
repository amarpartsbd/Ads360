import type { LucideIcon } from 'lucide-react';
import { Card, CardBody } from '@/Components/UI/Card';
import { MoneyValue, type SerialisedMoney } from '@/Components/Finance/MoneyValue';

export function BalanceCard({
    label,
    value,
    hint,
    icon: Icon,
}: {
    label: string;
    value: SerialisedMoney | string;
    hint?: string;
    icon?: LucideIcon;
}) {
    return (
        <Card>
            <CardBody className="space-y-1">
                <div className="flex items-center gap-2">
                    {Icon ? <Icon className="size-3.5 text-muted-foreground" aria-hidden="true" /> : null}
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {label}
                    </p>
                </div>
                <p className="text-2xl font-semibold tracking-tight">
                    <MoneyValue value={value} />
                </p>
                {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
            </CardBody>
        </Card>
    );
}
