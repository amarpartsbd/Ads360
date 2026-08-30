import { usePage } from '@inertiajs/react';
import { Alert } from '@/Components/UI/Alert';
import type { SharedProps } from '@/Types';

export function FlashMessages() {
    const { flash } = usePage<SharedProps>().props;

    if (!flash.success && !flash.error && !flash.warning) {
        return null;
    }

    return (
        <div className="space-y-3">
            {flash.success ? <Alert tone="success">{flash.success}</Alert> : null}
            {flash.warning ? <Alert tone="warning">{flash.warning}</Alert> : null}
            {flash.error ? <Alert tone="danger">{flash.error}</Alert> : null}
        </div>
    );
}
