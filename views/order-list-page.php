<?php
// --- SETUP for Tabs ---
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'all_orders';

// Get the default courier for fallback
$default_courier_id = get_option('oms_default_courier');
$default_courier = $default_courier_id ? OMS_Helpers::get_courier_by_id($default_courier_id) : null;
$default_courier_name = $default_courier ? $default_courier['name'] : 'Default Courier';

// Define the statuses for each tab
$not_confirmed_statuses = ['processing', 'on-hold', 'no-response', 'cancelled', 'pending'];
$confirmed_statuses = ['completed', 'ready-to-ship', 'shipped'];
$shipped_statuses = ['delivered', 'returned', 'partial-return'];

$tab_statuses = [
    'all_orders'    => array_merge($not_confirmed_statuses, $confirmed_statuses, $shipped_statuses),
    'not-confirmed' => $not_confirmed_statuses,
    'confirmed'     => $confirmed_statuses,
    'shipped'       => $shipped_statuses,
];

// --- SETUP: Get query variables for filters, search, and pagination ---
$paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// --- QUERY: Build arguments based on the current tab and filters ---
$args = [
    'limit'    => 20,
    'paged'    => $paged,
    'orderby'  => 'date',
    'order'    => 'DESC',
    'paginate' => true,
    'status'   => ($status_filter === 'all') ? $tab_statuses[$current_tab] : $status_filter,
];

if (!empty($search_term)) {
    $args['s'] = $search_term;
}

$results = wc_get_orders($args);
$orders = $results->orders;
$total_orders = $results->total;
$num_pages = $results->max_num_pages;

// --- PERFORMANCE FIX: Pre-fetch all success rates for the current page ---
$success_rates = [];
if (!empty($orders)) {
    // Pluck all unique phone numbers from the orders on the current page
    $phone_numbers = array_unique(wp_list_pluck($orders, 'billing_phone'));
    $api = new OMS_Courier_History_API();

    foreach ($phone_numbers as $phone) {
        if (empty($phone)) continue;
        
        $transient_key = 'oms_courier_rate_' . md5($phone);
        $cached_data = get_transient($transient_key);

        if (false !== $cached_data) {
            // Use cached data if available
            $success_rates[$phone] = $cached_data;
        } else {
            // Otherwise, fetch from API and cache it for the rest of the day
            $rate_data = $api->get_courier_success_rate($phone);
            set_transient($transient_key, $rate_data, strtotime('tomorrow') - time());
            $success_rates[$phone] = $rate_data;
        }
    }
}
// --- End Performance Fix ---


// --- Get status counts for the current tab's filters ---
$all_wc_statuses = wc_get_order_statuses();
$status_counts = [];
$statuses_to_count_for_links = ($current_tab === 'all_orders')
    ? array_map(fn($s) => str_replace('wc-', '', $s), array_keys($all_wc_statuses))
    : $tab_statuses[$current_tab];

foreach ($statuses_to_count_for_links as $status_slug) {
    $count = wc_orders_count($status_slug);
    if ($count > 0) {
        $status_counts[$status_slug] = $count;
    }
}

