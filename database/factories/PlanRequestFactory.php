<?php

namespace Database\Factories;

use App\Enums\PlanRequestStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlanRequest>
 */
class PlanRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\PlanRequest>
     */
    protected $model = PlanRequest::class;

    /**
     * Define the model's default state: a pending request to move to the Growth plan.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'organization_id' => Organization::factory(),
            'requested_by' => null,
            'action' => PlanRequest::ACTION_CHANGE,
            'plan_id' => fn () => Plan::query()->where('slug', 'growth')->value('id')
                ?? Plan::query()->orderBy('sort_order')->value('id')
                ?? Plan::factory()->create()->id,
            'message' => fake()->optional()->sentence(),
            'status' => PlanRequestStatus::Pending,
        ];
    }

    /**
     * A request to cancel the subscription.
     *
     * @return static
     */
    public function cancellation()
    {
        return $this->state(fn (array $attributes) => [
            'action' => PlanRequest::ACTION_CANCEL,
            'plan_id' => null,
        ]);
    }
}
