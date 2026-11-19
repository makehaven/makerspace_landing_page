<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\civicrm\Civicrm;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Can someone buy a gift card and use it right now? Read-only checks.
 *
 * The Jumpstart depends on things nobody thinks of as "the gift card": a GEMS
 * cohort being public with seats, its price not rising above the card, the
 * Chargebee key, cron. Any of them can change without a deploy, and the buyer
 * is the first to notice. This runs nightly (makerspace_snapshot early
 * warning reads the same tables) and on demand: drush mh:gift-card-preflight.
 */
class GiftCardPreflight {

  /**
   * Order pages that must be on, by name.
   */
  public const PAGES = ['jumpstart_gift', 'membership_gift_card', 'workshop_gift_card'];

  /**
   * Hours since the last cron run before it counts as stopped.
   */
  public const CRON_HOURS = 3;

  /**
   * GEMS cohorts a Jumpstart code can be used on, open for registration now.
   *
   * Same rule as GiftCardEventCodes::GEMS_FILTER plus "a buyer can register":
   * active, public, online registration on, not started, registration not
   * closed, and a seat left (max_participants empty means no limit).
   */
  public const OPEN_GEMS_SQL = "SELECT e.id, e.title, e.start_date, e.max_participants,
      (SELECT COUNT(*) FROM civicrm_participant p JOIN civicrm_participant_status_type st ON st.id = p.status_id AND st.is_counted = 1
        WHERE p.event_id = e.id AND p.is_test = 0) AS taken
    FROM civicrm_event e
    WHERE e.event_type_id = 7 AND e.title LIKE '%GEMS%' AND e.is_active = 1 AND e.is_public = 1
      AND e.is_online_registration = 1 AND e.is_template = 0 AND e.start_date > NOW()
      AND (e.registration_end_date IS NULL OR e.registration_end_date > NOW())
      AND (e.registration_start_date IS NULL OR e.registration_start_date <= NOW())
    ORDER BY e.start_date";

  public function __construct(
    protected GiftCardLedger $ledger,
    protected GiftCardChargebee $chargebee,
    protected Civicrm $civicrm,
    protected TimeInterface $time,
    protected StateInterface $state,
    protected ModuleHandlerInterface $moduleHandler,
    protected GiftCardInspector $inspector,
  ) {}

