<?php

namespace Tests\Feature;

use App\Models\PriceReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function report(User $user, int $price, string $item = 'rice-50kg', string $market = 'bodija')
    {
        return $this->actingAs($user)->postJson('/api/reports', ['item_id' => $item, 'market_id' => $market, 'price' => $price]);
    }

    public function test_snapshot_is_public_and_hides_who_reported(): void
    {
        $ada = User::factory()->create();
        $this->report($ada, 80000)->assertCreated();
        $this->app['auth']->forgetGuards();

        $res = $this->getJson('/api/snapshot?area=Ibadan')->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('reports.0.price', 80000)
            ->assertJsonPath('me', null);

        $userId = $res->json('reports.0.userId');
        $this->assertSame($ada->publicId(), $userId);
        $this->assertNotSame((string) $ada->id, $userId);
        $this->assertStringNotContainsString($ada->email, $res->getContent());
        $this->assertEquals(1, $res->json("trust.$userId"));
        $this->assertNotEmpty($res->json('markets'));
        $this->assertNotEmpty($res->json('items'));
    }

    public function test_snapshot_only_includes_the_area_and_the_last_14_days(): void
    {
        $ada = User::factory()->create();
        $this->report($ada, 80000, market: 'mile12')->assertCreated();
        $this->report($ada, 81000)->assertCreated();
        PriceReport::query()->where('price', 81000)->update(['created_at' => now()->subDays(15)]);

        $this->getJson('/api/snapshot?area=Ibadan')->assertOk()->assertJsonCount(0, 'reports');
        $this->getJson('/api/snapshot?area=Lagos')->assertOk()->assertJsonCount(1, 'reports');
        $this->getJson('/api/snapshot?area=Mars')->assertUnprocessable();
    }

    public function test_report_validates_item_market_and_price(): void
    {
        $ada = User::factory()->create();

        $this->report($ada, 0)->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->report($ada, 100_000_000)->assertUnprocessable();
        $this->report($ada, 5000, item: 'gold')->assertUnprocessable()->assertJsonValidationErrors('item_id');
        $this->report($ada, 5000, market: 'nowhere')->assertUnprocessable()->assertJsonValidationErrors('market_id');
    }

    public function test_twenty_reports_a_day_then_429(): void
    {
        $ada = User::factory()->create();
        foreach (range(1, 20) as $i) {
            $this->report($ada, 80000 + $i)->assertCreated();
        }
        $this->report($ada, 80000)->assertTooManyRequests();

        PriceReport::query()->update(['created_at' => now()->subHours(25)]);
        $this->report($ada, 80000)->assertCreated()->assertJsonPath('me.reportsToday', 1);
    }

    public function test_trust_rises_near_consensus_and_falls_far_from_it(): void
    {
        [$a, $b, $close, $far] = User::factory()->count(4)->create()->all();
        $this->report($a, 80000)->assertCreated();
        $this->report($b, 82000)->assertCreated();

        $this->report($close, 81000)->assertCreated()->assertJsonPath('me.trust', 1.05);
        $this->report($far, 400000)->assertCreated()->assertJsonPath('me.trust', 0.85);
    }

    public function test_trust_does_not_move_with_fewer_than_two_reporters(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $this->report($a, 80000)->assertCreated();
        $this->report($b, 400000)->assertCreated()->assertJsonPath('me.trust', 1);
    }

    public function test_votes_once_each_never_your_own(): void
    {
        [$ada, $bola] = User::factory()->count(2)->create()->all();
        $id = $this->report($ada, 80000)->json('report.id');

        $this->actingAs($ada)->postJson("/api/reports/$id/vote", ['vote' => 'confirm'])->assertForbidden();
        $this->actingAs($bola)->postJson("/api/reports/$id/vote", ['vote' => 'maybe'])->assertUnprocessable();
        $this->actingAs($bola)->postJson("/api/reports/$id/vote", ['vote' => 'confirm'])->assertCreated();
        $this->actingAs($bola)->postJson("/api/reports/$id/vote", ['vote' => 'flag'])->assertConflict();
        $this->actingAs($bola)->postJson('/api/reports/999/vote', ['vote' => 'flag'])->assertNotFound();

        $this->actingAs($bola)->getJson('/api/snapshot?area=Ibadan')
            ->assertJsonPath('reports.0.confirms', 1)
            ->assertJsonPath("myVotes.$id", 'confirm');
    }

    public function test_item_prices_use_server_consensus(): void
    {
        [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
        $this->report($a, 2500, 'tomato-basket')->assertCreated();
        $this->report($b, 2600, 'tomato-basket')->assertCreated();
        $this->report($c, 2450, 'tomato-basket')->assertCreated();
        $this->report($d, 25000, 'tomato-basket')->assertCreated();

        $res = $this->getJson('/api/items/tomato-basket/prices?area=Ibadan')->assertOk()
            ->assertJsonPath('areaPrice.outlierCount', 1)
            ->assertJsonPath('areaPrice.high', 2600);
        $this->assertContains($res->json('areaPrice.price'), [2450, 2500, 2600]);
        $this->getJson('/api/items/gold/prices?area=Ibadan')->assertNotFound();
    }
}
