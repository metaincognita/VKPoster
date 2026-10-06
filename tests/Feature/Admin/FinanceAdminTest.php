<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\FinanceExport;
use App\Domain\Admin\StaffRole;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\WebhookMask;
use App\Http\Controllers\Admin\FinanceController;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Support\DbTime;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Money in the back office: payment detail and check against the provider, the notification journal with masked copies and "handle again",
 * the accountant's export with provider fees, the filters, the price history, and the rule that a new price waits for the next renewal.
 */
#[CoversClass(FinanceController::class)]
#[CoversClass(FinanceExport::class)]
#[CoversClass(WebhookMask::class)]
final class FinanceAdminTest extends AdminTestCase
{
    public function testMaskHidesCardsContactsAndSecrets(): void
    {
        $body = (string) json_encode(['event' => 'payment.succeeded', 'object' => [
            'id' => 'pay-1', 'amount' => ['value' => '990.00'],
            'payment_method' => ['card' => ['last4' => '4242', 'first6' => '555555'], 'type' => 'bank_card'],
            'receipt' => ['customer' => ['email' => 'secret@example.com', 'phone' => '+79990001122']],
            'metadata' => ['note' => 'карта 4111111111111111 тут', 'token' => 'abc'],
            'signature' => 'deadbeef',
        ]]);
        $masked = WebhookMask::apply($body);
        self::assertStringContainsString('pay-1', $masked);
        self::assertStringContainsString('990.00', $masked);
        foreach (['secret@example.com', '+79990001122', '555555', 'deadbeef', '4111111111111111', '"abc"'] as $secret) {
            self::assertStringNotContainsString($secret, $masked, $secret);
        }
        self::assertStringContainsString('••••••••••••1111', $masked, 'a long digit run keeps only its last four digits');
        self::assertStringContainsString(WebhookMask::MASK, $masked);
        // Not JSON: a form post.
        $form = WebhookMask::apply('Token=abc&PaymentId=77&CardId=999&Amount=1000');
        self::assertStringContainsString('77', $form);
        self::assertStringNotContainsString('abc', $form);
        self::assertSame(WebhookMask::LIMIT + 2, mb_strlen(WebhookMask::apply((string) json_encode(['a' => str_repeat('x', 20000)]))));
    }

    public function testPaymentDetailAndReconcileWithTheProvider(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('payer@example.com', 'Плательщик');
        $result = $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'fake', true);
        $publicId = (string) $result->paymentId;
        $this->staff(StaffRole::Finance);

        $page = $this->plain($this->get('/admin/payments/' . $publicId));
        self::assertStringContainsString('Ожидает', $page);
        self::assertStringContainsString('Плательщик', $page);
        self::assertStringContainsString('payer@example.com', $page);
        self::assertStringContainsString('Сверить с провайдером', $page);

        // Nothing changed at the provider: the check says so.
        $this->post('/admin/payments/' . $publicId . '/reconcile', []);
        self::assertSame('pending', $this->db->select('SELECT status FROM payments WHERE public_id = ?', [$publicId])[0]['status']);

