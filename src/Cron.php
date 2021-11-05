<?php

namespace Drupal\commerce_stock_reserve;

use Drupal\commerce\Interval;
use Drupal\commerce_cart\CronInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_stock_local\Entity\StockLocation;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Default cron implementation.
 *
 * Queues abandoned carts for expiration (deletion).
 *
 * @see \Drupal\commerce_stock_reserve\Plugin\QueueWorker\CartExpiration
 */
class Cron implements CronInterface {

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderStorage;

  /**
   * The order type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderTypeStorage;

  /**
   * The commerce_stock_reserve_cart_expiration queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * Constructs a new Cron object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, QueueFactory $queue_factory) {
    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');
    $this->orderTypeStorage = $entity_type_manager->getStorage('commerce_order_type');
    $this->queue = $queue_factory->get('commerce_stock_reserve_cart_expiration');
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    /** @var \Drupal\commerce_stock\StockServiceManagerInterface $stockManager */
    $stockServiceManager = \Drupal::service('commerce_stock.service_manager');

    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface[] $order_types */
    $order_types = $this->orderTypeStorage->loadMultiple();
    foreach ($order_types as $order_type) {
      $interval = new Interval(2, 'hour');
      $all_order_ids = $this->getOrderIds($order_type->id(), $interval);
      foreach (array_chunk($all_order_ids, 100) as $order_ids) {
        $order_item_ids = [];
        foreach ($order_ids as $id) {
          // Only delete carts without payments:
          $payments = \Drupal::entityQuery('commerce_payment')->condition('order_id', $id)->count()->execute();
          $noPayments = !$payments || $payments == 0;
          if ($noPayments) {
            $order = Order::load($id);
            foreach ($order->getItems() as $item) {
              $variation = $item->getPurchasedEntity();

              // Only delete order items with items that are stock controlled.
              $checker = $stockServiceManager->getService($variation)->getStockChecker();
              if ($checker->getIsAlwaysInStock($variation)) {
                continue;
              }

              // Only delete order items when the item is out of stock.
              // This avoids deleting order items unecessarily,
              // which can impact user experience and prevent conversion of abandoned carts.
              if ($checker->getIsInStock($variation, StockLocation::loadMultiple())) {
                continue;
              }

              \Drupal::logger('commerce_stock_reserve')->debug(t('Adding to queue the removal of stocked item @orderItemId (@orderItemLabel) from cart for order @id', [
                '@orderItemId' => $item->id(),
                '@orderItemLabel' => $variation->label(),
                '@id' => $id,
              ]));

              $order_item_ids[] = $item->id();
            }
          }

          if (count($order_item_ids) > 0) {
            $this->queue->createItem($order_item_ids);
          }
        }
      }
    }
  }

  /**
   * Gets the applicable order IDs.
   *
   * @param string $order_type_id
   *   The order type ID.
   * @param \Drupal\commerce\Interval $interval
   *   The expiration interval.
   *
   * @return array
   *   The order IDs.
   */
  protected function getOrderIds($order_type_id, Interval $interval) {
    $current_date = new DrupalDateTime('now');
    $expiration_date = $interval->subtract($current_date);
    $ids = $this->orderStorage->getQuery()
      ->condition('type', $order_type_id)
      ->condition('changed', $expiration_date->getTimestamp(), '<=')
      ->condition('cart', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    return $ids;
  }
}
