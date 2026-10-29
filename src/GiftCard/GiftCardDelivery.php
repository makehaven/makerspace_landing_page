<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Civi\Api4\Contribution;
use Drupal\civicrm\Civicrm;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;

/**
 * Emails gift cards to recipients on the date the buyer chose.
 *
 * The buyer always gets the card in their receipt. When they ask us to send
 * it to the recipient, cron does that on or after their "don't contact before"
 * date, so a December order can arrive on the 25th without anyone at the
 * front desk remembering it.
 */
class GiftCardDelivery {

  public function __construct(
    protected GiftCardLedger $ledger,
    protected Civicrm $civicrm,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Sends every recipient email that is due.
   *
   * @return int
   *   Emails sent.
   */
  public function sendDue(): int {
    $sent = 0;
    foreach ($this->ledger->dueForRecipient($this->time->getRequestTime()) as $card) {
      if ($this->sendToRecipient($card)) {
        $sent++;
      }
    }
    return $sent;
  }

  /**
   * Emails one card to its recipient and notes when.
   */
  public function sendToRecipient(object $card): bool {
    if (!filter_var($card->recipient_email, FILTER_VALIDATE_EMAIL)) {
      $this->ledger->update((int) $card->id, ['recipient_emailed' => 0]);
      $this->ledger->record((int) $card->id, 'note', 0, 'system', 'recipient-email-invalid', 'Recipient email "' . $card->recipient_email . '" is not valid; card not emailed. Send it by hand.');
      return FALSE;
    }
    $result = $this->mailManager->mail('makerspace_landing_page', 'gift_card_recipient', $card->recipient_email, 'en', [
      'card' => $card,
      'print_url' => $this->printUrl($card),
    ], 'info@makehaven.org');
    if (empty($result['result'])) {
      $this->loggerFactory->get('makerspace_landing_page')->error('Gift card @code: email to the recipient failed.', ['@code' => $card->display_code]);
      return FALSE;
    }
    $now = $this->time->getRequestTime();
    $this->ledger->update((int) $card->id, ['recipient_emailed' => $now]);
    $this->ledger->record((int) $card->id, 'note', 0, 'system', 'recipient-emailed:' . $now, 'Emailed to ' . $card->recipient_email);
    return TRUE;
  }

  /**
   * The never-expiring print link for a card's order, or '' without one.
   */
  public function printUrl(object $card): string {
    if (!$card->contribution_id) {
      return '';
    }
    $this->civicrm->initialize();
    $row = Contribution::get(FALSE)->addSelect('invoice_id')
      ->addWhere('id', '=', (int) $card->contribution_id)->execute()->first();
    $invoice = (string) ($row['invoice_id'] ?? '');
    if ($invoice === '') {
      return '';
    }
    return Url::fromRoute('makerspace_landing_page.gift_card_print', [
      'contribution_id' => (int) $card->contribution_id,
      'key' => $invoice,
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * How to use a card, by kind, in one or two sentences.
   */
  public static function howToRedeem(object $card): string {
    $redeem = Url::fromRoute('makerspace_landing_page.gift_card_redeem', [], ['absolute' => TRUE, 'query' => ['code' => $card->display_code]])->toString();
    return match ($card->kind) {
      'workshop' => "Pick a class at https://www.makehaven.org/events and enter {$card->display_code} in the discount code box when you register. Whatever you don't use stays on the card for the next class.",
      'jumpstart' => "To start, join at https://www.makehaven.org/join-makehaven and enter {$card->display_code} as your gift certificate code: your 3 months of membership are free. Then email info@makehaven.org to pick your GEMS cohort.",
      default => "To use it, go to $redeem. New members enter the code when they join; current members add it to their account in one step.",
    };
  }

}
