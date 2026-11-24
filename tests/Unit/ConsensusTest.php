<?php

namespace Tests\Unit;

use App\Services\Consensus;
use PHPUnit\Framework\TestCase;

/** Mirrors the app's __tests__/consensus.test.ts case for case. */
class ConsensusTest extends TestCase
{
    private const DAY = 86_400_000;

    private const NOW = 1_795_521_600_000; // 2026-11-18 12:00 UTC

    private int $n = 0;

    private function report(int $price, ?string $userId = null, float $ageDays = 0, array $extra = []): array
    {
        return array_merge([
            'userId' => $userId ?? 'u'.$this->n++,
            'price' => $price,
            'createdAt' => (int) (self::NOW - $ageDays * self::DAY),
            'confirms' => 0,
            'flags' => 0,
        ], $extra);
    }

    private function trust1(): callable
    {
        return fn () => 1.0;
    }

    public function test_nothing_when_there_are_no_recent_reports(): void
    {
        $this->assertNull(Consensus::of([], $this->trust1(), self::NOW));
        $this->assertNull(Consensus::of([$this->report(80000, 'a', Consensus::WINDOW_DAYS + 1)], $this->trust1(), self::NOW));
    }

    public function test_ignores_zero_or_negative_prices(): void
    {
        $this->assertNull(Consensus::of([$this->report(0), $this->report(-5)], $this->trust1(), self::NOW));
    }

    public function test_a_typo_cannot_drag_the_price(): void
    {
        $c = Consensus::of([$this->report(2500), $this->report(2600), $this->report(2450), $this->report(25000)], $this->trust1(), self::NOW);
        $this->assertSame([25000], array_column($c['outliers'], 'price'));
        $this->assertGreaterThanOrEqual(2450, $c['price']);
        $this->assertLessThanOrEqual(2600, $c['price']);
        $this->assertSame(2600, $c['high']);
    }

    public function test_high_only_when_three_people_agree_recently_and_tightly(): void
    {
        $c = Consensus::of([$this->report(80000, 'a'), $this->report(82000, 'b'), $this->report(81000, 'c', 1)], $this->trust1(), self::NOW);
        $this->assertSame(3, $c['reporters']);
        $this->assertSame('high', $c['confidence']);
    }

    public function test_one_person_posting_many_times_is_one_reporter(): void
    {
        $spam = array_map(fn () => $this->report(80000, 'spam'), range(1, 4));
        $c = Consensus::of($spam, $this->trust1(), self::NOW);
        $this->assertSame(1, $c['reporters']);
        $this->assertSame('low', $c['confidence']);
    }

    public function test_stale_reports_lower_the_confidence(): void
    {
        $c = Consensus::of([$this->report(80000, 'a', 5), $this->report(81000, 'b', 6), $this->report(80500, 'c', 5)], $this->trust1(), self::NOW);
        $this->assertSame('medium', $c['confidence']);
    }

    public function test_newer_and_more_trusted_reports_win(): void
    {
        $recent = Consensus::of([$this->report(1000, 'a', 0), $this->report(1400, 'b', 10)], $this->trust1(), self::NOW);
        $this->assertSame(1000, $recent['price']);

        $trusted = Consensus::of([$this->report(1000, 'low'), $this->report(1400, 'high')], fn ($u) => $u === 'high' ? 2.0 : 0.2, self::NOW);
        $this->assertSame(1400, $trusted['price']);
    }

    public function test_report_weight_halves_every_three_days_and_follows_votes(): void
    {
        $this->assertEqualsWithDelta(0.5, Consensus::reportWeight($this->report(1, 'a', 3), 1, self::NOW), 1e-9);
        $base = Consensus::reportWeight($this->report(1), 1, self::NOW);
        $this->assertGreaterThan($base, Consensus::reportWeight($this->report(1, 'a', 0, ['confirms' => 4]), 1, self::NOW));
        $this->assertGreaterThan(0, Consensus::reportWeight($this->report(1, 'a', 0, ['flags' => 10]), 1, self::NOW));
    }

    public function test_trust_grows_and_drops_within_bounds(): void
    {
        $this->assertEqualsWithDelta(1.05, Consensus::nextTrust(1, 1020, 1000), 1e-9);
        $this->assertEqualsWithDelta(1.0, Consensus::nextTrust(1, 1200, 1000), 1e-9);
        $this->assertEqualsWithDelta(0.85, Consensus::nextTrust(1, 3000, 1000), 1e-9);
        $this->assertEqualsWithDelta(2.0, Consensus::nextTrust(2, 1000, 1000), 1e-9);
        $this->assertEqualsWithDelta(0.2, Consensus::nextTrust(0.2, 9000, 1000), 1e-9);
    }
}
