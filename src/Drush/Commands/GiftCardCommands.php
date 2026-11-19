<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\Drush\Commands;

use Drupal\makerspace_landing_page\GiftCard\GiftCardInspector;
use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Drupal\makerspace_landing_page\GiftCard\GiftCardPreflight;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only gift card checks, safe on live.
 */
class GiftCardCommands extends DrushCommands {

  public function __construct(
    protected GiftCardInspector $inspector,
    protected GiftCardPreflight $preflight,
    protected GiftCardLedger $ledger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('makerspace_landing_page.gift_card_inspector'),
      $container->get('makerspace_landing_page.gift_card_preflight'),
      $container->get('makerspace_landing_page.gift_card_ledger'),
    );
  }

  /**
   * Shows one card across the ledger, CiviDiscount and Chargebee.
   *
   * For a Jumpstart: the GEMS seat and the 3 months separately, which
   * registration or subscription used each, anything that disagrees, and
   * what the holder can still do. Writes nothing.
   */
  #[CLI\Command(name: 'mh:gift-card-check', aliases: ['gc-check'])]
  #[CLI\Argument(name: 'card', description: 'Card code (any spelling), card id, or order (contribution) id.')]
  #[CLI\Option(name: 'offline', description: 'Do not ask Chargebee.')]
  #[CLI\Option(name: 'json', description: 'Print the facts and findings as JSON.')]
  #[CLI\Usage(name: 'drush mh:gift-card-check 7F3A-92C1', description: 'Where a Jumpstart stands.')]
  public function check(string $card, array $options = ['offline' => FALSE, 'json' => FALSE]): int {
    $row = $this->inspector->find($card);
    if (!$row) {
      $this->io()->error("No gift card matches \"$card\".");
      return self::EXIT_FAILURE;
    }
    $result = $this->inspector->inspect($row, !$options['offline']);
    if ($options['json']) {
      $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return $result['problems'] ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
    }

    $io = $this->io();
    $io->title(sprintf('Gift card %s (%s, card %d)', $row->display_code, $row->kind, $row->id));
    $facts = $result['facts'];
    $io->definitionList(
      ['Value / left' => GiftCardMath::dollars((int) $row->amount_cents) . ' / ' . GiftCardMath::dollars((int) $row->balance_cents) . ' (' . $row->status . ')'],
      ['Order' => $row->contribution_id ? $row->contribution_id . ' (' . ($facts['order_status'] ?? '?') . ')' : 'none'],
      ['Bought by' => trim($row->buyer_name . ' ' . $row->buyer_email)],
      ['For' => trim($row->recipient_name . ' ' . $row->recipient_email) ?: '(themselves)'],
      ['Delivery' => $row->delivery . ($row->recipient_emailed ? ', emailed ' . date('Y-m-d H:i', (int) $row->recipient_emailed) : '')],
      ['Discount code' => $facts['discount'] ? sprintf('%s, $%s, used %d of %d', $facts['discount']['is_active'] ? 'ON' : 'off', number_format((float) $facts['discount']['amount'], 2), $facts['discount']['count_use'], $facts['discount']['count_max']) : ($row->discount_id ? 'MISSING' : 'none')],
      ['Chargebee coupon' => is_array($facts['coupon']) ? sprintf('%s: %s, %d redemption(s)', $facts['coupon']['id'], $facts['coupon']['status'], $facts['coupon']['redemptions']) : ($facts['coupon'] === 'missing' ? 'not made yet' : 'not checked')],
    );
    $io->section('Parts');
    $io->table(['Part', 'State', ''], $result['parts']);
    $io->section('History');
    $io->table(['When', 'What', 'Amount', 'Where', 'Reference', 'Note'], array_map(static fn($t) => [
      date('Y-m-d H:i', (int) $t->created), $t->type, $t->amount_cents ? GiftCardMath::dollars((int) $t->amount_cents) : '', $t->channel, $t->reference, $t->note,
    ], $this->ledger->history((int) $row->id)));
    if ($result['next']) {
      $io->section('What the holder can still do');
      $io->listing($result['next']);
    }
    if ($result['problems']) {
      $io->section('Problems');
      $io->listing($result['problems']);
      return self::EXIT_FAILURE;
    }
    $io->success('Everything agrees.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Can someone buy a gift card, and use every part of it, right now?
   *
   * Order pages on, an open GEMS cohort the Jumpstart covers, CiviDiscount,
   * the Chargebee key, the receipt's print button, cron, paid orders with no
   * card, and any card whose places disagree. Writes nothing. Exits 1 when a
   * check fails, so it can gate a deploy or a promotion.
   */
  #[CLI\Command(name: 'mh:gift-card-preflight', aliases: ['gc-preflight'])]
  #[CLI\Option(name: 'offline', description: 'Do not ask Chargebee.')]
  #[CLI\Option(name: 'json', description: 'Print results as JSON.')]
  #[CLI\Usage(name: 'terminus drush makehaven-website.live -- mh:gift-card-preflight', description: 'Before announcing the Jumpstart, or after changing GEMS cohorts.')]
  public function preflight(array $options = ['offline' => FALSE, 'json' => FALSE]): int {
    $results = $this->preflight->run(!$options['offline']);
    if ($options['json']) {
      $this->output()->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    else {
      $mark = ['ok' => 'ok', 'warn' => 'WARN', 'fail' => 'FAIL'];
      $this->io()->table(['', 'Check', 'Detail'], array_map(static fn($r) => [$mark[$r['result']], $r['check'], $r['detail']], $results));
    }
    return GiftCardPreflight::failed($results) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
