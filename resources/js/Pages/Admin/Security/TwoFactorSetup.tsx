import { Alert } from '@/Components/UI/Alert';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import AdminLayout from '@/Layouts/AdminLayout';

/**
 * Administrators are held here until they enrol an authenticator (spec §9).
 */
export default function TwoFactorSetup({ required }: { required: boolean }) {
    return (
        <AdminLayout title="Two-factor authentication">
            {required ? (
                <Alert tone="warning" title="Two-factor authentication is required">
                    Administrator accounts must have an authenticator app enrolled before they can use the
                    administration area.
                </Alert>
            ) : null}

            <Card>
                <CardHeader
                    title="Set up your authenticator"
                    description="Scan the code with an authenticator app, then enter the six-digit code it shows."
                />
                <CardBody className="space-y-4 text-sm text-muted-foreground">
                    <p>
                        Enrolment is handled by the account security endpoints. Once confirmed, you will be
                        asked for a code at every sign-in, and you will receive recovery codes to store
                        somewhere safe.
                    </p>
                </CardBody>
            </Card>
        </AdminLayout>
    );
}
