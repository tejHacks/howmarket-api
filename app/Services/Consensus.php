<?php

namespace App\Services;

/**
 * Price consensus: how HowMarket keeps prices honest. A line-for-line port of the
 * app's src/lib/consensus.ts, so the server and the app always agree.
 *
 * Nobody's single report sets a price. For each item in each market:
 *   1. Only recent reports count (last WINDOW_DAYS), and newer ones count more.
 *   2. Reports are weighted by the reporter's trust, and votes adjust each weight.
 *   3. Outliers are dropped with a robust test (median absolute deviation).
 *   4. The published price is the weighted median of what's left.
 *   5. Confidence depends on how many different people agree, how fresh the
 *      reports are, and how tight the spread is.
 *   6. Reporters close to consensus gain trust; repeat outliers lose it.
 *
 * A report is an array: ['userId' => string, 'price' => int, 'createdAt' => int (ms),
 * 'confirms' => int, 'flags' => int].
 */
class Consensus
{
    public const WINDOW_DAYS = 14;

    public const HALF_LIFE_DAYS = 3;

    public const DAILY_REPORT_LIMIT = 20;

    private const DAY = 86_400_000;

    /** Weight of one report: recency × reporter trust × community votes. */
    public static function reportWeight(array $r, float $trust, int $now): float
    {
        $ageDays = ($now - $r['createdAt']) / self::DAY;
        $recency = 0.5 ** ($ageDays / self::HALF_LIFE_DAYS);
        $votes = max(0.1, 1 + 0.25 * $r['confirms'] - 0.5 * $r['flags']);

        return $recency * max(0.1, $trust) * $votes;
    }

    /**
     * @param  array<int, array>  $reports
     * @param  callable(string): float  $trustOf
     * @return array{price:int, low:int, high:int, confidence:string, reporters:int, used:array, outliers:array, updatedAt:int}|null
     */
    public static function of(array $reports, callable $trustOf, int $now): ?array
    {
        $recent = array_values(array_filter(
            $reports,
            fn ($r) => $now - $r['createdAt'] <= self::WINDOW_DAYS * self::DAY && $r['price'] > 0,
        ));
        if ($recent === []) {
            return null;
        }

        [$used, $outliers] = count($recent) >= 3 ? self::splitOutliers($recent) : [$recent, []];

        $price = self::weightedMedian(array_map(
            fn ($r) => ['value' => $r['price'], 'weight' => self::reportWeight($r, $trustOf($r['userId']), $now)],
            $used,
        ));
        $prices = array_column($used, 'price');
        $reporters = count(array_unique(array_column($used, 'userId')));
        $newest = max(array_column($used, 'createdAt'));
        $spread = (max($prices) - min($prices)) / $price;
        $fresh = $now - $newest <= 3 * self::DAY;

        $confidence = ($reporters >= 3 && $fresh && $spread <= 0.25) ? 'high'
            : (($reporters >= 2 && $spread <= 0.5) ? 'medium' : 'low');

        return [
            'price' => (int) round($price),
            'low' => min($prices),
            'high' => max($prices),
            'confidence' => $confidence,
            'reporters' => $reporters,
            'used' => $used,
            'outliers' => $outliers,
            'updatedAt' => $newest,
        ];
    }

    /** Trust after a report is scored against consensus. Clamped to 0.2–2. */
    public static function nextTrust(float $trust, int $reportPrice, int $consensusPrice): float
    {
        $err = abs($reportPrice - $consensusPrice) / $consensusPrice;
        $delta = $err <= 0.1 ? 0.05 : ($err <= 0.25 ? 0 : ($err <= 0.5 ? -0.05 : -0.15));

        return round(min(2, max(0.2, $trust + $delta)), 2);
    }

    private static function median(array $xs): float
    {
        sort($xs);
        $n = count($xs);
        $m = intdiv($n, 2);

        return $n % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
    }

    /** @param array<int, array{value: float|int, weight: float}> $points */
    private static function weightedMedian(array $points): float
    {
        usort($points, fn ($a, $b) => $a['value'] <=> $b['value']);
        $total = array_sum(array_column($points, 'weight'));
        $acc = 0.0;
        foreach ($points as $p) {
            $acc += $p['weight'];
            if ($acc >= $total / 2) {
                return $p['value'];
            }
        }

        return end($points)['value'];
    }

    /** Robust outlier test: modified z-score from the median absolute deviation. */
    private static function splitOutliers(array $reports): array
    {
        $m = self::median(array_column($reports, 'price'));
        $mad = self::median(array_map(fn ($r) => abs($r['price'] - $m), $reports)) ?: $m * 0.05;
        $used = [];
        $outliers = [];
        foreach ($reports as $r) {
            $z = (0.6745 * abs($r['price'] - $m)) / $mad;
            if ($z > 3.5 || $r['price'] > $m * 2 || $r['price'] < $m / 2) {
                $outliers[] = $r;
            } else {
                $used[] = $r;
            }
        }

        return [$used, $outliers];
    }
}
