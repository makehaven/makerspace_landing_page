<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Civi\Api4\Contribution;
use Civi\Api4\LineItem;
use Drupal\civicrm\Civicrm;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * The gift card ledger: cards and every change to their balances.
 *
 * A card's balance only ever changes through record(), which writes a
 * transaction and the new balance together. That keeps two numbers the
 * bookkeeper needs answerable for any date: what is still owed on unused
 * cards, and what was redeemed, and where, in a month.
 */
class GiftCardLedger {

  public const CARD = 'makerspace_gift_card';
  public const TXN = 'makerspace_gift_card_txn';

  public function __construct(
    protected Connection $db,
    protected Civicrm $civicrm,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Loads a card by id.
   */
  public function load(int $id): ?object {
    return $this->db->select(self::CARD, 'c')->fields('c')
      ->condition('id', $id)->execute()->fetchObject() ?: NULL;
  }

  /**
   * Loads a card by its code, in any spelling ("7f3a 92c1" finds 7F3A-92C1).
   */
  public function findByCode(string $code): ?object {
    $code = GiftCardMath::normalize($code);
    if ($code === '') {
      return NULL;
    }
    return $this->db->select(self::CARD, 'c')->fields('c')
      ->condition('code', $code)->execute()->fetchObject() ?: NULL;
  }

  /**
   * Loads the card issued for an order, if there is one.
   */
  public function findByContribution(int $contribution_id): ?object {
    return $this->db->select(self::CARD, 'c')->fields('c')
      ->condition('contribution_id', $contribution_id)->execute()->fetchObject() ?: NULL;
  }

  /**
   * Issues the card for a completed Gift Card order. Safe to call repeatedly.
   *
   * @return object|null
   *   The card, or NULL when the contribution is not a completed Gift Card
   *   order.
   */
  public function issueFromContribution(int $contribution_id): ?object {
    if ($existing = $this->findByContribution($contribution_id)) {
      return $existing;
    }
    $this->civicrm->initialize();
    $c = Contribution::get(FALSE)
      ->addSelect(
        'id', 'invoice_id', 'total_amount', 'receive_date', 'contribution_status_id:name', 'financial_type_id:name',
        'contribution_page_id.name', 'contact_id', 'contact_id.display_name', 'contact_id.email_primary.email',
        'Gift_Card.Gift_For', 'Gift_Card.Recipient_Name', 'Gift_Card.Recipient_Email', 'Gift_Card.Contact_After',
        'Gift_Card.Delivery'
      )
      ->addWhere('id', '=', $contribution_id)
      ->execute()->first();
    if (!$c || $c['financial_type_id:name'] !== 'Gift Card' || $c['contribution_status_id:name'] !== 'Completed') {
      return NULL;
    }

    $display = GiftCardCode::fromInvoice($c['invoice_id']) ?: 'MH-' . $c['id'];
    $cents = (int) round(((float) $c['total_amount']) * 100);
    $is_gift = ($c['Gift_Card.Gift_For'] ?? 'gift') !== 'self';
    $delivery = (string) ($c['Gift_Card.Delivery'] ?? 'email');
    $after = !empty($c['Gift_Card.Contact_After']) ? strtotime((string) $c['Gift_Card.Contact_After']) : NULL;
    $kind = GiftCardMath::kindForPage($c['contribution_page_id.name'] ?? NULL);
    $lines = LineItem::get(FALSE)
      ->addSelect('qty', 'price_field_id.price_set_id.name', 'price_field_value_id.name')
      ->addWhere('contribution_id', '=', $contribution_id)
      ->execute();
    $bonus = GiftCardMath::presetBonus(array_map(static fn(array $l) => [
      'page' => $l['price_field_id.price_set_id.name'] ?? '',
      'option' => $l['price_field_value_id.name'] ?? '',
      'qty' => $l['qty'] ?? 1,
    ], (array) $lines->getArrayCopy()));

    $card = [
      'code' => GiftCardMath::normalize($display),
      'display_code' => $display,
      'kind' => $kind,
      'source' => 'civicrm',
      'contribution_id' => (int) $c['id'],
      'contact_id' => (int) $c['contact_id'],
      'buyer_name' => (string) ($c['contact_id.display_name'] ?? ''),
      'buyer_email' => (string) ($c['contact_id.email_primary.email'] ?? ''),
      'recipient_name' => $is_gift ? trim((string) ($c['Gift_Card.Recipient_Name'] ?? '')) : '',
      'recipient_email' => $is_gift ? trim((string) ($c['Gift_Card.Recipient_Email'] ?? '')) : '',
      'delivery' => in_array($delivery, ['email', 'recipient', 'mail'], TRUE) ? $delivery : 'email',
      'deliver_after' => $after ?: NULL,
      'paid_cents' => $cents,
      'amount_cents' => $cents + $bonus,
    ];
    try {
      $card = $this->create($card, 'Order ' . $c['id'], $c['receive_date'] ? strtotime((string) $c['receive_date']) : NULL);
    }
    catch (\Exception $e) {
      // Two processes issuing the same order at once: the unique key on
      // contribution_id stops the second; hand back the first.
      if ($existing = $this->findByContribution($contribution_id)) {
        return $existing;
      }
      throw $e;
    }
    $this->loggerFactory->get('makerspace_landing_page')->notice('Issued gift card @code (@kind, @amount) for order @cid.', [
      '@code' => $display,
      '@kind' => $kind,
      '@amount' => GiftCardMath::dollars($cents),
      '@cid' => $c['id'],
    ]);
    return $card;
  }

  /**
   * Creates a card with its opening "issue" transaction.
   *
   * The issue transaction is what was paid; any value beyond that (the
   * multi-month savings) is a separate "adjust" on the bonus channel, so the
   * monthly report can keep money received and promotions apart.
   *
   * @param array $values
   *   Column values; code, kind and amount_cents are required.
   * @param string $note
   *   Note on the issue transaction.
   * @param int|null $when
   *   When it was issued (defaults to now; legacy imports pass the sale date).
   */
  public function create(array $values, string $note = '', ?int $when = NULL): object {
    $now = $this->time->getRequestTime();
    $when = $when ?: $now;
    $amount = (int) $values['amount_cents'];
    $values += [
      'display_code' => $values['code'],
      'paid_cents' => $amount,
      'status' => 'active',
    ];
    $values['code'] = GiftCardMath::normalize($values['code']);
    $values['balance_cents'] = 0;
    $values['created'] = $when;
    $values['changed'] = $now;

    $transaction = $this->db->startTransaction();
    try {
      $id = (int) $this->db->insert(self::CARD)->fields($values)->execute();
      $paid = min((int) $values['paid_cents'], $amount);
      $this->insertTxn($id, 'issue', $paid, $values['source'] ?? 'manual', '', $note, $when);
      if ($amount > $paid) {
        $this->insertTxn($id, 'adjust', $amount - $paid, 'bonus', '', 'Multi-month savings: worth ' . GiftCardMath::dollars($amount) . ', paid ' . GiftCardMath::dollars($paid), $when);
      }
      $this->db->update(self::CARD)->fields(['balance_cents' => $amount, 'status' => $amount > 0 ? 'active' : 'used'])
        ->condition('id', $id)->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return $this->load($id);
  }

  /**
   * Records a change to a card's balance.
   *
   * @param int $card_id
   *   The card.
   * @param string $type
   *   One of redeem, adjust, void or note.
   * @param int $amount_cents
   *   Signed change: negative spends value, positive restores it.
   * @param string $channel
   *   Where the value went (chargebee_coupon, chargebee_credit,
   *   event_discount, staff).
   * @param string $reference
   *   Invoice, subscription or contribution. When given, the same
   *   type + channel + reference is recorded only once, so retries are safe.
   * @param string $note
   *   What a person reading the history later needs to know.
   *
   * @return bool
   *   FALSE when this exact change was already recorded.
   */
  public function record(int $card_id, string $type, int $amount_cents, string $channel, string $reference = '', string $note = ''): bool {
    $transaction = $this->db->startTransaction();
    try {
      // Lock the card row so two redemptions can't both spend one balance.
      $card = $this->db->query('SELECT * FROM {' . self::CARD . '} WHERE id = :id FOR UPDATE', [':id' => $card_id])->fetchObject();
      if (!$card) {
        throw new \InvalidArgumentException("No gift card $card_id.");
      }
      if ($reference !== '' && $this->hasTxn($card_id, $type, $channel, $reference)) {
        return FALSE;
      }
      $this->insertTxn($card_id, $type, $amount_cents, $channel, $reference, $note);
      $balance = (int) $card->balance_cents + $amount_cents;
      $status = $type === 'void' ? 'void' : ($balance > 0 ? 'active' : 'used');
      if ($card->status === 'void' && $type !== 'adjust') {
        $status = 'void';
      }
      $this->db->update(self::CARD)
        ->fields(['balance_cents' => $balance, 'status' => $status, 'changed' => $this->time->getRequestTime()])
        ->condition('id', $card_id)->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
    return TRUE;
  }

  /**
   * Takes back a change whose outside effect failed (Chargebee refused).
   *
   * Redemptions are recorded before the outside call, so a double-click can't
   * spend a card twice. If the call then fails, the row is removed and the
   * balance restored, so the same redemption can be tried again. Only for
   * that case: anything that really happened is corrected with an "adjust".
   */
  public function revert(int $card_id, string $type, string $channel, string $reference): void {
    $transaction = $this->db->startTransaction();
    try {
      $card = $this->db->query('SELECT * FROM {' . self::CARD . '} WHERE id = :id FOR UPDATE', [':id' => $card_id])->fetchObject();
      $txn = $this->db->select(self::TXN, 't')->fields('t')
        ->condition('card_id', $card_id)->condition('type', $type)
        ->condition('channel', $channel)->condition('reference', $reference)
        ->execute()->fetchObject();
      if (!$card || !$txn) {
        return;
      }
      $this->db->delete(self::TXN)->condition('id', $txn->id)->execute();
      $balance = (int) $card->balance_cents - (int) $txn->amount_cents;
      $this->db->update(self::CARD)
        ->fields([
          'balance_cents' => $balance,
          'status' => $card->status === 'void' ? 'void' : ($balance > 0 ? 'active' : 'used'),
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', $card_id)->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Whether a change with this reference was already recorded.
   */
  public function hasTxn(int $card_id, string $type, string $channel, string $reference): bool {
    return (bool) $this->db->select(self::TXN, 't')->fields('t', ['id'])
      ->condition('card_id', $card_id)->condition('type', $type)
      ->condition('channel', $channel)->condition('reference', $reference)
      ->range(0, 1)->execute()->fetchField();
  }

  /**
   * Updates plain card fields (delivery bookkeeping, links, notes).
   */
  public function update(int $card_id, array $fields): void {
    unset($fields['balance_cents'], $fields['id'], $fields['code']);
    $fields['changed'] = $this->time->getRequestTime();
    $this->db->update(self::CARD)->fields($fields)->condition('id', $card_id)->execute();
  }

  /**
   * A card's history, oldest first.
   */
  public function history(int $card_id): array {
    return $this->db->select(self::TXN, 't')->fields('t')
      ->condition('card_id', $card_id)->orderBy('created')->orderBy('id')
      ->execute()->fetchAll();
  }

  /**
   * Finds cards by code, buyer or recipient name/email; newest first.
   */
  public function search(string $text = '', string $status = '', int $limit = 100): array {
    $q = $this->db->select(self::CARD, 'c')->fields('c')->orderBy('created', 'DESC')->range(0, $limit);
    $text = trim($text);
    if ($text !== '') {
      $like = '%' . $this->db->escapeLike($text) . '%';
      $or = $q->orConditionGroup()
        ->condition('buyer_name', $like, 'LIKE')
        ->condition('buyer_email', $like, 'LIKE')
        ->condition('recipient_name', $like, 'LIKE')
        ->condition('recipient_email', $like, 'LIKE')
        ->condition('note', $like, 'LIKE');
      $code = GiftCardMath::normalize($text);
      if ($code !== '') {
        $or->condition('code', '%' . $this->db->escapeLike($code) . '%', 'LIKE');
      }
      $q->condition($or);
    }
    if ($status !== '') {
      $q->condition('status', $status);
    }
    return $q->execute()->fetchAll();
  }

  /**
   * Cards that pay for classes through a CiviDiscount code.
   */
  public function withDiscountCode(): array {
    return $this->db->select(self::CARD, 'c')->fields('c')
      ->isNotNull('discount_id')->execute()->fetchAll();
  }

  /**
   * Cards whose recipient should get their email now.
   */
  public function dueForRecipient(int $now): array {
    $q = $this->db->select(self::CARD, 'c')->fields('c')
      ->condition('delivery', 'recipient')
      ->condition('recipient_email', '', '<>')
      ->isNull('recipient_emailed')
      ->condition('status', 'void', '<>');
    $q->condition($q->orConditionGroup()->isNull('deliver_after')->condition('deliver_after', $now, '<='));
    return $q->execute()->fetchAll();
  }

  /**
   * Cards waiting for staff to mail a printed copy.
   */
  public function toMail(): array {
    return $this->db->select(self::CARD, 'c')->fields('c')
      ->condition('delivery', 'mail')->isNull('mailed')->condition('status', 'void', '<>')
      ->orderBy('created')->execute()->fetchAll();
  }

  /**
   * The month's numbers for the books.
   *
   * @return array
   *   Keys: sold (cents paid, by kind), sold_count, redeemed (cents, by
   *   channel, positive), bonus (cents of promotional value added),
   *   adjusted (cents, signed: staff corrections and voids), owed_start and owed_end
   *   (total balance on all cards at each date).
   */
  public function monthReport(int $start, int $end): array {
    $report = ['sold' => [], 'sold_count' => 0, 'redeemed' => [], 'bonus' => 0, 'adjusted' => 0];
    $issued = $this->db->query('SELECT c.kind, COUNT(*) n, SUM(c.paid_cents) paid FROM {' . self::CARD . '} c
      WHERE c.created >= :s AND c.created < :e AND c.source <> :legacy GROUP BY c.kind',
      [':s' => $start, ':e' => $end, ':legacy' => 'legacy'])->fetchAll();
    foreach ($issued as $row) {
      $report['sold'][$row->kind] = (int) $row->paid;
      $report['sold_count'] += (int) $row->n;
    }
    $spent = $this->db->query('SELECT t.type, t.channel, SUM(t.amount_cents) amount FROM {' . self::TXN . '} t
      WHERE t.created >= :s AND t.created < :e AND t.type IN (:types[]) GROUP BY t.type, t.channel',
      [':s' => $start, ':e' => $end, ':types[]' => ['redeem', 'adjust', 'void']])->fetchAll();
    foreach ($spent as $row) {
      if ($row->type === 'redeem') {
        $report['redeemed'][$row->channel] = ($report['redeemed'][$row->channel] ?? 0) - (int) $row->amount;
      }
      elseif ($row->channel === 'bonus') {
        $report['bonus'] += (int) $row->amount;
      }
      else {
        $report['adjusted'] += (int) $row->amount;
      }
    }
    $report['owed_start'] = $this->owedAt($start);
    $report['owed_end'] = $this->owedAt($end);
    return $report;
  }

  /**
   * Total unspent value on all cards just before a moment.
   */
  public function owedAt(int $when): int {
    return (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) FROM {' . self::TXN . '} WHERE created < :w',
      [':w' => $when])->fetchField();
  }

  /**
   * Inserts one transaction row.
   */
  protected function insertTxn(int $card_id, string $type, int $amount, string $channel, string $reference, string $note, ?int $when = NULL): void {
    $this->db->insert(self::TXN)->fields([
      'card_id' => $card_id,
      'type' => $type,
      'amount_cents' => $amount,
      'channel' => $channel,
      'reference' => mb_substr($reference, 0, 128),
      'uid' => (int) $this->currentUser->id(),
      'created' => $when ?: $this->time->getRequestTime(),
      'note' => mb_substr($note, 0, 512),
    ])->execute();
  }

}
