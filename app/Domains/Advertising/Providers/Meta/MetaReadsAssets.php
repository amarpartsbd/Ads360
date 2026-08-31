<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\DTOs\ProviderAccountState;
use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Integration\Models\ProviderConnection;
use App\Support\Values\Currency;

/**
 * Reading what a Meta grant covers, and what Meta says about an ad account
 * (spec §15, §20).
 *
 * Split from the adapter because discovery and health are read-only and share
 * a set of conversion problems that publishing does not have — chiefly that
 * Meta reports money as decimal strings in an account's own currency, and
 * reports some fields not at all.
 */
trait MetaReadsAssets
{
    /**
     * Everything the grant authorises, across the four asset kinds the
     * platform can use.
     *
     * Each edge is fetched independently and a failure on one does not sink
     * the others: a client whose pages we can read but whose pixels we cannot
     * should still get their pages. Only a failure that means the grant itself
     * is gone stops the whole run.
     *
     * @return list<DiscoveredAsset>
     */
    public function discoverAssets(ProviderConnection $connection): array
    {
        $client = $this->client->withToken($connection->accessToken());

        $assets = [];

        foreach ($this->assetReaders() as $reader) {
            try {
                $assets = [...$assets, ...$this->{$reader}($client)];
            } catch (ProviderUnavailable $exception) {
                // An expired or revoked grant affects every edge, so there is
                // nothing to be gained by trying the rest.
                if (! $exception->retryable && str_contains($exception->getMessage(), 'authentication')) {
                    throw $exception;
                }

                // A missing permission for one edge is normal: the client may
                // simply not have granted it. The others still stand.
                continue;
            }
        }

        return $assets;
    }

    /**
     * What Meta currently says about one of the platform's managed accounts.
     *
     * A null in the result means Meta did not report the figure, and callers
     * treat that as "unknown" rather than zero (§87) — an account reported as
     * having spent nothing would be handed straight back out as idle.
     */
    public function accountState(string $externalAccountId, ?ProviderConnection $connection = null): ProviderAccountState
    {
        $client = $connection === null
            // One of the platform's own accounts, reached with the platform's
            // own system user token (spec §17).
            ? $this->platformClient()
            : $this->client->withToken($connection->accessToken());

        $account = $client->get($this->accountPath($externalAccountId), [
            'fields' => implode(',', [
                'account_status',
                'disable_reason',
                'currency',
                'amount_spent',
                'spend_cap',
                'balance',
                'funding_source_details',
                'business',
                'timezone_name',
            ]),
        ]);

        $currency = isset($account['currency']) ? (string) $account['currency'] : null;

        return new ProviderAccountState(
            externalAccountId: $externalAccountId,
            status: $this->accountStatus($account),
            billingStatus: $this->billingStatus($account),
            // `amount_spent` is lifetime spend on the account, not today's, so
            // it is deliberately not reported as the daily figure. Per-campaign
            // spend comes from insights, which is what the ledger draws on.
            spentTodayMinor: null,
            spentThisMonthMinor: null,
            dailySpendLimitMinor: null,
            monthlySpendLimitMinor: $this->toMinor($account['spend_cap'] ?? null, $currency),
            currency: $currency,
            disapprovalReason: $this->disableReason($account),
            raw: ['account_status' => $account['account_status'] ?? null],
        );
    }

    /**
     * The edges discovery walks, in the order a client would recognise them.
     *
     * @return list<string>
     */
    private function assetReaders(): array
    {
        return ['readAdAccounts', 'readPages', 'readInstagramAccounts', 'readPixels'];
    }

    /**
     * @return list<DiscoveredAsset>
     */
    private function readAdAccounts(MetaGraphClient $client): array
    {
        $nodes = $client->paginate('me/adaccounts', [
            'fields' => 'account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
        ]);

        return array_values(array_map(
            fn (array $node): DiscoveredAsset => new DiscoveredAsset(
                type: AssetType::MetaAdAccount,
                // `act_` prefixed, because that is the form every other Meta
                // endpoint expects it in.
                externalId: 'act_'.(string) ($node['account_id'] ?? ''),
                name: (string) ($node['name'] ?? 'Ad account'),
                currency: isset($node['currency']) ? (string) $node['currency'] : null,
                timezone: isset($node['timezone_name']) ? (string) $node['timezone_name'] : null,
                status: $this->accountStatus($node),
                metadata: ['account_status' => $node['account_status'] ?? null],
            ),
            $nodes,
        ));
    }

    /**
     * @return list<DiscoveredAsset>
     */
    private function readPages(MetaGraphClient $client): array
    {
        $nodes = $client->paginate('me/accounts', [
            'fields' => 'id,name,category,tasks',
            'limit' => 100,
        ]);

        return array_values(array_map(
            static fn (array $node): DiscoveredAsset => new DiscoveredAsset(
                type: AssetType::FacebookPage,
                externalId: (string) ($node['id'] ?? ''),
                name: (string) ($node['name'] ?? 'Page'),
                status: 'ACTIVE',
                metadata: [
                    'category' => $node['category'] ?? null,
                    // What the client may actually do with the page. A page
                    // they can only analyse cannot carry an ad.
                    'tasks' => $node['tasks'] ?? [],
                ],
            ),
            $nodes,
        ));
    }

