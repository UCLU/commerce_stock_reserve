<?php

namespace Drupal\commerce_stock_reserve\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\commerce\Interval;

/**
 * Deletes expired carts.
 *
 * @QueueWorker(
 *  id = "commerce_stock_reserve_cart_expiration",
 *  title = @Translation("Cart expiration for Commerce Stock Reserve"),
 *  cron = {"time" = 30}
 * )
 */
class CartExpiration extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderItemStorage;

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderStorage;

  /**
   * Constructs a new CartExpiration object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->orderItemStorage = $entity_type_manager->getStorage('commerce_order_item');
    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $interval = commerce_stock_reserve_get_cart_expiry_interval();
    if (!$interval) {
      return;
    }

    foreach ($data as $order_item_id) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order */
      $orderItem = $this->orderItemStorage->loadUnchanged($order_item_id);
      if (!$orderItem) {
        \Drupal::logger('commerce_stock_reserve')->debug('Cannot find order item ID ' . $order_item_id);
        continue;
      }
      $order = $this->orderStorage->loadUnchanged($orderItem->getOrder()->id());

      $current_date = new DrupalDateTime('now');

      $expiration_date = $interval->subtract($current_date);
      $expiration_timestamp = $expiration_date->getTimestamp();

      // Make sure that the cart order still qualifies for expiration.
      if ($order->get('cart')->value && $order->getChangedTime() <= $expiration_timestamp) {
        $order = $orderItem->getOrder();
        $order->removeItem($orderItem);
        $order->save();
        if (count($order->getItems()) == 0) {
          \Drupal::logger('commerce_stock_reserve')->debug('Deleting empty cart order ' . $order->id());
          $order->delete();
        }

        $orderItem->delete();
      }
    }
  }
}
