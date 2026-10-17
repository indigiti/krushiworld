<?php
declare(strict_types=1);
namespace KrushiPulse\Application;

/**
 * Transparent observations only; not ML predictions, trading signals or promises.
 * Calculates signed historical modal-price movement from distinct report dates.
 */
final class ExplainableInsightService {
    public function __construct(private PriceIntelligenceService $history) {}

    public function explain(array $input): array {
        $history = $this->history->history($input);
        $points = (array)($history['points'] ?? []);
        if (!($history['available'] ?? false)) {
            return ['available'=>false,'reason'=>$history['reason'] ?? 'No verified price history.',
                'observations'=>0,'evidence'=>[], 'is_prediction'=>false];
        }
        $byDate = [];
        foreach ($points as $row) {
            if (!is_array($row)) continue;
            $date = (string)($row['date'] ?? '');
            $modal = $row['modal'] ?? null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !is_numeric($modal) || !is_finite((float)$modal) || (float)$modal < 0) continue;
            // Multiple sources/varieties on a date must not silently be averaged.
            // Choose one deterministic source per date while exposing that choice.
            if (!isset($byDate[$date])) $byDate[$date] = $row;
        }
        ksort($byDate);
        $samples = array_values($byDate);
        if (count($samples) < 2) {
            return [
                'available'=>false,
                'reason'=>'At least two distinct published report dates are required.',
                'observations'=>count($samples), 'evidence'=>$samples, 'is_prediction'=>false,
            ];
        }
        $first = $samples[0];
        $last = $samples[count($samples)-1];
        $firstPrice = (float)$first['modal'];
        $lastPrice = (float)$last['modal'];
        $absolute = round($lastPrice - $firstPrice, 2);
        $percent = $firstPrice > 0 ? round(100 * $absolute/$firstPrice, 2) : null;
        $values = array_map(static fn($r) => (float)$r['modal'], $samples);
        $ages = (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata')))
            ->diff(new \DateTimeImmutable((string)$last['date'], new \DateTimeZone('Asia/Kolkata')))->days;
        $stale = $ages === false || $ages > 3;
        $confidence = count($samples) >= 10 ? 'well_sampled'
            : (count($samples) >= 5 ? 'moderate_sample' : 'limited_sample');
        return [
            'available'=>true,
            'method'=>'Compare the first and last published modal prices for the same market, crop, variety, grade and original unit.',
            'observation_quality'=>$confidence,
            'is_prediction'=>false, 'should_not_be_used_as_guaranteed_sale_price'=>true,
            'is_stale'=>$stale,'age_days'=>$ages,'observations'=>count($samples),
            'direction'=>$absolute > 0 ? 'up' : ($absolute < 0 ? 'down' : 'unchanged'),
            'change_inr'=>$absolute,'change_pct'=>$percent,
            'low_modal'=>min($values),'high_modal'=>max($values),
            'first_report_date'=>$first['date'],'last_report_date'=>$last['date'],
            'first_modal'=>$firstPrice,'last_modal'=>$lastPrice,
            'unit'=>$last['unit'] ?? null,'variety'=>$last['variety'] ?? '',
            'grade'=>$last['grade'] ?? '',
            'source'=>$last['source'] ?? null,
            'limitations'=>[
                'Published wholesale quotations, not confirmed trades.',
                'Variety, grade, unit and market must match.',
                'Observation gaps may be substantial; missing days are not interpolated.',
                'No future-price prediction or buy/sell recommendation.',
            ],
        ];
    }
}
