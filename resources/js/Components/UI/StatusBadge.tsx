import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Circle,
    CircleDashed,
    Clock,
    CreditCard,
    HelpCircle,
    Link2Off,
    PauseCircle,
    ShieldOff,
    XCircle,
} from 'lucide-react';
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

    // Provider connections
    CONNECTED: { tone: 'success', Icon: CheckCircle2 },
    EXPIRING: { tone: 'warning', Icon: Clock },
    ERROR: { tone: 'danger', Icon: AlertTriangle },

    // Connected assets
    AVAILABLE: { tone: 'success', Icon: CheckCircle2 },
    UNAVAILABLE: { tone: 'neutral', Icon: CircleDashed },
    PERMISSION_LOST: { tone: 'warning', Icon: Link2Off },
    DISABLED: { tone: 'danger', Icon: Ban },

    // Managed ad accounts
    PENDING_SETUP: { tone: 'info', Icon: Clock },
    PAUSED: { tone: 'warning', Icon: PauseCircle },
    RETIRED: { tone: 'neutral', Icon: Circle },

    // Ad account health
    UNKNOWN: { tone: 'neutral', Icon: HelpCircle },
    HEALTHY: { tone: 'success', Icon: CheckCircle2 },
    DEGRADED: { tone: 'warning', Icon: AlertTriangle },
    AT_RISK: { tone: 'warning', Icon: AlertTriangle },
    CRITICAL: { tone: 'danger', Icon: XCircle },

    // Ad account billing
    CURRENT: { tone: 'success', Icon: CheckCircle2 },
    PAYMENT_METHOD_MISSING: { tone: 'warning', Icon: CreditCard },
    PAYMENT_FAILED: { tone: 'danger', Icon: CreditCard },
    LIMIT_REACHED: { tone: 'warning', Icon: AlertTriangle },

    // Pools
    DRAFT: { tone: 'neutral', Icon: CircleDashed },
    ARCHIVED: { tone: 'neutral', Icon: Circle },
};

export function StatusBadge({ status, label }: { status: string; label?: string }) {
    const { tone, Icon } = PRESENTATION[status] ?? { tone: 'neutral' as const, Icon: Circle };

    return (
        <Badge tone={tone} icon={<Icon className="size-3" aria-hidden="true" />}>
            {label ?? status}
        </Badge>
    );
}