    /**
     * Instagram accounts hang off pages rather than off the user, so they are
     * read per page.
     *
     * @return list<DiscoveredAsset>
     */
    private function readInstagramAccounts(MetaGraphClient $client): array
    {
        $pages = $client->paginate('me/accounts', [
            'fields' => 'id,connected_instagram_account{id,username,name}',
            'limit' => 100,
        ]);

        $assets = [];

        foreach ($pages as $page) {
            $instagram = $page['connected_instagram_account'] ?? null;

            if (! is_array($instagram) || ! isset($instagram['id'])) {
                continue;
            }

            $assets[] = new DiscoveredAsset(
                type: AssetType::InstagramAccount,
                externalId: (string) $instagram['id'],
                name: (string) ($instagram['username'] ?? $instagram['name'] ?? 'Instagram account'),
                status: 'ACTIVE',
                metadata: ['page_id' => (string) ($page['id'] ?? '')],
            );
        }

        return $assets;
    }

    /**
     * Pixels are owned by a business, so this only returns anything when the
     * platform is configured with one and the grant covers it.
     *
     * @return list<DiscoveredAsset>
     */
    private function readPixels(MetaGraphClient $client): array
    {
        if ($this->config->businessId === null) {
            return [];
        }

        $nodes = $client->paginate(
            $this->config->businessId.'/owned_pixels',
            ['fields' => 'id,name', 'limit' => 100],
        );

        return array_values(array_map(
            static fn (array $node): DiscoveredAsset => new DiscoveredAsset(
                type: AssetType::MetaPixel,
                externalId: (string) ($node['id'] ?? ''),
                name: (string) ($node['name'] ?? 'Pixel'),
                status: 'ACTIVE',
            ),
            $nodes,
        ));
    }

    /**
     * Meta reports account status as a number. Only 1 means "can run ads";
     * everything else is a reason it cannot, and is passed through as such
     * rather than being smoothed over (§27).
     *
     * @param  array<string, mixed>  $account
     */
    private function accountStatus(array $account): ?string
    {
        if (! isset($account['account_status'])) {
            return null;
        }

        return match ((int) $account['account_status']) {
            1 => 'ACTIVE',
            2 => 'DISABLED',
            3 => 'UNSETTLED',
            7 => 'PENDING_RISK_REVIEW',
            8 => 'PENDING_SETTLEMENT',
            9 => 'IN_GRACE_PERIOD',
            100 => 'PENDING_CLOSURE',
            101 => 'CLOSED',
            201 => 'ANY_ACTIVE',
            202 => 'ANY_CLOSED',
            default => 'UNKNOWN',
        };
    }

    /**
     * Whether Meta will accept spend, expressed in the platform's vocabulary.
     *
     * @param  array<string, mixed>  $account
     */
    private function billingStatus(array $account): ?string
    {
        $status = $this->accountStatus($account);

        if ($status === null) {
            return null;
        }

        $funding = $account['funding_source_details'] ?? null;

        if ($status === 'ACTIVE' && ! is_array($funding)) {
            // Active but with nothing to charge: Meta will refuse the first
            // ad, and it is better to know now.
            return 'PAYMENT_METHOD_MISSING';
        }

        return match ($status) {
            'ACTIVE' => 'CURRENT',
            'UNSETTLED', 'PENDING_SETTLEMENT' => 'PAYMENT_FAILED',
            'DISABLED', 'CLOSED', 'PENDING_CLOSURE' => 'SUSPENDED',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function disableReason(array $account): ?string
    {
        $reason = isset($account['disable_reason']) ? (int) $account['disable_reason'] : 0;

        return match ($reason) {
            0 => null,
            1 => 'Meta disabled this account for a policy violation.',
            2 => 'Meta disabled this account over an unsettled balance.',
            3 => 'Meta disabled this account for account integrity reasons.',
            4 => 'Meta disabled this account for a permissions issue.',
            5 => 'Meta disabled this account: the grace period ended.',
            default => 'Meta has disabled this account.',
        };
    }

    /**
     * Meta returns money as a decimal string in the account's own currency.
     * Converting needs the currency's scale, so an unknown currency returns
     * null rather than a figure that could be a hundred times wrong.
     */
    private function toMinor(mixed $amount, ?string $currency): ?int
    {
        if ($amount === null || $currency === null || ! Currency::supported($currency)) {
            return null;
        }

        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        $value = (string) $amount;

        if (! is_numeric($value)) {
            return null;
        }

        // `spend_cap` and `amount_spent` come back already in minor units for
        // most currencies, but Meta documents them as "in the account
        // currency's smallest unit" — so they are used as integers directly.
        $minor = (int) round((float) $value);

        return $minor >= 0 ? $minor : null;
    }

    /** Meta expects ad account ids prefixed; callers may pass either form. */
    private function accountPath(string $externalAccountId): string
    {
        return str_starts_with($externalAccountId, 'act_')
            ? $externalAccountId
            : 'act_'.$externalAccountId;
    }
}
