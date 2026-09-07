<?php
/**
 * Plugin Name: QC Product Labels
 * Description: Print product labels to Brother QL-1100 on DK-11209 die-cut labels (62mm x 29mm). One label per unit in stock.
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
           <em>Printer: Brother QL-1100 · DK-11209 die-cut labels · Use Chrome's print dialog, Paper size <strong>29 &times; 62 mm</strong> (Portrait, no Landscape toggle), Margins: None, Scale: 100%.</em></p>

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

        for ($i = 0; $i < $stock; $i++) {
            $to_print[] = [
                'name'          => $product->get_name(),
                'sku'           => $sku,
                'component'     => $component,
                'brand'         => $brand,
                'category_path' => $category_path,
                'image_url'     => $image_url,
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
             * Brother QL-1100 · DK-11209 die-cut label.
             * The media is 29mm WIDE (across the print head) × 62mm LONG (feed
             * direction). The Brother driver treats it as a PORTRAIT page,
             * 29mm × 62mm, and prints artwork without rotating it.
             *
             * So: @page is 29mm × 62mm and each label's artwork is laid out
             * landscape (62mm × 29mm) inside .label-inner and rotated -90deg to
             * sit on the portrait page. You then peel the label and apply it
             * turned 90deg, reading across the 62mm length — normal for
             * address-style die-cut labels.
             *
             * HOW TO PRINT (use Chrome's own print UI, NOT "Print using system
             * dialog", and do NOT touch a Landscape toggle):
             * 1. Destination: "Brother QL-1100"
             * 2. Paper size: a 29 × 62 mm size. If the list has none, create one:
             *    macOS  Printers & Scanners → Brother QL-1100 → Paper Sizes →
             *    Manage Custom Sizes → +  width 29mm, height 62mm, all margins 0.
             *    Do NOT pick a 62 × 29 (landscape) size or a 29 × 90 / 29 × 42.
             * 3. Layout: Portrait · Margins: None · Headers/footers: Off
             * 4. Scale: 100% (not "Fit to printable area")
             * 5. Print
             */
            @page {
                size: 29mm 62mm;
                margin: 0;
            }

            body {
                width: 29mm;
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
                /* On screen, show each label un-rotated so it is legible. */
                .label {
                    width: 62mm !important;
                    height: 29mm !important;
                }
                .label-inner {
                    position: static !important;
                    transform: none !important;
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

            /* ── Label ──
             * .label is the physical page: 29mm × 62mm portrait, one per label.
             * .label-inner is the artwork, laid out landscape (62mm × 29mm) and
             * rotated -90deg onto that portrait page. */
            .label {
                position: relative;
                width: 29mm;
                height: 62mm;
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
                position: absolute;
                top: 50%;
                left: 50%;
                width: 62mm;
                height: 29mm;
                /* If a test print is upside-down, change to rotate(90deg). */
                transform: translate(-50%, -50%) rotate(-90deg);
                transform-origin: center center;
                display: flex;
                flex-direction: row;
                align-items: center;
                padding: 1.5mm 2.5mm;
                gap: 1.5mm;
                overflow: hidden;
            }

            /* Product image column */
            .label-img {
                flex-shrink: 0;
                width: 21mm;
                height: 21mm;
                align-self: center;
                overflow: hidden;
            }
            .label-img img {
                width: 21mm;
                height: 21mm;
                object-fit: contain;
                display: block;
            }

            /* Text column */
            .label-info {
                flex: 1;
                min-width: 0;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: 0.3mm;
            }

            .label-brand {
                font-size: 5pt;
                font-weight: bold;
                color: #888;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .label-sku {
                font-size: 9pt;
                font-weight: bold;
                color: #000;
                line-height: 1.1;
            }

            .label-name {
                font-size: 6.5pt;
                line-height: 1.15;
                overflow: hidden;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                color: #333;
            }

            .label-category {
                font-size: 6pt;
                color: #333;
                letter-spacing: 0.2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .label-component {
                font-size: 6pt;
                color: #444;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        </style>
    </head>
    <body>
        <button class="print-btn" onclick="window.print()">
            🖨 Print <?= count($to_print) ?> Label<?= count($to_print) !== 1 ? 's' : '' ?> → QL-1100
        </button>
        <p class="print-hint">Paper size: <strong>29&nbsp;&times;&nbsp;62&nbsp;mm</strong>, Portrait · Margins: <strong>None</strong> · Headers &amp; footers: <strong>Off</strong> · Scale: <strong>100%</strong><br>
        <small>Use Chrome's print dialog (not the system dialog) and do not use a Landscape toggle. The label content prints sideways, along the 62&nbsp;mm length &mdash; that is intentional.</small></p>

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