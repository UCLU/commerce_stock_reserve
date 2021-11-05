<?php

namespace Drupal\commerce_stock_reserve\EventSubscriber;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderItemEvent;
use Drupal\commerce_stock\EventSubscriber\OrderEventSubscriber as StockOrderEventSubscriber;
use Drupal\commerce_stock\Plugin\Commerce\StockEventType\StockEventTypeInterface;
use Drupal\commerce_stock\Plugin\StockEvents\CoreStockEvents;
use Drupal\commerce_stock\StockLocationInterface;
use Drupal\commerce_stock\StockTransactionsInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Performs stock transactions on order and order item events.
 *
 * This is basically the inverse of the parent OrderEventSubscriber,
 * with the addition of taking stock out at cart stage
 * then returning it just before the actual stock sale transaction happens
 */
class OrderEventSubscriber extends StockOrderEventSubscriber {

  /**
   * Creates a stock transaction when an order is placed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The order workflow event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_place');
    $order = $event->getEntity();
    foreach ($order->getItems() as $item) {
      $entity = $item->getPurchasedEntity();
      if (!$entity) {
        continue;
      }

      if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
        continue;
      }

      // ON PLACE, ADD BACK TO STOCK WHAT WAS RESERVED
      // The onOrderPlace will take it out again as a sale.
      $quantity = 1 * $item->getQuantity();
      $context = self::createContextFromOrder($order);
      $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $quantity);
      $transaction_type = StockTransactionsInterface::STOCK_IN;

      $this->runTransactionEvent(
        $eventType,
        $context,
        $entity,
        $quantity,
        $location,
        $transaction_type,
        $order
      );
    }
  }

  /**
   * Acts on the order update event to create transactions for new items.
   *
   * The reason this isn't handled by OrderEvents::ORDER_ITEM_INSERT is because
   * that event never has the correct values.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderUpdate(OrderEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_update');
    $order = $event->getOrder();
    if ($order->getState()->getWorkflow()->getGroup() !== 'commerce_order') {
      return;
    }
    $original_order = $this->getOriginalEntity($order);

    $showMessage = FALSE;

    foreach ($order->getItems() as $item) {
      if (!$original_order->hasItem($item)) {
        if ($order && $order->get('cart')->value) {
          $entity = $item->getPurchasedEntity();
          if (!$entity) {
            continue;
          }

          if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
            continue;
          }

          $context = self::createContextFromOrder($order);
          $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $item->getQuantity());

          // TAKE STOCK OUT FOR NEW ITEM IN CART:
          $transaction_type = StockTransactionsInterface::STOCK_OUT;
          $quantity = -1 * $item->getQuantity();

          $showMessage = TRUE;

          $this->runTransactionEvent(
            $eventType,
            $context,
            $entity,
            $quantity,
            $location,
            $transaction_type,
            $order
          );
        }
      }
    }

    if ($showMessage) {
      if ($message = commerce_stock_reserve_get_message()) {
        \Drupal::messenger()->addMessage($message);
      }
    }
  }

  /**
   * Performs a stock transaction for an order Cancel event.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The order workflow event.
   */
  public function onOrderCancel(WorkflowTransitionEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_cancel');
    $order = $event->getEntity();
    $original_order = $this->getOriginalEntity($order);

    if ($original_order && !$original_order->get('cart')->value) {
      return;
    }
    foreach ($order->getItems() as $item) {
      $entity = $item->getPurchasedEntity();
      if (!$entity) {
        continue;
      }

      if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
        continue;
      }

      // RETURN STOCK ON CANCEL OF DRAFT ORDER
      $quantity = $item->getQuantity();
      $context = self::createContextFromOrder($order);
      $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $quantity);
      $transaction_type = StockTransactionsInterface::STOCK_IN;

