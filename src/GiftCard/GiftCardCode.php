<?php

namespace Drupal\makerspace_landing_page\GiftCard;

/**
 * Gift code and print-link key, both derived from a contribution's invoice_id.
 *
 * CiviCRM gives every online contribution a random 32-hex invoice_id. The full
 * value is the secret in the print link (never expires, unlike contact
 * checksums, which die after 60 days); the first 8 characters are the code
 * printed on the card, which staff match to the order at redemption. Eight of
 * 32 characters is not enough to rebuild the link.
 */
final class GiftCardCode {

  /**
   * The code printed on the card, e.g. "7F3A-92C1", or '' without an invoice.
   */
  public static function fromInvoice(?string $invoice_id): string {
    $clean = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) $invoice_id));
    if (strlen($clean) < 8) {
      return '';
    }
    return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
  }

  /**
   * Whether a print-link key matches the contribution's invoice_id.
   */
  public static function keyMatches(?string $invoice_id, ?string $key): bool {
    $invoice_id = (string) $invoice_id;
    $key = (string) $key;
    // A short or missing invoice_id would make the "secret" guessable.
    if (strlen($invoice_id) < 16 || $key === '') {
      return FALSE;
    }
    return hash_equals($invoice_id, $key);
  }

}
