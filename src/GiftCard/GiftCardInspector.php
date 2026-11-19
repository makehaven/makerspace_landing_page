<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\civicrm\Civicrm;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Gathers one card's facts from the ledger, CiviCRM and Chargebee.
 *
 * Read-only: nothing here writes anywhere, so it is safe to run on live at
 * any time (drush mh:gift-card-check, the staff card page). GiftCardState
 * turns the facts into words.
 */
class GiftCardInspector {

  public function __construct(
    protected GiftCardLedger $ledger,
    protected GiftCardChargebee $chargebee,
    protected Civicrm $civicrm,
    protected TimeInterface $time,
  ) {}

  /**
   * Finds a card by code (any spelling), card id or order (contribution) id.
   */
  public function find(string $needle): ?object {
    $needle = trim($needle);
    if ($card = $this->ledger->findByCode($needle)) {
      return $card;
    }
    if (ctype_digit($needle)) {
      return $this->ledger->load((int) $needle) ?: $this->ledger->findByContribution((int) $needle);
    }
    return NULL;
  }

  /**
   * Collects the facts GiftCardState::evaluate() reads.
   *
   * @param object $card
   *   Ledger row.
   * @param bool $ask_chargebee
   *   Look the coupon up in Chargebee (one HTTP call). Off for page views
   *   where speed matters more.
   */
  public function facts(object $card, bool $ask_chargebee = TRUE): array {
    $this->civicrm->initialize();
    $facts = [
      'card' => $card,
      'now' => $this->time->getCurrentTime(),
      'order_status' => NULL,
      'discount' => NULL,
      'discount_missing' => FALSE,
      'code_uses' => [],
      'months' => NULL,
      'coupon' => NULL,
      'coupon_error' => '',
    ];

    if ($card->contribution_id) {
      $facts['order_status'] = (string) \CRM_Core_DAO::singleValueQuery(
        'SELECT s.name FROM civicrm_contribution c JOIN civicrm_option_value s ON s.value = c.contribution_status_id
         JOIN civicrm_option_group g ON g.id = s.option_group_id AND g.name = %2 WHERE c.id = %1',
        [1 => [(int) $card->contribution_id, 'Integer'], 2 => ['contribution_status', 'String']]
      );
    }

    if ($card->discount_id) {
      $dao = \CRM_Core_DAO::executeQuery('SELECT is_active, count_use, count_max, amount FROM cividiscount_item WHERE id = %1', [1 => [(int) $card->discount_id, 'Integer']]);
      if ($dao->fetch()) {
        $facts['discount'] = [
          'is_active' => (int) $dao->is_active,
          'count_use' => (int) $dao->count_use,
          'count_max' => (int) $dao->count_max,
          'amount' => (string) $dao->amount,
        ];
      }
      else {
        $facts['discount_missing'] = TRUE;
      }

      $uses = \CRM_Core_DAO::executeQuery(
        "SELECT t.contribution_id, MIN(t.used_date) used, MAX(e.title) event, MAX(ps.name) pstatus
         FROM cividiscount_track t
         LEFT JOIN civicrm_participant p ON t.entity_table = 'civicrm_participant' AND p.id = t.entity_id
         LEFT JOIN civicrm_event e ON e.id = p.event_id
         LEFT JOIN civicrm_participant_status_type ps ON ps.id = p.status_id
         WHERE t.item_id = %1 AND t.contribution_id IS NOT NULL
         GROUP BY t.contribution_id ORDER BY used",
        [1 => [(int) $card->discount_id, 'Integer']]
      );
      while ($uses->fetch()) {
        $facts['code_uses'][] = [
          'contribution_id' => (int) $uses->contribution_id,
          'used' => (int) strtotime((string) $uses->used),
          'recorded' => $this->ledger->hasTxn((int) $card->id, 'redeem', 'event_discount', 'contribution:' . $uses->contribution_id),
          'event' => (string) $uses->event,
          'participant_status' => (string) $uses->pstatus,
        ];
      }
    }

    if ($card->kind === 'jumpstart') {
      foreach ($this->ledger->history((int) $card->id) as $txn) {
        if ($txn->type === 'note' && in_array($txn->channel, ['chargebee_coupon', 'chargebee_credit'], TRUE)) {
          $facts['months'] = [
            'channel' => $txn->channel,
            'reference' => $txn->reference,
            'created' => (int) $txn->created,
            'note' => $txn->note,
          ];
          break;
        }
      }
    }

    if ($ask_chargebee && in_array($card->kind, ['jumpstart', 'membership'], TRUE) && $this->chargebee->isConfigured()) {
      $id = $card->kind === 'jumpstart'
        ? GiftCardMath::jumpstartCouponId((string) $card->code)
        : GiftCardMath::membershipCouponId((string) $card->code, (int) $card->balance_cents);
      try {
        $coupon = $this->chargebee->getCoupon($id);
        $facts['coupon'] = $coupon ? [
          'id' => $id,
          'status' => (string) ($coupon['status'] ?? ''),
          'redemptions' => (int) ($coupon['redemptions'] ?? 0),
        ] : 'missing';
      }
      catch (\RuntimeException $e) {
        $facts['coupon_error'] = $e->getMessage();
      }
    }
    return $facts;
  }

  /**
   * Facts plus what they mean.
   */
  public function inspect(object $card, bool $ask_chargebee = TRUE): array {
    $facts = $this->facts($card, $ask_chargebee);
    $result = GiftCardState::evaluate($facts) + ['facts' => $facts];
    if ($facts['coupon_error'] !== '') {
      $result['problems'][] = 'Could not ask Chargebee about the coupon: ' . $facts['coupon_error'];
    }
    return $result;
  }

}
