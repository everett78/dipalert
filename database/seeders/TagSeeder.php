<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first() ?? User::factory()->createOne();

        $tags = ['Tech', 'Household'];

        foreach ($tags as $tag) {
            Tag::factory()->create([
                'name' => $tag,
                'user_id' => $user->id,
            ]);
        }
    }
}
