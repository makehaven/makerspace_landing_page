<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makerspace_landing_page\Controller\GiftCardAdminController;
use Drupal\makerspace_landing_page\GiftCard\GiftCardDelivery;
use Drupal\makerspace_landing_page\GiftCard\GiftCardEventCodes;
use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Drupal\makerspace_landing_page\GiftCard\GiftCardRedeemer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One card for staff (/admin/gift-cards/{id}): history and actions.
 */
class GiftCardAdminCardForm extends FormBase {

  public function __construct(
    protected GiftCardLedger $ledger,
    protected GiftCardRedeemer $redeemer,
    protected GiftCardDelivery $delivery,
    protected GiftCardEventCodes $eventCodes,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_landing_page.gift_card_ledger'),
      $container->get('makerspace_landing_page.gift_card_redeemer'),
      $container->get('makerspace_landing_page.gift_card_delivery'),
      $container->get('makerspace_landing_page.gift_card_event_codes'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_gift_card_admin_card';
  }

  /**
   * Page title.
   */
  public function title(int $card_id): string {
    $card = $this->ledger->load($card_id);
    return $card ? 'Gift card ' . $card->display_code : 'Gift card';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $card_id = NULL) {
    $card = $card_id ? $this->ledger->load($card_id) : NULL;
    if (!$card) {
      throw new NotFoundHttpException();
    }
    $form_state->set('card_id', (int) $card->id);
    $form['#cache']['max-age'] = 0;

    $facts = [
      [$this->t('Kind'), $card->kind],
      [$this->t('Value / left'), GiftCardMath::dollars((int) $card->amount_cents) . ' / ' . GiftCardMath::dollars((int) $card->balance_cents) . ' (' . $card->status . ')'],
      [$this->t('Paid'), GiftCardMath::dollars((int) $card->paid_cents)],
      [$this->t('Bought by'), trim($card->buyer_name . ' ' . ($card->buyer_email ? '(' . $card->buyer_email . ')' : ''))],
      [$this->t('For'), trim($card->recipient_name . ' ' . ($card->recipient_email ? '(' . $card->recipient_email . ')' : '')) ?: $this->t('(themselves)')],
      [$this->t('Delivery'), match ($card->delivery) {
        'recipient' => $this->t('We email the recipient') . ($card->deliver_after ? ' ' . $this->t('on or after @d', ['@d' => date('M j, Y', (int) $card->deliver_after)]) : '') . ($card->recipient_emailed ? ' ' . $this->t('(sent @d)', ['@d' => date('M j', (int) $card->recipient_emailed)]) : ''),
        'mail' => $this->t('Mail a printed card') . ($card->mailed ? ' ' . $this->t('(mailed @d)', ['@d' => date('M j', (int) $card->mailed)]) : ' ' . $this->t('(not mailed yet)')),
        default => $this->t('Buyer prints or forwards it'),
      }],
      [$this->t('Sold'), date('Y-m-d', (int) $card->created) . ($card->contribution_id ? ', ' . $this->t('order @c', ['@c' => $card->contribution_id]) : '')],
      [$this->t('Class discount code'), $card->discount_id ? $this->t('Yes (CiviDiscount @id)', ['@id' => $card->discount_id]) : $this->t('No')],
    ];
    $rows = [];
    foreach ($facts as [$label, $value]) {
      $rows[] = [['data' => $label, 'header' => TRUE], (string) $value];
    }
    $form['facts'] = ['#type' => 'table', '#rows' => $rows];
    if ($card->note) {
      $form['note_text'] = ['#markup' => '<p><em>' . nl2br(htmlspecialchars($card->note)) . '</em></p>'];
    }

    $links = [];
    if ($print = $this->delivery->printUrl($card)) {
      $links[] = '<a href="' . $print . '" target="_blank">' . $this->t('Print the card') . '</a>';
    }
    if ($card->contribution_id) {
      $links[] = '<a href="' . Url::fromUserInput('/civicrm/contact/view/contribution', ['query' => ['reset' => 1, 'action' => 'view', 'id' => $card->contribution_id, 'cid' => $card->contact_id]])->toString() . '">' . $this->t('The order in CiviCRM') . '</a>';
    }
    $links[] = '<a href="' . Url::fromRoute('makerspace_landing_page.gift_card_admin')->toString() . '">' . $this->t('All gift cards') . '</a>';
    $form['links'] = ['#markup' => '<p>' . implode(' &middot; ', $links) . '</p>'];

    $history = [];
    foreach ($this->ledger->history((int) $card->id) as $t) {
      $history[] = [
        date('Y-m-d H:i', (int) $t->created),
        $t->type,
        $t->amount_cents ? GiftCardMath::dollars((int) $t->amount_cents) : '',
        GiftCardAdminController::CHANNEL_LABELS[$t->channel] ?? $t->channel,
        $t->note,
      ];
    }
    $form['history'] = [
      '#type' => 'details',
      '#title' => $this->t('History'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('When'), $this->t('What'), $this->t('Amount'), $this->t('Where'), $this->t('Note')],
        '#rows' => $history,
      ],
    ];

    $form['send'] = ['#type' => 'details', '#title' => $this->t('Email or mail the card'), '#open' => FALSE];
    $form['send']['email_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Email the card to'),
      '#default_value' => $card->recipient_email ?: $card->buyer_email,
    ];
    $form['send']['send_email'] = ['#type' => 'submit', '#value' => $this->t('Send card email'), '#name' => 'send_email', '#limit_validation_errors' => [['email_to']]];
    if ($card->delivery === 'mail' && !$card->mailed) {
      $form['send']['mark_mailed'] = ['#type' => 'submit', '#value' => $this->t('Mark as mailed'), '#name' => 'mark_mailed', '#limit_validation_errors' => []];
    }

