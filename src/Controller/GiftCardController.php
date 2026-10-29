<?php

namespace Drupal\makerspace_landing_page\Controller;

use Civi\Api4\Contribution;
use Drupal\civicrm\Civicrm;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Drupal\makerspace_landing_page\GiftCard\GiftCardCode;
use Drupal\makerspace_landing_page\GiftCard\GiftCardDesigns;
use Drupal\makerspace_landing_page\GiftCard\GiftCardLedger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Prints a fold-up gift card for a CiviCRM "Gift Card" contribution.
 *
 * The buyer reaches this from the button in their receipt email, which carries
 * the contribution's invoice_id as the key; staff with "access CiviContribute"
 * can open any gift card without one (mailed cards, lost emails).
 */
class GiftCardController extends ControllerBase {

  public function __construct(
    protected Civicrm $civicrm,
    protected RendererInterface $renderer,
    protected ModuleExtensionList $moduleList,
    protected GiftCardLedger $ledger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('civicrm'),
      $container->get('renderer'),
      $container->get('extension.list.module'),
      $container->get('makerspace_landing_page.gift_card_ledger'),
    );
  }

  /**
   * Renders the printable card as a standalone page.
   */
  public function print(int $contribution_id, string $key = ''): Response {
    $this->civicrm->initialize();
    $c = Contribution::get(FALSE)
      ->addSelect(
        'id', 'invoice_id', 'total_amount', 'contribution_status_id:name', 'financial_type_id:name',
        'contribution_page_id.name', 'contact_id.first_name', 'contact_id.display_name',
        'Gift_Card.Gift_For', 'Gift_Card.Recipient_Name', 'Gift_Card.Card_Message'
      )
      ->addWhere('id', '=', $contribution_id)
      ->execute()
      ->first();

    // Same answer for "no such order" and "not a gift card", so the route
    // can't be used to probe which contribution ids exist.
    if (!$c || $c['financial_type_id:name'] !== 'Gift Card') {
      throw new NotFoundHttpException();
    }
    $staff = $this->currentUser()->hasPermission('access CiviContribute');
    if (!$staff && !GiftCardCode::keyMatches($c['invoice_id'], $key)) {
      throw new AccessDeniedHttpException();
    }
    if ($c['contribution_status_id:name'] !== 'Completed' && !$staff) {
      return $this->page([
        '#markup' => '<p>This gift card will be ready as soon as the payment finishes processing. Try again in a few minutes, or email info@makehaven.org.</p>',
      ], 'Gift card not ready yet');
    }

    $buyer = trim((string) ($c['contact_id.first_name'] ?: $c['contact_id.display_name']));
    $is_gift = ($c['Gift_Card.Gift_For'] ?? 'gift') !== 'self';
    $recipient = trim((string) ($c['Gift_Card.Recipient_Name'] ?? ''));

    // The card's face value: a 6- or 12-month card is worth more than it cost.
    $card = $this->ledger->findByContribution((int) $c['id']);
    $value = $card ? ((int) $card->amount_cents) / 100 : (float) $c['total_amount'];

    $base = base_path() . $this->moduleList->getPath('makerspace_landing_page');
    $build = [
      '#theme' => 'makerspace_gift_card',
      '#design' => GiftCardDesigns::forPage($c['contribution_page_id.name'] ?? NULL, $value),
      '#to' => $is_gift ? ($recipient !== '' ? $recipient : 'you') : $buyer,
      '#from' => $is_gift ? $buyer : '',
      '#message' => trim((string) ($c['Gift_Card.Card_Message'] ?? '')),
      '#code' => GiftCardCode::fromInvoice($c['invoice_id']) ?: 'MH-' . $c['id'],
      '#asset_base' => $base . '/images/gift-card',
      '#css_url' => $base . '/css/gift-card.css',
    ];
    return $this->page($build, 'Your MakeHaven gift card', FALSE);
  }

  /**
   * Wraps content in a bare page: no site chrome, so it prints as one sheet.
   */
  protected function page(array $content, string $title, bool $wrap = TRUE): Response {
    $html = (string) $this->renderer->renderInIsolation($content);
    if ($wrap) {
      $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($title) . '</title></head><body style="font-family:sans-serif;max-width:40em;margin:3em auto;padding:0 1em;">'
        . '<h1>' . htmlspecialchars($title) . '</h1>' . $html . '</body></html>';
    }
    $response = new Response($html);
    // Personal content behind a secret link: never cache or index it.
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    return $response;
  }

}
