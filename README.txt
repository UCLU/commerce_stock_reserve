Commerce Stock Reserve
======================
This is a small module that extends Commerce Stock
(https://drupal.org/project/commerce_stock) to allow "reserving" a product's
stock when a customer adds it to their shopping cart.

This removes the risk of overselling when multiple customers are attempting to
buy the same product at the same time. The trade-off is that it introduces a
risk of underselling, if stock remains "reserved" in customers' carts when sales
are closed. So this module is probably best used in conjunction with Commerce
Cart Expiration (https://drupal.org/project/commerce_cart_expiration), which
deletes "abandoned" shopping carts.
