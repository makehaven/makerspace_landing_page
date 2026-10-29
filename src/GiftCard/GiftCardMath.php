<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

/**
 * Pure gift card rules, kept apart from storage and APIs so they are testable.
 */
final class GiftCardMath {

  /**
   * Prefix of every Chargebee coupon made from a gift card.
   */
  public const COUPON_PREFIX = 'GIFT-';

  /**
   * Months of membership the Jumpstart includes.
   */
  public const JUMPSTART_MONTHS = 3;

  /**
   * Which kind of card each order page sells.
   *
   * Membership cards are credit toward dues (Chargebee), workshop cards credit
   * toward class registration (CiviCRM events), and the Jumpstart a GEMS seat
   * plus 3 free months of membership. Anything else sold as a Gift Card is
   * general credit ("credit"); staff decide at redemption.
   */
  public const PAGE_KINDS = [
    'membership_gift_card' => 'membership',
    'workshop_gift_card' => 'workshop',
    'jumpstart_gift' => 'jumpstart',
  ];

  /**
   * Upper case, letters and digits only: "7f3a-92c1 " and "7F3A92C1" match.
   */
  public static function normalize(string $code): string {
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
  }

  /**
   * The kind of card a contribution page sells.
   */
  public static function kindForPage(?string $page_name): string {
    return self::PAGE_KINDS[(string) $page_name] ?? 'credit';
  }

  /**
   * Chargebee coupon id that spends a membership card's current balance.
   *
   * The balance is part of the id, so a card partly spent elsewhere gets a
   * new coupon for what is left instead of reusing one for the old amount
   * (Chargebee won't change a coupon's amount once it exists).
   */
  public static function membershipCouponId(string $code, int $balance_cents): string {
    return self::COUPON_PREFIX . self::normalize($code) . '-' . $balance_cents;
  }

  /**
   * Chargebee coupon id for a Jumpstart card's free months.
   */
  public static function jumpstartCouponId(string $code): string {
    return self::COUPON_PREFIX . self::normalize($code) . '-JS';
  }

  /**
   * Reads a gift coupon id back into its card code.
   *
   * @return array|null
   *   Keys: code, jumpstart (bool), balance_cents (int|null); NULL when the id
   *   is not a gift card coupon.
   */
  public static function parseCouponId(string $coupon_id): ?array {
    if (!preg_match('/^' . preg_quote(self::COUPON_PREFIX, '/') . '([A-Z0-9]+)-(JS|\d+)$/', $coupon_id, $m)) {
      return NULL;
    }
    return [
      'code' => $m[1],
      'jumpstart' => $m[2] === 'JS',
      'balance_cents' => $m[2] === 'JS' ? NULL : (int) $m[2],
    ];
  }

  /**
   * Cents a coupon actually took off a Chargebee invoice.
   */
  public static function invoiceDiscount(array $invoice, string $coupon_id): int {
    $total = 0;
    foreach ($invoice['discounts'] ?? [] as $discount) {
      if (($discount['entity_id'] ?? NULL) === $coupon_id) {
        $total += (int) ($discount['amount'] ?? 0);
      }
    }
    if ($total > 0) {
      return $total;
    }
    foreach ($invoice['line_item_discounts'] ?? [] as $discount) {
      if (($discount['coupon_id'] ?? $discount['entity_id'] ?? NULL) === $coupon_id) {
        $total += (int) ($discount['discount_amount'] ?? 0);
      }
    }
    return $total;
  }

  /**
   * Cents a CiviDiscount code took off one registration's line items.
   *
   * CiviDiscount lowers each line's price and leaves the price option pointing
   * at the full fee, so the discount is full fee minus what was charged.
   *
   * @param array $lines
   *   Rows with qty, line_total and full_amount (the price option's amount).
   */
  public static function eventDiscount(array $lines): int {
    $total = 0;
    foreach ($lines as $line) {
      $full = (float) ($line['full_amount'] ?? 0) * (float) ($line['qty'] ?? 1);
      $charged = (float) ($line['line_total'] ?? 0);
      if ($full > $charged) {
        $total += (int) round(($full - $charged) * 100);
      }
    }
    return $total;
  }

  /**
   * Formats cents as dollars: 18600 -> "$186", 2550 -> "$25.50".
   */
  public static function dollars(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . '$' . ($cents % 100 === 0 ? number_format(intdiv($cents, 100)) : number_format($cents / 100, 2));
  }

}
