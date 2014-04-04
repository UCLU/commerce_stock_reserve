<?php
/**
 * @file
 * Default Rules for the Commerce Stock Reserve module.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_stock_reserve_default_rules_configuration() {

  $configs = array();

  // Reserve stock when the user has added or updated a product in their cart.
  $rule = rules_reaction_rule();
  $rule->label = 'Reserve stock for cart';
  $rule->active = TRUE;
  $rule->event('commerce_cart_product_add')
    ->event('commerce_line_item_update');
  $rule->action('commerce_stock_reserve_reserve', array(
    'commerce_line_item:select' => 'commerce-line-item',
  ));
  $configs['commerce_stock_reserve_for_cart'] = $rule;

  // Release stock when the user has removed a product from their cart.
  $rule = rules_reaction_rule();
  $rule->label = 'Release reserved stock for cart';
  $rule->active = TRUE;
  $rule->event('commerce_cart_product_remove');
  $rule->action('commerce_stock_reserve_release', array(
    'commerce_line_item:select' => 'commerce-line-item',
  ));
  $configs['commerce_stock_reserve_release_for_cart'] = $rule;

  // Release stock when the user completes checkout for an order.
  $rule = rules_reaction_rule();
  $rule->label = 'Release reserved stock when completing checkout';
  $rule->active = TRUE;
  $rule->event('commerce_checkout_complete');
  $rule->action(
    rules_loop(array(
      'list:select' => 'commerce-order:commerce-line-items',
      'item:var' => 'line_item',
      'item:label' => t('Current line item'),
      'item:type' => 'commerce_line_item',
    ))
    ->action('commerce_stock_reserve_release', array(
      'commerce_line_item:select' => 'line-item',
    ))
  );
  $configs['commerce_stock_reserve_release_checkout'] = $rule;

  return $configs;
}
