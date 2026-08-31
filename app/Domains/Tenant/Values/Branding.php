<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Values;

use InvalidArgumentException;
use JsonSerializable;

/**
 * How a tenant's copy of the platform looks (spec §43).
 *
 * White labelling is where a design system usually goes wrong, because the one
 * thing a customer most wants to change — the primary colour — is the one that
 * decides whether text on a button can be read. So this object validates rather
 * than merely stores: a colour that would make the interface illegible is
 * refused with a message saying why, at the point someone chooses it, rather
 * than shipping a screen their own staff cannot use.
 *
 * Everything here is optional. A tenant that sets nothing gets the platform's
 * own branding, which is why no value in this object is ever hard-coded at a
 * call site (Rule 6).
 */
final class Branding implements JsonSerializable
{
    /**
     * The lowest contrast ratio a primary colour may have against the white
     * text that sits on it.
     *
     * WCAG AA for normal text. Button labels are normal text, and a platform
     * that let a tenant fall below this would be selling them an interface
     * their own staff have to squint at (spec §74).
     */
    private const MINIMUM_CONTRAST = 4.5;

    private function __construct(
        public readonly ?string $name,
        public readonly ?string $logoUrl,
        public readonly ?string $primaryColor,
        public readonly ?string $supportEmail,
    ) {}

    /**
     * @param  array<string, mixed>  $branding
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $branding): self
    {
        return new self(
            name: self::text($branding['name'] ?? null, 'name', 64),
            logoUrl: self::url($branding['logo_url'] ?? null),
            primaryColor: self::color($branding['primary_color'] ?? null),
            supportEmail: self::email($branding['support_email'] ?? null),
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * The stored shape. Nulls are dropped rather than written, so a tenant that
     * clears a field falls back to the platform default rather than to an
     * explicit null that reads as "blank".
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'logo_url' => $this->logoUrl,
            'primary_color' => $this->primaryColor,
            'support_email' => $this->supportEmail,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Whether a colour is legible under white text.
     *
     * Public because the same rule has to be answerable from a form request,
     * which needs to fail validation rather than catch an exception.
     */
    public static function isLegible(string $hex): bool
    {
        $normalised = self::normaliseHex($hex);

        return $normalised !== null && self::contrastWithWhite($normalised) >= self::MINIMUM_CONTRAST;
    }

    public static function minimumContrast(): float
    {
        return self::MINIMUM_CONTRAST;
    }

    /**
     * The contrast ratio between a colour and white, by the WCAG formula.
     *
     * Written out rather than pulled from a package: it is fifteen lines, it
     * never changes, and a colour rule this platform enforces on its customers
     * should be one anyone reading this file can check.
     */
    public static function contrastWithWhite(string $hex): float
    {
        $normalised = self::normaliseHex($hex);

        if ($normalised === null) {
            return 0.0;
        }

        $luminance = self::relativeLuminance($normalised);

        // White's relative luminance is 1.
        return (1.0 + 0.05) / ($luminance + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $channels = [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];

        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** `#abc` and `#aabbcc` in, `#aabbcc` out. Anything else, null. */
    public static function normaliseHex(string $hex): ?string
    {
        $trimmed = strtolower(trim($hex));

        if (preg_match('/^#([0-9a-f]{3})$/', $trimmed, $short) === 1) {
            $trimmed = '#'.$short[1][0].$short[1][0].$short[1][1].$short[1][1].$short[1][2].$short[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $trimmed) === 1 ? $trimmed : null;
    }

    private static function color(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A brand colour must be a hex value such as #2158a7.');
        }

        $normalised = self::normaliseHex($value);

        if ($normalised === null) {
            throw new InvalidArgumentException('A brand colour must be a hex value such as #2158a7.');
        }

        if (! self::isLegible($normalised)) {
            throw new InvalidArgumentException(sprintf(
                'That colour is too light for white text to be read on it. '
                .'Choose a darker one — it needs a contrast of at least %.1f to 1, and this is %.1f.',
                self::MINIMUM_CONTRAST,
                self::contrastWithWhite($normalised),
            ));
        }

        return $normalised;
    }

    private static function text(mixed $value, string $field, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The brand {$field} must be text.");
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException("The brand {$field} must be {$max} characters or fewer.");
        }

        return $trimmed;
    }

    /**
     * A logo has to be somewhere a browser will load it from.
     *
     * HTTPS only: an http logo on an https page is a mixed-content warning in
     * every client's browser, and the first thing they would report is that
     * the platform looks broken.
     */
    private static function url(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! str_starts_with($value, 'https://')
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('A logo address must be a full https:// URL.');
        }

        return $value;
    }

    private static function email(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The support address must be a valid email address.');
        }

        return strtolower(trim($value));
    }
}
