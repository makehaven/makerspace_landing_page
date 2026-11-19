<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

/**
 * What a card's state is across the ledger, CiviDiscount and Chargebee.
 *
 * A card's value can sit in three places at once: the ledger balance, a
 * CiviDiscount code (classes, the Jumpstart's GEMS seat) and a Chargebee
 * coupon (membership, the Jumpstart's 3 months). They are kept in step by
 * hooks, cron and the Chargebee webhook, so when one of those misses, the
 * places disagree. This class reads plain facts gathered by
 * GiftCardInspector and says, in words staff can act on, what each part of
 * the card is doing, what disagrees, and what the holder can still do.
 *
 * No database or HTTP here, so every path is unit-tested.
 */
final class GiftCardState {

  /**
   * Hours after which an unreconciled registration or checkout is a problem.
   *
   * Cron runs hourly; two runs missed is worth a look.
   */
  public const GRACE_HOURS = 3;

  /**
   * Evaluates a card.
   *
   * @param array $facts
   *   Keys:
   *   - card: the ledger row (object or array).
   *   - now: unix time.
   *   - order_status: CiviCRM status name of the order, or NULL.
   *   - discount: NULL (none on the card, or the row is gone) or an array
   *     with is_active, count_use, count_max, amount (dollars string).
   *   - discount_missing: TRUE when the card names a code that no longer
   *     exists in CiviDiscount.
   *   - code_uses: list of arrays with contribution_id, used (unix),
   *     recorded (bool: the ledger has it), event (title or ''),
   *     participant_status (name or '').
   *   - months: NULL or an array with channel (chargebee_coupon or
   *     chargebee_credit), reference, created, note. Jumpstart only.
   *   - coupon: NULL when not looked up; 'missing' when Chargebee has no
   *     such coupon; else an array with status and redemptions.
   *
   * @return array
   *   Keys:
   *   - parts: list of [label, state, text]; state is one of unused, used,
   *     void, n/a.
   *   - problems: list of strings; each says what disagrees and what to do.
   *   - next: list of strings; what the holder can still do.
   */
  public static function evaluate(array $facts): array {
    $card = (object) $facts['card'];
    $now = (int) ($facts['now'] ?? time());
    $kind = (string) $card->kind;
    $balance = (int) $card->balance_cents;
    $void = $card->status === 'void';
    $code = (string) $card->display_code;
    $discount = $facts['discount'] ?? NULL;
    $uses = $facts['code_uses'] ?? [];
    $months = $facts['months'] ?? NULL;
    $coupon = $facts['coupon'] ?? NULL;
    $grace = self::GRACE_HOURS * 3600;

    $parts = [];
    $problems = [];
    $next = [];

    // The order behind the card.
    $order = $facts['order_status'] ?? NULL;
    if ($card->contribution_id && $order !== NULL && $order !== 'Completed' && !$void) {
      $problems[] = sprintf('The order is %s in CiviCRM but the card is still usable. If the money was refunded, void the card (that also switches its discount code off).', $order === '' ? 'missing' : $order);
    }

    // The class discount code (workshop, credit, Jumpstart seat).
    $has_code_part = GiftCardEventCodes::appliesTo($kind);
    if ($has_code_part) {
      if (!empty($facts['discount_missing'])) {
        $problems[] = 'The card points at a discount code that no longer exists in CiviDiscount. Someone deleted it; the holder cannot use the card at registration.';
      }
      elseif (!$card->discount_id && !$void && $balance > 0) {
        $problems[] = 'The card has no discount code, so it cannot be used at registration. Issuing did not finish: the next cron run should make it; if it does not, check the log for "makerspace_landing_page".';
      }
      if (is_array($discount)) {
        $usable = !$void && $balance > 0;
        if (!empty($discount['is_active']) && !$usable) {
          $problems[] = 'The discount code is still switched on although the card is ' . ($void ? 'void' : 'used up') . '. Cron switches it off; until then someone could register with it.';
        }
        if (empty($discount['is_active']) && $usable) {
          $problems[] = 'The discount code is switched off but the card still has value. Cron switches it back on; if it stays off, the holder will be told the code is invalid.';
        }
        if ($usable && !empty($discount['is_active']) && (int) $discount['count_use'] >= (int) $discount['count_max']) {
          $problems[] = sprintf('The discount code has no uses left (%d of %d) although the card still has value.', (int) $discount['count_use'], (int) $discount['count_max']);
        }
        if ($usable && abs((float) $discount['amount'] * 100 - $balance) >= 1) {
          $problems[] = sprintf('The discount code is worth $%s but the card has %s left.', number_format((float) $discount['amount'], 2), GiftCardMath::dollars($balance));
        }
      }
      foreach ($uses as $use) {
        if (empty($use['recorded']) && $now - (int) $use['used'] > $grace) {
          $problems[] = sprintf('A registration (order %d, %s) used the code but the ledger has not recorded it after %d hours. Cron records it; check that cron is running.', (int) $use['contribution_id'], date('Y-m-d H:i', (int) $use['used']), self::GRACE_HOURS);
        }
      }
    }

    if ($kind === 'jumpstart') {
      // Part 1: the GEMS seat. Its value is the card balance.
      $seat_used = $balance <= 0;
      $seat_use = NULL;
      foreach ($uses as $use) {
        $seat_use = $use;
      }
      if ($void) {
        $parts[] = ['GEMS seat', 'void', 'Card is void.'];
      }
      elseif ($seat_used) {
        $where = $seat_use ? sprintf('%s, order %d%s', $seat_use['event'] ?: 'a GEMS cohort', (int) $seat_use['contribution_id'], !empty($seat_use['participant_status']) ? ' (' . $seat_use['participant_status'] . ')' : '') : 'recorded in the ledger';
        $parts[] = ['GEMS seat', 'used', 'Used: ' . $where . '.'];
        if ($seat_use && in_array($seat_use['participant_status'] ?? '', ['Cancelled', 'Expired', 'Rejected'], TRUE)) {
          $problems[] = sprintf('The GEMS registration that used the seat is %s. The seat stays used: if they should get it back, record a correction of %s on this card (cron then switches the code back on).', $seat_use['participant_status'], GiftCardMath::dollars((int) $card->amount_cents));
        }
      }
      else {
        $pending = $uses ? ' A registration used the code; cron records it within the hour.' : '';
        $parts[] = ['GEMS seat', 'unused', 'Not used. Code ' . $code . ' takes the whole GEMS price off one cohort registration.' . $pending];
        $next[] = 'Register for a GEMS cohort at makehaven.org/gems and enter ' . $code . ' in the discount code box: the price comes to $0.';
      }

      // Part 2: the 3 months.
      if ($void) {
        $parts[] = ['3 months', 'void', 'Card is void.'];
        if (is_array($coupon) && ($coupon['status'] ?? '') === 'active' && !$months) {
          $problems[] = 'The card is void but its Chargebee coupon ' . GiftCardMath::jumpstartCouponId((string) $card->code) . ' is still active. The join form refuses a void card, so it cannot be reached that way; archive it in Chargebee to be safe.';
        }
      }
      elseif ($months) {
        $how = $months['channel'] === 'chargebee_credit'
          ? sprintf('added as %s credit to an existing member account', GiftCardMath::dollars(GiftCardMath::JUMPSTART_MEMBER_CREDIT_CENTS))
          : 'started as 3 free months on a new subscription';
        $parts[] = ['3 months', 'used', sprintf('Used %s: %s (%s).', date('Y-m-d', (int) $months['created']), $how, $months['reference'])];
        if ($months['channel'] === 'chargebee_coupon' && is_array($coupon) && (int) ($coupon['redemptions'] ?? 0) === 0 && ($coupon['status'] ?? '') === 'active') {
          $problems[] = 'The ledger says the free months started, but Chargebee shows the coupon unused. Look at the subscription named above in Chargebee.';
        }
      }
      else {
        $text = 'Not used.';
        if (is_array($coupon)) {
          $text .= ' The Chargebee coupon exists (made when someone typed the code on the join form)';
          if (($coupon['status'] ?? '') !== 'active' || (int) ($coupon['redemptions'] ?? 0) > 0) {
            $text .= ' and has been used at a checkout';
            $problems[] = 'Chargebee shows the free-months coupon used at a checkout, but the ledger has not recorded it. The webhook settles it; if this stays for more than a few hours, look for the subscription with coupon ' . GiftCardMath::jumpstartCouponId((string) $card->code) . ' in Chargebee.';
          }
          $text .= '.';
        }
        elseif ($coupon === 'missing') {
          $text .= ' Nobody has typed the code on the join form yet (no Chargebee coupon).';
        }
        $parts[] = ['3 months', 'unused', $text];
        $next[] = 'New or returning member: join at makehaven.org/join-makehaven and enter ' . $code . ' as the gift certificate code; the first 3 months are free.';
        $next[] = sprintf('Joined in the last %d days: add the months at /gift-card/redeem (%s credit). Members of longer standing cannot use the months; the GEMS seat still works.', GiftCardRedeemer::JUMPSTART_NEW_MEMBER_DAYS, GiftCardMath::dollars(GiftCardMath::JUMPSTART_MEMBER_CREDIT_CENTS));
      }
    }
    else {
      $state = $void ? 'void' : ($balance > 0 ? 'unused' : 'used');
      $label = match ($kind) {
        'workshop' => 'Class credit',
        'membership' => 'Membership credit',
        default => 'Credit',
      };
      $text = $void ? 'Card is void.' : sprintf('%s of %s left.', GiftCardMath::dollars($balance), GiftCardMath::dollars((int) $card->amount_cents));
      $parts[] = [$label, $state, $text];
      if (!$void && $balance > 0) {
        $next[] = match ($kind) {
          'workshop' => 'Register for a class and enter ' . $code . ' in the discount code box.',
          'membership' => 'Join with ' . $code . ' as the gift certificate code, or add it at /gift-card/redeem if already a member.',
          default => 'Staff decide how to apply it (/admin/gift-cards).',
        };
        if ($kind === 'membership' && is_array($coupon) && (($coupon['status'] ?? '') !== 'active' || (int) ($coupon['redemptions'] ?? 0) > 0)) {
          $problems[] = 'Chargebee shows this card\'s coupon used at a checkout, but the ledger still has the balance. The webhook settles it; if this stays for more than a few hours, check the subscription in Chargebee.';
        }
      }
    }

    // Recipient email.
    if ($card->delivery === 'recipient' && !$void && empty($card->recipient_emailed) && trim((string) $card->recipient_email) !== '') {
      $due = $card->deliver_after ? (int) $card->deliver_after : (int) $card->created;
      if ($now - $due > $grace) {
        $problems[] = sprintf('The recipient email was due %s and has not gone out. Send it from the card\'s staff page (/admin/gift-cards), and check that cron is running.', date('Y-m-d', $due));
      }
    }

    return ['parts' => $parts, 'problems' => $problems, 'next' => $next];
  }

}
