<?php

echo "Health Checker Worker started.\n";

$redis = new Redis();
$redis->pconnect('redis', 6379);

$multiHandler = curl_multi_init();

$defaultHealthCheck = curl_init('http://payment-processor-default:8080/payments/service-health');
curl_setopt($defaultHealthCheck, CURLOPT_RETURNTRANSFER, true);
curl_setopt($defaultHealthCheck, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($defaultHealthCheck, CURLOPT_TIMEOUT, 4);
curl_multi_add_handle($multiHandler, $defaultHealthCheck);

$fallbackHealthCheck = curl_init('http://payment-processor-fallback:8080/payments/service-health');
curl_setopt($fallbackHealthCheck, CURLOPT_RETURNTRANSFER, true);
curl_setopt($fallbackHealthCheck, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($fallbackHealthCheck, CURLOPT_TIMEOUT, 4);
curl_multi_add_handle($multiHandler, $fallbackHealthCheck);

while (true) {
    try {
        echo "Checking processor statuses...\n";

        $running = null;

        do {
            curl_multi_exec($multiHandler, $running);
            if ($running) {
                curl_multi_select($multiHandler);
            }
        } while ($running > 0);

        $defaultResult = curl_multi_getcontent($defaultHealthCheck);
        $fallbackResult = curl_multi_getcontent($fallbackHealthCheck);

        $results = [
            'default' => $defaultResult ? json_decode($defaultResult, true) : ['failing' => true, 'minResponseTime' => 9999],
            'fallback' => $fallbackResult ? json_decode($fallbackResult, true) : ['failing' => true, 'minResponseTime' => 9999],
        ];

        $encodedResults = json_encode($results);

        $redis->set('gateway-statuses', $encodedResults);

        echo "Gateway statuses updated in Redis: " . $encodedResults . "\n";

        sleep(5);
    } catch (\RedisException $e) {
        echo "Redis connection error: {$e->getMessage()}. Reconnecting...\n";
        $redis = new Redis();
        $redis->pconnect('redis', 6379);
        sleep(5);
    }
}

curl_multi_remove_handle($multiHandler, $defaultHealthCheck);
curl_close($defaultHealthCheck);
curl_multi_remove_handle($multiHandler, $fallbackHealthCheck);
curl_close($fallbackHealthCheck);
curl_multi_close($multiHandler);