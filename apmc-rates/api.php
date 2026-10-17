<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
require __DIR__ . '/src.php';
try {
    $category=(string)($_GET['category'] ?? 'main-fruit');
    $date=apmc_date(isset($_GET['date']) ? (string)$_GET['date'] : null);
    $requested=$date;
    $stale=false;
    try { $data=apmc_snapshot($category,$requested); }
    catch (RuntimeException $exception) {
        $data=apmc_latest($category,$requested);
        if (!$data) throw $exception;
        $stale=true;
    }
    $data['stale']=$stale || $data['date'] !== $requested;
    $data['requested_date']=$requested;
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error'=>'Market prices are temporarily unavailable. No verified snapshot available.']);
}
