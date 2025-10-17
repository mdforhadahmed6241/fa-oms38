<?php
global $wpdb;
$table_name = $wpdb->prefix . 'oms_incomplete_orders';

// Pagination
$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$per_page = 20;
$offset = ($paged - 1) * $per_page;

// Search
$search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Query
$where_clause = '';
if (!empty($search_term)) {
    $where_clause = $wpdb->prepare(" WHERE phone LIKE %s OR customer_data LIKE %s", '%' . $wpdb->esc_like($search_term) . '%', '%' . $wpdb->esc_like($search_term) . '%');
}

$total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name" . $where_clause);
$incomplete_orders = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name" . $where_clause . " ORDER BY updated_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
));

$num_pages = ceil($total_items / $per_page);

// --- PERFORMANCE FIX: Pre-fetch all success rates for the current page ---
$success_rates = [];
if (!empty($incomplete_orders)) {
    // Pluck all unique phone numbers from the orders on the current page
    $phone_numbers = array_unique(wp_list_pluck($incomplete_orders, 'phone'));
    $api = new OMS_Courier_History_API();

    foreach ($phone_numbers as $phone) {
        if (empty($phone)) continue;
        
        $transient_key = 'oms_courier_rate_' . md5($phone);
        $cached_data = get_transient($transient_key);

        if (false !== $cached_data) {
            $success_rates[$phone] = $cached_data;
        } else {
            $rate_data = $api->get_courier_success_rate($phone);
            set_transient($transient_key, $rate_data, strtotime('tomorrow') - time());
            $success_rates[$phone] = $rate_data;
        }
    }
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Incomplete Orders List</h1>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="oms-incomplete-list">
        <p class="search-box">
            <label class="screen-reader-text" for="post-search-input">Search Incomplete Orders:</label>
            <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($search_term); ?>">
            <input type="submit" id="search-submit" class="button" value="Search Orders">
        </p>
    </form>
    
    <div class="clear"></div>

    <form method="post">
        <table class="wp-list-table widefat fixed striped table-view-list posts">
            <thead>
                <tr>
                    <th scope="col" class="manage-column" style="width: 15%;">Last Updated</th>
                    <th scope="col" class="manage-column" style="width: 15%;">Customer</th>
                    <th scope="col" class="manage-column" style="width: 30%;">Cart Items</th>
                    <th scope="col" class="manage-column" style="width: 15%;">Success Rate</th>
                    <th scope="col" class="manage-column" style="width: 15%;">Note</th>
                    <th scope="col" class="manage-column" style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (!empty($incomplete_orders)) : ?>
                    <?php foreach ($incomplete_orders as $inc_order) :
                        $customer_data = unserialize($inc_order->customer_data);
                        $cart_contents = unserialize($inc_order->cart_contents);
                        $name = $customer_data['billing_first_name'] ?? '';
                        if (!empty($customer_data['billing_last_name'])) {
                            $name .= ' ' . $customer_data['billing_last_name'];
                        }
                        $address = $customer_data['billing_address_1'] ?? '';
                        $note = $customer_data['order_comments'] ?? '';
                    ?>
                        <tr>
                            <td><?php echo esc_html(date('M j, Y, g:i A', strtotime($inc_order->updated_at))); ?></td>
                            <td>
                                <div class="oms-customer-details">
                                    <span><?php echo esc_html($inc_order->phone); ?></span>
                                    <span><?php echo esc_html($name); ?></span>
                                    <span><?php echo esc_html($address); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php
                                if (!empty($cart_contents) && is_array($cart_contents)) {
                                    echo '<div class="oms-item-list">';
                                    foreach ($cart_contents as $cart_item) {
                                        $product_id = $cart_item['product_id'] ?? 0;
                                        $product = $product_id ? wc_get_product($product_id) : null;
                                        
                                        if ($product) {
                                            $image_url = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
                                            if (!$image_url) {
                                                $image_url = wc_placeholder_img_src();
                                            }
                                            $product_name = $product->get_name();
                                            $sku = $product->get_sku() ?: 'N/A';
                                            $quantity = $cart_item['quantity'];

                                            echo '<div class="oms-item-details">';
                                            echo '<img src="' . esc_url($image_url) . '" class="oms-item-image" alt="' . esc_attr($product_name) . '">';
                                            echo '<div class="oms-item-sku">';
                                            echo '<span>' . esc_html($product_name) . ' &times; ' . esc_html($quantity) . '</span>';
                                            echo '<span>SKU: ' . esc_html($sku) . '</span>';
                                            echo '</div>'; // end .oms-item-sku
                                            echo '</div>'; // end .oms-item-details
                                        } else {
                                            echo '<div class="oms-item-details"><em>Product data unavailable (possibly deleted).</em></div>';
                                        }
                                    }
                                    echo '</div>'; // end .oms-item-list
                                } else {
                                    echo 'Cart is empty.';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $phone = $inc_order->phone;
                                $rate_data = $success_rates[$phone] ?? null;
                                $output = 'N/A';

                                if ($rate_data && $rate_data['totalOrders'] > 0) {
                                    $colorClass = 'oms-rate-red';
                                    if ($rate_data['successRate'] >= 70) $colorClass = 'oms-rate-green';
                                    elseif ($rate_data['successRate'] >= 45) $colorClass = 'oms-rate-orange';
                                    $output = sprintf(
                                        '<span class="oms-circle %s"></span><span>Success: %d%%<br>Order: %d/%d</span>',
                                        esc_attr($colorClass),
                                        esc_html($rate_data['successRate']),
                                        esc_html($rate_data['successOrders']),
                                        esc_html($rate_data['totalOrders'])
                                    );
                                }
                                ?>
                                <div class="oms-success-rate-badge">
                                    <?php echo $output; // This variable contains HTML, so it should not be escaped here. ?>
                                </div>
                            </td>
                            <td>
                                <?php echo esc_html($note); ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=oms-incomplete-order-details&id=' . $inc_order->id)); ?>" class="button">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="no-items"><td class="colspanchange" colspan="6">No incomplete orders found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($num_pages > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
                <span class="pagination-links">
                    <?php
                    $paginate_base = add_query_arg(['page' => 'oms-incomplete-list'], admin_url('admin.php'));
                    if ($search_term) $paginate_base = add_query_arg('s', $search_term, $paginate_base);
                    
                    echo paginate_links([
                        'base'      => $paginate_base . '&paged=%#%',
                        'format'    => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total'     => $num_pages,
                        'current'   => $paged,
                    ]);
                    ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

