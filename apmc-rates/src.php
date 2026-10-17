<?php
declare(strict_types=1);
/**
 * KrushiWorld APMC Rates — Pune APMC official HTML collector.
 * CLI: php apmc-rates/refresh.php [YYYY-MM-DD]
 * HTTP: /apmc-rates/api.php?category=main-fruit&date=YYYY-MM-DD
 */
const APMC_CATEGORIES = [
    'main-veg' => 'Vegetables / भाजीपाला',
    'main-fruit' => 'Fruits / फळे',
    'main-gul-bhusar-dhanya' => 'Grains / भुसार धान्य',
    'keli-paan' => 'Banana leaves / केळी पान',
];
function apmc_date(?string $date): string {
    $date = $date ?: date('Y-m-d');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
    if (!$d || $d->format('Y-m-d') !== $date || $date > (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
        throw new InvalidArgumentException('Invalid date');
    }
    return $date;
}
function apmc_storage(): string {
    $override = getenv('APMC_DATA_DIR');
    $directory = $override ?: dirname(__DIR__, 2) . '/krushiworld-apmc-data';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Storage unavailable');
    return $directory;
}
function apmc_fetch(string $category, string $date): string {
    if (!array_key_exists($category, APMC_CATEGORIES)) throw new InvalidArgumentException('Unsupported category');
    $url = 'https://apmcpune.in/bajarbhav/view-daily-bajarbhav/' . $category . '/' . $date;
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 15, 'follow_location' => 0,
        'header' => "User-Agent: KrushiWorld-APMC/1.0\r\nAccept: text/html\r\n"
    ]]);
    $html = @file_get_contents($url, false, $context, 0, 1500000);
    $status = $http_response_header[0] ?? '';
    if ($html === false || !preg_match('~^HTTP/\\S+ 200\\b~', $status)) throw new RuntimeException('Official source temporarily unavailable');
    return $html;
}
function apmc_parse(string $html): array {
    if (!class_exists('DOMDocument') || !function_exists('mb_strtolower')) throw new RuntimeException('PHP DOM extension is required to parse official tables');
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);
    $results = [];
    foreach ($xpath->query('//table') as $table) {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $tr) as $td) $cells[] = trim(preg_replace('/\\s+/u', ' ', $td->textContent));
            if ($cells) $rows[] = $cells;
        }
        if (count($rows) < 2) continue;
        $headerAt = -1;
        $index = [];
        foreach (array_slice($rows, 0, 6) as $i => $row) {
            $normalized = array_map(static fn($x) => mb_strtolower($x, 'UTF-8'), $row);
            foreach ($normalized as $j => $s) {
                if (preg_match('/कमाल|जास्तीत जास्त|maximum|max(?:imum)?\\s*(?:price|rate)/u', $s)) $index['max']=$j;
                elseif (preg_match('/किमान|कमीत कमी|minimum|min(?:imum)?\\s*(?:price|rate)/u', $s)) $index['min']=$j;
                elseif (preg_match('/सरासरी|सर्वसाधारण|modal|average|avg/u', $s)) $index['modal']=$j;
                elseif (preg_match('/शेतमाल|मालाचे नाव|commodity|produce|item|वस्तू/u', $s)) $index['commodity']=$j;
                elseif (preg_match('/आवक|arrival|quantity/u', $s)) $index['arrivals']=$j;
                elseif (preg_match('/जात|variety|प्रत/u', $s)) $index['variety']=$j;
            }
            if (isset($index['commodity']) && (isset($index['min']) || isset($index['max']))) { $headerAt=$i; break; }
            $index = [];
        }
        if ($headerAt < 0) continue;
        foreach (array_slice($rows, $headerAt + 1) as $row) {
            $get = static fn($name) => isset($index[$name]) ? trim($row[$index[$name]] ?? '') : '';
            $name = $get('commodity');
            if ($name === '' || preg_match('/एकूण|total/u', $name)) continue;
            $num = static function ($s) {
                $s = str_replace(',', '', $s);
                return preg_match('/^-?\\d+(?:\\.\\d+)?$/', $s) ? (float)$s : null;
            };
            $min=$num($get('min')); $max=$num($get('max')); $modal=$num($get('modal'));
            if ($min===null && $max===null && $modal===null) continue;
            $results[] = ['commodity'=>$name,'variety'=>$get('variety'),'min'=>$min,'max'=>$max,'modal'=>$modal,'arrivals'=>$get('arrivals')];
        }
    }
    return $results;
}
function apmc_snapshot(string $category, string $date, bool $refresh = false): array {
    if (!array_key_exists($category, APMC_CATEGORIES)) throw new InvalidArgumentException('Unsupported category');
    $date = apmc_date($date);
    $directory = apmc_storage() . '/' . $category;
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Storage unavailable');
    $path = $directory . '/' . $date . '.json';
    if (!$refresh && is_file($path)) {
        $cached = json_decode((string)file_get_contents($path), true);
        if (is_array($cached)) return $cached + ['cached'=>true];
    }
    $items=apmc_parse(apmc_fetch($category, $date));
    if (!$items) throw new RuntimeException('No verified rows in official report; previous data retained');
    $snapshot=['source'=>'Pune APMC','source_url'=>'https://apmcpune.in/bajarbhav/view-daily-bajarbhav/'.$category.'/'.$date,
        'category'=>$category,'date'=>$date,'fetched_at'=>gmdate('c'),'unit'=>'As published by Pune APMC; verify report header', 'rows'=>$items];
    $tmp=tempnam($directory, 'pending-');
    if ($tmp===false || file_put_contents($tmp, json_encode($snapshot, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX)===false) throw new RuntimeException('Cannot persist snapshot');
    chmod($tmp, 0640);
    if (!rename($tmp, $path)) throw new RuntimeException('Cannot finalize snapshot');
    return $snapshot;
}
function apmc_latest(string $category, string $date): ?array {
    $directory=apmc_storage().'/'.$category;
    if (!is_dir($directory)) return null;
    foreach (array_reverse(glob($directory.'/????-??-??.json') ?: []) as $path) {
        if (basename($path, '.json') > $date) continue;
        $data=json_decode((string)file_get_contents($path),true);
        if (is_array($data) && isset($data['date'],$data['rows'])) return $data;
    }
    return null;
}
