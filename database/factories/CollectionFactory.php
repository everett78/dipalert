<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    protected $model = Collection::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'enabled' => true,
            'weight' => 0,
            'user_id' => User::factory(),
        ];
    }

    public function childOf(Collection $parent): self
    {
        return $this->state(fn () => ['parent_id' => $parent->id, 'user_id' => $parent->user_id]);
    }

    public function disabled(): self
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
