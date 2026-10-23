<?php

namespace Drupal\Tests\makerspace_landing_page\Unit;

use Drupal\makerspace_landing_page\GiftCard\GiftCardCode;
use Drupal\makerspace_landing_page\GiftCard\GiftCardDesigns;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_landing_page\GiftCard\GiftCardCode
 * @group makerspace_landing_page
 */
class GiftCardCodeTest extends UnitTestCase {

  /**
   * The printed code is the first 8 hex characters, upper-cased and split.
   */
  public function testCodeFromInvoice(): void {
    $this->assertSame('7F3A-92C1', GiftCardCode::fromInvoice('7f3a92c1d4e5f60718293a4b5c6d7e8f'));
    $this->assertSame('', GiftCardCode::fromInvoice(NULL));
    $this->assertSame('', GiftCardCode::fromInvoice('abc'));
  }

  /**
   * The print link only opens with the exact, full-length invoice_id.
   */
  public function testKeyMatches(): void {
    $invoice = '7f3a92c1d4e5f60718293a4b5c6d7e8f';
    $this->assertTrue(GiftCardCode::keyMatches($invoice, $invoice));
    $this->assertFalse(GiftCardCode::keyMatches($invoice, ''));
    $this->assertFalse(GiftCardCode::keyMatches($invoice, '7f3a92c1'), 'The printed code must not open the link.');
    $this->assertFalse(GiftCardCode::keyMatches($invoice, strtoupper($invoice)));
    // No invoice_id (e.g. a back-office entry) means no key can ever match.
    $this->assertFalse(GiftCardCode::keyMatches(NULL, ''));
    $this->assertFalse(GiftCardCode::keyMatches('', ''));
    $this->assertFalse(GiftCardCode::keyMatches('1234', '1234'));
  }

  /**
   * Unknown pages fall back to a design that names the amount.
   */
  public function testDesignFallback(): void {
    $this->assertStringContainsString('workshop', GiftCardDesigns::forPage('jumpstart_gift', 325)['headline']);
    $default = GiftCardDesigns::forPage('some_future_page', 62);
    $this->assertStringContainsString('$62.00', implode(' ', $default['items']));
  }

}