  /**
   * Runs every check.
   *
   * @param bool $online
   *   Also ask Chargebee (one HTTP call).
   *
   * @return array
   *   List of ['check' => key, 'result' => ok|warn|fail, 'detail' => text].
   */
  public function run(bool $online = TRUE): array {
    $this->civicrm->initialize();
    $out = [];
    $add = static function (string $check, string $result, string $detail) use (&$out): void {
      $out[] = ['check' => $check, 'result' => $result, 'detail' => $detail];
    };

    // 1. Order pages.
    $jumpstart_cents = 0;
    foreach (self::PAGES as $name) {
      $dao = \CRM_Core_DAO::executeQuery(
        'SELECT cp.id, cp.is_active, ft.name ft FROM civicrm_contribution_page cp LEFT JOIN civicrm_financial_type ft ON ft.id = cp.financial_type_id WHERE cp.name = %1',
        [1 => [$name, 'String']]
      );
      if (!$dao->fetch()) {
        $add('page:' . $name, 'fail', 'Order page "' . $name . '" does not exist.');
        continue;
      }
      if (!$dao->is_active || $dao->ft !== 'Gift Card') {
        $add('page:' . $name, 'fail', sprintf('Order page %d is %s, financial type %s (needs: on, Gift Card).', $dao->id, $dao->is_active ? 'on' : 'OFF', $dao->ft ?: 'none'));
        continue;
      }
      $prices = $this->pagePrices((int) $dao->id);
      if ($name === 'jumpstart_gift') {
        $jumpstart_cents = $prices ? (int) round(max($prices) * 100) : 0;
      }
      $add('page:' . $name, $prices ? 'ok' : 'fail', sprintf('Page %d on, Gift Card, prices: %s.', $dao->id, $prices ? implode(', ', array_map(static fn($p) => '$' . number_format($p, 2), $prices)) : 'NONE active'));
    }

    // 2. Somewhere to use the Jumpstart seat, at a price the card covers.
    $open = [];
    $dao = \CRM_Core_DAO::executeQuery(self::OPEN_GEMS_SQL);
    while ($dao->fetch()) {
      if ($dao->max_participants && (int) $dao->taken >= (int) $dao->max_participants) {
        continue;
      }
      $open[] = [
        'id' => (int) $dao->id,
        'title' => (string) $dao->title,
        'start' => substr((string) $dao->start_date, 0, 10),
        'left' => $dao->max_participants ? (int) $dao->max_participants - (int) $dao->taken : NULL,
        'prices' => $this->eventPrices((int) $dao->id),
      ];
    }
    if (!$open) {
      $add('gems:open', 'fail', 'No GEMS cohort is open for registration with a seat left. A Jumpstart buyer cannot use the GEMS half: publish a cohort (Program type, "GEMS" in the title, public, online registration on).');
    }
    else {
      $add('gems:open', 'ok', implode('; ', array_map(static fn($e) => sprintf('%s (%d) %s, %s, %s', $e['title'], $e['id'], $e['start'], $e['left'] === NULL ? 'no seat limit' : $e['left'] . ' seats left', $e['prices'] ? 'from $' . number_format(min($e['prices']), 2) : 'no price set (free)'), $open)));
      $over = [];
      foreach ($open as $e) {
        if ($e['prices'] && $jumpstart_cents && min($e['prices']) * 100 > $jumpstart_cents) {
          $over[] = sprintf('%s: cheapest option $%s', $e['title'], number_format(min($e['prices']), 2));
        }
      }
      $add('gems:price', $over ? 'fail' : 'ok', $over
        ? 'GEMS costs more than the Jumpstart covers ($' . number_format($jumpstart_cents / 100, 2) . '), so a card holder would be asked to pay the difference: ' . implode('; ', $over) . '. Raise the Jumpstart price, or the code amount.'
        : 'Every open cohort has an option the Jumpstart code brings to $0.');
    }

    // 3. Discount codes.
    $has_cividiscount = (bool) \CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_extension WHERE full_name = 'org.civicrm.module.cividiscount' AND is_active = 1");
    $add('cividiscount', $has_cividiscount ? 'ok' : 'fail', $has_cividiscount ? 'CiviDiscount is on.' : 'CiviDiscount is off: class and GEMS codes do nothing at registration.');

    // 4. Chargebee.
    $hook = $this->moduleHandler->moduleExists('chargebee_portal');
    $add('join_form', $hook ? 'ok' : 'fail', $hook ? 'chargebee_portal is on (the join form turns card codes into coupons).' : 'chargebee_portal is off: card codes do nothing on the join form.');
    if (!$this->chargebee->isConfigured()) {
      $add('chargebee', 'fail', 'No Chargebee key for this environment: the join form cannot make gift coupons.');
    }
    elseif ($online) {
      try {
        $this->chargebee->getCoupon('MH-PREFLIGHT-NO-SUCH-COUPON');
        $add('chargebee', 'ok', 'Chargebee answered with this environment\'s key.');
      }
      catch (\RuntimeException $e) {
        $add('chargebee', 'fail', 'Chargebee did not answer: ' . $e->getMessage());
      }
    }
    else {
      $add('chargebee', 'ok', 'Key present (not asked: --offline).');
    }

    // 5. Receipt with the print button.
    $receipt = (int) \CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_msg_template WHERE workflow_name = 'contribution_online_receipt' AND is_default = 1 AND msg_html LIKE '%/gift-card/print/%'");
    $add('receipt', $receipt ? 'ok' : 'fail', $receipt ? 'Order receipt has the print button.' : 'The default online receipt has lost the gift card print button (someone edited or reverted the template).');

    // 6. Cron: issues missed cards, records registrations, sends recipient email.
    $last = (int) $this->state->get('system.cron_last', 0);
    $hours = $last ? ($this->time->getCurrentTime() - $last) / 3600 : INF;
    $add('cron', $hours <= self::CRON_HOURS ? 'ok' : 'fail', $last ? sprintf('Cron last ran %.1f hours ago.', $hours) : 'Cron has never run.');

    // 7. Paid orders with no card.
    $orphans = \CRM_Core_DAO::executeQuery(
      "SELECT c.id FROM civicrm_contribution c JOIN civicrm_financial_type ft ON ft.id = c.financial_type_id AND ft.name = 'Gift Card'
       JOIN civicrm_option_value s ON s.value = c.contribution_status_id AND s.name = 'Completed'
       JOIN civicrm_option_group g ON g.id = s.option_group_id AND g.name = 'contribution_status'
       WHERE c.is_test = 0 AND c.receive_date < NOW() - INTERVAL %1 HOUR
         AND NOT EXISTS (SELECT 1 FROM " . GiftCardLedger::CARD . " gc WHERE gc.contribution_id = c.id)",
      [1 => [self::CRON_HOURS, 'Integer']]
    )->fetchAll();
    $ids = array_column($orphans, 'id');
    $add('orders', $ids ? 'fail' : 'ok', $ids ? 'Paid gift card orders with no card: ' . implode(', ', $ids) . '. The buyer has a receipt but no working code.' : 'Every paid gift card order has a card.');

    // 8. Cards whose places disagree (ledger, discount code, delivery).
    $bad = [];
    foreach ($this->ledger->search('', '', 1000) as $card) {
      $result = $this->inspector->inspect($card, FALSE);
      if ($result['problems']) {
        $bad[] = $card->display_code . ': ' . $result['problems'][0];
      }
    }
    $add('cards', $bad ? 'warn' : 'ok', $bad ? count($bad) . ' card(s) need a look (drush mh:gift-card-check <code>): ' . implode(' | ', $bad) : 'No card has a problem.');

    return $out;
  }

