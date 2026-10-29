<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;

/**
 * Turns gift card balances into membership credit in Chargebee.
 *
 * Two ways in:
 *  - A new member types the code on the join form. The form gets a
 *    single-use Chargebee coupon for the card's balance; Chargebee caps it at
 *    the first invoice, and once the subscription exists the rest of the
 *    balance goes onto the account as promotional credit (settleCheckout).
 *  - A current member enters the code on /gift-card/redeem: the whole balance
 *    goes onto their account as promotional credit (redeemForMember).
 *
 * Either way the card's value ends up on the member's Chargebee account,
 * which spends it month by month. Nobody has to make a coupon by hand.
 */
class GiftCardRedeemer {

  use StringTranslationTrait;

  public function __construct(
    protected GiftCardLedger $ledger,
    protected GiftCardChargebee $chargebee,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Resolves a code typed on the join form.
   *
   * @return array
   *   Keys: matched (bool: this is a gift card code), coupon (Chargebee coupon
   *   id to send to checkout, when there is one) and error (message for the
   *   form, when the card can't be used here).
   */
  public function resolveJoinCode(string $code): array {
    $card = $this->ledger->findByCode($code);
    if (!$card) {
      return ['matched' => FALSE];
    }
    if ($error = $this->unusableReason($card)) {
      return ['matched' => TRUE, 'error' => $error];
    }
    if ($card->kind === 'workshop') {
      return [
        'matched' => TRUE,
        'error' => (string) $this->t('@code is a workshop gift card. Use it when you sign up for a class: enter it in the discount code box at registration. To join, clear the code and continue.', ['@code' => $card->display_code]),
      ];
    }
    try {
      if ($card->kind === 'jumpstart') {
        $coupon = $this->chargebee->ensureFreeMonthsCoupon(
          GiftCardMath::jumpstartCouponId($card->code),
          GiftCardMath::JUMPSTART_MONTHS,
          'MakeHaven Jumpstart: ' . GiftCardMath::JUMPSTART_MONTHS . ' months of membership'
        );
      }
      else {
        $coupon = $this->chargebee->ensureFixedCoupon(
          GiftCardMath::membershipCouponId($card->code, (int) $card->balance_cents),
          (int) $card->balance_cents,
          'Gift card ' . $card->display_code
        );
      }
    }
    catch (\RuntimeException $e) {
      $this->loggerFactory->get('makerspace_landing_page')->error('Join form could not prepare gift card @code: @m', [
        '@code' => $card->display_code,
        '@m' => $e->getMessage(),
      ]);
      return [
        'matched' => TRUE,
        'error' => (string) $this->t("We couldn't apply your gift card just now. Clear the code and continue joining, then email info@makehaven.org with the code @code and we'll add it to your account.", ['@code' => $card->display_code]),
      ];
    }
    if (($coupon['status'] ?? 'active') !== 'active') {
      // The coupon was already used at a checkout, but the card still shows
      // the balance: settlement hasn't run yet. Don't hand out a second one.
      return [
        'matched' => TRUE,
        'error' => (string) $this->t('Gift card @code is already being applied to a membership. Questions: info@makehaven.org', ['@code' => $card->display_code]),
      ];
    }
    return ['matched' => TRUE, 'coupon' => $coupon['id']];
  }

  /**
   * Puts a card's whole balance on a current member's Chargebee account.
   *
   * @return string
   *   What happened, for the member.
   *
   * @throws \RuntimeException
   *   With a message safe to show when it can't be done.
   */
  public function redeemForMember(object $card, UserInterface $account): string {
    if ($error = $this->unusableReason($card)) {
      throw new \RuntimeException($error);
    }
    if ($card->kind === 'workshop') {
      throw new \RuntimeException((string) $this->t('This is a workshop gift card. Enter @code in the discount code box when you register for a class.', ['@code' => $card->display_code]));
    }
    if ($card->kind === 'jumpstart') {
      throw new \RuntimeException((string) $this->t('Jumpstart cards include a GEMS seat, so staff set them up with you. Email info@makehaven.org with the code @code.', ['@code' => $card->display_code]));
    }
    $customer = $account->hasField('field_user_chargebee_id') ? trim((string) $account->get('field_user_chargebee_id')->value) : '';
    if ($customer === '') {
      throw new \RuntimeException((string) $this->t("Your account isn't linked to a membership yet. If you're joining, enter the code on the join form instead."));
    }
    $amount = (int) $card->balance_cents;
    $reference = 'member:' . $account->id();
    // Record first, then credit: a ledger row with no credit is something
    // staff can see and fix; a credit with no ledger row could be applied
    // twice by a double-click.
    if (!$this->ledger->record((int) $card->id, 'redeem', -$amount, 'chargebee_credit', $reference, 'Applied to ' . $account->getAccountName() . ' (' . $customer . ')')) {
      throw new \RuntimeException((string) $this->t('This gift card was already applied to your account.'));
    }
    try {
      $this->chargebee->addCredit($customer, $amount, 'Gift card ' . $card->display_code);
    }
    catch (\RuntimeException $e) {
      $this->ledger->revert((int) $card->id, 'redeem', 'chargebee_credit', $reference);
      throw new \RuntimeException((string) $this->t("We couldn't reach the billing system. Your gift card wasn't used; please try again later or email info@makehaven.org."));
    }
    return (string) $this->t('@amount of membership credit is now on your account. It pays your next invoices automatically.', ['@amount' => GiftCardMath::dollars($amount)]);
  }

  /**
   * Settles a gift coupon used at checkout: records it, credits the rest.
   *
   * @param string $coupon_id
   *   The GIFT- coupon on the new subscription.
   * @param string $subscription_id
   *   The subscription.
   * @param string $customer_id
   *   Its customer.
   * @param array|null $invoice
   *   The first invoice, when the webhook carried it.
   *
   * @return bool
   *   FALSE when the invoice isn't there yet and this should be retried.
   */
  public function settleCheckout(string $coupon_id, string $subscription_id, string $customer_id, ?array $invoice = NULL): bool {
    $parsed = GiftCardMath::parseCouponId($coupon_id);
    $card = $parsed ? $this->ledger->findByCode($parsed['code']) : NULL;
    if (!$card) {
      $this->loggerFactory->get('makerspace_landing_page')->warning('Gift coupon @c on subscription @s matches no gift card.', ['@c' => $coupon_id, '@s' => $subscription_id]);
      return TRUE;
    }
    $reference = 'sub:' . $subscription_id;

    if ($parsed['jumpstart']) {
      // The free months are the promotion; the card's value is the GEMS seat,
      // which staff record when they register the member for a cohort.
      $this->ledger->record((int) $card->id, 'note', 0, 'chargebee_coupon', $reference,
        GiftCardMath::JUMPSTART_MONTHS . ' free months started for Chargebee customer ' . $customer_id . '. GEMS seat still to book.');
      return TRUE;
    }

    if (!$this->ledger->hasTxn((int) $card->id, 'redeem', 'chargebee_coupon', $reference)) {
      $invoice = $invoice ?: $this->chargebee->firstInvoice($subscription_id);
      if (!$invoice) {
        return FALSE;
      }
      $applied = min(GiftCardMath::invoiceDiscount($invoice, $coupon_id), (int) $card->balance_cents);
      $this->ledger->record((int) $card->id, 'redeem', -$applied, 'chargebee_coupon', $reference,
        'First invoice ' . ($invoice['id'] ?? '?') . ' for Chargebee customer ' . $customer_id);
      $card = $this->ledger->load((int) $card->id);
    }

    $rest = (int) $card->balance_cents;
    if ($rest > 0 && !$this->ledger->hasTxn((int) $card->id, 'redeem', 'chargebee_credit', $reference)) {
      $this->ledger->record((int) $card->id, 'redeem', -$rest, 'chargebee_credit', $reference, 'Rest of the card as credit for Chargebee customer ' . $customer_id);
      try {
        $this->chargebee->addCredit($customer_id, $rest, 'Gift card ' . $card->display_code . ' (rest of the balance)');
      }
      catch (\RuntimeException $e) {
        $this->ledger->revert((int) $card->id, 'redeem', 'chargebee_credit', $reference);
        throw $e;
      }
    }
    return TRUE;
  }

  /**
   * Why a card can't be used, or '' when it can.
   */
  public function unusableReason(object $card): string {
    if ($card->status === 'void') {
      return (string) $this->t('Gift card @code is no longer valid. Questions: info@makehaven.org', ['@code' => $card->display_code]);
    }
    if ($card->kind !== 'jumpstart' && (int) $card->balance_cents <= 0) {
      return (string) $this->t('Gift card @code has already been used. Questions: info@makehaven.org', ['@code' => $card->display_code]);
    }
    if ($card->kind === 'jumpstart' && $card->status === 'used') {
      return (string) $this->t('Gift card @code has already been used. Questions: info@makehaven.org', ['@code' => $card->display_code]);
    }
    return '';
  }

}
