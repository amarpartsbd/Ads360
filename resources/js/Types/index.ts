/**
 * Shared prop contracts between the Laravel back end and the React front end.
 *
 * These mirror what HandleInertiaRequests actually shares. Nothing sensitive is
 * represented here because nothing sensitive is sent.
 */

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    is_platform_user: boolean;
    two_factor_enabled: boolean;
    email_verified: boolean;
    timezone: string;
    /**
     * Used only to hide controls the user cannot use. Authorization is decided
     * on the server for every request; this list is a convenience, never a gate.
     */
    permissions: string[];
}

export interface TenantBranding {
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    support_email: string;
}

export interface Tenant {
    id: string;
    name: string;
    type: string;
    branding: TenantBranding;
}

export interface Organization {
    id: string;
    name: string;
    status: string;
    currency: string;
    timezone: string;
}

export interface FeatureFlags {
    google_ads: boolean;
    agency_module: boolean;
    ai_assistant: boolean;
    white_label: boolean;
    automated_rules: boolean;
    advanced_reporting: boolean;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    tenant: Tenant | null;
    /** The organization the request is bound to. Named to avoid colliding with page props. */
    currentOrganization: Organization | null;
    organizations: Organization[];
    platform: { name: string; support_email: string };
    features: FeatureFlags;
    flash: FlashMessages;
    [key: string]: unknown;
}

/** A Laravel length-aware paginator, as serialised by Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}
