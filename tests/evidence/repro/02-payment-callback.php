<?php

/**
 * Negative proof 2/4: payment callback amount/currency validation.
 *
 * Every callback is correctly signed with the merchant key; only the amount and
 * currency in the receipt differ from the frozen order total. The script runs
 * identically on pre (da53f6d) and post (ffb0aec) via P1_SOURCE_ROOT.
 */

require __DIR__ . '/support.php';

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Payment;
use Illuminate\Http\Request;

function signed(array $params): array
{
    unset($params['sign'], $params['sign_type']);
    $signParams = array_filter($params, static function ($value) { return $value !== '' && $value !== null; });
    ksort($signParams);
    $pairs = [];
    foreach ($signParams as $key => $value) $pairs[] = $key . '=' . $value;
    $params['sign'] = md5(implode('&', $pairs) . 'fixture-secret');
    $params['sign_type'] = 'MD5';
    return $params;
}

function notify(Payment $gateway, array $params): array
{
    $request = Request::create('/', 'POST', $params);
    \Tests\P1\Environment::$app->instance('request', $request);
    return fixture_call(function () use ($gateway, $request) {
        return (new PaymentController())->notify($gateway->payment, $gateway->uuid, $request);
    });
}

$cases = [
    'underpaid_1.00_of_100.00' => ['money' => '1.00'],
    'overpaid_101.00_of_100.00' => ['money' => '101.00'],
    'zero_amount' => ['money' => '0.00'],
    'negative_amount' => ['money' => '-0.01'],
    'fractional_cent_1.001' => ['money' => '1.001'],
    'usd_currency_same_number' => ['money' => '100.00', 'currency' => 'USD'],
    'amount_omitted' => ['__omit_money' => true],
    'control_correct_100.00' => [],
];

$report = ['tree' => getenv('P1_SOURCE_ROOT') ?: 'default', 'php' => PHP_VERSION, 'cases' => []];
foreach (['EPayQrcode', 'Paytaro'] as $method) {
    foreach ($cases as $label => $override) {
        $user = fixture_user();
        $gateway = fixture_gateway($method);
        $order = fixture_order($user, ['payment_id' => $gateway->id]);
        $overrides = $override;
        $omit = !empty($overrides['__omit_money']);
        unset($overrides['__omit_money']);
        $params = array_merge([
            'pid' => 'fixture-app', 'out_trade_no' => $order->trade_no,
            'trade_no' => 'gateway-' . bin2hex(random_bytes(4)),
            'trade_status' => 'TRADE_SUCCESS', 'type' => 'alipay', 'name' => 'order', 'money' => '100.00',
        ], $overrides);
        if ($omit) unset($params['money']);
        $payload = signed($params);
        $report['cases'][$method . '/' . $label] = ['input' => $payload] + notify($gateway, $payload) + fixture_state($order);
    }
}

fixture_emit($report);