        // The customer paid, but the notification never arrived: asking the provider fixes it.
        $this->app->container()->get(FakeGateway::class)->answer($publicId, 'pay');
        $this->post('/admin/payments/' . $publicId . '/reconcile', []);
        self::assertSame('succeeded', $this->db->select('SELECT status FROM payments WHERE public_id = ?', [$publicId])[0]['status']);
        $audit = json_decode((string) $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'admin.payment_reconciled' ORDER BY id DESC LIMIT 1")[0]['meta_json'], true);
        self::assertEquals(['before' => 'pending', 'after' => 'succeeded'], array_intersect_key($audit, ['before' => 1, 'after' => 1]));
        $paid = $this->plain($this->get('/admin/payments/' . $publicId));
        self::assertStringContainsString('Оплачен', $paid);
        self::assertStringContainsString('Вернуть деньги', $paid);
        self::assertStringContainsString('Оплата', $paid, 'the money journal shows the payment');
        self::assertSame(404, $this->get('/admin/payments/01JZZZZZZZZZZZZZZZZZZZZZZZ')->status);

        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->get('/admin/payments/' . $publicId)->status);
        self::assertSame(403, $this->post('/admin/payments/' . $publicId . '/reconcile', [])->status);
    }

    public function testRefundFromTheDetailPageReturnsThere(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('refund@example.com', 'Возврат');
        [, $payment] = $this->payWithFake($workspace, $owner);
        $this->staff(StaffRole::Finance);
        $response = $this->post('/admin/payments/' . $payment->publicId . '/refund', $this->confirm(['amount' => '100', 'confirm' => '1', 'back' => '/admin/payments/' . $payment->publicId]));
        self::assertSame('/admin/payments/' . $payment->publicId, $response->header('Location'));
        self::assertSame(10000, (int) $this->db->select('SELECT refunded_amount FROM payments WHERE public_id = ?', [$payment->publicId])[0]['refunded_amount']);
        $evil = $this->post('/admin/payments/' . $payment->publicId . '/refund', $this->confirm(['amount' => '1', 'confirm' => '1', 'back' => 'https://evil.example.com/']));
        self::assertSame('/admin/payments', $evil->header('Location'), 'only admin payment pages are valid places to return to');
    }

    public function testWebhookJournalShowsMaskedCopiesAndCanReplay(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('hook@example.com', 'Хук');
        $result = $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'fake', false);
        $publicId = (string) $result->paymentId;
        $ref = (string) $this->db->select('SELECT provider_payment_id FROM payments WHERE public_id = ?', [$publicId])[0]['provider_payment_id'];
        $this->db->execute(
            "INSERT INTO webhook_events (provider, event_id, type, payment_ref, outcome, detail, payload, received_at) VALUES ('fake', 'evt-1', 'payment.succeeded', ?, 'processed', 'ok', ?, ?)",
            [$ref, WebhookMask::apply((string) json_encode(['id' => $ref, 'email' => 'private@example.com'])), DbTime::format($this->clock->now())],
        );
        $id = (int) $this->db->lastInsertId();
        $this->staff(StaffRole::Finance);
        $list = $this->plain($this->get('/admin/webhooks?provider=fake&outcome=processed'));
        self::assertStringContainsString('payment.succeeded', $list);
        self::assertStringNotContainsString('payment.succeeded', $this->plain($this->get('/admin/webhooks?outcome=rejected')));
        $detail = $this->get('/admin/webhooks/' . $id);
        self::assertStringContainsString($ref, $detail->body);
        self::assertStringNotContainsString('private@example.com', $detail->body);
        self::assertStringContainsString('Обработать заново', $detail->body);

        $this->app->container()->get(FakeGateway::class)->answer($publicId, 'pay');
        $this->post('/admin/webhooks/' . $id . '/replay', []);
        self::assertSame('succeeded', $this->db->select('SELECT status FROM payments WHERE public_id = ?', [$publicId])[0]['status']);
        self::assertSame(404, $this->get('/admin/webhooks/999999')->status);
    }

    public function testListFiltersByProviderAndDates(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('filter@example.com', 'Фильтр');
        [, $payment] = $this->payWithFake($workspace, $owner);
        $staff = $this->staff(StaffRole::Finance);
        $today = $this->clock->now()->setTimezone(new \DateTimeZone($staff->timezone))->format('Y-m-d');
        self::assertStringContainsString($payment->publicId, $this->get('/admin/payments?provider=fake&from=' . $today . '&to=' . $today)->body);
        self::assertStringNotContainsString($payment->publicId, $this->get('/admin/payments?provider=tbank')->body);
        self::assertStringNotContainsString($payment->publicId, $this->get('/admin/payments?from=2000-01-01&to=2000-01-31')->body);
        self::assertSame(200, $this->get('/admin/payments?provider=%27OR+1%3D1&from=bad&to=also-bad')->status);
    }

    public function testSubscriptionFilters(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('sub@example.com', 'Подписчик');
        $this->payWithFake($workspace, $owner);
        $this->staff(StaffRole::Finance);
        self::assertStringContainsString('Подписчик', $this->get('/admin/subscriptions?status=active')->body);
        self::assertStringNotContainsString('Подписчик', $this->get('/admin/subscriptions?status=canceled')->body);
        $this->subscriptionService()->cancelRenewal($workspace->id, $owner->id);
        self::assertStringContainsString('Подписчик', $this->get('/admin/subscriptions?status=canceled')->body);
        self::assertSame(200, $this->get('/admin/subscriptions?status=free')->status);
    }

    public function testExportForTheAccountantWithFees(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('books@example.com', 'Бухгалтерия');
        [$invoice] = $this->payWithFake($workspace, $owner);
        $staff = $this->staff(StaffRole::Finance);
        $from = $this->clock->now()->setTimezone(new \DateTimeZone($staff->timezone))->modify('-1 day')->format('Y-m-d');
        $to = $this->clock->now()->setTimezone(new \DateTimeZone($staff->timezone))->format('Y-m-d');

        $csv = $this->get('/admin/payments/export?from=' . $from . '&to=' . $to);
        self::assertSame(200, $csv->status);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv->body);
        self::assertStringContainsString('Комиссия (оценка)', $csv->body);
        self::assertStringContainsString($invoice->number, $csv->body);
        $line = array_values(array_filter(explode("\r\n", $csv->body), static fn (string $l): bool => str_contains($l, $invoice->number)))[0];
        $cells = array_map(static fn (string $c): string => trim($c, '"'), explode('";"', $line));
        self::assertSame('', $cells[13], 'no fee is invented when the percentage is not set');

        $this->post('/admin/payments/fees', ['fee' => ['fake' => '2,5', 'bad provider!' => '3', 'tbank' => 'abc']]);
        self::assertSame(['fake' => 2.5], $this->app->container()->get(FinanceExport::class)->fees());
        $csv = $this->get('/admin/payments/export?from=' . $from . '&to=' . $to);
        $line = array_values(array_filter(explode("\r\n", $csv->body), static fn (string $l): bool => str_contains($l, $invoice->number)))[0];
        $cells = array_map(static fn (string $c): string => trim($c, '"'), explode('";"', $line));
        $expectedFee = number_format(round($invoice->amount * 0.025) / 100, 2, ',', '');
        self::assertSame($expectedFee, $cells[13]);
        self::assertContains('admin.finance_exported', $this->adminActions());

        self::assertSame('/admin/payments', $this->get('/admin/payments/export?from=2026-01-01')->header('Location'), 'a period is required');
        self::assertSame('/admin/payments', $this->get('/admin/payments/export?from=2020-01-01&to=2026-01-01')->header('Location'), 'and it is bounded');
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->get('/admin/payments/export?from=' . $from . '&to=' . $to)->status);
        self::assertSame(403, $this->post('/admin/payments/fees', [])->status);
    }

    public function testPlanHistoryAndAPriceThatWaitsForTheRenewal(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('renew@example.com', 'Продление');
        $this->payWithFake($workspace, $owner);
        $pricePaid = $this->subscription($workspace)->priceAmount;
        $this->staff(StaffRole::Finance);
        $plan = $this->plans()->findByCode('pro');
        self::assertNotNull($plan);
        $limits = ['name' => 'Про', 'price_month' => '2000', 'price_year' => '20000', 'is_public' => '1', 'limit_channels' => '30'];
        $this->post('/admin/plans/pro', $this->confirm($limits));

        $page = html_entity_decode($this->plain($this->get('/admin/plans/pro')));
        self::assertStringContainsString('История изменений', $page);
        self::assertStringContainsString('Было:', $page);
        self::assertStringContainsString('"month":200000', $page, 'the new price (kopecks) is in the history');

        // The running subscription keeps what it paid; the renewal is invoiced at the new price.
        self::assertSame($pricePaid, $this->subscription($workspace)->priceAmount);
        $this->periodEndsIn($workspace, '+1 day');
        $this->renewals()->renew($this->subscription($workspace), true);
        $invoice = $this->db->select("SELECT amount, kind FROM invoices WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspace->id])[0];
        self::assertSame('renewal', $invoice['kind']);
        self::assertSame(200000, (int) $invoice['amount']);
        self::assertNotSame($pricePaid, 200000);
    }
}
