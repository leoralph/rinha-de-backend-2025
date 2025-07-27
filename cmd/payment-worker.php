<?php

pcntl_async_signals(true);

$numWorkers = (int) $_ENV['WORKER_COUNT'] ?? 1;
$children = [];

echo "[Gerente] Iniciando o gerenciador de workers ($numWorkers).\n";

function sendPaymentRequest($ch, $processor, $body): bool
{
    curl_setopt($ch, CURLOPT_URL, "http://payment-processor-{$processor}:8080/payments");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $httpCode >= 200 && $httpCode < 300;
}


function run_worker_logic()
{
    $redis = new Redis();
    $redis->pconnect('redis', 6379);

    $paymentCurlHandle = curl_init();

    curl_setopt_array($paymentCurlHandle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

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

                if (!$isDefaultFailing && $defaultTime <= $fallbackTime * 2) {
                    $processor = 'default';
                }
            }

            $preciseTimestamp = microtime(true);

            $body = [
                'amount' => $amountInCents / 100,
                'correlationId' => $correlationId,
                'requestedAt' => DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp))
                    ->format('Y-m-d\TH:i:s.v\Z'),
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
}

function handle_shutdown_signal(int $signal)
{
    global $children;

    foreach ($children as $pid) {
        posix_kill($pid, SIGTERM);
    }

    while (pcntl_wait($status) > 0)
        ;

    exit(0);
}

pcntl_signal(SIGTERM, 'handle_shutdown_signal');
pcntl_signal(SIGINT, 'handle_shutdown_signal');
pcntl_signal(SIGHUP, 'handle_shutdown_signal');

for ($i = 1; $i <= $numWorkers; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        die;
    }

    if ($pid) {
        $children[$pid] = $pid;
    } else {
        run_worker_logic();
        exit;
    }
}

while (count($children) > 0) {
    $exitedPid = pcntl_wait($status);

    if ($exitedPid > 0) {
        unset($children[$exitedPid]);

        $newPid = pcntl_fork();

        if ($newPid === -1) {
            die;
        }

        if ($newPid) {
            $children[$newPid] = $newPid;
        } else {
            run_worker_logic();
            exit;
        }
    }
}

curl_close($paymentCurlHandle);