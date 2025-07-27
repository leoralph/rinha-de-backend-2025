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

function toFloatTimestamp(?string $dateString): ?float {
    if (!$dateString) {
        return null;
    }

    $date = new DateTime($dateString);

    if (!$date) {
        return null;
    }

    return (float) $date->format('U.v');
}

$routes = [
    'GET' => [
        '/payments-summary' => static function () use ($redis, $summaryScriptSha) {
            $from = toFloatTimestamp($_GET['from'] ?? null) ?? '-inf';
            $to = toFloatTimestamp($_GET['to'] ?? null) ?? '+inf';

            $pipe = $redis->pipeline();

            $pipe->evalSha($summaryScriptSha, ["payments:default", $from, $to], 1);
            $pipe->evalSha($summaryScriptSha, ["payments:fallback", $from, $to], 1);

            $results = $pipe->exec();

            return [
                'default' => [
                    'totalRequests' => (int) ($results[0][0] ?? 0),
                    'totalAmount' => ($results[0][1] ?? 0) / 100
                ],
                'fallback' => [
                    'totalRequests' => (int) ($results[1][0] ?? 0),
                    'totalAmount' => ($results[1][1] ?? 0) / 100
                ]
            ];
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
