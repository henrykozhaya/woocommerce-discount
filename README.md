# WooCommerce Discount Scipt

## Add Discount

## Remove Discount
# WooCommerce Discount Script (woocommerce-discount)

This repository contains **two CLI scripts** that you can run from the server terminal (SSH) to:

- Apply a discount to WooCommerce products (in safe batches)
- Remove discounts (restore prices)

It is designed for stores with **thousands of products**, and avoids memory exhaustion by:

- Querying products in **batches** (default: 200)
- Querying **IDs only** (not full product objects)
- Freeing memory aggressively between loops

---

## 1) Repository Structure

```
woocommerce-discount/
├─ add-discount.php
├─ remove-discount.php
├─ functions.php
└─ README.md
```

---

## 2) Where to Upload This Repository

Upload the whole folder **inside your WordPress installation**, for example:

```
/var/www/html/wordpress-root/woocommerce-discount/
```

Your folder must be located in a place where this path exists:

```
.../wp-load.php
```

Because the scripts bootstrap WordPress using:

```php
$wp_load = dirname(__FILE__, 2) . "/wp-load.php";
```

Meaning:

- `woocommerce-discount` must be inside a directory that is **2 levels below** the WordPress root.

Example that works:

```
wordpress/
├─ wp-load.php
└─ woocommerce-discount/
    ├─ add-discount.php
    ├─ remove-discount.php
    └─ functions.php
```

---

## 3) How to Run (SSH / Terminal)

### Requirements

- PHP CLI enabled on the server
- WordPress is installed
- WooCommerce plugin is active

### Run: Apply Discount

From your WordPress root:

```bash
cd /var/www/html/wordpress-root/woocommerce-discount
php add-discount.php
```

### Run: Remove Discount

```bash
cd /var/www/html/wordpress-root/woocommerce-discount
php remove-discount.php
```

---

## 4) What the Scripts Do (Step-by-Step)

### 4.1 Bootstrap WordPress

Both scripts:

1. Locate `wp-load.php`
2. Load WordPress
3. Load WooCommerce
4. Load `functions.php`

If anything is missing, the script stops immediately.

---

### 4.2 Build a WooCommerce Query (Memory Safe)

The scripts use:

```php
wc_get_products($args)
```

But the query is optimized to avoid memory issues:

- `return => 'ids'` (**important**)
- `limit => 200`
- `page => 1,2,3...`

So each batch loads only product IDs, and each product is processed individually.

---

### 4.3 Apply or Remove the Discount

- For **simple products**: the script updates the product itself.
- For **variable products**: the script updates **each variation**.

After each update, the script clears WooCommerce transients:

```php
wc_delete_product_transients($product_id);
```

And triggers garbage collection between batches:

```php
gc_collect_cycles();
```

---

## 5) Add Discount Script (`add-discount.php`)

### Purpose

Applies a discount to products by setting:

- `sale_price`
- `price`

And optionally adds a tag to the product.

---

### Discount Rules (`$discount_rules`)

This is the configuration used by the add script:

```php
$discount_rules = [
    "discount_type"   => "percentage",
    "discount_value"  => 10,
    "apply_to_all"    => true,
    "exclude_on_sale" => false,
    "exclude"         => [
        "product_ids" => [],
        "categories"  => [],
        "tags"        => [],
        "brands"      => [],
    ],
    "include"        => [
        "product_ids" => [],
        "categories"  => ['watches'],
        "tags"        => ['CoupleWatches'],
        "brands"      => ['casio'],
    ],
    "add_tag"         => "valentines-day"
];
```

---

### Configuration Reference (Detailed)

#### `discount_type`
- Type: `string`
- Allowed values: `percentage` or `fixed`

Controls how the sale price is calculated.

---

#### `discount_value`
- Type: `int|float`
- Must be `> 0`

- If `percentage`: `10` means **10% off**.
- If `fixed`: `10` means **10 currency units off**.

---

#### `apply_to_all`
- Type: `bool`

If `true`:

- The script targets **all products**
- Then applies the `exclude` rules

If `false`:

- The script targets only products defined in `include`

---

#### `exclude_on_sale`
- Type: `bool`

If `true`, the script will exclude products that are already on sale.

> Note: This is only applied when `apply_to_all = false`, or when `apply_to_all = true` with `action = add`.

---

#### `exclude`
- Type: `array`

This defines what should be excluded.

- `product_ids`: array of WooCommerce product IDs
- `categories`: array of category slugs
- `tags`: array of tag slugs
- `brands`: array of brand slugs (**taxonomy: `product_brand`**)

Example:

```php
"exclude" => [
  "product_ids" => [12, 55],
  "categories"  => ["sale"],
  "tags"        => ["black-friday"],
  "brands"      => ["casio"],
]
```

---

#### `include`
- Type: `array`

This defines what should be included when `apply_to_all = false`.

- `product_ids`: array of WooCommerce product IDs
- `categories`: array of category slugs
- `tags`: array of tag slugs
- `brands`: array of brand slugs

Example:

```php
"include" => [
  "product_ids" => [99, 100],
  "categories"  => ["watches"],
  "tags"        => ["couplewatches"],
  "brands"      => ["casio"],
]
```

---

#### `add_tag`
- Type: `string`

If set, the script will add this product tag to discounted products.

**Important behavior:**

- If the product is a variation, the tag is added to the **parent variable product**.

---

### Example: Apply 15% to Everything Except Some Categories

```php
$discount_rules = [
  "discount_type"   => "percentage",
  "discount_value"  => 15,
  "apply_to_all"    => true,
  "exclude_on_sale" => true,
  "exclude"         => [
    "product_ids" => [],
    "categories"  => ["clearance"],
    "tags"        => [],
    "brands"      => [],
  ],
  "include"         => [
    "product_ids" => [],
    "categories"  => [],
    "tags"        => [],
    "brands"      => [],
  ],
  "add_tag"         => "discounted"
];
```

---

## 6) Remove Discount Script (`remove-discount.php`)

### Purpose

This script removes the discount by:

- Clearing `sale_price`
- Restoring `price` back to `regular_price`

---

### Rules (`$rules`)

```php
$rules = [
    "apply_to_all"    => true,
    "exclude"         => [
        "product_ids" => [],
        "categories"  => [],
        "tags"        => [],
        "brands"      => [],
    ],
    "include"        => [
        "product_ids" => [],
        "categories"  => [],
        "tags"        => [],
        "brands"      => [],
    ],
];
```

---

### How Removal Works

There are two modes:

#### Mode A: `apply_to_all = true`

The script will:

1. Load **all product IDs currently on sale** using:

```php
wc_get_product_ids_on_sale();
```

2. Remove discounts from them.

You can still exclude some products using:

- `exclude.product_ids`
- `exclude.categories`
- `exclude.tags`
- `exclude.brands`

---

#### Mode B: `apply_to_all = false`

The script will remove discounts only from products matched by the `include` rules.

---

## 7) Batch Size & Performance

By default, the scripts run in batches of:

- `200` products per query

This value is set inside:

```php
build_wc_product_query_args($rules, 200, 1, $action)
```

You can safely change `200` to:

- `100` if the server is small
- `300` or `500` if the server is strong

---

## 8) Important Notes & Limitations

### 8.1 This Script Modifies Real Prices

This is not a dynamic discount rule.

It directly updates WooCommerce product prices by saving:

- `sale_price`
- `price`

So it is a **permanent change** until removed.

---

### 8.2 Variable Products

- Variable products themselves do not get a sale price.
- The script updates each variation.

---

### 8.3 Brand Taxonomy

The script assumes brands are stored in:

- Taxonomy: `product_brand`

If your site uses another taxonomy (example: `pa_brand`), update it inside:

```php
'taxonomy' => 'product_brand'
```

---

### 8.4 Cache

The script clears transients per product.

If you use a persistent object cache (Redis/Memcached), you may still want to clear cache after the run.

---

## 9) Safety Recommendations (Strongly Suggested)

Before running on production:

1. Take a database backup
2. Test on staging first
3. Start with a small include rule (few products)

---

## 10) Troubleshooting

### WooCommerce not active

If you see:

```
❌ WooCommerce is not active.
```

Make sure:

- WooCommerce plugin is enabled
- You are running the script inside the correct WordPress installation

---

### wp-load.php not found

If you see:

```
❌ wp-load.php not found.
```

Then the folder is not located correctly.

The scripts expect this structure:

```
wordpress/
├─ wp-load.php
└─ woocommerce-discount/
```

---

## 11) Output Example

The script prints progress for each batch and product:

```
📦 Batch page 1 - loaded 200 product IDs
✔ Discount applied to 123   100   90   Product Name
✔ Discount applied to 124   200   180  Another Product
...
```

---

## 12) Quick Start

1. Upload the folder inside WordPress:

```
woocommerce-discount/
```

2. Edit the rules in:

- `add-discount.php` (`$discount_rules`)
- `remove-discount.php` (`$rules`)

3. Run:

```bash
php add-discount.php
```

Or remove:

```bash
php remove-discount.php
```