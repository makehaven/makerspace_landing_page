<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff gift card list (/admin/gift-cards), money owed and monthly numbers.
 */
class GiftCardAdminController extends ControllerBase {

  /**
   * Where each redemption channel's value is earned, for the bookkeeper.
   */
  public const CHANNEL_LABELS = [
    'chargebee_coupon' => 'Membership (first invoice)',
    'chargebee_credit' => 'Membership (account credit)',
    'event_discount' => 'Classes',
    'staff' => 'Recorded by staff',
    'civicrm' => 'Sold online',
    'manual' => 'Added by staff',
    'legacy' => 'Imported from before the ledger',
    'system' => 'Automatic',
    'bonus' => 'Multi-month savings',
  ];

  public function __construct(
    protected GiftCardLedger $ledger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('makerspace_landing_page.gift_card_ledger'));
  }

  /**
   * The list, with search, the mail queue and this month's numbers.
   */
  public function list(Request $request): array {
    $text = trim((string) $request->query->get('q', ''));
    $status = (string) $request->query->get('status', '');
    $build = ['#cache' => ['max-age' => 0]];

    $start = strtotime(date('Y-m-01 00:00:00'));
    $report = $this->ledger->monthReport($start, strtotime('+1 month', $start));
    $build['owed'] = [
      '#markup' => '<p><strong>' . $this->t('Owed on unused cards: @owed.', ['@owed' => GiftCardMath::dollars($report['owed_end'])]) . '</strong> '
      . $this->t('This month: @n sold for @sold, @redeemed redeemed.', [
        '@n' => $report['sold_count'],
        '@sold' => GiftCardMath::dollars(array_sum($report['sold'])),
        '@redeemed' => GiftCardMath::dollars(array_sum($report['redeemed'])),
      ]) . ' <a href="' . Url::fromRoute('makerspace_landing_page.gift_card_report')->toString() . '">' . $this->t('Monthly report for the books') . '</a></p>',
    ];

    $to_mail = $this->ledger->toMail();
    if ($to_mail) {
      $items = [];
      foreach ($to_mail as $card) {
        $items[] = [
          '#markup' => '<a href="' . $this->cardUrl($card) . '">' . htmlspecialchars($card->display_code) . '</a>: '
          . htmlspecialchars($card->recipient_name ?: $card->buyer_name) . ', ' . $this->t('ordered @date', ['@date' => date('M j', (int) $card->created)]),
        ];
      }
      $build['mail'] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('To mail (@n)', ['@n' => count($to_mail)]),
        'list' => ['#theme' => 'item_list', '#items' => $items],
        'help' => ['#markup' => '<p>' . $this->t('Open a card, print it, mail it to the buyer\'s address, then press "Mark as mailed".') . '</p>'],
      ];
    }

    $build['search'] = [
      '#type' => 'inline_template',
      '#template' => '<form method="get" class="mh-gift-search" style="margin:1em 0;display:flex;gap:.5em;flex-wrap:wrap;align-items:end;">
        <label>{{ "Code, name or email"|t }}<br><input type="search" name="q" value="{{ q }}" size="30"></label>
        <label>{{ "Status"|t }}<br><select name="status">
          <option value="">{{ "Any"|t }}</option>
          {% for k, v in statuses %}<option value="{{ k }}"{{ k == status ? " selected" }}>{{ v }}</option>{% endfor %}
        </select></label>
        <button type="submit" class="button">{{ "Search"|t }}</button></form>',
      '#context' => [
        'q' => $text,
        'status' => $status,
        'statuses' => ['active' => $this->t('Has a balance'), 'used' => $this->t('Used up'), 'void' => $this->t('Void')],
      ],
    ];

    $rows = [];
    foreach ($this->ledger->search($text, $status, 200) as $card) {
      $rows[] = [
        ['data' => ['#markup' => '<a href="' . $this->cardUrl($card) . '">' . htmlspecialchars($card->display_code) . '</a>']],
        $card->kind,
        $card->buyer_name,
        $card->recipient_name,
        date('Y-m-d', (int) $card->created),
        GiftCardMath::dollars((int) $card->amount_cents),
        GiftCardMath::dollars((int) $card->balance_cents),
        $card->status,
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Code'), $this->t('Kind'), $this->t('Buyer'), $this->t('For'), $this->t('Sold'),
        $this->t('Value'), $this->t('Left'), $this->t('Status'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No gift cards match.'),
    ];
    return $build;
  }

  /**
   * The month's numbers for the bookkeeper, on screen or as CSV.
   */
  public function report(Request $request): array|Response {
    $month = (string) $request->query->get('month', date('Y-m', strtotime('first day of last month')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
      $month = date('Y-m');
    }
    $start = strtotime($month . '-01 00:00:00');
    $end = strtotime('+1 month', $start);
    $r = $this->ledger->monthReport($start, $end);

    $lines = [];
    $lines[] = ['Owed on unused cards at the start of ' . date('F Y', $start), GiftCardMath::dollars($r['owed_start'])];
    foreach ($r['sold'] as $kind => $cents) {
      $lines[] = ['Sold: ' . $kind . ' cards (paid)', GiftCardMath::dollars($cents)];
    }
    foreach ($r['redeemed'] as $channel => $cents) {
      $lines[] = ['Redeemed: ' . (self::CHANNEL_LABELS[$channel] ?? $channel), GiftCardMath::dollars($cents)];
    }
    if ($r['bonus']) {
      $lines[] = ['Bonus value on 6- and 12-month cards (a discount, not money received)', GiftCardMath::dollars($r['bonus'])];
    }
    if ($r['adjusted']) {
      $lines[] = ['Staff corrections and voids', GiftCardMath::dollars($r['adjusted'])];
    }
    $lines[] = ['Owed on unused cards at the end of ' . date('F Y', $start), GiftCardMath::dollars($r['owed_end'])];

    if ($request->query->get('format') === 'csv') {
      $out = fopen('php://temp', 'r+');
      fputcsv($out, ['Line', 'Amount']);
      foreach ($lines as $line) {
        fputcsv($out, $line);
      }
      rewind($out);
      $response = new Response(stream_get_contents($out));
      $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
      $response->headers->set('Content-Disposition', 'attachment; filename="gift-cards-' . $month . '.csv"');
      return $response;
    }

    $prev = date('Y-m', strtotime('-1 month', $start));
    $next = date('Y-m', $end);
    return [
      '#cache' => ['max-age' => 0],
      'nav' => [
        '#markup' => '<p><a href="?month=' . $prev . '">&larr; ' . date('F Y', strtotime($prev . '-01')) . '</a> &middot; <strong>' . date('F Y', $start) . '</strong> &middot; <a href="?month=' . $next . '">' . date('F Y', $end) . ' &rarr;</a> &middot; <a href="?month=' . $month . '&amp;format=csv">' . $this->t('Download CSV') . '</a></p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Line'), $this->t('Amount')],
        '#rows' => $lines,
      ],
      'help' => [
        '#markup' => '<p>' . $this->t('Cards sold before the online system (PayPal orders, hand-made Chargebee and class codes) are only counted once staff import them. Membership redemptions are earned as Member Dues and class redemptions as Event Fees.') . '</p>',
      ],
    ];
  }

  /**
   * Link to one card's page.
   */
  protected function cardUrl(object $card): string {
    return Url::fromRoute('makerspace_landing_page.gift_card_admin_card', ['card_id' => $card->id])->toString();
  }

}
