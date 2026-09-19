<?php

namespace Database\Seeders;

use App\Models\Grap\Lead;
use Database\Factories\Grap\LeadFactory;
use Illuminate\Database\Seeder;

/**
 * Enough sample leads to work the screens.
 *
 * Placeholder for the real thing: the live tables are millions of rows
 * loaded with `grap:import`. This seeder only ever adds rows whose
 * data_source is 'sample', so importing the real export never collides
 * with it and clearing it out afterwards is one delete.
 */
class GrapLeadSeeder extends Seeder
{
    public function run(): void
    {
        if (Lead::where('data_source', 'sample')->exists()) {
            return;
        }

        LeadFactory::new()->buyer()->count(120)->create();
        LeadFactory::new()->supplier()->count(120)->create();
        LeadFactory::new()->buyer()->withSecondContact()->count(30)->create();
        LeadFactory::new()->supplier()->withSecondContact()->count(30)->create();
    }
}
