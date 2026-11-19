<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_landing_page\Unit\Fake;

use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;

/**
 * The ledger in memory, with the same balance and once-only rules.
 */
class FakeGiftCardLedger extends GiftCardLedger {

  /**
   * Cards by id.
   *
   * @var object[]
   */
  public array $cards = [];

  /**
   * Transactions, in order.
   *
   * @var object[]
   */
  public array $txns = [];

  /**
   * No database: nothing to construct.
   */
  public function __construct() {}

  /**
   * Adds a card; returns it.
   */
  public function add(array $values): object {
    $id = count($this->cards) + 1;
    $display = $values['display_code'] ?? sprintf('C%03d-TEST', $id);
    $card = (object) ($values + [
      'id' => $id,
      'code' => GiftCardMath::normalize($display),
      'display_code' => $display,
      'kind' => 'jumpstart',
      'status' => 'active',
      'amount_cents' => 28500,
      'balance_cents' => $values['amount_cents'] ?? 28500,
      'contribution_id' => NULL,
      'discount_id' => NULL,
      'delivery' => 'email',
      'recipient_email' => '',
      'recipient_emailed' => NULL,
      'deliver_after' => NULL,
      'created' => 1700000000,
    ]);
    $card->id = $id;
    $this->cards[$id] = $card;
    return clone $card;
  }

  /**
   * {@inheritdoc}
   */
  public function load(int $id): ?object {
    return isset($this->cards[$id]) ? clone $this->cards[$id] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function findByCode(string $code): ?object {
    $code = GiftCardMath::normalize($code);
    foreach ($this->cards as $card) {
      if ($code !== '' && $card->code === $code) {
        return clone $card;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function record(int $card_id, string $type, int $amount_cents, string $channel, string $reference = '', string $note = ''): bool {
    $card = $this->cards[$card_id];
    if ($reference !== '' && $this->hasTxn($card_id, $type, $channel, $reference)) {
      return FALSE;
    }
    $this->txns[] = (object) [
      'id' => count($this->txns) + 1,
      'card_id' => $card_id,
      'type' => $type,
      'amount_cents' => $amount_cents,
      'channel' => $channel,
      'reference' => $reference,
      'note' => $note,
      'created' => 1700000000 + count($this->txns),
    ];
    $card->balance_cents += $amount_cents;
    $status = $type === 'void' ? 'void' : ($card->balance_cents > 0 ? 'active' : 'used');
    $card->status = ($card->status === 'void' && $type !== 'adjust') ? 'void' : $status;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function revert(int $card_id, string $type, string $channel, string $reference): void {
    foreach ($this->txns as $i => $t) {
      if ($t->card_id === $card_id && $t->type === $type && $t->channel === $channel && $t->reference === $reference) {
        $card = $this->cards[$card_id];
        $card->balance_cents -= $t->amount_cents;
        if ($card->status !== 'void') {
          $card->status = $card->balance_cents > 0 ? 'active' : 'used';
        }
        unset($this->txns[$i]);
        $this->txns = array_values($this->txns);
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasTxn(int $card_id, string $type, string $channel, string $reference): bool {
    foreach ($this->txns as $t) {
      if ($t->card_id === $card_id && $t->type === $type && $t->channel === $channel && $t->reference === $reference) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasChannelTxn(int $card_id, string $type, array $channels): bool {
    foreach ($this->txns as $t) {
      if ($t->card_id === $card_id && $t->type === $type && in_array($t->channel, $channels, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function history(int $card_id): array {
    return array_values(array_filter($this->txns, static fn($t) => $t->card_id === $card_id));
  }

  /**
   * Transactions on one channel for a card.
   */
  public function on(int $card_id, string $channel): array {
    return array_values(array_filter($this->txns, static fn($t) => $t->card_id === $card_id && $t->channel === $channel));
  }

}
