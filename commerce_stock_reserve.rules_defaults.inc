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


/**
 * Implements hook_default_rules_configuration().
 */
function commerce_stock_reserve_default_rules_configuration_alter(&$configs) {
  // The commerce_ssr (Commerce Simple Stock Rules) module defines default Rules
  // which validate stock when the user is adding a product to the cart or
  // beginning checkout. These need to be modified account for users who have
  // already reserved some stock for that product. So here we add the condition
  // 'NOT Enough product stock is available for the user' to both of those
  // default Rules.
  $rules_to_alter = array(
    'rules_stock_validate_add_to_cart',
    'rules_stock_validate_checkout',
  );
  foreach ($rules_to_alter as $rule_name) {
    if (!isset($configs[$rule_name])) {
      continue;
    }
    $condition = rules_condition('commerce_stock_reserve_is_enough_available', array(
      'commerce_product:select' => 'commerce-product',
      'stock_already_ordered:select' => 'stock-already-ordered',
    ))->negate();
    $configs[$rule_name]->condition($condition);
  }
}
