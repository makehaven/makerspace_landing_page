<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_landing_page\Unit;

use Drupal\makerspace_landing_page\GiftCard\GiftCardState;
use Drupal\Tests\UnitTestCase;

/**
 * What staff and drush mh:gift-card-check say about a card.
 *
 * @coversDefaultClass \Drupal\makerspace_landing_page\GiftCard\GiftCardState
 * @group makerspace_landing_page
 */
class GiftCardStateTest extends UnitTestCase {

  protected const NOW = 1763500000;

  /**
   * Facts for a fresh, healthy Jumpstart, with overrides.
   */
  protected function facts(array $card = [], array $facts = []): array {
    return $facts + [
      'card' => $card + [
        'id' => 1,
        'code' => '7F3A92C1',
        'display_code' => '7F3A-92C1',
        'kind' => 'jumpstart',
        'status' => 'active',
        'amount_cents' => 28500,
        'balance_cents' => 28500,
        'contribution_id' => 77,
        'discount_id' => 9,
        'delivery' => 'email',
        'recipient_email' => '',
        'recipient_emailed' => NULL,
        'deliver_after' => NULL,
        'created' => self::NOW - 86400,
      ],
      'now' => self::NOW,
      'order_status' => 'Completed',
      'discount' => ['is_active' => 1, 'count_use' => 0, 'count_max' => 1, 'amount' => '285.00'],
      'code_uses' => [],
      'months' => NULL,
      'coupon' => 'missing',
    ];
  }

  /**
   * States of the parts, keyed by label.
   */
  protected function states(array $result): array {
    return array_column($result['parts'], 1, 0);
  }

  /**
   * Fresh card: both parts unused, both next steps offered, nothing wrong.
   */
  public function testFreshJumpstart(): void {
    $r = GiftCardState::evaluate($this->facts());
    $this->assertSame(['GEMS seat' => 'unused', '3 months' => 'unused'], $this->states($r));
    $this->assertSame([], $r['problems']);
    $this->assertCount(3, $r['next']);
    $this->assertStringContainsString('no Chargebee coupon', $r['parts'][1][2]);
  }

  /**
   * Both used, code switched off: done, nothing left to do.
   */
  public function testFullyUsed(): void {
    $r = GiftCardState::evaluate($this->facts(['balance_cents' => 0, 'status' => 'used'], [
      'discount' => ['is_active' => 0, 'count_use' => 1, 'count_max' => 1, 'amount' => '0.00'],
      'code_uses' => [['contribution_id' => 900, 'used' => self::NOW - 7200, 'recorded' => TRUE, 'event' => 'GEMS Cohort 31', 'participant_status' => 'Registered']],
      'months' => ['channel' => 'chargebee_coupon', 'reference' => 'sub:S1', 'created' => self::NOW - 3600, 'note' => ''],
      'coupon' => ['status' => 'expired', 'redemptions' => 1],
    ]));
    $this->assertSame(['GEMS seat' => 'used', '3 months' => 'used'], $this->states($r));
    $this->assertStringContainsString('GEMS Cohort 31, order 900', $r['parts'][0][2]);
    $this->assertSame([], $r['problems']);
    $this->assertSame([], $r['next']);
  }

  /**
   * Member credit path is described as credit, not a subscription.
   */
  public function testMonthsAsCredit(): void {
    $r = GiftCardState::evaluate($this->facts([], [
      'months' => ['channel' => 'chargebee_credit', 'reference' => 'member:40', 'created' => self::NOW, 'note' => ''],
    ]));
    $this->assertStringContainsString('$186 credit', $r['parts'][1][2]);
    $this->assertSame([], $r['problems']);
  }

  /**
   * Seat used in the ledger but the code still on: someone could reuse it.
   */
  public function testCodeStillOnAfterSeat(): void {
    $r = GiftCardState::evaluate($this->facts(['balance_cents' => 0, 'status' => 'used']));
    $this->assertCount(1, $r['problems']);
    $this->assertStringContainsString('still switched on', $r['problems'][0]);
  }

  /**
   * A registration cron has not recorded: fine for an hour, a problem later.
   */
  public function testUnrecordedRegistration(): void {
    $use = ['contribution_id' => 901, 'recorded' => FALSE, 'event' => 'GEMS Cohort 31', 'participant_status' => 'Registered'];
    $fresh = GiftCardState::evaluate($this->facts([], ['code_uses' => [$use + ['used' => self::NOW - 600]]]));
    $this->assertSame([], $fresh['problems']);
    $this->assertStringContainsString('cron records it', $fresh['parts'][0][2]);

    $stale = GiftCardState::evaluate($this->facts([], ['code_uses' => [$use + ['used' => self::NOW - 5 * 3600]]]));
    $this->assertStringContainsString('has not recorded it', $stale['problems'][0]);
  }

  /**
   * Chargebee coupon used, ledger silent: the webhook missed.
   */
  public function testCheckoutNotSettled(): void {
    $r = GiftCardState::evaluate($this->facts([], ['coupon' => ['status' => 'expired', 'redemptions' => 1]]));
    $this->assertSame('unused', $this->states($r)['3 months']);
    $this->assertStringContainsString('ledger has not recorded it', $r['problems'][0]);
  }

