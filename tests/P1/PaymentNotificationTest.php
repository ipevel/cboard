<?php

namespace Tests\P1;

use App\Models\Order;
use App\Models\Plan;
use App\Services\PaymentNotificationService;
use App\Utils\StripeCurrency;

class PaymentNotificationTest extends DatabaseTestCase
{
    public function testMessageSummarizesOrderAndHidesChannelSecrets(): void
    {
        $user = $this->user(['email' => 'payer@example.test']);
        $plan = Plan::create(['name' => '标准套餐', 'group_id' => 1]);
        $order = $this->order($user, [
            'plan_id' => $plan->id, 'type' => 1, 'period' => 'month_price',
            'total_amount' => 7500, 'balance_amount' => 2500, 'discount_amount' => 1000,
            'handling_amount' => 100, 'paid_at' => 1762300000, 'callback_no' => 'receipt-1',
        ]);
        $message = PaymentNotificationService::message($order, [
            'amount_minor' => 7600, 'currency' => 'CNY', 'amount' => '76.00',
        ]);
        foreach ([
            '用户：payer@example.test', '用户 ID：' . $user->id, '业务：套餐新购',
            '套餐：标准套餐', '周期：月付', '订单应付：75.00 CNY', '余额抵扣：25.00 CNY',
            '优惠金额：10.00 CNY', '手续费：1.00 CNY', '实收金额：76.00 CNY',
            '订单号：' . $order->trade_no, '支付流水号：receipt-1',
            '付款时间：' . date('Y-m-d H:i:s', 1762300000),
        ] as $text) $this->assertStringContainsString($text, $message);
    }

    public function testRechargeOrderOmitsPlanLines(): void
    {
        $user = $this->user();
        $order = $this->order($user, ['type' => 9, 'total_amount' => 10000, 'paid_at' => time()]);
        $message = PaymentNotificationService::message($order, []);
        $this->assertStringContainsString('业务：余额充值', $message);
        $this->assertStringContainsString('充值金额：100.00 CNY', $message);
        $this->assertStringNotContainsString('套餐：', $message);
        $this->assertStringNotContainsString('周期：', $message);
    }

    public function testNewlinesInReferencedValuesAreStripped(): void
    {
        $user = $this->user();
        $plan = Plan::create(['name' => "标准套餐\n伪造行：x", 'group_id' => 1]);
        $order = $this->order($user, ['type' => 1, 'plan_id' => $plan->id, 'period' => 'month_price',
            'total_amount' => 100, 'paid_at' => time()]);
        $message = PaymentNotificationService::message($order, []);
        $this->assertStringNotContainsString("\n伪造行", $message);
        $this->assertStringContainsString('套餐：标准套餐 伪造行：x', $message);
    }

    public function testFormatMinorKeepsZeroDecimalCurrenciesWhole(): void
    {
        $this->assertSame('7600', StripeCurrency::formatMinor(7600, 'jpy'));
        $this->assertSame('76.00', StripeCurrency::formatMinor(7600, 'cny'));
    }
}
