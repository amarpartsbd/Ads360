import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import ClientLayout from '@/Layouts/ClientLayout';

/**
 * A stand-in for a provider's consent screen, used in development only
 * (spec §95).
 *
 * It is labelled as a simulation on the page itself, so nobody mistakes it for
 * the real thing while walking through the flow.
 */
export default function SimulatedConsent({
    provider,
    state,
}: {
    provider: { value: string; label: string };
    state: string;
}) {
    const [processing, setProcessing] = useState(false);

    const decide = (decision: 'grant' | 'refuse') => {
        setProcessing(true);

        router.post(
            route('client.assets.oauth.simulate.submit', { provider: provider.value.toLowerCase() }),
            { state, decision },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <ClientLayout
            title="Simulated consent"
            description="A local stand-in for the provider's authorisation screen."
        >
            <Alert tone="info" title="This is not a real provider screen">
                Development environments have no live provider credentials, so this page stands in for the
                consent step. Nothing here reaches {provider.label}.
            </Alert>

            <Card>
                <CardHeader
                    title={`Authorise Ads360 for ${provider.label}?`}
                    description="Granting returns an authorisation code to the callback, exactly as the provider would."
                />
                <CardBody className="flex flex-wrap gap-2">
                    <Button disabled={processing} onClick={() => decide('grant')}>
                        Grant access
                    </Button>
                    <Button variant="ghost" disabled={processing} onClick={() => decide('refuse')}>
                        Refuse
                    </Button>
                </CardBody>
            </Card>
        </ClientLayout>
    );
}
