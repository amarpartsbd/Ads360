<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationProfile>
 */
class VerificationProfileFactory extends Factory
{
    protected $model = VerificationProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = $this->faker->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'legal_business_name' => $company.' Limited',
            'trading_name' => $company,
            'business_type' => 'Retail',
            'website' => 'https://example.test',
            'facebook_page' => 'https://facebook.com/example',
            'contact_number' => '+8801700000000',
            'business_email' => $this->faker->unique()->companyEmail(),
            'address_line_1' => '12 Example Road',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'postal_code' => '1212',
            'country' => 'BD',
            'authorized_person_name' => $this->faker->name(),
            'authorized_person_designation' => 'Managing Director',
            'authorized_person_email' => $this->faker->unique()->safeEmail(),
            'authorized_person_phone' => '+8801700000001',
            'trade_license_number' => 'TRAD-'.$this->faker->unique()->numberBetween(100000, 999999),
            'tin' => (string) $this->faker->unique()->numberBetween(100000000, 999999999),
            'bin_vat_number' => 'BIN-'.$this->faker->unique()->numberBetween(100000, 999999),
            'expected_monthly_spend_minor' => 50_000_00,
            'expected_monthly_spend_currency' => 'BDT',
            'advertising_category' => 'General retail',
            'status' => VerificationStatus::NotSubmitted,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'tenant_id' => $organization->tenant_id,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => VerificationStatus::Pending,
            'submitted_at' => now(),
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn (): array => [
            'status' => VerificationStatus::UnderReview,
            'submitted_at' => now()->subDay(),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'status' => VerificationStatus::Verified,
            'submitted_at' => now()->subDays(3),
            'reviewed_at' => now()->subDay(),
        ]);
    }

    public function requiresInformation(): static
    {
        return $this->state(fn (): array => [
            'status' => VerificationStatus::RequiresInformation,
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
            'client_message' => 'The trade licence scan is unreadable. Please upload a clearer copy.',
        ]);
    }
}
