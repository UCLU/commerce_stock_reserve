<?php
/**
 * Rules integration for the Commerce Stock Reserve module.
 */

/**
 * Implements hook_rules_action_info().
 */
function commerce_stock_reserve_rules_action_info() {
  $actions = array();

  $actions['commerce_stock_reserve'] = array(
    'label' => t('Reserve stock for a line item'),
    'group' => t('Commerce Stock Reserve'),
    'parameter' => array(
      'commerce_line_item' => array(
        'type' => 'commerce_line_item',
        'label' => t('Line item'),
      ),
    ),
  );

  $actions['commerce_stock_reserve_release'] = array(
    'label' => t('Release stock for a line item'),
    'group' => t('Commerce Stock Reserve'),
    'parameter' => array(
      'commerce_line_item' => array(
        'type' => 'commerce_line_item',
        'label' => t('Line item'),
      ),
    ),
  );

  return $actions;
}

/**
 * Rules action helper to release stock for a line item.
 *
 * @param object $line_item
 *   The Commerce line item object.
 */
function commerce_stock_reserve_release($line_item) {
  commerce_stock_reserve($line_item, TRUE);
}
