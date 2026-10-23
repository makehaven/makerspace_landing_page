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
      return $designs[$page_name];
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
      'redeem' => 'Email info@makehaven.org with your code, and we\'ll apply it to your account.',
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
          '<b>GEMS</b>: 10 weeks of guided sessions with a coach and a small cohort',
          '<b>Badges</b> on the laser cutter, woodshop, sewing machines and MIG welder',
          '<b>Materials</b> for the class projects',
        ],
        'footnote' => 'intro · digital fab · wood · metal · textiles · graduation',
        'redeem' => 'Email info@makehaven.org with your code, or visit makehaven.org/jumpstart. Pick a GEMS cohort and your 3 months of membership begin with it.',
      ],
    ];
  }

}
