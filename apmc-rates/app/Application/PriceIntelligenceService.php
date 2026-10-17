<?php
declare(strict_types=1);
namespace KrushiPulse\Application;

/**
 * Read-only history/market-comparison queries. No collector runs in HTTP.
 * SQLite is private under storage/history; absence never fabricates historical rates.
 */
final class PriceIntelligenceService {
    private function connection(): ?\SQLite3 {
        $file = APMC_STORAGE . '/history/rates.sqlite';
        if (!class_exists(\SQLite3::class) || !is_file($file)) return null;
        try {
            $db = new \SQLite3($file, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(1200);
            return $db;
        } catch (\Throwable) { return null; }
    }
    private static function field($value, int $max): string {
        return trim(substr((string)$value, 0, $max));
    }
    private static function days($value): int {
        return in_array((int)$value, [7,30,90], true) ? (int)$value : 30;
    }
    private function params(array $input): ?array {
        $commodity = self::field($input['commodity'] ?? '', 160);
        $market = self::field($input['market'] ?? '', 112);
        $unit = strtolower(self::field($input['unit'] ?? '', 32));
        if ($commodity === '' || $unit === '' || !preg_match('/^[a-z0-9-]{1,112}$/D', $market)) return null;
        if (strlen($commodity) > 160 || strlen($unit) > 32) return null;
        return [
            'commodity' => $commodity, 'market' => $market, 'unit' => $unit,
            'variety' => self::field($input['variety'] ?? '', 100),
            'grade' => self::field($input['grade'] ?? '', 100),
            'days' => self::days($input['days'] ?? 30),
        ];
    }
    private static function bind(\SQLite3Stmt $stmt, array $values): void {
        foreach ($values as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    private static function rows(\SQLite3 $db, string $sql, array $values): array {
        $stmt = $db->prepare($sql);
        if (!$stmt) return [];
        try {
            self::bind($stmt, $values);
            $result = $stmt->execute();
            if (!$result) return [];
            $out = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
            $result->finalize();
            return $out;
        } finally { $stmt->close(); }
    }
    public function history(array $input): array {
        $query = $this->params($input);
        if ($query === null) return ['available' => false, 'reason' => 'Select a market, crop and original unit.', 'points' => []];
        $db = $this->connection();
        if ($db === null) return ['available' => false, 'reason' => 'Historical records are not available yet.', 'points' => []];
        try {
            $from = gmdate('Y-m-d', strtotime('-' . $query['days'] . ' days'));
            // Variety and grade stay exact. Never merge dissimilar quality into a trend.
            $list = self::rows($db, "SELECT report_date AS date, modal_price AS modal, min_price AS min,
                    max_price AS max, commodity_name AS commodity, source,
                    unit, variety, grade, market_id
                FROM prices WHERE market_id=:market AND (commodity_key=:crop OR lower(commodity_name)=lower(:crop))
                    AND unit=:unit AND variety=:variety AND grade=:grade
                    AND report_date >= :from
                ORDER BY report_date ASC LIMIT 150",
                ['market'=>$query['market'],'crop'=>$query['commodity'],
                 'unit'=>$query['unit'],'variety'=>$query['variety'],
                 'grade'=>$query['grade'],'from'=>$from]);
            return [
                'available' => count($list) > 0,
                'series_type' => 'reported_modal_price',
                'currency' => 'INR',
                'price_unit' => $query['unit'],
                'report_days' => $query['days'],
                'points' => $list,
                'has_trend' => count($list) >= 2,
                'reason' => !$list ? 'No matching reports for this mandi, variety, grade and unit.' : null,
            ];
        } catch (\Throwable) {
            return ['available'=>false,'reason'=>'Historical query unavailable.','points'=>[]];
        } finally { $db->close(); }
    }
    public function compare(array $input): array {
        $query = $this->params($input);
        if ($query === null) return ['available'=>false,'reason'=>'Select a crop, market and original unit.','markets'=>[]];
        $db = $this->connection();
        if ($db === null) return ['available'=>false,'reason'=>'Comparison history not available yet.','markets'=>[]];
        try {
            $from = gmdate('Y-m-d', strtotime('-' . $query['days'] . ' days'));
            // One latest reported observation per mandi in a strictly equal
            // commodity/variety/grade/unit/source group, not a mixing of grades.
            $list = self::rows($db, "SELECT p.market_id,p.source,p.commodity_name AS commodity,p.variety,p.grade,
                    p.unit,p.report_date AS date,p.modal_price AS modal,p.min_price AS min,p.max_price AS max
                FROM prices p
                WHERE (p.commodity_key=:crop OR lower(p.commodity_name)=lower(:crop))
                  AND p.variety=:variety AND p.grade=:grade AND p.unit=:unit
                  AND p.report_date>=:from
                  AND p.report_date=(SELECT MAX(p2.report_date) FROM prices p2
                    WHERE p2.market_id=p.market_id AND p2.source=p.source
                    AND p2.commodity_key=p.commodity_key AND p2.variety=p.variety
                    AND p2.grade=p.grade AND p2.unit=p.unit AND p2.report_date>=:from)
                ORDER BY p.report_date DESC,p.modal_price DESC LIMIT 300",
                ['crop'=>$query['commodity'],'variety'=>$query['variety'],'grade'=>$query['grade'],
                 'unit'=>$query['unit'],'from'=>$from]);
            $unique = [];
            foreach ($list as $row) {
                $id = (string)$row['market_id'];
                if (!isset($unique[$id])) $unique[$id] = $row;
            }
            // Not advertised as a "best market" ranking: transportation,
            // fees, availability and real transactions are not included.
            uasort($unique,static fn($a,$b)=>(float)$b['modal']<=>(float)$a['modal']);
            return ['available'=>count($unique)>0,'markets'=>array_slice(array_values($unique),0,25),
                    'count'=>count($unique),'price_unit'=>$query['unit'],'report_days'=>$query['days'],
                    'method'=>'Latest reported modal quotation per mandi; not a guaranteed transaction price.',
                    'reason'=>!$unique?'No comparable reports for the same variety, grade and unit.':null];
        } catch (\Throwable) {
            return ['available'=>false,'reason'=>'Comparison query unavailable.','markets'=>[]];
        } finally { $db->close(); }
    }
}
