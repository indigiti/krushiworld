<?php
/**
 * Backward-compatible simple APMC endpoint from KrushiWorld's first prototype.
 * This is a read-only adapter over the v1.4 collector cache and archive.
 * Preferred full API remains public/index.php?api=latest / api=query.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
apmc_apply_security_headers(false);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
try {
    $category = (string)($_GET['category'] ?? 'main-fruit');
    $cfg = apmc_json_read(__DIR__ . '/collector/config.json');
    $supported = array_values(array_filter(array_map(static fn($x) => $x['slug'] ?? null, $cfg['markets'] ?? [])));
    if (!in_array($category, $supported, true)) throw new InvalidArgumentException('Unsupported category');

    $requested = (string)($_GET['date'] ?? date('Y-m-d'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requested, new DateTimeZone('Asia/Kolkata'));
    if (!$date || $date->format('Y-m-d') !== $requested || $requested > date('Y-m-d')) {
        throw new InvalidArgumentException('Invalid or future date');
    }

    $snapshot = [];
    for ($step = 0; $step <= 30; ++$step) {
        $candidate = $date->modify('-' . $step . ' days');
        $archive = APMC_STORAGE . '/daily/' . $candidate->format('Y/m/d') . '/' . $category . '.json';
        $data = apmc_json_read($archive);
        if (!empty($data['records'])) { $snapshot = $data; break; }
    }
    if (!$snapshot) {
        $current = (new RateRepository())->market($category);
        if (!empty($current['records']) && isset($current['date']) && $current['date'] <= $requested) {
            $snapshot = $current;
        }
    }
    if (!$snapshot || empty($snapshot['records'])) {
        http_response_code(503);
        echo json_encode(['error' => 'No verified historical rates for this date/category.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $actualDate = (string)($snapshot['date'] ?? '');
    $rows = array_map(static fn(array $r): array => [
        'commodity' => (string)($r['name_mr'] ?? $r['name_en'] ?? ''),
        'commodity_en' => (string)($r['name_en'] ?? ''),
        'variety' => (string)($r['variety'] ?? ''),
        'min' => $r['min'] ?? null,
        'max' => $r['max'] ?? null,
        'modal' => $r['average'] ?? null,
        'unit' => $r['unit'] ?? null,
        'arrivals' => $r['arrival'] ?? null,
        'report_date' => $r['date'] ?? $actualDate,
    ], $snapshot['records']);
    echo json_encode([
        'source' => 'Pune APMC',
        'source_url' => $snapshot['source_url'] ?? null,
        'category' => $category,
        'date' => $actualDate,
        'requested_date' => $requested,
        'stale' => $actualDate !== $requested,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Rates temporarily unavailable.'], JSON_UNESCAPED_UNICODE);
}
