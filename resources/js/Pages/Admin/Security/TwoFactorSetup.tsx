import { TwoFactorEnrolment } from '@/Components/Security/TwoFactorEnrolment';
import { Alert } from '@/Components/UI/Alert';
import AdminLayout from '@/Layouts/AdminLayout';

/**
 * Administrators are held here until they enrol an authenticator (spec §9).
 */
export default function TwoFactorSetup({
    required,
    enabled,
    confirmed,
}: {
    required: boolean;
    enabled: boolean;
    confirmed: boolean;
}) {
    return (
        <AdminLayout title="Two-factor authentication">
            {required ? (
                <Alert tone="warning" title="Two-factor authentication is required">
                    Administrator accounts must have an authenticator app enrolled before they can use the
                    administration area.
                </Alert>
            ) : null}

            <TwoFactorEnrolment enabled={enabled} confirmed={confirmed} required={required} />
        </AdminLayout>
    );
}
