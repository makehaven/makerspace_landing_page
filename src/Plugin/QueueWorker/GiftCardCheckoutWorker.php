<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\makerspace_landing_page\GiftCard\GiftCardRedeemer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settles a gift card coupon used at checkout.
 *
 * @QueueWorker(
 *   id = "makerspace_gift_card_checkout",
 *   title = @Translation("Gift card checkout settlement"),
 *   cron = {"time" = 30}
 * )
 */
class GiftCardCheckoutWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Stop retrying after this long and leave it to staff.
   */
  protected const GIVE_UP_AFTER = 3 * 86400;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected GiftCardRedeemer $redeemer,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('makerspace_landing_page.gift_card_redeemer'),
      $container->get('logger.factory')->get('makerspace_landing_page'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    try {
      $done = $this->redeemer->settleCheckout($data['coupon_id'], $data['subscription_id'], $data['customer_id'], $data['invoice'] ?? NULL);
    }
    catch (\RuntimeException $e) {
      $done = FALSE;
      $this->logger->warning('Gift card checkout @c not settled yet: @m', ['@c' => $data['coupon_id'], '@m' => $e->getMessage()]);
    }
    if ($done) {
      return;
    }
    if (time() - (int) ($data['queued'] ?? time()) > self::GIVE_UP_AFTER) {
      $this->logger->error('Gave up settling gift card coupon @c on subscription @s after 3 days. Check the card on /admin/gift-cards and add the rest of its balance as Chargebee credit by hand.', [
        '@c' => $data['coupon_id'],
        '@s' => $data['subscription_id'],
      ]);
      return;
    }
    // Try again in 15 minutes rather than straight away in this same cron run.
    throw new DelayedRequeueException(900);
  }

}
