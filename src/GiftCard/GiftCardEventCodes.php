<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\civicrm\Civicrm;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Lets a gift card pay for classes through a CiviDiscount code.
 *
 * Staff used to make one discount code per workshop gift card by hand. Those
 * codes had no balance: a $150 code used on a $75 class lost the other $75, or,
 * set to unlimited uses, could be used again and again. Here each card gets a
 * code equal to the card's code, worth the card's balance, good for one use.
 * After a registration uses it, cron records what it took off, lowers the code
 * to the balance left and allows one more use, until the balance is gone.
 */
class GiftCardEventCodes {

  /**
   * Event types a gift card can pay for.
   *
   * Workshop, Program and Member Only Ticketed: the same filter staff put on
   * the hand-made gift codes.
   */
  public const EVENT_FILTER = '{"Event":{"event_type_id":{"IN":["6","16","7"]}}}';

  public function __construct(
    protected GiftCardLedger $ledger,
    protected Civicrm $civicrm,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether a kind of card can pay for classes.
   */
  public static function appliesTo(string $kind): bool {
    return in_array($kind, ['workshop', 'credit'], TRUE);
  }

  /**
   * Creates the card's discount code if it doesn't have one yet.
   */
  public function ensureCode(object $card): void {
    if (!self::appliesTo($card->kind) || $card->discount_id || (int) $card->balance_cents <= 0 || $card->status !== 'active') {
      return;
    }
    $this->civicrm->initialize();
    $id = \CRM_Core_DAO::singleValueQuery('SELECT id FROM cividiscount_item WHERE code = %1', [1 => [$card->display_code, 'String']]);
    if (!$id) {
      $who = trim($card->recipient_name) !== '' ? $card->recipient_name : $card->buyer_name;
      \CRM_Core_DAO::executeQuery(
        'INSERT INTO cividiscount_item (code, description, amount, amount_type, count_max, count_user_max, count_use, events, pricesets, memberships, autodiscount, is_active, discount_msg_enabled, discount_msg, filters, frontend_description)
         VALUES (%1, %2, %3, %4, 1, 0, 0, %5, %5, %5, %6, 1, 0, %5, %7, %8)',
        [
          1 => [$card->display_code, 'String'],
          2 => [mb_substr('Gift card ' . $card->display_code . ($who !== '' ? ' (' . $who . ')' : '') . ', managed by the gift card ledger', 0, 255), 'String'],
          3 => [self::amount((int) $card->balance_cents), 'String'],
          4 => ['2', 'String'],
          5 => ['', 'String'],
          6 => ['[]', 'String'],
          7 => [self::EVENT_FILTER, 'String'],
          8 => ['Gift card', 'String'],
        ]
      );
      $id = \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    }
    $this->ledger->update((int) $card->id, ['discount_id' => (int) $id]);
  }

  /**
   * Records class registrations paid with gift cards and resets their codes.
   *
   * @return int
   *   Number of registrations newly recorded.
   */
  public function reconcile(): int {
    $this->civicrm->initialize();
    $recorded = 0;
    foreach ($this->ledger->withDiscountCode() as $card) {
      $uses = \CRM_Core_DAO::executeQuery(
        'SELECT DISTINCT contribution_id, MIN(used_date) used FROM cividiscount_track WHERE item_id = %1 AND contribution_id IS NOT NULL GROUP BY contribution_id',
        [1 => [(int) $card->discount_id, 'Integer']]
      );
      while ($uses->fetch()) {
        $reference = 'contribution:' . $uses->contribution_id;
        if ($this->ledger->hasTxn((int) $card->id, 'redeem', 'event_discount', $reference)) {
          continue;
        }
        $applied = GiftCardMath::eventDiscount($this->lines((int) $uses->contribution_id));
        $card = $this->ledger->load((int) $card->id);
        $take = min($applied, max(0, (int) $card->balance_cents));
        $note = 'Class registration, order ' . $uses->contribution_id;
        if ($applied > $take) {
          // Several tickets in one order can each get the full discount.
          $note .= sprintf('. Took %s off but only %s was left: %s over.', GiftCardMath::dollars($applied), GiftCardMath::dollars($take), GiftCardMath::dollars($applied - $take));
          $this->loggerFactory->get('makerspace_landing_page')->warning('Gift card @code: @note', ['@code' => $card->display_code, '@note' => $note]);
        }
        $this->ledger->record((int) $card->id, 'redeem', -$take, 'event_discount', $reference, $note);
        $recorded++;
      }
      $this->syncCode($this->ledger->load((int) $card->id));
    }
    return $recorded;
  }

  /**
   * Sets a card's discount code to its balance, with one use available.
   */
  public function syncCode(object $card): void {
    $active = $card->status === 'active' && (int) $card->balance_cents > 0;
    \CRM_Core_DAO::executeQuery(
      'UPDATE cividiscount_item SET amount = %1, count_max = count_use + 1, is_active = %2 WHERE id = %3',
      [
        1 => [self::amount(max(0, (int) $card->balance_cents)), 'String'],
        2 => [$active ? 1 : 0, 'Integer'],
        3 => [(int) $card->discount_id, 'Integer'],
      ]
    );
  }

  /**
   * An order's event line items with each price option's full amount.
   */
  protected function lines(int $contribution_id): array {
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT li.qty, li.line_total, pfv.amount AS full_amount
       FROM civicrm_line_item li
       LEFT JOIN civicrm_price_field_value pfv ON pfv.id = li.price_field_value_id
       WHERE li.contribution_id = %1 AND li.entity_table = %2',
      [1 => [$contribution_id, 'Integer'], 2 => ['civicrm_participant', 'String']]
    );
    $lines = [];
    while ($dao->fetch()) {
      $lines[] = ['qty' => $dao->qty, 'line_total' => $dao->line_total, 'full_amount' => $dao->full_amount];
    }
    return $lines;
  }

  /**
   * CiviDiscount stores the amount as a decimal string.
   */
  protected static function amount(int $cents): string {
    return number_format($cents / 100, 2, '.', '');
  }

}
