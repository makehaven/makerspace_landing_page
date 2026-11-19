<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_landing_page\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Drupal\makerspace_landing_page\GiftCard\GiftCardRedeemer;
use Drupal\Tests\makerspace_landing_page\Unit\Fake\FakeGiftCardChargebee;
use Drupal\Tests\makerspace_landing_page\Unit\Fake\FakeGiftCardLedger;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Every way a Jumpstart card can be used, against in-memory fakes.
 *
 * One code, two parts: the GEMS seat (the card balance, spent by a cohort
 * registration through CiviDiscount) and the 3 free months (a Chargebee
 * coupon at join, or $186 credit for someone who joined in the last 30 days).
 * Either part can go first. The scenarios here are the test plan in
 * docs/ops/JUMPSTART_GIFT_CARD.md, section "Paths".
 *
 * @coversDefaultClass \Drupal\makerspace_landing_page\GiftCard\GiftCardRedeemer
 * @group makerspace_landing_page
 */
class JumpstartJourneyTest extends UnitTestCase {

  /**
   * The ledger, in memory.
   */
  protected FakeGiftCardLedger $ledger;

  /**
   * Chargebee, in memory.
   */
  protected FakeGiftCardChargebee $chargebee;

  /**
   * Users by uid; uid 0 = not logged in.
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected array $users = [];

  /**
   * Main profile join/reactivation dates by uid.
   */
  protected array $profiles = [];

