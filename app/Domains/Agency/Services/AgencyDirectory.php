<?php

declare(strict_types=1);

namespace App\Domains\Agency\Services;

use App\Domains\Agency\DTOs\AgencyClientSummary;
use App\Domains\Agency\DTOs\AgencyReport;
use App\Domains\Analytics\Models\CampaignDailyMetric;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Enums\MembershipStatus;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Support\Values\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clients an agency manages, and how each is doing (spec §42).
 *
 * Two things this service is careful about.
 *
 * **It never widens reach.** The roster is built from the organizations the
 * *asking user* can reach, not from the agency's tenant. An agency owner sees
 * every client; an agency manager assigned to two clients sees two. Passing the
 * tenant alone would have been simpler and would have shown a media buyer every
 * client the agency has.
 *
 * **It aggregates in the database.** A roster of two hundred clients that
 * loaded each one's campaigns and ledger to count them would be two hundred
 * queries and a page that times out. Every figure here comes from one grouped
 * query per dimension, keyed by organization.
 */
final class AgencyDirectory
{
    /**
     * The agency's roster over a reporting window.
     *
     * @return Collection<int, AgencyClientSummary>
     */
    public function roster(
        Tenant $agency,
        User $viewer,
        ?DateTimeInterface $since = null,
        ?DateTimeInterface $until = null,
    ): Collection {
        $clients = $this->clientsVisibleTo($agency, $viewer)->get();

        if ($clients->isEmpty()) {
            return collect();
        }

        $ids = $clients->pluck('id')->all();

        $balances = $this->balances($ids);
        $campaigns = $this->campaignCounts($ids);
        $performance = $this->performance($ids, $since, $until);
        $staff = $this->staffCounts($ids);
        $verified = $this->verifiedOrganizationIds($ids);

        return $clients->map(function (Organization $client) use (
            $balances, $campaigns, $performance, $staff, $verified
        ): AgencyClientSummary {
            $id = $client->getKey();
            $currency = (string) $client->default_currency;
            $row = $performance[$id] ?? null;

            return new AgencyClientSummary(
                publicId: $client->public_id,
                name: $client->name,
                status: $client->status,
                isVerified: in_array($id, $verified, true),
                availableBalance: Money::ofMinor($balances[$id] ?? 0, $currency),
                // Null, not zero: nothing reported is not the same as nothing
                // spent, and an agency reads those two very differently.
                spend: $row === null ? null : Money::ofMinor((int) $row->spend, $currency),
                activeCampaigns: (int) ($campaigns[$id]->active ?? 0),
                totalCampaigns: (int) ($campaigns[$id]->total ?? 0),
                assignedStaff: (int) ($staff[$id] ?? 0),
                impressions: $row === null ? null : (int) $row->impressions,
                clicks: $row === null ? null : (int) $row->clicks,
                conversions: $row === null ? null : (int) $row->conversions,
            );
        })->values();
    }

    /**
     * The roster plus its totals over a window (spec §42).
     *
     * The window is required here, unlike on `roster()`, because a report
     * without one is a figure nobody can check: "spend" with no dates cannot be
     * reconciled against anything the client was shown or invoiced (§78).
     */
    public function report(
        Tenant $agency,
        User $viewer,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): AgencyReport {
        $clients = $this->roster($agency, $viewer, $since, $until);

        $currencies = $clients
            ->map(static fn (AgencyClientSummary $client): string => $client->availableBalance->currency->code)
            ->unique()
            ->values()
            ->all();

        /*
         * One currency, one total. More than one and the total is withheld
         * rather than converted at a rate this report invented — see
         * AgencyReport.
         */
        $single = count($currencies) === 1 ? $currencies[0] : null;

        return new AgencyReport(
            clients: $clients,
            since: $since,
            until: $until,
            totalSpend: $single === null ? null : $this->sum(
                $clients->map(static fn (AgencyClientSummary $c): ?Money => $c->spend)->all(),
                $single,
            ),
            totalBalance: $single === null ? null : $this->sum(
                $clients->map(static fn (AgencyClientSummary $c): Money => $c->availableBalance)->all(),
                $single,
            ),
            totalImpressions: (int) $clients->sum(static fn (AgencyClientSummary $c): int => $c->impressions ?? 0),
            totalClicks: (int) $clients->sum(static fn (AgencyClientSummary $c): int => $c->clicks ?? 0),
            totalConversions: (int) $clients->sum(static fn (AgencyClientSummary $c): int => $c->conversions ?? 0),
            activeCampaigns: (int) $clients->sum(static fn (AgencyClientSummary $c): int => $c->activeCampaigns),
            clientCount: $clients->count(),
            currencies: $currencies,
        );
    }