    $form['member'] = ['#type' => 'details', '#title' => $this->t('Apply to a member\'s membership'), '#open' => FALSE];
    $form['member']['member_user'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Member'),
      '#description' => $this->t('Adds the whole balance (@b) to their Chargebee account as credit.', ['@b' => GiftCardMath::dollars((int) $card->balance_cents)]),
    ];
    $form['member']['apply_member'] = ['#type' => 'submit', '#value' => $this->t('Apply to this member'), '#name' => 'apply_member', '#limit_validation_errors' => [['member_user']]];

    $form['change'] = ['#type' => 'details', '#title' => $this->t('Record a use or a correction'), '#open' => FALSE];
    $form['change']['change_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('What happened'),
      '#options' => [
        'redeem' => $this->t('Used: staff applied value by hand (amount is taken off)'),
        'adjust' => $this->t('Correction: add or take off value (use a minus sign to take off)'),
        'void' => $this->t('Void the card (takes the whole balance off)'),
      ],
      '#default_value' => 'redeem',
    ];
    $form['change']['change_amount'] = ['#type' => 'number', '#title' => $this->t('Amount in dollars'), '#step' => '0.01'];
    $form['change']['change_note'] = ['#type' => 'textfield', '#title' => $this->t('Note (what and why)'), '#maxlength' => 400];
    $form['change']['record'] = [
      '#type' => 'submit',
      '#value' => $this->t('Record'),
      '#name' => 'record',
      '#limit_validation_errors' => [['change_type'], ['change_amount'], ['change_note']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger === 'send_email' && !$form_state->getValue('email_to')) {
      $form_state->setErrorByName('email_to', $this->t('Enter an email address.'));
    }
    if ($trigger === 'apply_member' && !$form_state->getValue('member_user')) {
      $form_state->setErrorByName('member_user', $this->t('Choose a member.'));
    }
    if ($trigger === 'record') {
      if (trim((string) $form_state->getValue('change_note')) === '') {
        $form_state->setErrorByName('change_note', $this->t('Say what happened, for whoever reads this later.'));
      }
      if ($form_state->getValue('change_type') !== 'void' && !(float) $form_state->getValue('change_amount')) {
        $form_state->setErrorByName('change_amount', $this->t('Enter an amount.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $card = $this->ledger->load((int) $form_state->get('card_id'));
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    $who = $this->currentUser()->getAccountName();

    switch ($trigger) {
      case 'send_email':
        $card->recipient_email = (string) $form_state->getValue('email_to');
        if ($this->delivery->sendToRecipient($card)) {
          $this->messenger()->addStatus($this->t('Card emailed to @e.', ['@e' => $card->recipient_email]));
        }
        else {
          $this->messenger()->addError($this->t('The email could not be sent.'));
        }
        break;

      case 'mark_mailed':
        $this->ledger->update((int) $card->id, ['mailed' => $this->time->getRequestTime()]);
        $this->ledger->record((int) $card->id, 'note', 0, 'staff', 'mailed', 'Printed card mailed by ' . $who);
        $this->messenger()->addStatus($this->t('Marked as mailed.'));
        break;

      case 'apply_member':
        $user = $this->entityTypeManager->getStorage('user')->load($form_state->getValue('member_user'));
        try {
          $this->redeemer->redeemForMember($card, $user);
          $this->messenger()->addStatus($this->t('Applied to @name as Chargebee credit.', ['@name' => $user->getDisplayName()]));
        }
        catch (\RuntimeException $e) {
          $this->messenger()->addError($e->getMessage());
        }
        break;

      case 'record':
        $type = (string) $form_state->getValue('change_type');
        $cents = (int) round(((float) $form_state->getValue('change_amount')) * 100);
        $amount = match ($type) {
          'redeem' => -abs($cents),
          'void' => -(int) $card->balance_cents,
          default => $cents,
        };
        $note = trim((string) $form_state->getValue('change_note')) . ' (' . $who . ')';
        $this->ledger->record((int) $card->id, $type, $amount, 'staff', '', $note);
        $card = $this->ledger->load((int) $card->id);
        if ($card->discount_id) {
          $this->eventCodes->syncCode($card);
        }
        $this->messenger()->addStatus($this->t('Recorded. Balance is now @b.', ['@b' => GiftCardMath::dollars((int) $card->balance_cents)]));
        break;
    }
  }

}