  /**
   * Ledger says the months started but Chargebee shows the coupon unused.
   */
  public function testLedgerAheadOfChargebee(): void {
    $r = GiftCardState::evaluate($this->facts([], [
      'months' => ['channel' => 'chargebee_coupon', 'reference' => 'sub:S1', 'created' => self::NOW, 'note' => ''],
      'coupon' => ['status' => 'active', 'redemptions' => 0],
    ]));
    $this->assertStringContainsString('Chargebee shows the coupon unused', $r['problems'][0]);
  }

  /**
   * Refunded order, card still live: void it.
   */
  public function testRefundedOrderCardActive(): void {
    $r = GiftCardState::evaluate($this->facts([], ['order_status' => 'Refunded']));
    $this->assertStringContainsString('Refunded', $r['problems'][0]);
    $this->assertStringContainsString('void the card', $r['problems'][0]);
  }

  /**
   * Void card with its code off and no coupon: nothing wrong, nothing to do.
   */
  public function testVoidCardClean(): void {
    $r = GiftCardState::evaluate($this->facts(['status' => 'void', 'balance_cents' => 0], [
      'order_status' => 'Refunded',
      'discount' => ['is_active' => 0, 'count_use' => 0, 'count_max' => 1, 'amount' => '0.00'],
    ]));
    $this->assertSame(['GEMS seat' => 'void', '3 months' => 'void'], $this->states($r));
    $this->assertSame([], $r['problems']);
    $this->assertSame([], $r['next']);
  }

  /**
   * Void card whose code is still on.
   */
  public function testVoidCardCodeOn(): void {
    $r = GiftCardState::evaluate($this->facts(['status' => 'void', 'balance_cents' => 0]));
    $this->assertStringContainsString('card is void', $r['problems'][0]);
  }

  /**
   * No discount code, or one deleted in CiviDiscount.
   */
  public function testMissingCode(): void {
    $none = GiftCardState::evaluate($this->facts(['discount_id' => NULL], ['discount' => NULL]));
    $this->assertStringContainsString('no discount code', $none['problems'][0]);
    $gone = GiftCardState::evaluate($this->facts([], ['discount' => NULL, 'discount_missing' => TRUE]));
    $this->assertStringContainsString('no longer exists', $gone['problems'][0]);
  }

  /**
   * Code worth less than the card (someone edited it in CiviDiscount).
   */
  public function testCodeAmountDrift(): void {
    $r = GiftCardState::evaluate($this->facts([], ['discount' => ['is_active' => 1, 'count_use' => 0, 'count_max' => 1, 'amount' => '225.00']]));
    $this->assertStringContainsString('worth $225.00 but the card has $285 left', $r['problems'][0]);
  }

  /**
   * Code with no uses left while the card has value.
   */
  public function testCodeOutOfUses(): void {
    $r = GiftCardState::evaluate($this->facts([], ['discount' => ['is_active' => 1, 'count_use' => 1, 'count_max' => 1, 'amount' => '285.00']]));
    $this->assertStringContainsString('no uses left', $r['problems'][0]);
  }

  /**
   * The registration that used the seat was cancelled: say how to give it back.
   */
  public function testCancelledSeatRegistration(): void {
    $r = GiftCardState::evaluate($this->facts(['balance_cents' => 0, 'status' => 'used'], [
      'discount' => ['is_active' => 0, 'count_use' => 1, 'count_max' => 1, 'amount' => '0.00'],
      'code_uses' => [['contribution_id' => 902, 'used' => self::NOW - 86400, 'recorded' => TRUE, 'event' => 'GEMS Cohort 31', 'participant_status' => 'Cancelled']],
    ]));
    $this->assertStringContainsString('is Cancelled', $r['problems'][0]);
    $this->assertStringContainsString('correction of $285', $r['problems'][0]);
  }

  /**
   * Recipient email overdue; on time is not a problem.
   */
  public function testRecipientEmail(): void {
    $card = ['delivery' => 'recipient', 'recipient_email' => 'r@example.com'];
    $due = GiftCardState::evaluate($this->facts($card + ['deliver_after' => self::NOW + 86400]));
    $this->assertSame([], $due['problems']);
    $late = GiftCardState::evaluate($this->facts($card + ['deliver_after' => self::NOW - 86400]));
    $this->assertStringContainsString('recipient email was due', $late['problems'][0]);
    $sent = GiftCardState::evaluate($this->facts($card + ['deliver_after' => self::NOW - 86400, 'recipient_emailed' => self::NOW - 80000]));
    $this->assertSame([], $sent['problems']);
  }

  /**
   * Workshop and membership cards: one part, balance-based.
   */
  public function testOtherKinds(): void {
    $w = GiftCardState::evaluate($this->facts(['kind' => 'workshop', 'amount_cents' => 10000, 'balance_cents' => 2500], [
      'discount' => ['is_active' => 1, 'count_use' => 1, 'count_max' => 2, 'amount' => '25.00'],
      'coupon' => NULL,
    ]));
    $this->assertSame(['Class credit' => 'unused'], $this->states($w));
    $this->assertSame([], $w['problems']);

    $m = GiftCardState::evaluate($this->facts(['kind' => 'membership', 'discount_id' => NULL, 'amount_cents' => 6200, 'balance_cents' => 6200], [
      'discount' => NULL,
      'coupon' => ['status' => 'expired', 'redemptions' => 1],
    ]));
    $this->assertSame(['Membership credit' => 'unused'], $this->states($m));
    $this->assertStringContainsString('coupon used at a checkout', $m['problems'][0]);
  }

}
