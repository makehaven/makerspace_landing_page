/**
 * @file
 * Gift card order pages: show the recipient questions only for a gift.
 *
 * "Myself" (or nothing picked yet): no recipient name, email, card message or
 * date, and no "Email it to the recipient" delivery choice.
 * "Someone else": name, email and message show; the date shows, moved under
 * the delivery choice that refers to it ("on the date below"), only when
 * "Email it to the recipient" is picked. Hidden fields are emptied on submit
 * so nothing typed and then hidden is saved. The server checks the same rules
 * (makerspace_landing_page_civicrm_validateForm). The questions move up
 * under the price, and are checked before the card is charged, so a missed
 * answer never sends the buyer back to an emptied card field.
 */
(function ($) {
  'use strict';

  function field(name) {
    return $('[data-crm-custom="Gift_Card:' + name + '"]');
  }

  // A profile field is its editrow plus an optional help row after it.
  function rows(name) {
    var $input = field(name).first();
    if (!$input.length) {
      return $();
    }
    var $row = $input.closest('[id^="editrow-"]');
    var id = ($row.attr('id') || '').replace('editrow-', '');
    return $row.add($('#helprow-' + id));
  }

  function choice(name) {
    return field(name).filter(':checked').val() || '';
  }

  $(function () {
    if (!field('Gift_For').length) {
      return;
    }

    var $name = rows('Recipient_Name');
    var $email = rows('Recipient_Email');
    var $message = rows('Card_Message');
    var $after = rows('Contact_After');
    var $delivery = rows('Delivery');
    var $toRecipient = field('Delivery').filter('[value="recipient"]');
    var $toRecipientChoice = $toRecipient.closest('.crm-option-label-pair')
      .add($('label[for="' + $toRecipient.attr('id') + '"]'));

    // The date belongs to "Email it to the recipient on the date below".
    if ($after.length && $delivery.length) {
      $after.insertAfter($delivery.last());
    }

    // Ask who it is for right under the price, before the buyer's own
    // details and the card: at the bottom it was easy to miss (JR 2026-11-24).
    var $group = field('Gift_For').closest('.crm-profile-name-gift_card_details')
      .closest('.crm-public-form-item');
    if ($group.length && $('#priceset-div').length) {
      $group.addClass('mh-gift-card-questions').insertAfter('#priceset-div');
    }

    function update() {
      var gift = choice('Gift_For') === 'gift';
      if (!gift && $toRecipient.is(':checked')) {
        field('Delivery').filter('[value="email"]').prop('checked', true).trigger('change');
      }
      $toRecipientChoice.toggle(gift);
      var toRecipient = gift && choice('Delivery') === 'recipient';
      $name.add($message).toggle(gift);
      $email.toggle(gift);
      $after.toggle(toRecipient);
      field('Recipient_Email').prop('required', toRecipient);
      field('Recipient_Name').prop('required', gift);
    }

    field('Gift_For').add(field('Delivery')).on('change click', update);
    update();

    // Check these before the payment script runs: a refused form comes back
    // from the server with the card number wiped, so catch the misses here.
    // Capture phase on the form runs before the payment script's own
    // listeners on the button.
    var $form = field('Gift_For').closest('form');

    function problem($rows, text) {
      $rows.first().find('.mh-gift-card-error').remove();
      if (text) {
        $('<div class="mh-gift-card-error" role="alert"></div>').text(text)
          .appendTo($rows.first());
      }
    }

    function check() {
      var gift = choice('Gift_For') === 'gift';
      var $who = rows('Gift_For');
      var bad = [];
      problem($who, choice('Gift_For') ? '' : ts('Please choose who the card is for.'));
      if (!choice('Gift_For')) {
        bad.push($who);
      }
      var name = $.trim(field('Recipient_Name').val() || '');
      problem($name, gift && !name ? ts("Please enter the recipient's name. It is printed on the card.") : '');
      if (gift && !name) {
        bad.push($name);
      }
      var email = $.trim(field('Recipient_Email').val() || '');
      var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      var emailBad = gift && ((choice('Delivery') === 'recipient' && !emailOk) || (email && !emailOk));
      problem($email, emailBad ? ts("Please enter the recipient's email address so we can send them the card.") : '');
      if (emailBad) {
        bad.push($email);
      }
      if (bad.length) {
        bad[0].get(0).scrollIntoView({behavior: 'smooth', block: 'center'});
        bad[0].find('input, textarea').first().trigger('focus');
        return false;
      }
      return true;
    }

    if ($form.length) {
      $form.get(0).addEventListener('click', function (e) {
        var button = e.target.closest('[type="submit"]');
        if (button && !button.hasAttribute('formnovalidate') && !check()) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      }, true);
      $form.get(0).addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.target.closest('textarea, button, a') && !check()) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      }, true);
      field('Gift_For').add(field('Recipient_Name')).add(field('Recipient_Email')).add(field('Delivery'))
        .on('change input', function () {
          if ($form.find('.mh-gift-card-error').length) {
            check();
          }
        });
    }

    $form.on('submit', function () {
      [['Recipient_Name', $name], ['Recipient_Email', $email], ['Card_Message', $message], ['Contact_After', $after]].forEach(function (pair) {
        if (!pair[1].first().is(':visible')) {
          field(pair[0]).val('');
          // The date picker keeps its typed value in a sibling display input.
          pair[1].find('input').not('[type=radio],[type=checkbox],[type=hidden]').val('');
        }
      });
    });
  });
})(CRM.$);
