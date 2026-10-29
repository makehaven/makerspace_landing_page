<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Drupal\makerspace_landing_page\GiftCard\GiftCardRedeemer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public redeem page (/gift-card/redeem): check a balance and use it.
 *
 * Current members add the balance to their membership in one click. Anyone
 * else is sent to the join form with the code already filled in. Workshop
 * cards explain where the code goes at class registration.
 */
class GiftCardRedeemForm extends FormBase {

  /**
   * Code lookups allowed per visitor per hour, so codes can't be guessed.
   */
  protected const LOOKUPS_PER_HOUR = 15;

  public function __construct(
    protected GiftCardLedger $ledger,
    protected GiftCardRedeemer $redeemer,
    protected FloodInterface $flood,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_landing_page.gift_card_ledger'),
      $container->get('makerspace_landing_page.gift_card_redeemer'),
      $container->get('flood'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_gift_card_redeem';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#cache']['max-age'] = 0;
    $card = $form_state->get('card');

    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gift card code'),
      '#description' => $this->t('Printed on the card, like 7F3A-92C1.'),
      '#default_value' => $card ? $card->display_code : (string) $this->getRequest()->query->get('code', ''),
      '#required' => TRUE,
      '#size' => 20,
      '#maxlength' => 32,
    ];
    $form['check'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check my card'),
      '#name' => 'check',
    ];

    if (!$card) {
      $form['help'] = [
        '#markup' => '<p>' . $this->t('Lost your card? Email info@makehaven.org with the name of the person who bought it, and we\'ll find it.') . '</p>',
      ];
      return $form;
    }

    $form['result'] = [
      '#type' => 'container',
      '#weight' => -10,
      '#attributes' => ['class' => ['mh-gift-redeem-result']],
    ];
    $form['result']['summary'] = ['#markup' => '<h2>' . $this->summary($card) . '</h2>'];

    if ($reason = $this->redeemer->unusableReason($card)) {
      $form['result']['reason'] = ['#markup' => '<p>' . $reason . '</p>'];
      return $form;
    }

    if ($card->kind === 'workshop') {
      $form['result']['how'] = [
        '#markup' => '<p>' . $this->t('Pick a class on <a href=":events">the events calendar</a> and enter <strong>@code</strong> in the discount code box when you register. Whatever you don\'t use stays on the card for your next class.', [
          ':events' => '/events',
          '@code' => $card->display_code,
        ]) . '</p>',
      ];
      return $form;
    }

    $join = Url::fromUserInput('/join-makehaven', ['query' => ['claim_a_gift_certificate' => 1, 'code' => $card->display_code]])->toString();
    $account = $this->currentUser();
    $user = $account->isAuthenticated() ? $this->entityTypeManager->getStorage('user')->load($account->id()) : NULL;
    $is_member = $user && $user->hasField('field_user_chargebee_id') && trim((string) $user->get('field_user_chargebee_id')->value) !== '';

    if ($card->kind === 'jumpstart') {
      $form['result']['how'] = [
        '#markup' => '<p>' . ($is_member
          ? $this->t('You already have a membership, so staff will set up your Jumpstart with you. Email info@makehaven.org with the code @code.', ['@code' => $card->display_code])
          : $this->t('<a href=":join">Join MakeHaven with this code</a>: your first 3 months of membership are free. Then email info@makehaven.org to pick your GEMS cohort.', [':join' => $join])) . '</p>',
      ];
      return $form;
    }

    if ($is_member) {
      $form['result']['how'] = [
        '#markup' => '<p>' . $this->t('Add it to your membership and it pays your next invoices automatically.') . '</p>',
      ];
      $form['result']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add @amount to my membership', ['@amount' => GiftCardMath::dollars((int) $card->balance_cents)]),
        '#name' => 'apply',
        '#button_type' => 'primary',
      ];
      return $form;
    }

    $login = Url::fromRoute('user.login', [], ['query' => ['destination' => Url::fromRoute('<current>', [], ['query' => ['code' => $card->display_code]])->toString()]])->toString();
    $form['result']['how'] = [
      '#markup' => '<p><a class="btn btn-primary" href="' . $join . '">' . $this->t('Join MakeHaven with this card') . '</a></p><p>'
      . $this->t('Already a member? <a href=":login">Log in</a> to add it to your membership.', [':login' => $login]) . '</p>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? 'check';
    if ($trigger === 'apply') {
      return;
    }
    $ip = $this->getRequest()->getClientIp();
    if (!$this->flood->isAllowed('makerspace_gift_card_lookup', self::LOOKUPS_PER_HOUR, 3600, $ip)) {
      $form_state->setErrorByName('code', $this->t('Too many tries. Please wait an hour, or email info@makehaven.org.'));
      return;
    }
    $this->flood->register('makerspace_gift_card_lookup', 3600, $ip);
    $card = $this->ledger->findByCode((string) $form_state->getValue('code'));
    if (!$card) {
      $form_state->setErrorByName('code', $this->t("We can't find that code. Check it against the card, or email info@makehaven.org. Cards bought before November 2026 may not be online yet; staff can still look them up."));
      return;
    }
    $form_state->set('card', $card);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? 'check';
    if ($trigger === 'apply') {
      $card = $this->ledger->findByCode((string) $form_state->getValue('code'));
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
      if ($card && $user) {
        try {
          $this->messenger()->addStatus($this->redeemer->redeemForMember($card, $user));
          $card = $this->ledger->load((int) $card->id);
        }
        catch (\RuntimeException $e) {
          $this->messenger()->addError($e->getMessage());
        }
      }
      $form_state->set('card', $card);
    }
    $form_state->setRebuild();
  }

  /**
   * One line: what the card is and what's left on it.
   */
  protected function summary(object $card): string {
    $kind = match ($card->kind) {
      'workshop' => $this->t('Workshop gift card'),
      'membership' => $this->t('Membership gift card'),
      'jumpstart' => $this->t('MakeHaven Jumpstart'),
      default => $this->t('MakeHaven gift card'),
    };
    if ($card->kind === 'jumpstart') {
      return (string) $kind . ' ' . $card->display_code;
    }
    return (string) $this->t('@kind @code: @balance left', [
      '@kind' => $kind,
      '@code' => $card->display_code,
      '@balance' => GiftCardMath::dollars(max(0, (int) $card->balance_cents)),
    ]);
  }

}