  /**
   * Active amounts on a contribution page's price set, in dollars.
   */
  protected function pagePrices(int $page_id): array {
    return $this->prices('civicrm_contribution_page', $page_id);
  }

  /**
   * Active, public amounts on an event's price set, in dollars.
   */
  protected function eventPrices(int $event_id): array {
    return $this->prices('civicrm_event', $event_id);
  }

  /**
   * Active, public, non-zero fixed amounts on an entity's price set.
   *
   * Text fields ("other amount") store a unit price of 1 and are left out.
   */
  protected function prices(string $table, int $id): array {
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT DISTINCT pfv.amount FROM civicrm_price_set_entity pse
       JOIN civicrm_price_field pf ON pf.price_set_id = pse.price_set_id AND pf.is_active = 1 AND pf.html_type <> 'Text'
       JOIN civicrm_price_field_value pfv ON pfv.price_field_id = pf.id AND pfv.is_active = 1
       WHERE pse.entity_table = %1 AND pse.entity_id = %2 AND pfv.amount > 0
         AND (pfv.visibility_id IS NULL OR pfv.visibility_id = 1) AND (pf.visibility_id IS NULL OR pf.visibility_id = 1)",
      [1 => [$table, 'String'], 2 => [$id, 'Integer']]
    );
    $out = [];
    while ($dao->fetch()) {
      $out[] = (float) $dao->amount;
    }
    sort($out);
    return $out;
  }

  /**
   * Whether any check failed.
   */
  public static function failed(array $results): bool {
    return (bool) array_filter($results, static fn($r) => $r['result'] === 'fail');
  }

}
