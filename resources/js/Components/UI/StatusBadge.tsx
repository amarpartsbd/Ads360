import { AlertTriangle, CheckCircle2, Circle, Clock, ShieldOff, XCircle } from 'lucide-react';
import { Badge } from '@/Components/UI/Badge';

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

/**
 * Maps a domain status to a tone and a glyph.
 *
 * Every state carries an icon as well as a colour, so status never depends on
 * colour alone (spec §74).
 */
const PRESENTATION: Record<string, { tone: Tone; Icon: typeof Circle }> = {
    // Verification
    NOT_SUBMITTED: { tone: 'neutral', Icon: Circle },
    PENDING: { tone: 'warning', Icon: Clock },
    UNDER_REVIEW: { tone: 'info', Icon: Clock },
    VERIFIED: { tone: 'success', Icon: CheckCircle2 },
    REJECTED: { tone: 'danger', Icon: XCircle },
    REQUIRES_INFORMATION: { tone: 'warning', Icon: AlertTriangle },
    SUSPENDED: { tone: 'danger', Icon: ShieldOff },

    // Organizations and memberships
    ACTIVE: { tone: 'success', Icon: CheckCircle2 },
    INVITED: { tone: 'info', Icon: Clock },
    CLOSED: { tone: 'neutral', Icon: Circle },
    REVOKED: { tone: 'neutral', Icon: XCircle },
    EXPIRED: { tone: 'neutral', Icon: Clock },
    ACCEPTED: { tone: 'success', Icon: CheckCircle2 },

    // Documents
    PENDING_REVIEW: { tone: 'warning', Icon: Clock },
};

export function StatusBadge({ status, label }: { status: string; label?: string }) {
    const { tone, Icon } = PRESENTATION[status] ?? { tone: 'neutral' as const, Icon: Circle };

    return (
        <Badge tone={tone} icon={<Icon className="size-3" aria-hidden="true" />}>
            {label ?? status}
        </Badge>
    );
}
