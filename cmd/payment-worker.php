<?php

$redis = new Redis();
$redis->pconnect('redis', 6379);

$paymentCurlHandle = curl_init();

curl_setopt_array($paymentCurlHandle, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);

function sendPaymentRequest($ch, $processor, $body): bool
{
    curl_setopt($ch, CURLOPT_URL, "http://payment-processor-{$processor}:8080/payments");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $httpCode >= 200 && $httpCode < 300;
}

echo "Payment Worker started. Listening for payment jobs...\n";

while (true) {
    try {
        $job = $redis->brPop(['payment_jobs'], 0);
        if (!$job || !isset($job[1]))
            continue;

        $payload = json_decode($job[1], true);
        $correlationId = $payload['correlationId'];
        $amountInCents = $payload['amountInCents'];

        if ($redis->exists($correlationId)) {
            continue;
        }

        $statusJson = $redis->get('gateway-statuses');
        $statuses = $statusJson ? json_decode($statusJson, true) : null;

        $processor = 'fallback';

        if ($statuses) {
            $isDefaultFailing = $statuses['default']['failing'] ?? true;
            $defaultTime = $statuses['default']['minResponseTime'] ?? 9999;
            $fallbackTime = $statuses['fallback']['minResponseTime'] ?? 0;

            if (!$isDefaultFailing && $defaultTime <= $fallbackTime * 3) {
                $processor = 'default';
            }
        }

        $preciseTimestamp = microtime(true);
        $date = DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
        $requestedAtString = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

        $body = [
            'amount' => $amountInCents / 100,
            'correlationId' => $correlationId,
            'requestedAt' => $requestedAtString,
        ];

        $success = sendPaymentRequest($paymentCurlHandle, $processor, $body);

        if (!$success) {
            $processor = ($processor === 'default') ? 'fallback' : 'default';
            $success = sendPaymentRequest($paymentCurlHandle, $processor, $body);
        }

        if (!$success) {
            $redis->lPush('payment_jobs', $job[1]);
            continue;
        }

        $redis->set($correlationId, 1);

        $redis->zAdd(
            'payments:' . $processor,
            $preciseTimestamp,
            $correlationId . ':' . $amountInCents
        );

    } catch (\RedisException $e) {
        $redis = new Redis();
        $redis->pconnect('redis', 6379);
    }
}

curl_close($paymentCurlHandle);