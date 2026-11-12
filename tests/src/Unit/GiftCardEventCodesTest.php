<?php

namespace Drupal\Tests\makerspace_landing_page\Unit;

use Drupal\makerspace_landing_page\GiftCard\GiftCardEventCodes;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_landing_page\GiftCard\GiftCardEventCodes
 * @group makerspace_landing_page
 */
class GiftCardEventCodesTest extends UnitTestCase {

  /**
   * Jumpstart cards get a code too, so the GEMS seat needs no staff.
   */
  public function testAppliesTo(): void {
    $this->assertTrue(GiftCardEventCodes::appliesTo('workshop'));
    $this->assertTrue(GiftCardEventCodes::appliesTo('credit'));
    $this->assertTrue(GiftCardEventCodes::appliesTo('jumpstart'));
    $this->assertFalse(GiftCardEventCodes::appliesTo('membership'));
  }

  /**
   * A Jumpstart code works on GEMS cohorts only; other cards on classes.
   */
  public function testFilterFor(): void {
    $gems = json_decode(GiftCardEventCodes::filterFor('jumpstart'), TRUE);
    $this->assertSame(['IN' => ['7']], $gems['Event']['event_type_id']);
    $this->assertSame(['LIKE' => '%GEMS%'], $gems['Event']['title']);
    $this->assertSame(GiftCardEventCodes::EVENT_FILTER, GiftCardEventCodes::filterFor('workshop'));
    // cividiscount_item.filters is VARCHAR(255).
    $this->assertLessThanOrEqual(255, strlen(GiftCardEventCodes::GEMS_FILTER));
  }

}