      $this->runTransactionEvent(
        $eventType,
        $context,
        $entity,
        $quantity,
        $location,
        $transaction_type,
        $order
      );
    }
  }

  /**
   * Performs a stock transaction on an order delete event.
   *
   * This happens on PREDELETE since the items are not available after DELETE.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onOrderDelete(OrderEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_delete');
    $order = $event->getOrder();
    if ($order->getState()->getWorkflow()->getGroup() !== 'commerce_order') {
      return;
    }
    if (!$order->get('cart')->value) {
      return;
    }
    $items = $order->getItems();
    foreach ($items as $item) {
      $entity = $item->getPurchasedEntity();
      if (!$entity) {
        continue;
      }

      if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
        continue;
      }

      // RETURN STOCK ON ORDER DELETE
      $quantity = $item->getQuantity();
      $context = self::createContextFromOrder($order);
      $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $quantity);
      $transaction_type = StockTransactionsInterface::STOCK_IN;

      $this->runTransactionEvent(
        $eventType,
        $context,
        $entity,
        $quantity,
        $location,
        $transaction_type,
        $order
      );
    }
  }

  /**
   * Performs a stock transaction on an order item update.
   *
   * @param \Drupal\commerce_order\Event\OrderItemEvent $event
   *   The order item event.
   */
  public function onOrderItemUpdate(OrderItemEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_item_update');
    $item = $event->getOrderItem();
    $order = $item->getOrder();

    if ($order && $order->get('cart')->value) {
      if ($order->getState()->getWorkflow()->getGroup() !== 'commerce_order') {
        return;
      }
      $original = $this->getOriginalEntity($item);
      $diff = $original->getQuantity() - $item->getQuantity();
      if ($diff) {
        $entity = $item->getPurchasedEntity();
        if (!$entity) {
          return;
        }

        if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
          return;
        }

        // ON QUANTITY CHANGE
        // If we are removing quantity, return it as IN
        // If we are adding quantity, reserve it as OUT
        $transaction_type = ($diff < 0) ? StockTransactionsInterface::STOCK_IN : StockTransactionsInterface::STOCK_OUT;
        $context = self::createContextFromOrder($order);
        $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $diff);

        $this->runTransactionEvent(
          $eventType,
          $context,
          $entity,
          $diff,
          $location,
          $transaction_type,
          $order
        );
      }
    }
  }

  /**
   * Performs a stock transaction when an order item is deleted.
   *
   * @param \Drupal\commerce_order\Event\OrderItemEvent $event
   *   The order item event.
   */
  public function onOrderItemDelete(OrderItemEvent $event) {
    $eventType = $this->getEventType('commerce_stock_order_item_delete');
    $item = $event->getOrderItem();
    $order = $item->getOrder();
    if ($order && $order->get('cart')->value) {
      if ($order->getState()->getWorkflow()->getGroup() !== 'commerce_order') {
        return;
      }

      $entity = $item->getPurchasedEntity();
      if (!$entity) {
        return;
      }

      if (!commerce_stock_reserve_check_if_stock_controlled($entity)) {
        return;
      }

      $context = self::createContextFromOrder($order);
      $location = $this->stockServiceManager->getTransactionLocation($context, $entity, $item->getQuantity());
      $transaction_type = StockTransactionsInterface::STOCK_IN;
      $quantity = $item->getQuantity();

      $this->runTransactionEvent(
        $eventType,
        $context,
        $entity,
        $quantity,
        $location,
        $transaction_type,
        $order
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = parent::getSubscribedEvents();
    return $events;
  }
  /**
   * Run the transaction event.
   *
   * @param \Drupal\commerce_stock\Plugin\Commerce\StockEventType\StockEventTypeInterface $event_type
   *   The stock event type.
   * @param \Drupal\commerce\Context $context
   *   The context containing the customer & store.
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   The purchasable entity.
   * @param int $quantity
   *   The quantity.
   * @param \Drupal\commerce_stock\StockLocationInterface $location
   *   The stock location.
   * @param int $transaction_type_id
   *   The transaction type ID.
   * @param \Drupal\commerce_order\Entity\Order $order
   *   The original order the transaction belongs to.
   *
   * @return int
   *   Return the ID of the transaction or FALSE if no transaction created.
   */
  private function runTransactionEvent(
    StockEventTypeInterface $event_type,
    Context $context,
    PurchasableEntityInterface $entity,
    $quantity,
    StockLocationInterface $location,
    $transaction_type_id,
    Order $order
  ) {

    $data['message'] = $event_type->getDefaultMessage();
    $metadata = [
      'related_oid' => $order->id(),
      'related_uid' => $order->getCustomerId(),
      'data' => $data,
    ];

    $event_type_id = CoreStockEvents::mapStockEventIds($event_type->getPluginId());

    return $this->eventsManager->createInstance('core_stock_events')
      ->stockEvent(
        $context,
        $entity,
        $event_type_id,
        $quantity,
        $location,
        $transaction_type_id,
        $metadata
      );
  }

  /**
   * Creates a stock event type object.
   *
   * @param string $plugin_id
   *   The id of the stock event type.
   *
   * @return \Drupal\commerce_stock\Plugin\Commerce\StockEventType\StockEventTypeInterface
   *   The stock event type.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  private function getEventType($plugin_id) {
    return $this->eventTypeManager->createInstance($plugin_id);
  }

  /**
   * Returns the entity from an updated entity object. In certain
   * cases the $entity->original property is empty for updated entities. In such
   * a situation we try to load the unchanged entity from storage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The changed/updated entity object.
   *
   * @return null|\Drupal\Core\Entity\EntityInterface
   *   The original unchanged entity object or NULL.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getOriginalEntity(EntityInterface $entity) {
    // $entity->original only exists during save. See
    // \Drupal\Core\Entity\EntityStorageBase::save().
    // If we don't have $entity->original we try to load it.
    $original_entity = NULL;
    $original_entity = $entity->original;

    // @ToDo Consider how this may change due to: ToDo https://www.drupal.org/project/drupal/issues/2839195
    if (!$original_entity) {
      $id = $entity->getOriginalId() !== NULL ? $entity->getOriginalId() : $entity->id();
      $original_entity = $this->entityTypeManager
        ->getStorage($entity->getEntityTypeId())
        ->loadUnchanged($id);
    }
    return $original_entity;
  }
}
