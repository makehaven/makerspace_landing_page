<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_landing_page\Unit\Fake;

use Drupal\makerspace_landing_page\GiftCard\GiftCardChargebee;

/**
 * Chargebee in memory: coupons, credits, invoices, and an outage switch.
 */
class FakeGiftCardChargebee extends GiftCardChargebee {

  /**
   * Coupons by id, as Chargebee returns them.
   */
  public array $coupons = [];

  /**
   * Credits added: list of [customer, cents, description].
   */
  public array $credits = [];

  /**
   * First invoices by subscription id.
   */
  public array $invoices = [];

  /**
   * When TRUE every call fails as if Chargebee were unreachable.
   */
  public bool $down = FALSE;

  /**
   * No HTTP client or config.
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCoupon(string $id): ?array {
    $this->failIfDown();
    return $this->coupons[$id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function ensureCoupon(string $id, array $discount, string $invoice_name): array {
    $this->failIfDown();
    return $this->coupons[$id] ??= ['id' => $id, 'status' => 'active', 'redemptions' => 0, 'invoice_name' => $invoice_name] + $discount;
  }

  /**
   * {@inheritdoc}
   */
  public function addCredit(string $customer_id, int $amount_cents, string $description): void {
    $this->failIfDown();
    $this->credits[] = [$customer_id, $amount_cents, $description];
  }

  /**
   * {@inheritdoc}
   */
  public function firstInvoice(string $subscription_id): ?array {
    $this->failIfDown();
    return $this->invoices[$subscription_id] ?? NULL;
  }

  /**
   * A checkout used the coupon (what Chargebee does on subscription create).
   */
  public function redeem(string $id): void {
    $this->coupons[$id]['redemptions'] = ($this->coupons[$id]['redemptions'] ?? 0) + 1;
    $this->coupons[$id]['status'] = 'expired';
  }

  /**
   * Throws like the real client on a transport or API failure.
   */
  protected function failIfDown(): void {
    if ($this->down) {
      throw new \RuntimeException('Chargebee unreachable (test).');
    }
  }

}
