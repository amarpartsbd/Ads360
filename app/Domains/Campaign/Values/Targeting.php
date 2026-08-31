<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Values;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Who an ad set is shown to (spec §21).
 *
 * Stored as a document but never read as a loose array. Targeting decides
 * where a client's money goes, and a stored key nobody recognises would mean a
 * narrowing the client asked for silently ceasing to apply — so anything this
 * object cannot interpret is refused rather than ignored.
 *
 * Deliberately absent: any targeting on a protected characteristic. Providers
 * restrict these for housing, employment and credit advertising, and the
 * platform does not offer them at all rather than offering them and hoping the
 * provider refuses (spec §27).
 */
final class Targeting implements JsonSerializable
{
    private const MINIMUM_AGE = 18;

    private const MAXIMUM_AGE = 65;

    /**
     * @param  list<string>  $countries  ISO-3166 alpha-2.
     * @param  list<string>  $regions  Provider region identifiers, opaque here.
     * @param  list<string>  $cities
     * @param  list<string>  $languages
     * @param  list<string>  $interests  Provider interest identifiers.
     * @param  list<string>  $excludedInterests
     * @param  list<string>  $genders  'male', 'female', or empty for everyone.
     * @param  list<string>  $devices  'mobile', 'desktop', 'tablet'.
     * @param  list<string>  $customAudiences  Audiences the client owns at the provider.
     */
    private function __construct(
        public readonly array $countries,
        public readonly array $regions,
        public readonly array $cities,
        public readonly int $minimumAge,
        public readonly int $maximumAge,
        public readonly array $genders,
        public readonly array $languages,
        public readonly array $interests,
        public readonly array $excludedInterests,
        public readonly array $devices,
        public readonly array $customAudiences,
    ) {}

    /** The broadest audience: everywhere the campaign is allowed to run. */
    public static function everyone(): self
    {
        return new self([], [], [], self::MINIMUM_AGE, self::MAXIMUM_AGE, [], [], [], [], [], []);
    }

    /**
     * @param  array<string, mixed>  $targeting
     */
    public static function fromArray(array $targeting): self
    {
        $minimum = self::intOr($targeting, 'minimum_age', self::MINIMUM_AGE);
        $maximum = self::intOr($targeting, 'maximum_age', self::MAXIMUM_AGE);

        // Advertising to minors carries obligations the platform does not
        // support, so the floor is not negotiable from stored data.
        if ($minimum < self::MINIMUM_AGE) {
            throw new InvalidArgumentException(
                'Targeting cannot be set below age '.self::MINIMUM_AGE.'.'
            );
        }

        if ($maximum > self::MAXIMUM_AGE) {
            throw new InvalidArgumentException(
                'Targeting cannot be set above age '.self::MAXIMUM_AGE.'.'
            );
        }

        if ($minimum > $maximum) {
            throw new InvalidArgumentException('The minimum age cannot exceed the maximum age.');
        }

        return new self(
            countries: self::codeList($targeting, 'countries'),
            regions: self::stringList($targeting, 'regions'),
            cities: self::stringList($targeting, 'cities'),
            minimumAge: $minimum,
            maximumAge: $maximum,
            genders: self::enumeratedList($targeting, 'genders', ['male', 'female']),
            languages: self::stringList($targeting, 'languages'),
            interests: self::stringList($targeting, 'interests'),
            excludedInterests: self::stringList($targeting, 'excluded_interests'),
            devices: self::enumeratedList($targeting, 'devices', ['mobile', 'desktop', 'tablet']),
            customAudiences: self::stringList($targeting, 'custom_audiences'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'countries' => $this->countries,
            'regions' => $this->regions,
            'cities' => $this->cities,
            'minimum_age' => $this->minimumAge,
            'maximum_age' => $this->maximumAge,
            'genders' => $this->genders,
            'languages' => $this->languages,
            'interests' => $this->interests,
            'excluded_interests' => $this->excludedInterests,
            'devices' => $this->devices,
            'custom_audiences' => $this->customAudiences,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Whether the audience is specific enough to publish. A campaign with no
     * geography at all spends a client's budget worldwide, which is almost
     * never what they meant.
     */
    public function hasGeography(): bool
    {
        return $this->countries !== [] || $this->regions !== [] || $this->cities !== [];
    }

    /**
     * A short description for the review screen, so a reviewer can see the
     * audience without reading JSON.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->countries !== []) {
            $parts[] = implode(', ', $this->countries);
        }

        if ($this->cities !== []) {
            $parts[] = count($this->cities).' '.(count($this->cities) === 1 ? 'city' : 'cities');
        }

        $parts[] = "ages {$this->minimumAge}–{$this->maximumAge}";

        if ($this->genders !== []) {
            $parts[] = implode(' and ', $this->genders);
        }

        if ($this->interests !== []) {
            $parts[] = count($this->interests).' interests';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $targeting
     */
    private static function intOr(array $targeting, string $key, int $default): int
    {
        if (! isset($targeting[$key])) {
            return $default;
        }

        $value = $targeting[$key];

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Targeting [{$key}] must be a whole number.");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @return list<string>
     */
    private static function stringList(array $targeting, string $key): array
    {
        if (! isset($targeting[$key])) {
            return [];
        }

        if (! is_array($targeting[$key])) {
            throw new InvalidArgumentException("Targeting [{$key}] must be a list.");
        }

        $values = [];

        foreach ($targeting[$key] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException("Targeting [{$key}] must contain non-empty text.");
            }

            $values[] = trim($entry);
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @return list<string>
     */
    private static function codeList(array $targeting, string $key): array
    {
        // Deduplicated *after* normalising: "BD" and "bd" are the same
        // country, and sending it twice would have the provider apply the
        // same targeting twice.
        return array_values(array_unique(array_map(
            static function (string $code) use ($key): string {
                if (mb_strlen($code) !== 2) {
                    throw new InvalidArgumentException("Targeting [{$key}] expects two-letter country codes.");
                }

                return strtoupper($code);
            },
            self::stringList($targeting, $key),
        )));
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @param  list<string>  $permitted
     * @return list<string>
     */
    private static function enumeratedList(array $targeting, string $key, array $permitted): array
    {
        return array_values(array_unique(array_map(
            static function (string $value) use ($key, $permitted): string {
                $normalised = mb_strtolower($value);

                if (! in_array($normalised, $permitted, true)) {
                    throw new InvalidArgumentException(
                        "Targeting [{$key}] does not accept [{$value}]."
                    );
                }

                return $normalised;
            },
            self::stringList($targeting, $key),
        )));
    }
}
