# WooCommerce Integration

The WooCommerce integration rewards purchasing behavior and product engagement. The manifest loads automatically when WooCommerce is active.

## Actions

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `wc_order_completed` | Complete a purchase | 25 | Yes |
| `wc_first_purchase` | Complete first purchase ever | 50 | No (once only) |
| `wc_product_reviewed` | Leave a product review | 15 | Yes (1/product/day) |
| `wc_wishlist_add` | Add a product to wishlist | 5 | Yes (5min cooldown) |

### Notes

- `wc_first_purchase` fires on the same hook as `wc_order_completed` but checks whether this is the customer's first completed order. It only awards points when exactly one completed order exists for that customer.
- `wc_product_reviewed` fires on `comment_post` with a guard that checks the post type is `product` and the comment is approved. This prevents double-awarding if you also have the WordPress Core comment action active.
- `wc_wishlist_add` requires the **YITH WooCommerce Wishlist** plugin. It fires on `yith_wcwl_added_to_wishlist`.

## Preventing Double Points on Reviews

The WooCommerce manifest's review action explicitly checks `post_type === 'product'`. The WordPress Core manifest's comment action targets non-product post types. The two actions do not overlap.

## Requirements

- WooCommerce active
