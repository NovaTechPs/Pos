<?php

namespace Database\Seeders;

use App\Models\WebCategory;
use Illuminate\Database\Seeder;

class WebCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            WebCategory::factory()->create();
        }
    }
}
