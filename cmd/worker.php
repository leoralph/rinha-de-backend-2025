<?php

$redis = new Redis();
$redis->pconnect('redis', 6379);

echo "Worker started. Listening for payment jobs...\n";

function sendPaymentRequest($processor, $body): bool
{
    $ch = curl_init("http://payment-processor-{$processor}:8080/payments");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Resposta do processador '{$processor}': {$response}\n";

    return $httpCode >= 200 && $httpCode < 300;
}

function getProcessorStatuses(Redis $redis): array
{
    if (!$results = $redis->get('gateway-statuses')) {
        $multiHandler = curl_multi_init();

        $defaultHealthCheck = curl_init('http://payment-processor-default:8080/payments/service-health');
        curl_setopt($defaultHealthCheck, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($multiHandler, $defaultHealthCheck);

        $fallbackHealthCheck = curl_init('http://payment-processor-fallback:8080/payments/service-health');
        curl_setopt($fallbackHealthCheck, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($multiHandler, $fallbackHealthCheck);

        $running = null;

        do {
            curl_multi_exec($multiHandler, $running);
            if ($running) {
                curl_multi_select($multiHandler);
            }
        } while ($running > 0);

        $results = [
            'default' => json_decode(curl_multi_getcontent($defaultHealthCheck), true),
            'fallback' => json_decode(curl_multi_getcontent($fallbackHealthCheck), true),
        ];

        curl_multi_remove_handle($multiHandler, $defaultHealthCheck);
        curl_close($defaultHealthCheck);
        curl_multi_remove_handle($multiHandler, $fallbackHealthCheck);
        curl_close($fallbackHealthCheck);
        curl_multi_close($multiHandler);

        $redis->setex('gateway-statuses', 5, json_encode($results));
    } else {
        $results = json_decode($results, true);
    }

    return $results;
}

while (true) {
    try {
        $job = $redis->brPop(['payment_jobs'], 0);
        if (!$job || !isset($job[1])) {
            continue;
        }

        $payload = json_decode($job[1], true);
        $correlationId = $payload['correlationId'];

        if ($redis->exists($correlationId)) {
            continue;
        }

        $statuses = getProcessorStatuses($redis);
        $initialProcessor = 'default';
        if (
            $statuses['default']['failing']
            || $statuses['default']['minResponseTime'] > $statuses['fallback']['minResponseTime'] * 3
        ) {
            $initialProcessor = 'fallback';
        }

        $preciseTimestamp = microtime(true);
        $date = DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
        $requestedAtString = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

        $body = [
            'amount' => $payload['amount'],
            'correlationId' => $correlationId,
            'requestedAt' => $requestedAtString, // Envia a string de alta precisão
        ];

        $success = sendPaymentRequest($initialProcessor, $body);
        $finalProcessor = $initialProcessor;

        if (!$success) {
            $fallbackProcessor = ($initialProcessor === 'default') ? 'fallback' : 'default';
            $success = sendPaymentRequest($fallbackProcessor, $body);
            if ($success) {
                $finalProcessor = $fallbackProcessor;
            }
        }

        if (!$success) {
            $redis->lPush('payment_jobs', $job[1]);
            continue;
        }

        $redis->setex($correlationId, 86400, 1);

        $redis->zAdd(
            'payments:' . $finalProcessor,
            $preciseTimestamp, // << USA O FLOAT PRECISO
            $correlationId . ':' . $payload['amount'] * 100
        );
    } catch (\RedisException $e) {
        echo "Redis connection error: " . $e->getMessage() . "\n";
        $redis = new Redis();
        $redis->pconnect('redis', 6379);
    }
}