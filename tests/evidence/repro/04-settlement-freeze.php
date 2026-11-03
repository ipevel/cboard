<?php

/**
 * Negative proof 4/4: settlement freeze / amount binding.
 *
 * 4a: a settlement call that carries the frozen attempt but no receipt amount
 *     (the legacy "signature-only" shape). This is the delta introduced at
 *     ffb0aec in PaymentSettlementService::amountMatches().
 * 4b: a settlement call for a Stripe gateway with no frozen attempt at all.
 *     This guard predates the comparison point, so it is expected to fail on
 *     both trees; the run reports that instead of claiming a regression.
 */

require __DIR__ . '/support.php';

use App\Services\OrderService;
use App\Services\PaymentSettlementService;
use Illuminate\Support\Facades\DB;

$report = ['tree' => getenv('P1_SOURCE_ROOT') ?: 'default', 'php' => PHP_VERSION,
    'attack_inputs' => [
        '4a' => 'PaymentSettlementService::settle() with a valid frozen attempt but no receipt amount/currency (legacy signature-only shape)',
        '4a_control' => 'same call with amount and currency equal to the frozen attempt quote',
        '4b' => 'PaymentSettlementService::settle() for a Stripe gateway with no frozen attempt row',
    ], 'cases' => []];

// 4a: EPayQrcode and Paytaro settlement without a receipt amount.
foreach (['EPayQrcode', 'Paytaro'] as $method) {
    $user = fixture_user();
    $gateway = fixture_gateway($method);
    $order = fixture_order($user, ['payment_id' => $gateway->id]);
    $attempt = fixture_attempt($user, $order, $gateway);
    $result = fixture_call(function () use ($order, $gateway, $attempt, $method) {
        return (new PaymentSettlementService())->settle($order, 'proof-' . $method . '-no-amount', [
            'payment_id' => $gateway->id, 'attempt_id' => $attempt->id,
        ]);
    });
    $report['cases']['4a_' . $method . '_attempt_without_amount'] = $result + fixture_state($order);
}

// 4a control: the same call with the frozen amount and currency.
foreach (['EPayQrcode', 'Paytaro'] as $method) {
    $user = fixture_user();
    $gateway = fixture_gateway($method);
    $order = fixture_order($user, ['payment_id' => $gateway->id]);
    $attempt = fixture_attempt($user, $order, $gateway);
    $result = fixture_call(function () use ($order, $gateway, $attempt, $method) {
        return (new PaymentSettlementService())->settle($order, 'proof-' . $method . '-with-amount', [
            'payment_id' => $gateway->id, 'attempt_id' => $attempt->id,
            'amount' => number_format((int)$attempt->total_amount / 100, 2, '.', ''),
            'currency' => 'CNY', 'expected_currency' => 'CNY',
        ]);
    });
    $report['cases']['4a_control_' . $method . '_attempt_with_amount'] = $result + fixture_state($order);
}

// 4b: Stripe settlement with no frozen attempt.
foreach (['StripeALL', 'StripeCredit'] as $method) {
    $user = fixture_user();
    $gateway = fixture_gateway($method, ['currency' => 'usd', 'stripe_sk_live' => 'sk_test_fixture',
        'stripe_webhook_key' => 'whsec_fixture']);
    $order = fixture_order($user, ['payment_id' => $gateway->id, 'total_amount' => 12000]);
    $result = fixture_call(function () use ($order, $gateway, $method) {
        return (new PaymentSettlementService())->settle($order, 'proof-' . $method . '-no-attempt', [
            'payment_id' => $gateway->id, 'amount_minor' => 12000, 'currency' => 'usd',
        ]);
    });
    $report['cases']['4b_' . $method . '_no_frozen_attempt'] = $result + fixture_state($order);
}

fixture_emit($report);
