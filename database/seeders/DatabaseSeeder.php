<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Job;
use App\Models\JobAttribute;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $languages = ["PHP", "Python", "JavaScript", "Java", "C++", "C#", "Ruby", "Swift", "Go", "Kotlin"];
        $locations = [
            [
                'city' => 'Dubai',
                'state' => 'Dubai',
                'country' => 'United Arab Emirates',
            ],
            [
                'city' => 'Abu Dhabi',
                'state' => 'Dubai',
                'country' => 'United Arab Emirates',
            ],
            [
                'city' => 'San Francisco',
                'state' => 'California',
                'country' => 'United States',
            ],
            [
                'city' => 'Vadodara',
                'state' => 'Gujarat',
                'country' => 'India',
            ]
        ];
        $categories = ["Web App Development", "Mobile App Development", "UI/UX Design"];

        foreach ($locations as $location) {
            \App\Models\Location::create($location);
        }

        foreach ($categories as $category) {
            \App\Models\Category::create(['name' => $category]);
        }

        foreach ($languages as $language) {
            \App\Models\Language::create(['name' => $language]);
        }

        $attributes = [
            [
                'name' => 'years_experience',
                'type' => 'number_value',
            ],
            [
                'name' => 'joining_availibility',
                'type' => 'select_value',
                'options' => ['immediately', 'within_a_week', 'within_a_month'],
            ],
            [
                'name' => 'job_post_start_date',
                'type' => 'date_value',
            ],
            [
                'name' => 'job_post_end_date',
                'type' => 'date_value',
            ],
            [
                'name' => 'job_type',
                'type' => 'select_value',
                'options' => ['full_time', 'part_time', 'contract', 'freelance'],
            ],
        ];

        foreach ($attributes as $attribute) {
            \App\Models\Attribute::create($attribute);
        }

        Job::factory(500)->create()->each(function ($job) {

            $job->languages()->attach(\App\Models\Language::inRandomOrder()->limit(3)->get()->pluck('id'));
            $job->categories()->attach(\App\Models\Category::inRandomOrder()->limit(2)->get()->pluck('id'));
            $job->locations()->attach(\App\Models\Location::inRandomOrder()->limit(2)->get()->pluck('id'));

            Attribute::inRandomOrder()->limit(3)->get()->each(function ($attribute) use ($job) {
                if($attribute->type === 'select_value') {
                    JobAttribute::create([
                        'job_id' => $job->id,
                        'attribute_id' => $attribute->id,
                        'select_value' => $attribute->options[array_rand($attribute->options)],
                    ]);
                } elseif ($attribute->type === 'number_value') {
                    JobAttribute::create([
                        'job_id' => $job->id,
                        'attribute_id' => $attribute->id,
                        'number_value' => rand(1, 100),
                    ]);
                } elseif ($attribute->type === 'date_value') {
                    JobAttribute::create([
                        'job_id' => $job->id,
                        'attribute_id' => $attribute->id,
                        'date_value' => now()->addDays(rand(1, 30)),
                    ]);
                } elseif ($attribute->type === 'text_value') {
                    JobAttribute::create([
                        'job_id' => $job->id,
                        'attribute_id' => $attribute->id,
                        'text_value' => 'This is a text value',
                    ]);
                } elseif ($attribute->type === 'boolean_value') {
                    JobAttribute::create([
                        'job_id' => $job->id,
                        'attribute_id' => $attribute->id,
                        'text_value' => true,
                    ]);
                }
            });
        });
    }
}
