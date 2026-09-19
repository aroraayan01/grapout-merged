<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $gb = 1024 * 1024 * 1024;

        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Get organised with the essentials.',
                'monthly_price' => 0, 'annual_price' => 0, 'sort_order' => 0,
                'limits' => [
                    'max_tasks' => 50, 'storage_bytes' => $gb, 'max_groups' => 1,
                    'max_group_members' => 4, 'max_categories' => 5,
                    'max_meeting_participants' => 4, 'max_meeting_minutes' => 40,
                    'max_products' => 10, 'rank_tier' => 0,
                    // Hot Leads: look all you like, within reason; unlocking a
                    // contact is what you buy a plan for.
                    'grap_searches_per_day' => 10,
                    'grap_reveals_per_day' => 0, 'grap_reveals_per_month' => 0,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => false, 'reports_export' => false, 'subadmins' => false,
                    'trade_priority' => false,
                    'grap_leads' => true, 'grap_export' => false,
                ],
                'is_public' => true, 'is_active' => true,
            ],
            // The trade plans. The tier is what decides who comes first in a
            // search: Platinum above Diamond above Gold above Free. Prices are
            // starting points; the team sets the real ones under Admin → Plans.
            [
                'slug' => 'gold',
                'name' => 'Gold',
                'description' => 'Your company page ahead of every free listing, with more products and full calls and meetings.',
                'monthly_price' => 999, 'annual_price' => 9990, 'sort_order' => 1, 'is_public' => true, 'is_active' => true, 'is_recommended' => false,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 5 * $gb, 'max_groups' => 3, 'max_group_members' => 10, 'max_categories' => null,
                    'max_meeting_participants' => 10, 'max_meeting_minutes' => 120, 'max_products' => 50, 'rank_tier' => 1,
                    'grap_searches_per_day' => 100, 'grap_reveals_per_day' => 25, 'grap_reveals_per_month' => 500,
                ],
                'features' => ['reminders' => true, 'notes' => true, 'voice_assistant' => true, 'calls' => true, 'reports_export' => true, 'subadmins' => false, 'trade_priority' => true, 'grap_leads' => true, 'grap_export' => true],
            ],
            [
                'slug' => 'diamond',
                'name' => 'Diamond',
                'description' => 'Ahead of Gold in every search, more products, bigger meetings.',
                'monthly_price' => 2499, 'annual_price' => 24990, 'sort_order' => 2, 'is_public' => true, 'is_active' => true, 'is_recommended' => true,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 20 * $gb, 'max_groups' => 10, 'max_group_members' => 25, 'max_categories' => null,
                    'max_meeting_participants' => 25, 'max_meeting_minutes' => 240, 'max_products' => 200, 'rank_tier' => 2,
                    'grap_searches_per_day' => 500, 'grap_reveals_per_day' => 100, 'grap_reveals_per_month' => 2000,
                ],
                'features' => ['reminders' => true, 'notes' => true, 'voice_assistant' => true, 'calls' => true, 'reports_export' => true, 'subadmins' => true, 'trade_priority' => true, 'grap_leads' => true, 'grap_export' => true],
            ],
            [
                'slug' => 'platinum',
                'name' => 'Platinum',
                'description' => 'First in every search, unlimited products, everything open.',
                'monthly_price' => 4999, 'annual_price' => 49990, 'sort_order' => 3, 'is_public' => true, 'is_active' => true, 'is_recommended' => false,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => null, 'max_groups' => null, 'max_group_members' => null, 'max_categories' => null,
                    'max_meeting_participants' => null, 'max_meeting_minutes' => null, 'max_products' => null, 'rank_tier' => 3,
                    'grap_searches_per_day' => null, 'grap_reveals_per_day' => null, 'grap_reveals_per_month' => null,
                ],
                'features' => ['reminders' => true, 'notes' => true, 'voice_assistant' => true, 'calls' => true, 'reports_export' => true, 'subadmins' => true, 'trade_priority' => true, 'grap_leads' => true, 'grap_export' => true],
            ],
            [
                'slug' => 'personal',
                'name' => 'Personal',
                'description' => 'Unlimited personal productivity.',
                'monthly_price' => 99, 'annual_price' => 999, 'trial_days' => 14, 'sort_order' => 1, 'is_public' => false,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 5 * $gb, 'max_groups' => 2,
                    'max_group_members' => 6, 'max_categories' => null,
                    'max_meeting_participants' => 8, 'max_meeting_minutes' => 60,
                    'max_products' => 100,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => false,
                    'trade_priority' => true,
                ],
            ],
            [
                'slug' => 'family',
                'name' => 'Family',
                'description' => 'Shared tasks, calendar, chat and calls for the whole family.',
                'monthly_price' => 199, 'annual_price' => 1999, 'trial_days' => 14, 'is_public' => false,
                'is_recommended' => true, 'sort_order' => 2,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 20 * $gb, 'max_groups' => 5,
                    'max_group_members' => 12, 'max_categories' => null,
                    'max_meeting_participants' => 16, 'max_meeting_minutes' => 120,
                    'max_products' => null,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => false,
                    'trade_priority' => true,
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'Team management with assignments and reports.',
                'monthly_price' => 499, 'annual_price' => 4999, 'trial_days' => 14, 'sort_order' => 3, 'is_public' => false,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 50 * $gb, 'max_groups' => 15,
                    'max_group_members' => 30, 'max_categories' => null,
                    'max_meeting_participants' => 50, 'max_meeting_minutes' => 300,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Larger teams, audit logs and priority support.',
                'monthly_price' => 999, 'annual_price' => 9999, 'sort_order' => 4, 'is_public' => false,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 200 * $gb, 'max_groups' => null,
                    'max_group_members' => 100, 'max_categories' => null,
                    'max_meeting_participants' => 100, 'max_meeting_minutes' => null,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits, integrations and dedicated support.',
                'monthly_price' => 0, 'annual_price' => 0, 'is_public' => false, 'sort_order' => 5,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => null, 'max_groups' => null,
                    'max_group_members' => null, 'max_categories' => null,
                    'max_meeting_participants' => null, 'max_meeting_minutes' => null, 'rank_tier' => 3,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