  /**
   * Who is logged in (0 = nobody).
   */
  protected int $currentUid = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->ledger = new FakeGiftCardLedger();
    $this->chargebee = new FakeGiftCardChargebee();
  }

  /**
   * The redeemer, wired to the fakes and the current user.
   */
  protected function redeemer(): GiftCardRedeemer {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($logger);

    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturnCallback(fn($uid) => $this->users[(int) $uid] ?? NULL);
    $profile_storage = $this->createMock(EntityStorageInterface::class);
    $profile_storage->method('loadByProperties')->willReturnCallback(fn(array $p) => isset($this->profiles[(int) $p['uid']]) ? [$this->profile($this->profiles[(int) $p['uid']])] : []);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(fn($type) => $type === 'user' ? $user_storage : $profile_storage);

    $current = $this->createMock(AccountProxyInterface::class);
    $current->method('isAnonymous')->willReturnCallback(fn() => $this->currentUid === 0);
    $current->method('id')->willReturnCallback(fn() => $this->currentUid);

    $redeemer = new GiftCardRedeemer($this->ledger, $this->chargebee, $loggers, $etm, $current);
    $redeemer->setStringTranslation($this->getStringTranslationStub());
    return $redeemer;
  }

  /**
   * A main profile with join and reactivation dates.
   */
  protected function profile(array $dates): ContentEntityInterface {
    $profile = $this->createMock(ContentEntityInterface::class);
    $profile->method('hasField')->willReturn(TRUE);
    $profile->method('get')->willReturnCallback(static function ($field) use ($dates) {
      $value = $dates[$field] ?? NULL;
      return new class($value) {

        public function __construct(public $value) {}

        /**
         *
         */
        public function isEmpty(): bool {
          return $this->value === NULL;
        }

      };
    });
    return $profile;
  }

  /**
   * Adds a user. $joined: days ago, or NULL for no join date.
   */
  protected function user(int $uid, bool $member, ?int $joined, string $chargebee_id = ''): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('isAnonymous')->willReturn(FALSE);
    $user->method('hasRole')->willReturnCallback(static fn($r) => $member && $r === 'member');
    $user->method('getAccountName')->willReturn('user' . $uid);
    $user->method('hasField')->willReturnCallback(static fn($f) => $f === 'field_user_chargebee_id');
    $user->method('get')->willReturnCallback(static fn() => (object) ['value' => $chargebee_id]);
    $this->users[$uid] = $user;
    if ($joined !== NULL) {
      $this->profiles[$uid] = ['field_member_join_date' => date('Y-m-d', strtotime("-$joined days"))];
    }
    return $user;
  }

  /**
   * A fresh Jumpstart card, as the order would issue it.
   */
  protected function jumpstart(): object {
    return $this->ledger->add(['display_code' => '7F3A-92C1', 'kind' => 'jumpstart', 'amount_cents' => 28500, 'discount_id' => 9]);
  }

  /**
   * What cron records when a GEMS registration used the code.
   */
  protected function useSeat(object $card, int $order = 5001): void {
    $card = $this->ledger->load((int) $card->id);
    $take = GiftCardMath::registrationTake('jumpstart', 22500, (int) $card->balance_cents);
    $this->ledger->record((int) $card->id, 'redeem', -$take, 'event_discount', 'contribution:' . $order, 'GEMS registration, order ' . $order);
  }

  /**
   * Join with the code as someone new, then Chargebee settles the checkout.
   */
  protected function joinAndSettle(GiftCardRedeemer $r, object $card, string $sub = 'SUB1'): array {
    $result = $r->resolveJoinCode($card->display_code);
    if (!empty($result['coupon'])) {
      $this->chargebee->redeem($result['coupon']);
      $r->settleCheckout($result['coupon'], $sub, 'CUST1');
    }
    return $result;
  }

  /**
   * Path 1: someone new joins first. The coupon is 3 months at 100%.
   */
  public function testNewPersonJoinsFirstThenUsesSeat(): void {
    $r = $this->redeemer();
    $card = $this->jumpstart();

    $result = $r->resolveJoinCode('7f3a 92c1');
    $this->assertSame('GIFT-7F3A92C1-JS', $result['coupon'] ?? NULL, 'A typed code without its dash still matches.');
    $coupon = $this->chargebee->coupons['GIFT-7F3A92C1-JS'];
    $this->assertSame(['percentage', 100, 'limited_period', 3, 1], [$coupon['discount_type'], $coupon['discount_percentage'], $coupon['duration_type'], $coupon['duration_month'], 1]);

    // Typing it again before checkout gives the same coupon, not a second.
    $this->assertSame($result['coupon'], $r->resolveJoinCode('7F3A-92C1')['coupon']);
    $this->assertCount(1, $this->chargebee->coupons);

    $this->chargebee->redeem($result['coupon']);
    $this->assertTrue($r->settleCheckout($result['coupon'], 'SUB1', 'CUST1'));
    // The webhook can arrive twice.
    $this->assertTrue($r->settleCheckout($result['coupon'], 'SUB1', 'CUST1'));

    $card = $this->ledger->load((int) $card->id);
    $this->assertTrue($r->jumpstartMonthsUsed($card));
    $this->assertFalse($r->jumpstartSeatUsed($card), 'Joining does not touch the seat.');
    $this->assertSame(28500, (int) $card->balance_cents);
    $this->assertCount(1, $this->ledger->on((int) $card->id, 'chargebee_coupon'), 'Settled once.');
    $this->assertSame('', $r->unusableReason($card), 'The seat is still usable.');

    $this->useSeat($card);
    $card = $this->ledger->load((int) $card->id);
    $this->assertTrue($r->jumpstartSeatUsed($card));
    $this->assertSame('used', $card->status);
    $this->assertStringContainsString('already been used', $r->unusableReason($card));
    $this->assertEmpty($this->chargebee->credits, 'No credit on the new-member path.');
  }

  /**
   * Path 2: seat first, then join. The months still work at 0 balance.
   */
  public function testSeatFirstThenJoin(): void {
    $r = $this->redeemer();
    $card = $this->jumpstart();
    $this->useSeat($card);
    $card = $this->ledger->load((int) $card->id);
    $this->assertSame(0, (int) $card->balance_cents);
    $this->assertSame('', $r->unusableReason($card));

    $result = $this->joinAndSettle($r, $card);
    $this->assertSame('GIFT-7F3A92C1-JS', $result['coupon'] ?? NULL);
    $this->assertTrue($r->jumpstartMonthsUsed($this->ledger->load((int) $card->id)));
    $this->assertStringContainsString('already been used', $r->unusableReason($this->ledger->load((int) $card->id)));
  }

  /**
   * The seat is the whole card, whatever the cohort cost that day.
   */
  public function testSeatTakesWholeBalance(): void {
    $this->assertSame(28500, GiftCardMath::registrationTake('jumpstart', 22500, 28500));
    $this->assertSame(28500, GiftCardMath::registrationTake('jumpstart', 30000, 28500));
    $this->assertSame(0, GiftCardMath::registrationTake('jumpstart', 28500, 0));
    // Class cards lose only what the code took off.
    $this->assertSame(7500, GiftCardMath::registrationTake('workshop', 7500, 15000));
    $this->assertSame(5000, GiftCardMath::registrationTake('workshop', 7500, 5000));
  }

  /**
   * Months already used: the join form says so and points at the seat.
   */
  public function testMonthsUsedJoinRefusedSeatStillOffered(): void {
    $r = $this->redeemer();
    $card = $this->jumpstart();
    $this->joinAndSettle($r, $card);

    $again = $r->resolveJoinCode('7F3A-92C1');
    $this->assertArrayNotHasKey('coupon', $again);
    $this->assertStringContainsString('already been used', $again['error']);
    $this->assertStringContainsString('GEMS seat still works', $again['error']);
  }

  /**
   * Coupon used at a checkout the webhook has not settled: no second coupon.
   */
  public function testUnsettledCheckoutBlocksSecondJoin(): void {
    $r = $this->redeemer();
    $card = $this->jumpstart();
    $coupon = $r->resolveJoinCode($card->display_code)['coupon'];
    $this->chargebee->redeem($coupon);

    $again = $r->resolveJoinCode($card->display_code);
    $this->assertArrayNotHasKey('coupon', $again);
    $this->assertStringContainsString('already being applied', $again['error']);
  }

  /**
   * Former member (no member role) coming back may use the months.
   */
  public function testFormerMemberMayJoinWithMonths(): void {
    $this->user(20, FALSE, 900);
    $this->currentUid = 20;
    $result = $this->redeemer()->resolveJoinCode($this->jumpstart()->display_code);
    $this->assertSame('GIFT-7F3A92C1-JS', $result['coupon'] ?? NULL);
  }

  /**
   * Long-standing member: months refused on join form and redeem page.
   */
  public function testLongStandingMemberRefusedMonthsKeepsSeat(): void {
    $member = $this->user(30, TRUE, 400, 'CUST30');
    $this->currentUid = 30;
    $r = $this->redeemer();
    $card = $this->jumpstart();

    $join = $r->resolveJoinCode($card->display_code);
    $this->assertArrayNotHasKey('coupon', $join);
    $this->assertStringContainsString('for new members', $join['error']);
    $this->assertStringContainsString('GEMS seat still works', $join['error']);

    try {
      $r->redeemForMember($card, $member);
      $this->fail('A long-standing member got the months.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('for new members', $e->getMessage());
    }
    $this->assertEmpty($this->chargebee->credits);
    $this->assertEmpty($this->chargebee->coupons);
    $this->assertSame('', $r->unusableReason($this->ledger->load((int) $card->id)));
  }

  /**
   * A member with no join date on file counts as long-standing.
   */
  public function testMemberWithoutJoinDateRefused(): void {
    $this->currentUid = 31;
    $this->user(31, TRUE, NULL, 'CUST31');
    $this->assertFalse($this->redeemer()->jumpstartMonthsAllowed($this->users[31]));
  }

  /**
   * Joined in the last 30 days: $186 credit once; the seat is untouched.
   */
  public function testRecentMemberGetsCreditOnce(): void {
    $member = $this->user(40, TRUE, 10, 'CUST40');
    $this->currentUid = 40;
    $r = $this->redeemer();
    $card = $this->jumpstart();

    $message = $r->redeemForMember($card, $member);
    $this->assertStringContainsString('$186', $message);
    $this->assertSame([['CUST40', GiftCardMath::JUMPSTART_MEMBER_CREDIT_CENTS]], array_map(static fn($c) => [$c[0], $c[1]], $this->chargebee->credits));

    $card = $this->ledger->load((int) $card->id);
    $this->assertTrue($r->jumpstartMonthsUsed($card));
    $this->assertSame(28500, (int) $card->balance_cents, 'Seat still there.');

    try {
      $r->redeemForMember($card, $member);
      $this->fail('Credited twice.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('already been used', $e->getMessage());
    }
    $this->assertCount(1, $this->chargebee->credits);

    // And the join form now refuses the months too.
    $this->assertStringContainsString('already been used', $r->resolveJoinCode($card->display_code)['error']);
  }

  /**
   * 31 days after joining is no longer new.
   */
  public function testThirtyDayEdge(): void {
    $this->user(41, TRUE, 29);
    $this->user(42, TRUE, 31);
    $r = $this->redeemer();
    $this->assertTrue($r->jumpstartMonthsAllowed($this->users[41]));
    $this->assertFalse($r->jumpstartMonthsAllowed($this->users[42]));
  }

  /**
   * Chargebee down during a member credit: nothing is used, retry works.
   */
  public function testCreditFailureLeavesMonthsUnused(): void {
    $member = $this->user(50, TRUE, 5, 'CUST50');
    $this->currentUid = 50;
    $r = $this->redeemer();
    $card = $this->jumpstart();

    $this->chargebee->down = TRUE;
    try {
      $r->redeemForMember($card, $member);
      $this->fail('No error when Chargebee was down.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Nothing was used', $e->getMessage());
    }
    $this->assertFalse($r->jumpstartMonthsUsed($this->ledger->load((int) $card->id)));

    $this->chargebee->down = FALSE;
    $r->redeemForMember($card, $member);
    $this->assertCount(1, $this->chargebee->credits);
  }

  /**
   * Chargebee down at the join form: a clear message, no coupon.
   */
  public function testJoinFormWhenChargebeeDown(): void {
    $this->chargebee->down = TRUE;
    $result = $this->redeemer()->resolveJoinCode($this->jumpstart()->display_code);
    $this->assertArrayNotHasKey('coupon', $result);
    $this->assertStringContainsString("couldn't reach our billing system", $result['error']);
  }

  /**
   * A member with no Chargebee account is sent to the join form.
   */
  public function testMemberWithoutChargebeeAccount(): void {
    $member = $this->user(60, TRUE, 3, '');
    $this->expectExceptionMessage("isn't linked to a membership");
    $this->redeemer()->redeemForMember($this->jumpstart(), $member);
  }

  /**
   * A void card (refund) is refused everywhere.
   */
  public function testVoidCardRefused(): void {
    $r = $this->redeemer();
    $card = $this->jumpstart();
    $this->ledger->record((int) $card->id, 'void', -28500, 'staff', 'void:1', 'Refunded');
    $card = $this->ledger->load((int) $card->id);
    $this->assertSame('void', $card->status);
    $this->assertStringContainsString('no longer valid', $r->resolveJoinCode($card->display_code)['error']);
    $this->assertStringContainsString('no longer valid', $r->unusableReason($card));
    $this->assertEmpty($this->chargebee->coupons);
  }

  /**
   * A code that is not a card is left to the join form's own coupon check.
   */
  public function testUnknownCodeNotMatched(): void {
    $this->jumpstart();
    $this->assertSame(['matched' => FALSE], $this->redeemer()->resolveJoinCode('SPRING25'));
  }

  /**
   * A workshop card typed on the join form is pointed at registration.
   */
  public function testWorkshopCardOnJoinForm(): void {
    $card = $this->ledger->add(['display_code' => 'W111-2222', 'kind' => 'workshop', 'amount_cents' => 5000]);
    $result = $this->redeemer()->resolveJoinCode($card->display_code);
    $this->assertStringContainsString('workshop gift card', $result['error']);
  }

  /**
   * Membership card: first invoice off by coupon, the rest as credit, once.
   */
  public function testMembershipCardSettlesRestAsCredit(): void {
    $r = $this->redeemer();
    $card = $this->ledger->add(['display_code' => 'M111-2222', 'kind' => 'membership', 'amount_cents' => 18600]);
    $coupon = $r->resolveJoinCode($card->display_code)['coupon'];
    $this->assertSame('GIFT-M1112222-18600', $coupon);
    $this->chargebee->redeem($coupon);
    $this->chargebee->invoices['SUB9'] = ['id' => 'INV1', 'discounts' => [['entity_id' => $coupon, 'amount' => 6200]]];

    $this->assertTrue($r->settleCheckout($coupon, 'SUB9', 'CUST9'));
    $this->assertTrue($r->settleCheckout($coupon, 'SUB9', 'CUST9'));
    $card = $this->ledger->load((int) $card->id);
    $this->assertSame(0, (int) $card->balance_cents);
    $this->assertSame([['CUST9', 12400]], array_map(static fn($c) => [$c[0], $c[1]], $this->chargebee->credits));
  }

  /**
   * Settlement waits (returns FALSE) until the first invoice exists.
   */
  public function testMembershipSettleWaitsForInvoice(): void {
    $r = $this->redeemer();
    $card = $this->ledger->add(['display_code' => 'M333-4444', 'kind' => 'membership', 'amount_cents' => 6200]);
    $coupon = $r->resolveJoinCode($card->display_code)['coupon'];
    $this->assertFalse($r->settleCheckout($coupon, 'SUB10', 'CUST10'));
    $this->assertSame(6200, (int) $this->ledger->load((int) $card->id)->balance_cents);
  }

}
