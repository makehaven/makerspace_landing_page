<?php

namespace Drupal\makerspace_landing_page\GiftCard;

/**
 * Printable gift card designs, keyed by CiviCRM contribution page name.
 *
 * Adding a gift card: sell it on a CiviCRM contribution page with financial
 * type "Gift Card" and the "Gift Card Details" profile, then add an entry here
 * keyed by that page's machine name. Pages without an entry print the default
 * design, which names the amount instead of a product.
 */
final class GiftCardDesigns {

  /**
   * Returns the design for a contribution page, falling back to the default.
   *
   * @param string|null $page_name
   *   The contribution page's machine name.
   * @param float $amount
   *   The amount paid, used by the default design.
   */
  public static function forPage(?string $page_name, float $amount): array {
    $designs = self::designs();
    if ($page_name !== NULL && isset($designs[$page_name])) {
      $design = $designs[$page_name];
      $value = '$' . number_format($amount, $amount == floor($amount) ? 0 : 2);
      $design['items'] = array_map(static fn(string $item) => str_replace('@amount', $value, $item), $design['items']);
      return $design;
    }
    return [
      'headline' => 'A gift of making.',
      'inside_title' => 'What\'s inside',
      'items' => [
        '<b>$' . number_format($amount, 2) . '</b> of credit at MakeHaven',
        'Use it toward membership, workshops or other MakeHaven services',
        'A 24/7 community workspace with wood, metal, textiles, laser, 3D printing and electronics',
      ],
      'footnote' => '',
      'redeem' => 'Use it at makehaven.org/redeem: enter your code when you join, or add it to your membership if you\'re already a member.',
    ];
  }

  /**
   * All named designs.
   */
  private static function designs(): array {
    return [
      'jumpstart_gift' => [
        'headline' => 'The gift inside is a whole workshop.',
        'inside_title' => 'What\'s inside',
        'items' => [
          '<b>3 months</b> of 24/7 makerspace membership',
          '<b>GEMS</b> (Guided Exploration of Makerspace Skills): 10 weeks with a coach and a small cohort',
          '<b>Badges</b> on the laser cutter, woodshop, sewing machines and MIG welder',
          '<b>Materials</b> for the class projects',
        ],
        'footnote' => 'intro · digital fab · wood · metal · textiles · graduation',
        'redeem' => 'Join at makehaven.org/redeem with your code: your 3 months of membership are free. Then email info@makehaven.org to pick your GEMS cohort.',
      ],
      'membership_gift_card' => [
        'headline' => 'A gift of making.',
        'inside_title' => 'What\'s inside',
        'items' => [
          '<b>@amount</b> toward MakeHaven membership',
          '24/7 access to a community workshop: wood, metal, textiles, laser, 3D printing and electronics',
          'Badge classes, member events, and people who will show you how',
        ],
        'footnote' => 'Never expires · use it toward any membership plan',
        'redeem' => 'Use it at makehaven.org/redeem: new members enter the code when they join; current members add it to their account in one step.',
      ],
      'workshop_gift_card' => [
        'headline' => 'Learn something new.',
        'inside_title' => 'What\'s inside',
        'items' => [
          '<b>@amount</b> toward MakeHaven classes',
          'Woodworking, welding, sewing, laser cutting, electronics and more',
          'Small, hands-on classes taught by local makers',
        ],
        'footnote' => 'Never expires · whatever isn\'t spent stays on the card',
        'redeem' => 'Pick a class at makehaven.org/events and enter your code in the discount code box when you register.',
      ],
    ];
  }

}