    /**
     * Adds money that is known to share a currency.
     *
     * Nulls are skipped rather than treated as zero, and a roster where
     * nothing was reported at all sums to zero in that currency rather than to
     * null — the total of no spend is no spend, which is a figure an agency
     * can act on.
     *
     * @param  array<int, Money|null>  $amounts
     */
    private function sum(array $amounts, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($amounts as $amount) {
            if ($amount !== null) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    /**
     * The organizations this user may see on the agency's roster.
     *
     * The house account is excluded: it is the agency itself, and listing it
     * among the clients would have an agency reporting on its own workspace as
     * though it were a customer.
     *
     * @return Builder<Organization>
     */
    public function clientsVisibleTo(Tenant $agency, User $viewer): Builder
    {
        return $viewer->reachableOrganizations()
            ->where('organizations.tenant_id', $agency->getKey())
            ->where('organizations.is_house_account', false)
            ->orderBy('organizations.name');
    }

    /**
     * Whether an organization is one of this agency's clients *and* one this
     * user may act on. Used before every write, so a valid identifier from
     * another agency finds nothing.
     */
    public function clientFor(Tenant $agency, User $viewer, string $publicId): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = $this->clientsVisibleTo($agency, $viewer)
            ->where('organizations.public_id', $publicId)
            ->first();

        return $organization;
    }

    /**
     * Available balance per organization, in minor units.
     *
     * The cached balance, which is what every other screen shows. It is
     * reconciled against the ledger by its own job; recomputing here would
     * make a roster of two hundred clients replay two hundred ledgers.
     *
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function balances(array $organizationIds): array
    {
        return Wallet::query()
            ->withoutGlobalScopes()
            ->whereIn('organization_id', $organizationIds)
            ->pluck('available_balance_cached', 'organization_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, object{active: int, total: int}>
     */
    private function campaignCounts(array $organizationIds): array
    {
        return Campaign::query()
            ->withoutGlobalScopes()
            ->whereIn('organization_id', $organizationIds)
            ->selectRaw('organization_id')
            ->selectRaw('count(*) as total')
            ->selectRaw(
                'count(*) filter (where status = ?) as active',
                [CampaignStatus::Active->value],
            )
            ->groupBy('organization_id')
            ->get()
            ->keyBy('organization_id')
            ->all();
    }

    /**
     * Performance per organization over the window.
     *
     * @param  list<int>  $organizationIds
     * @return array<int, object>
     */
    private function performance(
        array $organizationIds,
        ?DateTimeInterface $since,
        ?DateTimeInterface $until,
    ): array {
        $query = CampaignDailyMetric::query()
            ->withoutGlobalScopes()
            ->whereIn('organization_id', $organizationIds)
            ->selectRaw('organization_id')
            ->selectRaw('sum(spend) as spend')
            ->selectRaw('sum(impressions) as impressions')
            ->selectRaw('sum(clicks) as clicks')
            ->selectRaw('sum(conversions) as conversions')
            ->groupBy('organization_id');

        if ($since !== null) {
            $query->where('metric_date', '>=', $since->format('Y-m-d'));
        }

        if ($until !== null) {
            $query->where('metric_date', '<=', $until->format('Y-m-d'));
        }

        return $query->get()->keyBy('organization_id')->all();
    }

    /**
     * How many people are assigned to each client.
     *
     * Only active memberships, and only staff assigned to that specific
     * client: the agency's owners reach every client without a membership row,
     * and counting them here would report the same two people on every line.
     *
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function staffCounts(array $organizationIds): array
    {
        return DB::table('organization_user')
            ->whereIn('organization_id', $organizationIds)
            ->where('status', MembershipStatus::Active->value)
            ->selectRaw('organization_id, count(*) as members')
            ->groupBy('organization_id')
            ->pluck('members', 'organization_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @param  list<int>  $organizationIds
     * @return list<int>
     */
    private function verifiedOrganizationIds(array $organizationIds): array
    {
        return VerificationProfile::query()
            ->withoutGlobalScopes()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', VerificationStatus::Verified->value)
            ->pluck('organization_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }
}
