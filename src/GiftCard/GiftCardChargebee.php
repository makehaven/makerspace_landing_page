<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * The few Chargebee calls gift cards need: coupons, credit and invoices.
 *
 * Live talks to the live site with the live key; every other environment
 * talks to the Chargebee test site with the test key, so a test or local run
 * can never create a live coupon or credit a real member. Keys come from
 * chargebee_portal.settings (filled from Pantheon Secrets), like the join
 * form's coupon check.
 */
class GiftCardChargebee {

  public function __construct(
    protected ClientInterface $http,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether there is a key for this environment's Chargebee site.
   */
  public function isConfigured(): bool {
    return $this->apiKey() !== '';
  }

  /**
   * Returns a coupon, or NULL when Chargebee has no coupon with that id.
   *
   * @throws \RuntimeException
   *   When Chargebee could not be asked.
   */
  public function getCoupon(string $id): ?array {
    $response = $this->request('GET', 'coupons/' . rawurlencode($id));
    if ($response['status'] === 404) {
      return NULL;
    }
    $this->assertOk($response, 'coupon lookup');
    return $response['body']['coupon'] ?? NULL;
  }

  /**
   * Makes sure a single-use coupon worth a fixed amount off one invoice exists.
   *
   * Chargebee never takes more off than the invoice is for, so a card worth
   * more than one month covers the first invoice and the rest is added as
   * promotional credit once the subscription exists (see
   * GiftCardCheckoutWorker).
   */
  public function ensureFixedCoupon(string $id, int $amount_cents, string $invoice_name): array {
    return $this->ensureCoupon($id, [
      'discount_type' => 'fixed_amount',
      'discount_amount' => $amount_cents,
      'duration_type' => 'one_time',
    ], $invoice_name);
  }

  /**
   * Makes sure a single-use "N months free" coupon exists (Jumpstart).
   */
  public function ensureFreeMonthsCoupon(string $id, int $months, string $invoice_name): array {
    return $this->ensureCoupon($id, [
      'discount_type' => 'percentage',
      'discount_percentage' => 100,
      'duration_type' => 'limited_period',
      'duration_month' => $months,
    ], $invoice_name);
  }

  /**
   * Adds promotional credit to a customer.
   *
   * @throws \RuntimeException
   *   When Chargebee refused or could not be reached.
   */
  public function addCredit(string $customer_id, int $amount_cents, string $description): void {
    if ($customer_id === '' || $amount_cents <= 0) {
      throw new \RuntimeException('Refusing to add credit without a customer or a positive amount.');
    }
    $response = $this->request('POST', 'customers/' . rawurlencode($customer_id) . '/add_promotional_credit', [
      'amount' => $amount_cents,
      'description' => mb_substr($description, 0, 250),
      'credit_type' => 'promotional_credits',
    ]);
    $this->assertOk($response, 'add promotional credit');
  }

  /**
   * Returns the subscription's earliest invoice, or NULL when none exists yet.
   */
  public function firstInvoice(string $subscription_id): ?array {
    $response = $this->request('GET', 'invoices', [
      'subscription_id[is]' => $subscription_id,
      'sort_by[asc]' => 'date',
      'limit' => 1,
    ]);
    $this->assertOk($response, 'invoice lookup');
    return $response['body']['list'][0]['invoice'] ?? NULL;
  }

  /**
   * Creates the coupon unless it already exists.
   */
  protected function ensureCoupon(string $id, array $discount, string $invoice_name): array {
    $existing = $this->getCoupon($id);
    if ($existing) {
      return $existing;
    }
    // Product Catalog 1.0 (this account): plan_constraint "all" with
    // each_specified_item means the plan is discounted, not a donation add-on.
    $params = [
      'id' => $id,
      'name' => $id,
      'invoice_name' => mb_substr($invoice_name, 0, 100),
      'apply_on' => 'each_specified_item',
      'plan_constraint' => 'all',
      'addon_constraint' => 'none',
      'max_redemptions' => 1,
    ] + $discount;
    $response = $this->request('POST', 'coupons', $params);
    $this->assertOk($response, 'coupon create');
    return $response['body']['coupon'] ?? [];
  }

  /**
   * Sends one request; never throws for HTTP status, only for transport.
   *
   * @return array
   *   Keys: status (int), body (array).
   */
  protected function request(string $method, string $path, array $params = []): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('No Chargebee API key for this environment.');
    }
    $options = [
      'auth' => [$this->apiKey(), ''],
      'timeout' => 15,
      'http_errors' => FALSE,
    ];
    if ($params) {
      $options[$method === 'GET' ? 'query' : 'form_params'] = $params;
    }
    try {
      $response = $this->http->request($method, 'https://' . $this->domain() . '/api/v2/' . $path, $options);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Chargebee unreachable: ' . $e->getMessage(), 0, $e);
    }
    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode((string) $response->getBody(), TRUE) ?: [],
    ];
  }

  /**
   * Throws with Chargebee's own message unless the response is a success.
   */
  protected function assertOk(array $response, string $what): void {
    if ($response['status'] >= 200 && $response['status'] < 300) {
      return;
    }
    $message = $response['body']['message'] ?? ('HTTP ' . $response['status']);
    $this->loggerFactory->get('makerspace_landing_page')->error('Gift card Chargebee @what failed: @m', [
      '@what' => $what,
      '@m' => $message,
    ]);
    throw new \RuntimeException("Chargebee $what failed: $message");
  }

  /**
   * Whether this is the production environment.
   */
  protected function isLive(): bool {
    return ($_ENV['PANTHEON_ENVIRONMENT'] ?? NULL) === 'live';
  }

  /**
   * This environment's Chargebee site.
   */
  protected function domain(): string {
    return $this->isLive() ? 'makehaven.chargebee.com' : 'makehaven-test.chargebee.com';
  }

  /**
   * This environment's API key.
   */
  protected function apiKey(): string {
    $config = $this->configFactory->get('chargebee_portal.settings');
    return trim((string) $config->get($this->isLive() ? 'live_api_key' : 'test_api_key'));
  }

}
