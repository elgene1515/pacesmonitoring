<?php

namespace Tests\Feature;

use App\Models\PaceStockMovement;

/**
 * The admin changes the pool of several PACEs at once (receive, add / remove,
 * recount), all or nothing.
 */
class BatchStockTest extends ApiTestCase
{
    private function setUpPaces(): array
    {
        $organization = $this->organization();
        $admin = $this->admin($organization);

        $paces = [
            $this->pace(['number' => '1001', 'name' => 'Math', 'pool_stock' => 10], $admin),
            $this->pace(['number' => '1002', 'name' => 'Math', 'pool_stock' => 4], $admin),
            $this->pace(['number' => '1003', 'name' => 'Math', 'pool_stock' => 0], $admin),
        ];

        $this->signIn($admin);

        return [$admin, $paces, collect($paces)->pluck('id')->all()];
    }

    public function test_booklets_received_are_added_to_every_selected_pool(): void
    {
        [, $paces, $ids] = $this->setUpPaces();

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'receive', 'quantity' => 5, 'note' => 'Delivery'])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.pool_stock', 15)
            ->assertJsonPath('data.1.pool_stock', 9)
            ->assertJsonPath('data.2.pool_stock', 5);

        $this->assertSame(3, PaceStockMovement::query()->where('type', 'received')->where('quantity', 5)->count());
        $this->assertDatabaseHas('paces', ['id' => $paces[2]->id, 'pool_stock' => 5]);
    }

    public function test_a_recount_sets_every_pool_to_the_exact_number(): void
    {
        [, $paces, $ids] = $this->setUpPaces();

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'set', 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.0.pool_stock', 4)
            ->assertJsonPath('data.1.pool_stock', 4)
            ->assertJsonPath('data.2.pool_stock', 4);

        // The pace that already had 4 needs no entry; the other two are logged as adjustments.
        $this->assertSame(2, PaceStockMovement::query()->where('type', 'adjusted')->count());
        $this->assertDatabaseHas('pace_stock_movements', ['pace_id' => $paces[0]->id, 'type' => 'adjusted', 'quantity' => -6]);
        $this->assertDatabaseHas('pace_stock_movements', ['pace_id' => $paces[2]->id, 'type' => 'adjusted', 'quantity' => 4]);
    }

    public function test_a_number_can_be_added_or_removed_from_every_pool(): void
    {
        [, , $ids] = $this->setUpPaces();

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => [$ids[0], $ids[1]], 'mode' => 'adjust', 'quantity' => -3])
            ->assertOk()
            ->assertJsonPath('data.0.pool_stock', 7)
            ->assertJsonPath('data.1.pool_stock', 1);

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'adjust', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_one_pool_too_small_cancels_the_whole_batch(): void
    {
        [, $paces, $ids] = $this->setUpPaces();

        // The third pool is empty, so removing 3 from all fails, and nothing is changed.
        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'adjust', 'quantity' => -3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseHas('paces', ['id' => $paces[0]->id, 'pool_stock' => 10]);
        $this->assertDatabaseHas('paces', ['id' => $paces[1]->id, 'pool_stock' => 4]);
        $this->assertSame(0, PaceStockMovement::query()->count());
    }

    public function test_the_batch_is_validated_and_limited_to_the_admins_school(): void
    {
        [, , $ids] = $this->setUpPaces();

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => [], 'mode' => 'receive', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pace_ids']);
        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'receive', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => $ids, 'mode' => 'bogus', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        // A PACE of another school is not found, and nothing changes.
        $other = $this->pace(['pool_stock' => 9], $this->admin($this->organization()));

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => [$ids[0], $other->id], 'mode' => 'receive', 'quantity' => 2])
            ->assertNotFound();
        $this->assertDatabaseHas('paces', ['id' => $ids[0], 'pool_stock' => 10]);
        $this->assertDatabaseHas('paces', ['id' => $other->id, 'pool_stock' => 9]);
    }

    public function test_teachers_cannot_use_the_batch_endpoint(): void
    {
        $organization = $this->organization();
        $teacher = $this->teacher($organization);
        $pace = $this->pace(['pool_stock' => 3], $teacher);

        $this->signIn($teacher);

        $this->postJson('/api/admin/paces/stock/batch', ['pace_ids' => [$pace->id], 'mode' => 'receive', 'quantity' => 1])
            ->assertForbidden();
    }
}
