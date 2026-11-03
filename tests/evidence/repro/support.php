<?php

/**
 * Shared fixtures for the negative proofs.
 *
 * Every script boots the P1 harness against P1_SOURCE_ROOT, so the same file
 * exercises the pre tree (da53f6d) and the post tree (ffb0aec).
 */

require __DIR__ . '/../../P1/bootstrap.php';

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAttemptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\P1\Environment;

Environment::boot();

function fixture_user(array $values = [])
{
    return User::create(array_merge(['email' => 'proof' . uniqid() . '@example.test'], $values));
}

function fixture_order(User $user, array $values = [])
{
    return Order::create(array_merge([
        'user_id' => $user->id, 'trade_no' => uniqid('proof-order-'),
        'total_amount' => 10000, 'status' => 0,
    ], $values));
}

function fixture_gateway(string $method, array $config = [])
{
    return Payment::create(['uuid' => uniqid('proof-gw-'), 'payment' => $method, 'enable' => 1,
        'config' => $config + ['pid' => 'fixture-app', 'key' => 'fixture-secret',
            'url' => 'https://gateway.example.test']]);
}

function fixture_attempt(User $user, Order $order, Payment $gateway)
{
    return (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
}

function fixture_state(Order $order): array
{
    return [
        'order_status' => (int)$order->fresh()->status,
        'receipts' => (int)DB::table('v2_payment_receipt')->count(),
        'attempts' => (int)DB::table('v2_payment_attempt')->count(),
    ];
}

function fixture_call(callable $operation): array
{
    try {
        $value = $operation();
        return ['outcome' => 'returned', 'value' => is_scalar($value) || $value === null ? $value : gettype($value)];
    } catch (\Throwable $error) {
        return [
            'outcome' => 'threw',
            'class' => get_class($error),
            'status' => method_exists($error, 'getStatusCode') ? $error->getStatusCode() : null,
            'message' => substr($error->getMessage(), 0, 160),
        ];
    }
}

function fixture_emit(array $report): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
