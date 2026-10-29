<?php

namespace Drupal\Tests\makerspace_landing_page\Unit;

use Drupal\makerspace_landing_page\GiftCard\GiftCardDesigns;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_landing_page\GiftCard\GiftCardMath
 * @group makerspace_landing_page
 */
class GiftCardMathTest extends UnitTestCase {

  /**
   * People type codes with spaces, dashes and lower case.
   */
  public function testNormalize(): void {
    $this->assertSame('7F3A92C1', GiftCardMath::normalize(' 7f3a-92c1 '));
    $this->assertSame('7F3A92C1', GiftCardMath::normalize('7F3A 92C1'));
    $this->assertSame('', GiftCardMath::normalize('--'));
  }

  /**
   * Each order page sells one kind of card; anything else is general credit.
   */
  public function testKindForPage(): void {
    $this->assertSame('membership', GiftCardMath::kindForPage('membership_gift_card'));
    $this->assertSame('workshop', GiftCardMath::kindForPage('workshop_gift_card'));
    $this->assertSame('jumpstart', GiftCardMath::kindForPage('jumpstart_gift'));
    $this->assertSame('credit', GiftCardMath::kindForPage(NULL));
  }

  /**
   * Coupon ids carry the card and the balance, and read back.
   */
  public function testCouponIdsRoundTrip(): void {
    $id = GiftCardMath::membershipCouponId('7f3a-92c1', 18600);
    $this->assertSame('GIFT-7F3A92C1-18600', $id);
    $this->assertSame(['code' => '7F3A92C1', 'jumpstart' => FALSE, 'balance_cents' => 18600], GiftCardMath::parseCouponId($id));

    $js = GiftCardMath::jumpstartCouponId('7F3A-92C1');
    $this->assertSame(['code' => '7F3A92C1', 'jumpstart' => TRUE, 'balance_cents' => NULL], GiftCardMath::parseCouponId($js));

    // Kate's hand-made coupons and promo codes are not gift card coupons.
    $this->assertNull(GiftCardMath::parseCouponId('GC2M-399'));
    $this->assertNull(GiftCardMath::parseCouponId('GOLD-ZNDE'));
  }

  /**
   * What the coupon took off an invoice, from either discount listing.
   */
  public function testInvoiceDiscount(): void {
    $coupon = 'GIFT-7F3A92C1-18600';
    $invoice = [
      'discounts' => [
      ['entity_id' => $coupon, 'amount' => 6200],
      ['entity_id' => 'OTHER', 'amount' => 1000],
      ],
    ];
    $this->assertSame(6200, GiftCardMath::invoiceDiscount($invoice, $coupon));

    $line_level = ['line_item_discounts' => [['coupon_id' => $coupon, 'discount_amount' => 4000]]];
    $this->assertSame(4000, GiftCardMath::invoiceDiscount($line_level, $coupon));
    $this->assertSame(0, GiftCardMath::invoiceDiscount([], $coupon));
  }

  /**
   * A class discount is the full fee minus what was charged, per line.
   *
   * Real rows from live: a $75 code on a $98 class charged $23; one order of
   * two $56 tickets with a $150 code charged $0 for both.
   */
  public function testEventDiscount(): void {
    $this->assertSame(7500, GiftCardMath::eventDiscount([['qty' => 1, 'line_total' => '23.00', 'full_amount' => '98.000000000']]));
    $this->assertSame(11200, GiftCardMath::eventDiscount([
      ['qty' => 1, 'line_total' => '0.00', 'full_amount' => '56'],
      ['qty' => 1, 'line_total' => '0.00', 'full_amount' => '56'],
    ]));
    // A line charged in full took nothing.
    $this->assertSame(0, GiftCardMath::eventDiscount([['qty' => 1, 'line_total' => '40.00', 'full_amount' => '40']]));
  }

  /**
   * Dollars without needless cents.
   */
  public function testPresetBonus(): void {
    $line = static fn(string $page, string $option, $qty = 1) => ['page' => $page, 'option' => $option, 'qty' => $qty];
    $this->assertSame(2500, GiftCardMath::presetBonus([$line('membership_gift_card', 'six_months')]));
    $this->assertSame(10000, GiftCardMath::presetBonus([$line('membership_gift_card', 'twelve_months', '2.00')]));
    $this->assertSame(0, GiftCardMath::presetBonus([$line('membership_gift_card', 'three_months')]));
    $this->assertSame(0, GiftCardMath::presetBonus([$line('membership_gift_card', 'other_amount')]));
    $this->assertSame(0, GiftCardMath::presetBonus([$line('workshop_gift_card', 'six_months')]));
    $this->assertSame(0, GiftCardMath::presetBonus([]));
  }

  public function testDollars(): void {
    $this->assertSame('$186', GiftCardMath::dollars(18600));
    $this->assertSame('$25.50', GiftCardMath::dollars(2550));
    $this->assertSame('-$62', GiftCardMath::dollars(-6200));
    $this->assertSame('$1,200', GiftCardMath::dollars(120000));
  }

  /**
   * The new card designs print the amount bought.
   */
  public function testDesignsShowAmount(): void {
    $design = GiftCardDesigns::forPage('workshop_gift_card', 75.0);
    $this->assertSame('<b>$75</b> toward MakeHaven classes', $design['items'][0]);
    $design = GiftCardDesigns::forPage('membership_gift_card', 186.0);
    $this->assertSame('<b>$186</b> toward MakeHaven membership', $design['items'][0]);
  }

}
