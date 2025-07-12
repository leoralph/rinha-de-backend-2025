<?php

ignore_user_abort(true);

$redis = new Redis();
$redis->pconnect('redis', 6379);

$routes = [
    'GET' => [
        '/payments-summary' => static function () use ($redis) {
            $fromTimestamp = isset($_GET['from']) && !empty($_GET['from'])
                ? strtotime($_GET['from'])
                : null;

            // Faz o mesmo para o parâmetro 'to'.
            $toTimestamp = isset($_GET['to']) && !empty($_GET['to'])
                ? strtotime($_GET['to'])
                : null;

            // --- Sua Lógica (já está correta) ---
        
            $summary = [];

            // Define os limites para a busca no Redis. Se os timestamps forem null,
            // usa '-inf' e '+inf' para buscar tudo.
            $from = $fromTimestamp ?? '-inf';
            $to = $toTimestamp ?? '+inf';

            foreach (['default', 'fallback'] as $processor) {
                $cacheKey = "payments:{$processor}";

                // zRangeByScore busca os membros dentro do intervalo de scores (timestamps).
                // Isto é feito no servidor Redis, economizando muita memória na sua aplicação PHP.
                $results = $redis->zRangeByScore($cacheKey, $from, $to);

                $totalAmount = 0.0;
                foreach ($results as $member) {
                    // Extrai o valor do membro "correlationId:amount"
                    $amountPart = substr($member, strpos($member, ':') + 1);
                    $totalAmount += (float) $amountPart;
                }

                $summary[$processor] = [
                    'totalRequests' => count($results),
                    'totalAmount' => $totalAmount,
                ];
            }

            // Retorna o resultado final como JSON
            echo json_encode($summary);
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

            if ($redis->exists($_REQUEST['correlationId'])) {
                return;
            }

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

                $redis->set('gateway-statuses', json_encode($results), 5);
            } else {
                $results = json_decode($results, true);
            }

            $processor = 'default';

            if ($results['default']['failing'] || $results['default']['minResponseTime'] > $results['fallback']['minResponseTime']) {
                $processor = 'fallback';
            }

            $body = [
                'amount' => $_REQUEST['amount'],
                'correlationId' => $_REQUEST['correlationId'],
                'requestedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            ];

            $ch = curl_init("http://payment-processor-{$processor}:8080/payments");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $processor = $processor === 'default' ? 'fallback' : 'default';

                $ch = curl_init("http://payment-processor-{$processor}:8080/payments");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }

            if ($httpCode !== 200) {
                http_response_code(500);
                return;
            }

            $cacheKey = "payments:{$processor}";
            $timestamp = time(); // O score para ordenar por data
            $member = "{$_REQUEST['correlationId']}:{$_REQUEST['amount']}"; // O membro com os dados
        
            // Adiciona ao Sorted Set
            $redis->zAdd($cacheKey, ['NX'], $timestamp, $member); // 'NX' é opcional, garante que não sobrescreva se já existir
        
            // 3. Marca o correlationId como processado para a verificação de duplicidade.
            // Use 'setex' para que a chave expire eventualmente e não polua o cache para sempre.
            $redis->set($_REQUEST['correlationId'], 1); // Expira em 24 horas (86400 segundos)
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