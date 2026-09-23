<?php
/**
 * Plugin Name: QC Product Labels
 * Description: Print product labels to Brother QL-1100 on DK-22205 continuous labels (62mm x 75mm per label). One label per unit in stock.
 * Version: 1.6
 * Author: Quality Components
 */

if (!defined('ABSPATH')) exit;

// Intercept print requests before WordPress outputs any admin HTML.
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'qc-product-labels') return;
    if (empty($_GET['print'])) return;
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorised');

    $print_ids = array_map('intval', explode(',', sanitize_text_field($_GET['print'])));
    $products  = wc_get_products([
        'limit'   => -1,
        'status'  => 'publish',
        'orderby' => 'name',
        'order'   => 'ASC',
    ]);
    qc_labels_print($products, $print_ids);
});

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Product Labels',
        'Product Labels',
        'manage_woocommerce',
        'qc-product-labels',
        'qc_labels_page'
    );
});

function qc_labels_page() {
    $products = wc_get_products([
        'limit'   => -1,
        'status'  => 'publish',
        'orderby' => 'name',
        'order'   => 'ASC',
    ]);

    ?>
    <div class="wrap">
        <h1>Product Labels</h1>
        <p>Select products and click <strong>Print Labels</strong>. One label is printed per unit in stock.<br>
           <em>Printer: Brother QL-1100 · DK-22205 continuous labels · Use Chrome's print dialog, Paper size <strong>62 &times; 75 mm</strong>, Margins: None, Scale: 100%.</em></p>

        <form method="get" action="">
            <input type="hidden" name="page" value="qc-product-labels">
            <table class="wp-list-table widefat fixed striped" style="max-width:900px">
                <thead>
                    <tr>
                        <td style="width:30px"><input type="checkbox" id="select-all"></td>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Component</th>
                        <th>Speed</th>
                        <th style="width:80px">Stock</th>
                        <th style="width:80px">Labels</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product):
                    $sku       = $product->get_sku();
                    $stock     = (int) $product->get_stock_quantity();
                    $component = qc_get_attribute($product, 'Component');
                    $speed_raw = qc_get_attribute($product, 'Speed');
                    $speed     = $speed_raw ? $speed_raw . ' Speed' : '';
                    ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $product->get_id() ?>" class="product-cb"></td>
                        <td><strong><?= esc_html($product->get_name()) ?></strong></td>
                        <td><?= esc_html($sku) ?></td>
                        <td><?= esc_html($component) ?></td>
                        <td><?= esc_html($speed) ?></td>
                        <td><?= $stock > 0 ? $stock : '<span style="color:#999">0</span>' ?></td>
                        <td>
                            <?php if ($stock > 0): ?>
                                <a href="<?= admin_url('admin.php?page=qc-product-labels&print=' . $product->get_id()) ?>"
                                   target="_blank" class="button button-small">Print</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px">
                <button type="submit" class="button button-primary" id="print-selected-btn" disabled>
                    Print Selected
                </button>
                <span style="margin-left:12px;color:#666" id="selected-count">0 products selected</span>
            </p>
        </form>
    </div>

    <script>
    document.getElementById('select-all').addEventListener('change', function () {
        document.querySelectorAll('.product-cb').forEach(cb => cb.checked = this.checked);
        updateCount();
    });
    document.querySelectorAll('.product-cb').forEach(cb => cb.addEventListener('change', updateCount));
    function updateCount() {
        const checked = document.querySelectorAll('.product-cb:checked');
        document.getElementById('selected-count').textContent =
            checked.length + ' product' + (checked.length === 1 ? '' : 's') + ' selected';
        document.getElementById('print-selected-btn').disabled = checked.length === 0;
    }
    document.querySelector('form').addEventListener('submit', function (e) {
        const checked = [...document.querySelectorAll('.product-cb:checked')].map(cb => cb.value);
        if (!checked.length) { e.preventDefault(); return; }
        e.preventDefault();
        window.open('<?= admin_url('admin.php?page=qc-product-labels&print=') ?>' + checked.join(','), '_blank');
    });
    </script>
    <?php
}

