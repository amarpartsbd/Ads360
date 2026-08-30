<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Enums;

/**
 * The business documents a client may submit (spec §11).
 */
enum DocumentType: string
{
    case TradeLicense = 'TRADE_LICENSE';
    case TinCertificate = 'TIN_CERTIFICATE';
    case VatRegistration = 'VAT_REGISTRATION';
    case NationalId = 'NATIONAL_ID';
    case Passport = 'PASSPORT';
    case BankStatement = 'BANK_STATEMENT';
    case AuthorizationLetter = 'AUTHORIZATION_LETTER';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::TradeLicense => 'Trade licence',
            self::TinCertificate => 'TIN certificate',
            self::VatRegistration => 'BIN / VAT registration',
            self::NationalId => 'National ID of authorised person',
            self::Passport => 'Passport of authorised person',
            self::BankStatement => 'Bank statement',
            self::AuthorizationLetter => 'Letter of authorisation',
            self::Other => 'Other supporting document',
        };
    }

    /**
     * Whether a submission cannot proceed without this document.
     *
     * Identity of the authorised person is satisfied by either a national ID or
     * a passport, so neither is required on its own; the submission action
     * checks that at least one is present.
     */
    public function isRequired(): bool
    {
        return $this === self::TradeLicense;
    }

    /**
     * @return list<self>
     */
    public static function identityDocuments(): array
    {
        return [self::NationalId, self::Passport];
    }
}