// Calculate the total for the "All" link by summing counts within the tab's definition
$total_count_for_tab = 0;
foreach($tab_statuses[$current_tab] as $status_slug_in_tab) {
    $total_count_for_tab += wc_orders_count($status_slug_in_tab);
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Order List</h1>
    <a href="<?php echo admin_url('admin.php?page=oms-add-order'); ?>" class="page-title-action">Add New</a>
    <hr class="wp-header-end">

    <h2 class="nav-tab-wrapper">
        <a href="?page=oms-order-list&tab=all_orders" class="nav-tab <?php echo $current_tab == 'all_orders' ? 'nav-tab-active' : ''; ?>">
            All Orders
        </a>
        <a href="?page=oms-order-list&tab=not-confirmed" class="nav-tab <?php echo $current_tab == 'not-confirmed' ? 'nav-tab-active' : ''; ?>">
            Not Confirmed Orders
        </a>
        <a href="?page=oms-order-list&tab=confirmed" class="nav-tab <?php echo $current_tab == 'confirmed' ? 'nav-tab-active' : ''; ?>">
            Confirmed Orders
        </a>
        <a href="?page=oms-order-list&tab=shipped" class="nav-tab <?php echo $current_tab == 'shipped' ? 'nav-tab-active' : ''; ?>">
            Shipped Orders
        </a>
    </h2>

    <form method="get">
        <input type="hidden" name="page" value="oms-order-list">
        <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">
        
        <ul class="subsubsub">
            <li class="all"><a href="?page=oms-order-list&tab=<?php echo esc_attr($current_tab); ?>" class="<?php echo ($status_filter === 'all') ? 'current' : ''; ?>">All <span class="count">(<?php echo esc_html($total_count_for_tab); ?>)</span></a></li>
            <?php foreach ($status_counts as $slug => $count) :
                $wc_slug = 'wc-' . $slug;
                if (isset($all_wc_statuses[$wc_slug])) :
            ?>
            <li>| <a href="?page=oms-order-list&tab=<?php echo esc_attr($current_tab); ?>&status=<?php echo esc_attr($slug); ?>" class="<?php echo ($status_filter === $slug) ? 'current' : ''; ?>"><?php echo esc_html($all_wc_statuses[$wc_slug]); ?> <span class="count">(<?php echo esc_html($count); ?>)</span></a></li>
            <?php endif; endforeach; ?>
        </ul>

        <p class="search-box">
            <label class="screen-reader-text" for="post-search-input">Search Orders:</label>
            <input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($search_term); ?>">
            <input type="submit" id="search-submit" class="button" value="Search Orders">
        </p>
    </form>
    
    <div class="clear"></div>

    <form method="post">
        <?php wp_nonce_field('oms_bulk_actions', 'oms_bulk_action_nonce'); ?>
        <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1">Bulk actions</option>
                    <?php foreach (wc_get_order_statuses() as $slug => $name) : ?>
                        <option value="<?php echo esc_attr(str_replace('wc-', '', $slug)); ?>">Change status to <?php echo esc_html(strtolower($name)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" id="doaction" class="button action" value="Apply">
            </div>
             <?php if ($num_pages > 1) : ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_orders); ?> items</span>
                <span class="pagination-links">
                    <?php
                     $paginate_base = add_query_arg(['page' => 'oms-order-list', 'tab' => $current_tab], admin_url('admin.php'));
                    if ($status_filter !== 'all') $paginate_base = add_query_arg('status', $status_filter, $paginate_base);
                    if ($search_term) $paginate_base = add_query_arg('s', $search_term, $paginate_base);
                    $paginate_base = add_query_arg('paged', '%#%', $paginate_base);

                    echo paginate_links([
                        'base'      => $paginate_base,
                        'format'    => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total'     => $num_pages,
                        'current'   => $paged,
                        'mid_size'  => 2,
                    ]);
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat fixed striped table-view-list posts">
            <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"></td>
                    <th scope="col" class="manage-column" style="width: 12%;">Created At</th>
                    <th scope="col" class="manage-column" style="width: 15%;">Customer</th>
                    <th scope="col" class="manage-column" style="width: 25%;">Order Items</th>
                    <th scope="col" class="manage-column" style="width: 10%;">Success Rate</th>
                    <th scope="col" class="manage-column" style="width: 18%;">Note</th>
                    <?php if ($current_tab === 'confirmed' || $current_tab === 'shipped') : ?>
                        <th scope="col" class="manage-column" style="width: 10%;">Courier Status</th>
                    <?php endif; ?>
                    <th scope="col" class="manage-column" style="width: 10%;">Action</th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (!empty($orders)) : ?>
                    <?php foreach ($orders as $order) : ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($order->get_id()); ?>"></th>
                            <td>
                                <div class="oms-date-id">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=oms-order-details&order_id=' . $order->get_id())); ?>"><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></a>
                                    <span><?php echo esc_html($order->get_date_created()->date_i18n('M j, Y, g:i A')); ?></span>
                                    <span class="oms-status-badge status-<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="oms-customer-details">
                                    <span><?php echo esc_html($order->get_billing_phone()); ?></span>
                                    <span><?php echo esc_html($order->get_formatted_billing_full_name()); ?></span>
                                    <span><?php echo wp_kses_post($order->get_billing_address_1()); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $items = $order->get_items();
                                $first_item = reset($items);
                                if ($first_item) :
                                    $product = $first_item->get_product();
                                    $image_url = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : wc_placeholder_img_src();
                                ?>
                                <div class="oms-item-details">
                                    <img src="<?php echo esc_url($image_url); ?>" class="oms-item-image" alt="<?php echo esc_attr($first_item->get_name()); ?>">
                                    <div class="oms-item-sku">
                                        <span><?php echo esc_html($first_item->get_name()); ?></span>
                                        <span>SKU: <?php echo esc_html($product ? $product->get_sku() : 'N/A'); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                             <td>
                                <?php
                                $phone = $order->get_billing_phone();
                                $rate_data = isset($success_rates[$phone]) ? $success_rates[$phone] : null;
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
                                    <?php echo $output; ?>
                                </div>
                            </td>
                            <td>
                                <?php
                                $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal', 'orderby' => 'date_created', 'order' => 'DESC']);
                                $latest_note_content = '';
                                $system_message_identifiers = [ 'Status updated from custom order page.', 'Order status changed from', 'was upgraded to', 'API returned', 'was generated for this customer', 'sent to' ];
                                if ($notes) {
                                    foreach($notes as $note) {
                                        $is_system_note = false;
                                        foreach ($system_message_identifiers as $identifier) { if (strpos($note->content, $identifier) !== false) { $is_system_note = true; break; } }
                                        if (!$is_system_note) { $latest_note_content = $note->content; break; }
                                    }
                                }
                                echo esc_html($latest_note_content);
                                ?>
                            </td>
                            <?php if ($current_tab === 'confirmed' || $current_tab === 'shipped') : ?>
                                <td class="oms-courier-status-cell" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                                    <?php
                                    $steadfast_id = $order->get_meta('_steadfast_consignment_id');
                                    $pathao_id = $order->get_meta('_pathao_consignment_id');
                                    
                                    if ($steadfast_id) {
                                        $tracking_code = $order->get_meta('_steadfast_tracking_code');
                                        $tracking_url = "https://steadfast.com.bd/t/{$tracking_code}";
                                        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" class="button button-secondary">Track Steadfast</a>';
                                        echo '<span class="oms-parcel-id">Parcel ID: ' . esc_html($steadfast_id) . '</span>';
                                    } elseif ($pathao_id) {
                                        $tracking_url = "https://merchant.pathao.com/courier/orders/{$pathao_id}";
                                        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" class="button button-secondary">Track Pathao</a>';
                                        echo '<span class="oms-parcel-id">Parcel ID: ' . esc_html($pathao_id) . '</span>';
                                    } else {
                                        $button_courier = $default_courier;
                                        $order_courier_id = $order->get_meta('_oms_selected_courier_id', true);
                                        if ($order_courier_id) {
                                            $order_courier = OMS_Helpers::get_courier_by_id($order_courier_id);
                                            if ($order_courier) {
                                                $button_courier = $order_courier;
                                            }
                                        }
                                        $button_name = $button_courier ? $button_courier['name'] : 'Courier';
                                        $button_courier_id = $button_courier ? $button_courier['id'] : '';
                                        
                                        echo '<button class="button button-primary oms-send-to-courier-list-btn" data-order-id="' . esc_attr($order->get_id()) . '" data-courier-id="' . esc_attr($button_courier_id) .'">Send to ' . esc_html($button_name) . '</button>';
                                        echo '<span class="oms-parcel-id">Not Uploaded</span>';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=oms-order-details&order_id=' . $order->get_id())); ?>" class="button">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : 
                    $colspan = ($current_tab === 'confirmed' || $current_tab === 'shipped') ? 8 : 7;
                ?>
                    <tr class="no-items"><td class="colspanchange" colspan="<?php echo $colspan; ?>">No orders found for this tab.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-bottom" class="screen-reader-text">Select bulk action</label>
                <select name="action2" id="bulk-action-selector-bottom">
                    <option value="-1">Bulk actions</option>
                    <?php foreach (wc_get_order_statuses() as $slug => $name) : ?>
                        <option value="<?php echo esc_attr(str_replace('wc-', '', $slug)); ?>">Change status to <?php echo esc_html(strtolower($name)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" id="doaction2" class="button action" value="Apply">
            </div>
            
            <?php if ($num_pages > 1) : ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_orders); ?> items</span>
                <span class="pagination-links">
                     <?php
                    echo paginate_links([
                        'base'      => $paginate_base,
                        'format'    => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total'     => $num_pages,
                        'current'   => $paged,
                        'mid_size'  => 2,
                    ]);
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>
