<?php

ignore_user_abort(true);

$redis = new Redis();
$redis->pconnect('redis', 6379);

$luaScript = <<<LUA
local members = redis.call('zrangebyscore', KEYS[1], ARGV[1], ARGV[2]);
local totalAmount = 0;
local totalRequests = #members;
for i, member in ipairs(members) do
  local pos = string.find(member, ':', 1, true);
  if pos then
    totalAmount = totalAmount + tonumber(string.sub(member, pos + 1));
  end
end
return {totalRequests, totalAmount};
LUA;

$summaryScriptSha = $redis->script('load', $luaScript);


$routes = [
    'GET' => [
        '/payments-summary' => static function () use ($redis, $summaryScriptSha) {
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

                $result = $redis->evalSha($summaryScriptSha, [$cacheKey, $from, $to], 1);

                $summary[$processor] = [
                    'totalRequests' => (int) ($result[0] ?? 0),
                    'totalAmount' => round(($result[1] ?? 0) / 100, 2)
                ];
            }

            return $summary;
        },
    ],
    'POST' => [
        '/payments' => static function () use ($redis) {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['correlationId'], $input['amount'])) {
                http_response_code(400);
                return;
            }

            $redis->lPush('payment_jobs', json_encode([
                'correlationId' => $input['correlationId'],
                'amountInCents' => (int) round($input['amount'] * 100),
            ]));
        },
        '/purge-payments' => static function () use ($redis) {
            $redis->flushAll();
        },
    ],
];


$handler = static function () use ($routes) {
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    $routeHandler = $routes[$method][$uri] ?? null;

    if ($routeHandler === null) {
        http_response_code(404);
        return;
    }

    $response = $routeHandler();

    if ($response === null) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
};


while (\frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}