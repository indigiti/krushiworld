<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
date_default_timezone_set('Asia/Kolkata');
require __DIR__.'/src.php';
$date=apmc_date($argv[1] ?? null);
$errors=0;
foreach (array_keys(APMC_CATEGORIES) as $category) {
    try {
        $result=apmc_snapshot($category,$date,true);
        echo $category.' '.$result['date'].' '.count($result['rows'])." rows\n";
    } catch (Throwable $error) {
        $errors++;
        fwrite(STDERR,$category.': '.$error->getMessage()."\n");
    }
}
exit($errors ? 1 : 0);
