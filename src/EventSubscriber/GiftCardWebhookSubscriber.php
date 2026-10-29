<?php

declare(strict_types=1);

namespace Drupal\makerspace_landing_page\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\makerspace_landing_page\GiftCard\GiftCardMath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Queues settlement when a new subscription used a gift card coupon.
 *
 * Listens to chargebee_status_sync's webhook event by name, so this module
 * doesn't depend on that one. Chargebee is waiting on the webhook request, so
 * the Chargebee calls happen later, in the queue (GiftCardCheckoutWorker).
 */
class GiftCardWebhookSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['chargebee_status_sync.webhook' => ['onWebhook']];
  }

  /**
   * Queues each gift coupon on a newly created subscription.
   */
  public function onWebhook(Event $event): void {
    if (($event->eventType ?? '') !== 'subscription_created' || !method_exists($event, 'subscription')) {
      return;
    }
    $subscription = $event->subscription();
    foreach ($subscription['coupons'] ?? [] as $coupon) {
      $coupon_id = (string) ($coupon['coupon_id'] ?? '');
      if (GiftCardMath::parseCouponId($coupon_id) === NULL) {
        continue;
      }
      $this->queueFactory->get('makerspace_gift_card_checkout')->createItem([
        'coupon_id' => $coupon_id,
        'subscription_id' => (string) ($subscription['id'] ?? ''),
        'customer_id' => (string) ($subscription['customer_id'] ?? $event->customerId ?? ''),
        'invoice' => $event->payload['content']['invoice'] ?? NULL,
        'queued' => time(),
      ]);
    }
  }

}
