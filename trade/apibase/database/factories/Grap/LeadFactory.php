<?php

namespace Database\Factories\Grap;

use App\Models\Grap\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Believable rows for the screens and the tests.
 *
 * Deliberately uneven: some rows have an email and no number, some the
 * reverse, some a second contact, some none. Even fixtures hid the bug
 * where a row with no email at all was treated as unreachable.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        $kind = fake()->randomElement(Lead::KINDS);
        $country = fake()->randomElement(['India', 'United Arab Emirates', 'Germany', 'Viet Nam', 'Turkey', 'Brazil', 'United States', 'Kenya', 'Poland', 'Indonesia']);
        $category = fake()->randomElement(['Textiles & Apparel', 'Spices & Condiments', 'Auto Components', 'Handicrafts', 'Pharmaceuticals', 'Leather Goods', 'Industrial Machinery', 'Ceramic Tiles']);
        $company = fake()->company();
        $domain = strtolower(preg_replace('/[^a-z]+/i', '', explode(' ', $company)[0])) . '.com';

        $hasEmail = fake()->boolean(80);
        $hasPhone = fake()->boolean(70);

        return [
            'kind' => $kind,
            'company_name' => $company,
            'company_address' => fake()->streetAddress() . ', ' . fake()->city(),
            'country' => $country,
            'website' => fake()->boolean(60) ? "https://www.{$domain}" : null,
            'linkedin_url' => fake()->boolean(25) ? 'https://www.linkedin.com/company/' . fake()->slug(2) : null,
            'brief_intro' => fake()->boolean(70) ? fake()->sentence(14) : null,
            'business_category' => $category,
            'input_name' => null,
            'company_ref' => null,
            'contact_person' => fake()->boolean(85) ? fake()->name() : null,
            'designation' => fake()->randomElement(['Proprietor', 'Purchase Manager', 'Director', 'Export Manager', 'CEO', 'Head of Sourcing', 'Partner']),
            'email' => $hasEmail ? strtolower(fake()->firstName()) . '@' . $domain : null,
            'email_catch_all' => false,
            'dial_code' => $hasPhone ? fake()->randomElement(['91', '971', '49', '84', '90', '55']) : null,
            'phone' => $hasPhone && fake()->boolean(60) ? fake()->numerify('##########') : null,
            'mobile' => $hasPhone ? fake()->numerify('##########') : null,
            'data_source' => 'sample',
            'source_ref' => fake()->unique()->numerify('SMPL-######'),
        ];
    }

    public function buyer(): static
    {
        return $this->state(fn () => ['kind' => Lead::KIND_BUYER]);
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['kind' => Lead::KIND_SUPPLIER]);
    }

    /** A row with a second person on it. */
    public function withSecondContact(): static
    {
        return $this->state(fn (array $a) => [
            'contact_person_2' => fake()->name(),
            'designation_2' => 'Accounts',
            'email_2' => 'accounts@' . str_replace(['https://www.', 'http://'], '', (string) ($a['website'] ?? 'example.com')),
            'email_2_catch_all' => false,
            'dial_code_2' => $a['dial_code'] ?? null,
            'mobile_2' => fake()->numerify('##########'),
        ]);
    }
}
