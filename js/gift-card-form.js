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
 * (makerspace_landing_page_civicrm_validateForm).
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

    field('Gift_For').closest('form').on('submit', function () {
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