function qc_labels_print($all_products, $print_ids) {
    $to_print = [];
    foreach ($all_products as $product) {
        if (!in_array($product->get_id(), $print_ids)) continue;
        $stock         = max(1, (int) $product->get_stock_quantity());
        $sku           = $product->get_sku();
        $component     = qc_get_attribute($product, 'Component');
        $brand         = qc_get_attribute($product, 'Brand') ?: 'L-TWOO';
        $category_path = qc_category_path($product->get_id());
        $image_id      = $product->get_image_id();
        $image_url     = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
        $stock_code    = get_post_meta($product->get_id(), '_qc_stock_code', true);

        for ($i = 0; $i < $stock; $i++) {
            $to_print[] = [
                'name'          => $product->get_name(),
                'sku'           => $sku,
                'component'     => $component,
                'brand'         => $brand,
                'category_path' => $category_path,
                'image_url'     => $image_url,
                'stock_code'    => $stock_code,
            ];
        }
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>QC Labels</title>
<style>
            * { margin: 0; padding: 0; box-sizing: border-box; }

            /*
             * Brother QL-1100 · DK-22205 continuous label (62mm wide).
             * Each label is 62mm wide × 75mm long — a natural portrait page,
             * no rotation needed.
             *
             * HOW TO PRINT (macOS system print dialog):
             * 1. Destination: "Brother QL-1100"
             * 2. Paper size: 62 × 75 mm. Create once if missing:
             *    System Settings → Printers & Scanners → QL-1100 → open any
             *    print dialog → Paper Size → Manage Custom Sizes → +
             *    width 62mm, height 75mm, all margins 0.
             * 3. Margins: None · Scale: 100%
             * 4. Print
             */
            @page {
                size: 62mm 75mm;
                margin: 0;
            }

            body {
                width: 62mm;
                background: white;
                font-family: Arial, Helvetica, sans-serif;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @media screen {
                body {
                    background: #e0e0e0;
                    padding: 10px;
                    width: auto;
                }
                .label-inner {
                    box-shadow: 0 1px 4px rgba(0,0,0,0.25);
                    border-radius: 1mm;
                }
                .print-btn {
                    display: block;
                    margin: 0 auto 16px;
                    padding: 10px 28px;
                    background: #2271b1;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    font-size: 15px;
                    cursor: pointer;
                }
                .labels-wrap {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 6px;
                }
            }

            @media print {
                .print-btn { display: none; }
                .print-hint { display: none; }
                .labels-wrap { display: block; }
            }

            .print-hint {
                text-align: center;
                font-size: 12px;
                color: #666;
                margin-bottom: 16px;
                font-family: Arial, sans-serif;
            }

            /* ── Label ── one per physical page: 62mm × 75mm portrait */
            .label {
                width: 62mm;
                height: 75mm;
                background: white;
                overflow: hidden;
                page-break-after: always;
                break-after: page;
            }
            .label:last-child {
                page-break-after: avoid;
                break-after: avoid;
            }

            .label-inner {
                width: 62mm;
                height: 75mm;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: flex-start;
                padding: 1.5mm 1.5mm 3mm 3.5mm;
                gap: 1.5mm;
                overflow: hidden;
            }

            .label-img {
                flex-shrink: 0;
                width: 24mm;
                height: 24mm;
            }
            .label-img img {
                width: 24mm;
                height: 24mm;
                object-fit: contain;
                display: block;
            }

            .label-info {
                width: 100%;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
                gap: 0.5mm;
            }

            .label-brand {
                font-size: 7pt;
                font-weight: bold;
                color: #000;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .label-sku {
                font-size: 15pt;
                font-weight: bold;
                color: #000;
                line-height: 1.1;
            }

            .label-name {
                font-size: 9pt;
                line-height: 1.2;
                overflow: hidden;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                color: #000;
            }

            .label-category {
                font-size: 8pt;
                color: #000;
                letter-spacing: 0.2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
            }

            .label-component {
                font-size: 8pt;
                color: #000;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
            }

            .label-stock-code {
                font-size: 7pt;
                color: #000;
                font-family: monospace;
                white-space: nowrap;
            }

            .label-supplier {
                margin-top: auto;
                font-size: 6.5pt;
                color: #000;
                font-style: italic;
            }
        </style>
    </head>
    <body>
        <button class="print-btn" onclick="window.print()">
            🖨 Print <?= count($to_print) ?> Label<?= count($to_print) !== 1 ? 's' : '' ?> → QL-1100
        </button>
        <p class="print-hint">Paper size: <strong>62&nbsp;&times;&nbsp;75&nbsp;mm</strong> · Margins: <strong>None</strong> · Scale: <strong>100%</strong><br>
        <small>If no 62&nbsp;&times;&nbsp;75&nbsp;mm size appears, create one in macOS System Settings &rarr; Printers &amp; Scanners &rarr; QL-1100 &rarr; open print dialog &rarr; Paper Size &rarr; Manage Custom Sizes (width 62&nbsp;mm, height 75&nbsp;mm, all margins 0).</small></p>

        <div class="labels-wrap">
        <?php foreach ($to_print as $i => $item): ?>
            <div class="label">
                <div class="label-inner">
                    <div class="label-img">
                        <?php if ($item['image_url']): ?>
                        <img src="<?= esc_url($item['image_url']) ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <div class="label-info">
                        <div class="label-brand"><?= esc_html($item['brand']) ?></div>
                        <?php if ($item['sku']): ?>
                        <div class="label-sku"><?= esc_html($item['sku']) ?></div>
                        <?php endif; ?>
                        <div class="label-name"><?= esc_html($item['name']) ?></div>
                        <?php if ($item['category_path']): ?>
                        <div class="label-category"><?= esc_html($item['category_path']) ?></div>
                        <?php endif; ?>
                        <?php if ($item['component']): ?>
                        <div class="label-component"><?= esc_html($item['component']) ?></div>
                        <?php endif; ?>
                        <?php if ($item['stock_code']): ?>
                        <div class="label-stock-code"><?= esc_html($item['stock_code']) ?></div>
                        <?php endif; ?>
                        <div class="label-supplier">Supplied by Quality Components in Australia</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <script>
        // Wait for all images to load before printing
        const imgs = document.querySelectorAll('.label-img img');
        if (imgs.length === 0) {
            setTimeout(() => window.print(), 200);
        } else {
            let loaded = 0;
            imgs.forEach(img => {
                const done = () => { if (++loaded === imgs.length) window.print(); };
                if (img.complete) done(); else { img.onload = done; img.onerror = done; }
            });
        }
        </script>
    </body>
    </html>
    <?php
    exit;
}

function qc_category_path($product_id) {
    $terms = get_the_terms($product_id, 'product_cat');
    if (!$terms || is_wp_error($terms)) return '';

    $top = $sub = null;
    foreach ($terms as $term) {
        if ($term->parent === 0 && $term->slug !== 'uncategorized') {
            $top = $term;
        } elseif ($term->parent !== 0) {
            $sub = $term;
        }
    }
    if (!$top) return $terms[0]->name ?? '';
    return $sub ? $top->name . ' -> ' . $sub->name : $top->name;
}

function qc_get_attribute($product, $attr_name) {
    $attributes = $product->get_attributes();
    foreach ($attributes as $key => $attr) {
        $label = wc_attribute_label($key);
        if (strtolower($label) === strtolower($attr_name) ||
            strtolower(str_replace('pa_', '', $key)) === strtolower($attr_name)) {
            if ($attr->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $key, ['fields' => 'names']);
                return implode(', ', $terms);
            } else {
                return $attr->get_options()[0] ?? '';
            }
        }
    }
    return '';
}