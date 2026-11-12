<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\GiftCard;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Days after joining that a member still counts as new for a Jumpstart.
   */
  public const JUMPSTART_NEW_MEMBER_DAYS = 30;

  /**
   * Whether this person may have a Jumpstart's 3 free months.
   *
   * The Jumpstart is a recruitment offer (JR 2026-11-12): the free months are
   * for people joining, including former members coming back, and for anyone
   * who joined (or rejoined) in the last 30 days, say someone who bought it
   * for themselves the week they joined. A longer-standing member still gets
   * the GEMS seat.
   */
  public function jumpstartMonthsAllowed(?UserInterface $user): bool {
    if (!$user || $user->isAnonymous() || !$user->hasRole('member')) {
      return TRUE;
    }
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties(['uid' => $user->id(), 'type' => 'main']);
    $latest = 0;
    foreach ($profiles as $profile) {
      foreach (['field_member_join_date', 'field_member_reactivation_date'] as $field) {
        if ($profile->hasField($field) && !$profile->get($field)->isEmpty()) {
          $latest = max($latest, (int) strtotime((string) $profile->get($field)->value));
        }
      }
    }
    return $latest > 0 && $latest >= strtotime('-' . self::JUMPSTART_NEW_MEMBER_DAYS . ' days');
  }

  /**
   * What a member who can't have the free months is told.
   */
  protected function newMembersOnlyMessage(object $card): string {
    return (string) $this->t("The Jumpstart's 3 free months are for new members, and you're already a member. Its GEMS seat still works: pick a cohort at makehaven.org/gems and enter @code in the discount code box when you register.", ['@code' => $card->display_code]);
  }

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
    if ($card->kind === 'jumpstart' && !$this->jumpstartMonthsAllowed($this->currentUserEntity())) {
      return ['matched' => TRUE, 'error' => $this->newMembersOnlyMessage($card)];
    }
    if ($card->kind === 'jumpstart' && $this->jumpstartMonthsUsed($card)) {
      return [
        'matched' => TRUE,
        'error' => (string) $this->t('The 3 months of membership on @code have already been used. Its GEMS seat still works: pick a cohort at makehaven.org/gems and enter @code in the discount code box when you register. To join now, clear the code and continue.', ['@code' => $card->display_code]),
      ];
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
        'error' => (string) $this->t("We couldn't reach our billing system to apply @code. Please try again in a few minutes. If it still doesn't work, email info@makehaven.org with the code.", ['@code' => $card->display_code]),
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
    $customer = $account->hasField('field_user_chargebee_id') ? trim((string) $account->get('field_user_chargebee_id')->value) : '';
    if ($customer === '') {
      throw new \RuntimeException((string) $this->t("Your account isn't linked to a membership yet. If you're joining, enter the code on the join form instead."));
    }
    if ($card->kind === 'jumpstart') {
      return $this->redeemJumpstartMonthsForMember($card, $account, $customer);
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
   * Puts a Jumpstart's 3 months on a current member's account as credit.
   *
   * The card's balance is its GEMS seat, which stays on the card for the
   * cohort registration; the months are recorded as a zero-amount note, the
   * same way a new member's free months are.
   */
  protected function redeemJumpstartMonthsForMember(object $card, UserInterface $account, string $customer): string {
    if (!$this->jumpstartMonthsAllowed($account)) {
      throw new \RuntimeException($this->newMembersOnlyMessage($card));
    }
    if ($this->jumpstartMonthsUsed($card)) {
      throw new \RuntimeException((string) $this->t('The 3 months of membership on this Jumpstart have already been used.'));
    }
    $credit = GiftCardMath::JUMPSTART_MEMBER_CREDIT_CENTS;
    $reference = 'member:' . $account->id();
    if (!$this->ledger->record((int) $card->id, 'note', 0, 'chargebee_credit', $reference, GiftCardMath::JUMPSTART_MONTHS . ' months (' . GiftCardMath::dollars($credit) . ') added as credit for ' . $account->getAccountName() . ' (' . $customer . ')')) {
      throw new \RuntimeException((string) $this->t('The 3 months of membership on this Jumpstart have already been used.'));
    }
    try {
      $this->chargebee->addCredit($customer, $credit, 'Jumpstart ' . $card->display_code . ': ' . GiftCardMath::JUMPSTART_MONTHS . ' months of membership');
    }
    catch (\RuntimeException $e) {
      $this->ledger->revert((int) $card->id, 'note', 'chargebee_credit', $reference);
      throw new \RuntimeException((string) $this->t("We couldn't reach the billing system. Nothing was used; please try again in a few minutes."));
    }
    return (string) $this->t('@amount of membership credit (3 months) is now on your account. It pays your next invoices automatically.', ['@amount' => GiftCardMath::dollars($credit)]);
  }

  /**
   * The logged-in user, or NULL.
   */
  protected function currentUserEntity(): ?UserInterface {
    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Whether a Jumpstart's 3 months have been used (joined, or credited).
   */
  public function jumpstartMonthsUsed(object $card): bool {
    return $this->ledger->hasChannelTxn((int) $card->id, 'note', ['chargebee_coupon', 'chargebee_credit']);
  }

  /**
   * Whether a Jumpstart's GEMS seat has been used at a registration.
   */
  public function jumpstartSeatUsed(object $card): bool {
    return (int) $card->balance_cents <= 0;
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
        GiftCardMath::JUMPSTART_MONTHS . ' free months started for Chargebee customer ' . $customer_id . '.' . ($this->jumpstartSeatUsed($card) ? '' : ' GEMS seat not used yet.'));
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
    if ($card->kind === 'jumpstart' && $this->jumpstartSeatUsed($card) && $this->jumpstartMonthsUsed($card)) {
      return (string) $this->t('Gift card @code has already been used. Questions: info@makehaven.org', ['@code' => $card->display_code]);
    }
    return '';
  }

}
