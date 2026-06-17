<?php
/**
 * Plugin Name: WooCommerce Discount Rules
 * Description: Apply and remove WooCommerce sale prices from JSON-backed discount rules.
 * Version: 1.0.0
 * Author: Henry Kozhya
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/functions.php';

add_action('admin_menu', 'wcdp_register_admin_page');
add_action('admin_enqueue_scripts', 'wcdp_admin_assets');

function wcdp_register_admin_page(): void
{
    add_submenu_page(
        'woocommerce',
        'Discount Rules',
        'Discount Rules',
        'manage_woocommerce',
        'wcdp-discount-rules',
        'wcdp_render_admin_page'
    );
}

function wcdp_admin_assets(string $hook): void
{
    if ($hook !== 'woocommerce_page_wcdp-discount-rules') {
        return;
    }

    wp_enqueue_style(
        'wcdp-admin',
        plugins_url('assets/admin.css', __FILE__),
        [],
        '1.0.0'
    );
}

function wcdp_render_admin_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to manage discount rules.', 'woocommerce-discount'));
    }

    $active_tab = isset($_GET['tab']) && $_GET['tab'] === 'remove' ? 'remove' : 'add';
    $message = '';
    $output = '';
    $csv_url = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('wcdp_save_rules');

        $action = isset($_POST['discount_action']) && $_POST['discount_action'] === 'remove' ? 'remove' : 'add';
        $active_tab = $action;
        $rules = wcdp_rules_from_post($action);
        $saved = wcdp_write_rules($action, $rules);

        if ($saved) {
            $message = 'Rules saved.';

            if (isset($_POST['run_rules'])) {
                if (!class_exists('WooCommerce')) {
                    $output = "WooCommerce is not active.\n";
                } else {
                    ob_start();

                    if ($action === 'add') {
                        apply_sale_price_discount($rules);
                    } else {
                        remove_product_discount($rules);
                    }

                    $output = (string) ob_get_clean();
                    $csv_url = wcdp_save_run_output_csv($output, $action);
                }
            }
        } else {
            $message = 'Could not save the JSON file. Check file permissions.';
        }
    }

    $rules = wcdp_read_rules($active_tab);
    $attributes = wcdp_get_product_attributes();
    ?>
    <div class="wrap wcdp-wrap">
        <h1>WooCommerce Discount Rules</h1>

        <?php if ($message !== '') : ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper">
            <a class="nav-tab <?php echo $active_tab === 'add' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wcdp-discount-rules&tab=add')); ?>">Add Discount</a>
            <a class="nav-tab <?php echo $active_tab === 'remove' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wcdp-discount-rules&tab=remove')); ?>">Remove Discount</a>
        </nav>

        <form method="post" class="wcdp-form">
            <?php wp_nonce_field('wcdp_save_rules'); ?>
            <input type="hidden" name="discount_action" value="<?php echo esc_attr($active_tab); ?>">

            <section class="wcdp-panel">
                <h2>Run Settings</h2>
                <label class="wcdp-check">
                    <input type="checkbox" name="dry_run" value="1" <?php checked(!empty($rules['dry_run'])); ?>>
                    Dry run
                </label>
                <label class="wcdp-check">
                    <input type="checkbox" name="apply_to_all" value="1" <?php checked(!empty($rules['apply_to_all'])); ?>>
                    Start from all products
                </label>

                <?php if ($active_tab === 'add') : ?>
                    <label class="wcdp-field">
                        <span>Discount type</span>
                        <select name="discount_type">
                            <option value="percentage" <?php selected($rules['discount_type'] ?? '', 'percentage'); ?>>Percentage</option>
                            <option value="fixed" <?php selected($rules['discount_type'] ?? '', 'fixed'); ?>>Fixed amount</option>
                        </select>
                    </label>
                    <label class="wcdp-field">
                        <span>Discount value</span>
                        <input type="number" name="discount_value" min="0" step="0.01" value="<?php echo esc_attr((string) ($rules['discount_value'] ?? 0)); ?>">
                    </label>
                    <label class="wcdp-check">
                        <input type="checkbox" name="exclude_on_sale" value="1" <?php checked(!empty($rules['exclude_on_sale'])); ?>>
                        Exclude products already on sale
                    </label>
                    <label class="wcdp-field">
                        <span>Add product tag after discount</span>
                        <input type="text" name="add_tag" value="<?php echo esc_attr((string) ($rules['add_tag'] ?? '')); ?>" placeholder="discounted">
                    </label>
                <?php endif; ?>
            </section>

            <?php wcdp_render_rule_group('include', $rules['include'] ?? [], $attributes); ?>
            <?php wcdp_render_rule_group('exclude', $rules['exclude'] ?? [], $attributes); ?>

            <p class="submit">
                <button type="submit" name="save_rules" class="button button-secondary">Save Rules</button>
                <button type="submit" name="run_rules" class="button button-primary">Save and Run</button>
            </p>
        </form>

        <?php if ($output !== '') : ?>
            <section class="wcdp-panel">
                <div class="wcdp-output-header">
                    <h2>Run Output</h2>
                    <?php if ($csv_url !== '') : ?>
                        <a class="button button-secondary" href="<?php echo esc_url($csv_url); ?>" download>Download CSV</a>
                    <?php endif; ?>
                </div>
                <pre class="wcdp-output"><?php echo esc_html($output); ?></pre>
            </section>
        <?php endif; ?>
    </div>
    <?php
}

function wcdp_render_rule_group(string $group, array $rules, array $attributes): void
{
    $label = ucfirst($group);
    ?>
    <section class="wcdp-panel">
        <h2><?php echo esc_html($label); ?> Rules</h2>
        <div class="wcdp-grid">
            <?php wcdp_textarea_field($group, 'product_ids', 'Product IDs', $rules['product_ids'] ?? []); ?>
            <?php wcdp_textarea_field($group, 'categories', 'Category slugs', $rules['categories'] ?? []); ?>
            <?php wcdp_textarea_field($group, 'tags', 'Tag slugs', $rules['tags'] ?? []); ?>
            <?php wcdp_textarea_field($group, 'brands', 'Brand slugs', $rules['brands'] ?? []); ?>
        </div>

        <h3>Attributes</h3>
        <div class="wcdp-attributes">
            <?php if (empty($attributes)) : ?>
                <p>No global WooCommerce attributes were found.</p>
            <?php endif; ?>

            <?php foreach ($attributes as $attribute) : ?>
                <?php
                $taxonomy = $attribute['taxonomy'];
                $value = $rules['attributes'][$taxonomy] ?? [];
                ?>
                <label class="wcdp-field">
                    <span><?php echo esc_html($attribute['label']); ?> terms</span>
                    <textarea name="<?php echo esc_attr($group); ?>_attributes[<?php echo esc_attr($taxonomy); ?>]" rows="2" placeholder="term-slug-1, term-slug-2"><?php echo esc_textarea(implode(', ', $value)); ?></textarea>
                </label>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function wcdp_textarea_field(string $group, string $name, string $label, array $value): void
{
    ?>
    <label class="wcdp-field">
        <span><?php echo esc_html($label); ?></span>
        <textarea name="<?php echo esc_attr($group . '_' . $name); ?>" rows="3" placeholder="comma-separated"><?php echo esc_textarea(implode(', ', $value)); ?></textarea>
    </label>
    <?php
}

function wcdp_rules_from_post(string $action): array
{
    $rules = [
        'dry_run' => !empty($_POST['dry_run']),
        'apply_to_all' => !empty($_POST['apply_to_all']),
        'exclude' => wcdp_rule_group_from_post('exclude'),
        'include' => wcdp_rule_group_from_post('include'),
    ];

    if ($action === 'add') {
        $rules['discount_type'] = isset($_POST['discount_type']) && $_POST['discount_type'] === 'fixed' ? 'fixed' : 'percentage';
        $rules['discount_value'] = isset($_POST['discount_value']) ? (float) sanitize_text_field(wp_unslash($_POST['discount_value'])) : 0;
        $rules['exclude_on_sale'] = !empty($_POST['exclude_on_sale']);
        $rules['add_tag'] = isset($_POST['add_tag']) ? sanitize_title(wp_unslash($_POST['add_tag'])) : '';
    }

    return $rules;
}

function wcdp_rule_group_from_post(string $group): array
{
    return [
        'product_ids' => wcdp_parse_ids($_POST[$group . '_product_ids'] ?? ''),
        'categories' => wcdp_parse_slug_list($_POST[$group . '_categories'] ?? ''),
        'tags' => wcdp_parse_slug_list($_POST[$group . '_tags'] ?? ''),
        'brands' => wcdp_parse_slug_list($_POST[$group . '_brands'] ?? ''),
        'attributes' => wcdp_parse_attributes($_POST[$group . '_attributes'] ?? []),
    ];
}

function wcdp_parse_ids($value): array
{
    return array_values(array_filter(array_map('absint', wcdp_parse_list($value))));
}

function wcdp_parse_slug_list($value): array
{
    return array_values(array_filter(array_map('sanitize_title', wcdp_parse_list($value))));
}

function wcdp_parse_list($value): array
{
    $value = is_string($value) ? wp_unslash($value) : '';
    $parts = preg_split('/[\s,]+/', $value);

    return is_array($parts) ? $parts : [];
}

function wcdp_parse_attributes($attributes): array
{
    if (!is_array($attributes)) {
        return [];
    }

    $parsed = [];

    foreach ($attributes as $attribute => $terms) {
        $taxonomy = normalize_discount_attribute_taxonomy((string) $attribute);
        $terms = wcdp_parse_slug_list($terms);

        if ($taxonomy !== '' && !empty($terms)) {
            $parsed[$taxonomy] = $terms;
        }
    }

    return $parsed;
}

function wcdp_read_rules(string $action): array
{
    $file = wcdp_rules_file($action);

    if (!file_exists($file)) {
        return wcdp_default_rules($action);
    }

    $json = file_get_contents($file);
    $rules = json_decode((string) $json, true);

    if (!is_array($rules)) {
        return wcdp_default_rules($action);
    }

    return array_replace_recursive(wcdp_default_rules($action), $rules);
}

function wcdp_write_rules(string $action, array $rules): bool
{
    $json = wp_json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return false;
    }

    return file_put_contents(wcdp_rules_file($action), $json . "\n") !== false;
}

function wcdp_save_run_output_csv(string $output, string $action): string
{
    $csv = wcdp_extract_csv_from_run_output($output);

    if ($csv === '') {
        return '';
    }

    $uploads = wp_upload_dir();

    if (!empty($uploads['error'])) {
        return '';
    }

    $dir = trailingslashit($uploads['basedir']) . 'woocommerce-discount';

    if (!wp_mkdir_p($dir)) {
        return '';
    }

    $filename = sprintf(
        'discount-%s-%s.csv',
        sanitize_key($action),
        gmdate('Ymd-His')
    );

    $path = trailingslashit($dir) . $filename;

    if (file_put_contents($path, $csv) === false) {
        return '';
    }

    return trailingslashit($uploads['baseurl']) . 'woocommerce-discount/' . $filename;
}

function wcdp_extract_csv_from_run_output(string $output): string
{
    $lines = preg_split('/\r\n|\r|\n/', $output);

    if (!is_array($lines)) {
        return '';
    }

    $csv_lines = [];
    $capture = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (strpos($line, 'ID,') === 0) {
            $capture = true;
            $csv_lines[] = $line;
            continue;
        }

        if (!$capture) {
            continue;
        }

        if (preg_match('/^\d+,/', $line)) {
            $csv_lines[] = $line;
        }
    }

    return empty($csv_lines) ? '' : implode("\n", $csv_lines) . "\n";
}

function wcdp_rules_file(string $action): string
{
    return __DIR__ . '/' . ($action === 'remove' ? 'remove' : 'add') . '-discount.json';
}

function wcdp_default_rules(string $action): array
{
    $rules = [
        'dry_run' => true,
        'apply_to_all' => false,
        'exclude' => wcdp_empty_rule_group(),
        'include' => wcdp_empty_rule_group(),
    ];

    if ($action === 'add') {
        $rules = array_merge(
            [
                'discount_type' => 'percentage',
                'discount_value' => 0,
                'exclude_on_sale' => true,
            ],
            $rules,
            [
                'add_tag' => '',
            ]
        );
    }

    return $rules;
}

function wcdp_empty_rule_group(): array
{
    return [
        'product_ids' => [],
        'categories' => [],
        'tags' => [],
        'brands' => [],
        'attributes' => [],
    ];
}

function wcdp_get_product_attributes(): array
{
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return [];
    }

    $attributes = [];

    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);

        if (!taxonomy_exists($taxonomy)) {
            continue;
        }

        $attributes[] = [
            'taxonomy' => $taxonomy,
            'label' => $attribute->attribute_label ?: $attribute->attribute_name,
        ];
    }

    return $attributes;
}
