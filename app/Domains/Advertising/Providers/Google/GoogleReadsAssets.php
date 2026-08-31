<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use App\Support\Values\Currency;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Reading what a Google grant covers, and what Google says about a customer
 * account (spec §15, §20).
 *
 * Split from the adapter for the same reason the Meta reader is: discovery and
 * health are read-only and share a set of problems publishing does not have.
 * Here those problems are Google's own — a manager hierarchy to walk, a
 * currency-dependent micros conversion on every figure, and an account
 * timezone that decides what "today" means.
 */
trait GoogleReadsAssets
{
    /**
     * Everything the grant authorises.
     *
     * Google answers this in two steps, and the second is the interesting one.
     * `listAccessibleCustomers` returns only the accounts the authorising user
     * is directly attached to — which, for anyone managing advertising
     * seriously, is a *manager* account rather than the accounts that run ads.
     * Expanding each one through `customer_client` is what turns "one manager
     * account" into "the fourteen accounts underneath it".
     *
     * A failure on one customer does not sink the rest: a client whose manager
     * account we can read but whose one restricted sub-account we cannot should
     * still get the other thirteen. Only a failure that means the grant itself
     * is gone stops the whole run.
     *
     * Deliberately not discovered here: Analytics properties and Merchant
     * Center accounts. They are declared in AssetType because the vocabulary
     * is settled, but reading them means their own APIs and their own scopes,
     * and returning an empty list is honest where inventing one would not be
     * (§87).
     *
     * @return list<DiscoveredAsset>
     */
    public function discoverAssets(ProviderConnection $connection): array
    {
        $client = $this->client->withToken($connection->accessToken());

        $assets = [];
        $seen = [];

        foreach ($client->listAccessibleCustomers() as $customerId) {
            try {
                $discovered = $this->readCustomerTree($client, $customerId);
            } catch (ProviderUnavailable $exception) {
                // A revoked grant affects every customer, so there is nothing
                // to be gained by trying the rest.
                if (! $exception->retryable && str_contains($exception->getMessage(), 'authentication')) {
                    throw $exception;
                }

                // One inaccessible account among many is normal in a manager
                // hierarchy: access is granted per account, not per tree.
                continue;
            }

            foreach ($discovered as $asset) {
                // A sub-account reachable through two managers the client is
                // attached to would otherwise be listed twice.
                if (isset($seen[$asset->externalId])) {
                    continue;
                }

                $seen[$asset->externalId] = true;
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * What Google currently says about one of the platform's managed accounts.
     *
     * A null in the result means Google did not report the figure, and callers
     * treat that as unknown rather than zero (§87).
     */
    public function accountState(string $externalAccountId, ?ProviderConnection $connection = null): ProviderAccountState
    {
        $customerId = GoogleAdsConfig::digits($externalAccountId) ?? $externalAccountId;

        $client = $connection === null
            // One of the platform's own accounts, reached with the platform's
            // own grant through the platform's manager (spec §17).
            ? $this->platformClient()
            // A client's own account is not under the platform's manager, so
            // the request is made through the account itself.
            : $this->client->withToken($connection->accessToken())->withManagerAccount($customerId);

        $customer = $client->searchOne($customerId, $this->customerQuery());

        if ($customer === null) {
            throw ProviderUnavailable::refused(
                Provider::Google,
                "Google returned no customer record for {$customerId}",
                'We could not read this Google Ads account. It may no longer be shared with us.',
            );
        }

        $fields = is_array($customer['customer'] ?? null) ? $customer['customer'] : [];
        $currency = isset($fields['currencyCode']) ? (string) $fields['currencyCode'] : null;
        $timezone = isset($fields['timeZone']) ? (string) $fields['timeZone'] : null;

        $spend = $this->monthToDateSpend($client, $customerId, $currency, $timezone);

        return new ProviderAccountState(
            externalAccountId: $customerId,
            status: $this->customerStatus($fields),
            billingStatus: $this->billingStatus($client, $customerId, $fields),
            spentTodayMinor: $spend['today'],
            spentThisMonthMinor: $spend['month'],
            /*
             * Google exposes an account-level budget cap only for accounts on
             * monthly invoicing, which most are not, and this adapter does not
             * read the ones that do. Reporting a limit it never saw would have
             * the allocation engine make decisions from a figure that was
             * never true — which is why `supports(SpendLimits)` says no.
             */
            dailySpendLimitMinor: null,
            monthlySpendLimitMinor: null,
            currency: $currency,
            disapprovalReason: $this->statusReason($fields),
            raw: [
                'status' => $fields['status'] ?? null,
                'test_account' => $fields['testAccount'] ?? null,
                'manager' => $fields['manager'] ?? null,
            ],
        );
    }

    /**
     * One accessible customer, expanded into itself and everything beneath it.
     *
     * @return list<DiscoveredAsset>
     *
     * @throws ProviderUnavailable
     */
    private function readCustomerTree(GoogleAdsClient $client, string $customerId): array
    {
        /*
         * Queried through the customer itself rather than through the
         * platform's manager: this is the client's grant on the client's own
         * hierarchy, and naming our manager account would have Google refuse
         * a request they are entitled to make.
         */
        $rows = $client->withManagerAccount($customerId)->search(
            $customerId,
            'SELECT customer_client.id, customer_client.descriptive_name, '
            .'customer_client.currency_code, customer_client.time_zone, '
            .'customer_client.manager, customer_client.status, customer_client.level, '
            .'customer_client.test_account '
            .'FROM customer_client '
            // CANCELLED and CLOSED accounts cannot run ads and cannot be
            // reopened; listing them would offer a client something dead.
            ."WHERE customer_client.status IN ('ENABLED', 'SUSPENDED')",
            pageSize: 500,
        );

        $assets = [];

        foreach ($rows as $row) {
            $child = is_array($row['customerClient'] ?? null) ? $row['customerClient'] : [];
            $id = GoogleAdsConfig::digits($child['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $isManager = ($child['manager'] ?? false) === true;

            $assets[] = new DiscoveredAsset(
                type: AssetType::GoogleAdsAccount,
                // Digits, not the dashed form a person reads. Every Google
                // endpoint wants it this way, and storing the display form
                // would mean stripping dashes at each of them.
                externalId: $id,
                name: (string) ($child['descriptiveName'] ?? 'Google Ads account'),
                currency: isset($child['currencyCode']) ? (string) $child['currencyCode'] : null,
                timezone: isset($child['timeZone']) ? (string) $child['timeZone'] : null,
                status: isset($child['status']) ? (string) $child['status'] : null,
                metadata: [
                    /*
                     * A manager account cannot run ads. It is listed anyway,
                     * because it is genuinely part of what the grant covers
                     * and a client seeing their hierarchy is the point — but
                     * flagged, so nothing tries to publish into one.
                     */
                    'manager_account' => $isManager,
                    'level' => isset($child['level']) ? (int) $child['level'] : null,
                    // Google's sandbox accounts accept campaigns and never
                    // serve them. Publishing into one would look like success.
                    'test_account' => ($child['testAccount'] ?? false) === true,
                ],
            );
        }

        return $assets;
    }

    /** The fields the account-state read needs, as GAQL. */
    private function customerQuery(): string
    {
        return 'SELECT customer.id, customer.descriptive_name, customer.currency_code, '
            .'customer.time_zone, customer.status, customer.manager, customer.test_account '
            .'FROM customer LIMIT 1';
    }

    /**
     * Google's customer status in the platform's vocabulary.
     *
     * Passed through as the reason it cannot run rather than smoothed over
     * (§27).
     *
     * @param  array<string, mixed>  $fields
     */
    private function customerStatus(array $fields): ?string
    {
        $status = $fields['status'] ?? null;

        if (! is_string($status)) {
            return null;
        }

        return match ($status) {
            'ENABLED' => 'ACTIVE',
            'SUSPENDED' => 'SUSPENDED',
            'CANCELED', 'CANCELLED' => 'CLOSED',
            'CLOSED' => 'CLOSED',
            default => 'UNKNOWN',
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function statusReason(array $fields): ?string
    {
        return match ($fields['status'] ?? null) {
            'SUSPENDED' => 'Google has suspended this advertising account.',
            'CANCELED', 'CANCELLED' => 'This Google Ads account has been cancelled.',
            'CLOSED' => 'This Google Ads account has been closed.',
            default => null,
        };
    }

    /**
     * Whether Google will accept spend on this account.
     *
     * Read from the account's billing setup rather than guessed from its
     * status: an account can be perfectly enabled and have no payment method,
     * which Google only reveals when the first ad fails to serve.
     *
     * @param  array<string, mixed>  $fields
     */
    private function billingStatus(GoogleAdsClient $client, string $customerId, array $fields): ?string
    {
        $status = $this->customerStatus($fields);

        if ($status !== null && $status !== 'ACTIVE') {
            return match ($status) {
                'SUSPENDED', 'CLOSED' => 'SUSPENDED',
                default => null,
            };
        }

        try {
            $row = $client->searchOne(
                $customerId,
                'SELECT billing_setup.id, billing_setup.status FROM billing_setup '
                ."WHERE billing_setup.status IN ('APPROVED', 'APPROVED_HELD', 'PENDING') LIMIT 1",
            );
        } catch (ProviderUnavailable) {
            // Billing is readable only with the right permission on the
            // account. Not knowing is reported as not knowing (§87).
            return null;
        }

        $setup = is_array($row['billingSetup'] ?? null) ? $row['billingSetup'] : [];

        return match ($setup['status'] ?? null) {
            'APPROVED' => 'CURRENT',
            // Approved but held: Google has the details and is not charging
            // against them yet, which stops spend just as surely.
            'APPROVED_HELD' => 'PAYMENT_FAILED',
            'PENDING' => 'PAYMENT_METHOD_MISSING',
            // No billing setup at all. The account will refuse the first ad.
            default => 'PAYMENT_METHOD_MISSING',
        };
    }

    /**
     * Spend so far today and so far this month, in the account's own currency.
     *
     * One query, not two: `segments.date DURING THIS_MONTH` returns a row per
     * day, so today's figure is one of the rows already fetched. The dates are
     * Google's own, in the *account's* timezone — which is why today's date is
     * computed in that timezone rather than the platform's. An account in
     * Asia/Dhaka is a day ahead of one in America/Los_Angeles, and using the
     * server's idea of today would report the wrong day's spend for one of
     * them.
     *
     * @return array{today: int|null, month: int|null}
     */
    private function monthToDateSpend(
        GoogleAdsClient $client,
        string $customerId,
        ?string $currency,
        ?string $timezone,
    ): array {
        if ($currency === null || ! Currency::supported($currency)) {
            // Without a scale, a micros figure cannot be converted to minor
            // units at all — and a guess would be wrong by a factor of a
            // hundred in half the currencies the platform supports.
            return ['today' => null, 'month' => null];
        }

        try {
            $rows = $client->search(
                $customerId,
                'SELECT metrics.cost_micros, segments.date FROM customer '
                .'WHERE segments.date DURING THIS_MONTH',
                pageSize: 100,
                maxPages: 2,
            );
        } catch (ProviderUnavailable) {
            return ['today' => null, 'month' => null];
        }

        if ($rows === []) {
            // No rows is "nothing reported", not zero: a sync minutes after
            // midnight in the account's timezone legitimately has none.
            return ['today' => null, 'month' => null];
        }

        $today = $this->todayIn($timezone);
        $monthMinor = 0;

        /*
         * Zero rather than null, and only because the query came back with
         * rows: Google omits a day entirely when it has no statistics for it,
         * so "this month reported, today absent" means today has genuinely
         * spent nothing. Leaving it null would keep yesterday's figure on the
         * account and over-report its utilisation all day.
         */
        $todayMinor = 0;

        foreach ($rows as $row) {
            $micros = $row['metrics']['costMicros'] ?? null;
            $date = $row['segments']['date'] ?? null;

            if ($micros === null) {
                continue;
            }

            $value = Micros::toMinor($micros, $currency);

            if ($value === null) {
                continue;
            }

            $monthMinor += $value;

            if (is_string($date) && $date === $today) {
                $todayMinor = $value;
            }
        }

        return ['today' => $todayMinor, 'month' => $monthMinor];
    }

    /** Today's date as Google would date a row for this account. */
    private function todayIn(?string $timezone): string
    {
        try {
            $zone = new DateTimeZone($timezone ?? 'UTC');
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }
}
