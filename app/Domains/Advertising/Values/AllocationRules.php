<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Values;

use App\Domains\Compliance\Enums\VerificationStatus;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The conditions a pool places on allocation (spec §18, §19).
 *
 * Rules are stored as a document but never read as a loose array: everything
 * that consumes them goes through this object, so a typo in a stored key
 * surfaces as a failure to construct rather than as a rule that silently
 * stopped applying.
 *
 * Two kinds of condition live here, and they are deliberately separate:
 * conditions on the client asking for an account, and conditions on the
 * account being considered. Both have to pass.
 */
final class AllocationRules implements JsonSerializable
{
    /**
     * @param  list<string>|null  $allowedCountries  Null means no country restriction; an empty list would mean nobody qualifies, which is never what an operator means.
     * @param  list<string>  $blockedCategories  Advertising categories this pool refuses outright.
     * @param  list<string>|null  $allowedCategories  Null means every category not explicitly blocked.
     */
    private function __construct(
        public readonly VerificationStatus $requiredVerificationStatus,
        public readonly ?int $minimumWalletBalanceMinor,
        public readonly ?array $allowedCountries,
        public readonly ?array $allowedCategories,
        public readonly array $blockedCategories,
        public readonly ?int $maxAccountRiskScore,
        public readonly ?int $maxDailyUtilisationPercent,
        public readonly int $reserveHeadroomMinor,
        public readonly bool $requireHealthyAccount,
        public readonly ?int $maxClientsPerAccount,
    ) {}

    /**
     * The conservative default: a verified client, a healthy account, and no
     * further narrowing. An operator relaxes from here deliberately.
     */
    public static function default(): self
    {
        return new self(
            requiredVerificationStatus: VerificationStatus::Verified,
            minimumWalletBalanceMinor: null,
            allowedCountries: null,
            allowedCategories: null,
            blockedCategories: [],
            maxAccountRiskScore: null,
            maxDailyUtilisationPercent: null,
            reserveHeadroomMinor: 0,
            requireHealthyAccount: true,
            maxClientsPerAccount: null,
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function fromArray(array $rules): self
    {
        $status = $rules['required_verification_status'] ?? VerificationStatus::Verified->value;
        $verification = VerificationStatus::tryFrom((string) $status);

        if ($verification === null) {
            throw new InvalidArgumentException("Unknown verification status [{$status}] in allocation rules.");
        }

        return new self(
            requiredVerificationStatus: $verification,
            minimumWalletBalanceMinor: self::nonNegativeIntOrNull($rules, 'minimum_wallet_balance_minor'),
            allowedCountries: self::codeListOrNull($rules, 'allowed_countries', 2),
            allowedCategories: self::stringListOrNull($rules, 'allowed_categories'),
            blockedCategories: self::stringListOrNull($rules, 'blocked_categories') ?? [],
            maxAccountRiskScore: self::boundedIntOrNull($rules, 'max_account_risk_score', 100),
            maxDailyUtilisationPercent: self::boundedIntOrNull($rules, 'max_daily_utilisation_percent', 100),
            reserveHeadroomMinor: self::nonNegativeIntOrNull($rules, 'reserve_headroom_minor') ?? 0,
            requireHealthyAccount: (bool) ($rules['require_healthy_account'] ?? true),
            maxClientsPerAccount: self::positiveIntOrNull($rules, 'max_clients_per_account'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'required_verification_status' => $this->requiredVerificationStatus->value,
            'minimum_wallet_balance_minor' => $this->minimumWalletBalanceMinor,
            'allowed_countries' => $this->allowedCountries,
            'allowed_categories' => $this->allowedCategories,
            'blocked_categories' => $this->blockedCategories,
            'max_account_risk_score' => $this->maxAccountRiskScore,
            'max_daily_utilisation_percent' => $this->maxDailyUtilisationPercent,
            'reserve_headroom_minor' => $this->reserveHeadroomMinor,
            'require_healthy_account' => $this->requireHealthyAccount,
            'max_clients_per_account' => $this->maxClientsPerAccount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function permitsCountry(?string $country): bool
    {
        if ($this->allowedCountries === null) {
            return true;
        }

        return $country !== null && in_array(strtoupper($country), $this->allowedCountries, true);
    }

    public function permitsCategory(?string $category): bool
    {
        if ($category === null) {
            // A pool that names categories expects one to be given. Absence is
            // not a pass when the operator asked for a specific set.
            return $this->allowedCategories === null;
        }

        $normalised = mb_strtolower(trim($category));

        if (in_array($normalised, $this->blockedCategories, true)) {
            return false;
        }

        return $this->allowedCategories === null
            || in_array($normalised, $this->allowedCategories, true);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function nonNegativeIntOrNull(array $rules, string $key): ?int
    {
        if (! isset($rules[$key])) {
            return null;
        }

        $value = $rules[$key];

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Allocation rule [{$key}] must be a whole number.");
        }

        $value = (int) $value;

        if ($value < 0) {
            throw new InvalidArgumentException("Allocation rule [{$key}] cannot be negative.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function positiveIntOrNull(array $rules, string $key): ?int
    {
        $value = self::nonNegativeIntOrNull($rules, $key);

        if ($value === 0) {
            throw new InvalidArgumentException("Allocation rule [{$key}] must be at least one.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function boundedIntOrNull(array $rules, string $key, int $max): ?int
    {
        $value = self::nonNegativeIntOrNull($rules, $key);

        if ($value !== null && $value > $max) {
            throw new InvalidArgumentException("Allocation rule [{$key}] cannot exceed {$max}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<string>|null
     */
    private static function stringListOrNull(array $rules, string $key): ?array
    {
        if (! isset($rules[$key])) {
            return null;
        }

        if (! is_array($rules[$key])) {
            throw new InvalidArgumentException("Allocation rule [{$key}] must be a list.");
        }

        $values = [];

        foreach ($rules[$key] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException("Allocation rule [{$key}] must contain non-empty text.");
            }

            $values[] = mb_strtolower(trim($entry));
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<string>|null
     */
    private static function codeListOrNull(array $rules, string $key, int $length): ?array
    {
        $values = self::stringListOrNull($rules, $key);

        if ($values === null) {
            return null;
        }

        return array_map(
            static function (string $code) use ($key, $length): string {
                if (mb_strlen($code) !== $length) {
                    throw new InvalidArgumentException("Allocation rule [{$key}] expects {$length}-letter codes.");
                }

                return strtoupper($code);
            },
            $values,
        );
    }
}
