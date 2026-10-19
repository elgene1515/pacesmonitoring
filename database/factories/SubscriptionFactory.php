<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Subscription>
     */
    protected $model = Subscription::class;

    /**
     * Define the model's default state: a one-year active subscription on the
     * first plan (the migrations insert the Starter plan).
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'organization_id' => Organization::factory(),
            'plan_id' => fn () => Plan::query()->orderBy('sort_order')->orderBy('id')->value('id') ?? Plan::factory()->create()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::today()->toDateString(),
            'ends_at' => Carbon::today()->addYear()->toDateString(),
            'notes' => null,
            'created_by' => null,
        ];
    }

    /**
     * A trial that ends in 30 days.
     *
     * @return static
     */
    public function trial()
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Trial,
            'ends_at' => Carbon::today()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * A subscription whose end date has passed.
     *
     * @return static
     */
    public function expired()
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::today()->subYear()->subDay()->toDateString(),
            'ends_at' => Carbon::today()->subDay()->toDateString(),
        ]);
    }
}
