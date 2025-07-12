<?php

ignore_user_abort(true);

$redis = new Redis();
$redis->pconnect('redis', 6379);

$routes = [
    'GET' => [
        '/payments-summary' => static function () use ($redis) {
            $toFloatTimestamp = function (?string $dateString): ?float {
                if (!$dateString) {
                    return null;
                }
                $date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString) ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);

                if (!$date) {
                    return null;
                }

                return (float) $date->format('U.u');
            };

            $from = $toFloatTimestamp($_GET['from'] ?? null) ?? '-inf';
            $to = $toFloatTimestamp($_GET['to'] ?? null) ?? '+inf';

            $summary = [];

            foreach (['default', 'fallback'] as $processor) {
                $cacheKey = "payments:{$processor}";
                $results = $redis->zRangeByScore($cacheKey, $from, $to);

                $totalAmountInCents = 0;
                foreach ($results as $member) {
                    $amountPart = substr($member, strpos($member, ':') + 1);
                    $totalAmountInCents += (int) $amountPart;
                }

                $summary[$processor] = [
                    'totalRequests' => count($results),
                    'totalAmount' => round($totalAmountInCents / 100, 2)
                ];
            }

            return $summary;
        },
    ],
    'POST' => [
        '/payments' => static function () use ($redis) {
            $_REQUEST = json_decode(file_get_contents('php://input'), true);

            if (
                !isset($_REQUEST['correlationId'])
                || !isset($_REQUEST['amount'])
            ) {
                http_response_code(400);
                return;
            }

            $redis->lPush('payment_jobs', json_encode([
                'correlationId' => $_REQUEST['correlationId'],
                'amount' => (float) $_REQUEST['amount'],
            ]));
        },
        '/purge-payments' => static function () use ($redis) {
            $redis->flushAll();
        },
    ],
];


$handler = static function () use ($routes) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (
        !isset($routes[$_SERVER['REQUEST_METHOD']])
        || !isset($routes[$_SERVER['REQUEST_METHOD']][$uri])
    ) {
        http_response_code(404);
        return;
    }

    header('Content-Type: application/json');

    if (!$response = $routes[$_SERVER['REQUEST_METHOD']][$uri]()) {
        return;
    }

    echo json_encode($response);
};

while (true) {
    $keepRunning = \frankenphp_handle_request($handler);

    gc_collect_cycles();

    if (!$keepRunning)
        break;
}