<?php
declare(strict_types=1);
namespace KrushiPulse\Application;

/**
 * Source-attributed weather advisory adapter.
 * Authorized provider imports may publish a vetted snapshot to PRIVATE storage.
 * No GPS or third-party weather requests in an HTTP request.
 */
final class WeatherAdvisoryService {
    public function latest(): array {
        $file = APMC_STORAGE . '/weather/latest.json';
        $snapshot = apmc_json_read($file, []);
        $required = ['source','location','issued_at','summary','source_url'];
        foreach ($required as $field) {
            if (!is_string($snapshot[$field] ?? null) || trim($snapshot[$field]) === '') {
                return ['available'=>false,'status'=>'not_configured','reason'=>'No authorized weather provider is configured.','source_url'=>'https://mausam.imd.gov.in/'];
            }
        }
        $url = (string)$snapshot['source_url'];
        if (!preg_match('~^https://[a-z0-9.-]+(?:/[^\\s]*)?$~i', $url)) {
            return ['available'=>false,'status'=>'invalid_source','reason'=>'Weather source verification failed.'];
        }
        $date = strtotime((string)$snapshot['issued_at']);
        if ($date === false || $date > time()+3600 || time()-$date > 12*3600) {
            return ['available'=>false,'status'=>'stale','reason'=>'Weather advisory is older than 12 hours.','issued_at'=>$snapshot['issued_at']];
        }
        return [
            'available'=>true, 'status'=>'ready',
            'source'=>substr((string)$snapshot['source'],0,100),
            'source_url'=>$url,
            'location'=>substr((string)$snapshot['location'],0,120),
            'issued_at'=>gmdate(DATE_ATOM,$date),
            'summary'=>mb_substr((string)$snapshot['summary'],0,600),
            'warning'=>mb_substr((string)($snapshot['warning'] ?? ''),0,400),
        ];
    }
}
