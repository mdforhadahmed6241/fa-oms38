<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all AJAX requests for the plugin.
 */
class OMS_Ajax {

    public function __construct() {
        // Intentionally left blank.
    }

    /**
     * Register all AJAX hooks.
     */
    public function load_hooks() {
        $ajax_actions = [
            'send_to_courier', 'sync_pathao_cities', 'sync_pathao_zones', 'sync_pathao_areas',
            'clear_plugin_cache', 'get_pathao_zones_for_order_page', 'get_pathao_areas_for_order_page',
            'search_products', 'get_product_details_for_order', 'save_order_details', 'update_order_status',
            'create_order', 'get_customer_history', 'add_order_note', 'get_courier_history', 'save_couriers'
        ];
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_oms_ajax_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    /**
     * AJAX handler to send an order to a courier.
     */
    public function ajax_send_to_courier() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        if (!current_user_can('edit_shop_orders')) wp_send_json_error(['message' => 'Permission denied.']);
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Order not found.']);

        $courier_id = isset($_POST['courier_id']) ? sanitize_text_field($_POST['courier_id']) : ($order->get_meta('_oms_selected_courier_id', true) ?: get_option('oms_default_courier'));
        $courier = OMS_Helpers::get_courier_by_id($courier_id);
        if (!$courier) wp_send_json_error(['message' => 'Courier configuration not found. Please select a valid courier.']);

        $result = ['success' => false, 'message' => 'Invalid courier type specified.'];
        $final_response = [];

        if ($courier['type'] === 'steadfast') {
            $api = new OMS_Steadfast_API($courier['credentials']);
            $result = $api->create_consignment($order);
            if ($result['success']) { $final_response = [ 'success' => true, 'message' => $result['message'], 'courier_name' => esc_html($courier['name']), 'consignment_id' => $result['consignment_id'], 'tracking_url' => "https://steadfast.com.bd/t/" . $result['tracking_code'] ]; }
        } elseif ($courier['type'] === 'pathao') {
            $api = new OMS_Pathao_API($courier['credentials']);
            $location_data = [ 'city_id' => $order->get_meta('_oms_pathao_city_id', true), 'zone_id' => $order->get_meta('_oms_pathao_zone_id', true), 'area_id' => $order->get_meta('_oms_pathao_area_id', true) ];
            if (empty($location_data['city_id']) || empty($location_data['zone_id'])) { wp_send_json_error(['message' => 'Pathao requires a City and Zone. Please set this on the order details page before sending.']); return; }
            $result = $api->create_order($order, $location_data);
             if ($result['success']) { $final_response = [ 'success' => true, 'message' => $result['message'], 'courier_name' => esc_html($courier['name']), 'consignment_id' => $result['consignment_id'], 'tracking_url' => "https://merchant.pathao.com/courier/orders/" . $result['consignment_id'] ]; }
        }

        if (!empty($final_response)) {
            $order->update_meta_data('_oms_selected_courier_id', $courier_id);
            $order->save();
            wp_send_json_success($final_response);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }
    
    public function ajax_sync_pathao_cities() {
        check_ajax_referer('oms_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);
        $courier = ($_POST['courier_id'] ?? null) ? OMS_Helpers::get_courier_by_id(sanitize_text_field($_POST['courier_id'])) : null;
        if (!$courier || $courier['type'] !== 'pathao') { wp_send_json_error(['message' => 'A valid Pathao courier configuration is required to sync.']); }
        $api = new OMS_Pathao_API($courier['credentials']);
        $cities = $api->get_cities();
        if (is_array($cities)) {
            global $wpdb; $table_name = $wpdb->prefix . 'oms_pathao_cities';
            $wpdb->query("TRUNCATE TABLE $table_name");
            foreach ($cities as $city) { $wpdb->insert($table_name, ['city_id' => $city['city_id'], 'city_name' => $city['city_name']]); }
            wp_send_json_success(['message' => 'Cities synced.', 'cities' => $cities]);
        } else { wp_send_json_error(['message' => 'Failed to fetch cities from Pathao API. Check credentials for ' . esc_html($courier['name']) . '.']); }
    }

    public function ajax_sync_pathao_zones() {
        check_ajax_referer('oms_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);
        $city_id = isset($_POST['city_id']) ? absint($_POST['city_id']) : 0;
        if (!$city_id) wp_send_json_error(['message' => 'City ID is required.']);
        $courier = ($_POST['courier_id'] ?? null) ? OMS_Helpers::get_courier_by_id(sanitize_text_field($_POST['courier_id'])) : null;
        if (!$courier || $courier['type'] !== 'pathao') { wp_send_json_error(['message' => 'A valid Pathao courier configuration is required to sync.']); }
        $api = new OMS_Pathao_API($courier['credentials']);
        $zones = $api->get_zones($city_id);
        if (is_array($zones)) {
            global $wpdb; $table_name = $wpdb->prefix . 'oms_pathao_zones';
            foreach ($zones as $zone) { $wpdb->replace($table_name, [ 'zone_id' => $zone['zone_id'], 'city_id' => $city_id, 'zone_name' => $zone['zone_name'] ]); }
            wp_send_json_success(['message' => 'Zones for city ' . $city_id . ' synced.', 'zones' => $zones]);
        } else { wp_send_json_error(['message' => 'Failed to fetch zones for city ' . $city_id]); }
    }

    public function ajax_sync_pathao_areas() {
        check_ajax_referer('oms_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);
        $zone_id = isset($_POST['zone_id']) ? absint($_POST['zone_id']) : 0;
        if (!$zone_id) wp_send_json_error(['message' => 'Zone ID is required.']);
        $courier = ($_POST['courier_id'] ?? null) ? OMS_Helpers::get_courier_by_id(sanitize_text_field($_POST['courier_id'])) : null;
        if (!$courier || $courier['type'] !== 'pathao') { wp_send_json_error(['message' => 'A valid Pathao courier configuration is required to sync.']); }
        $api = new OMS_Pathao_API($courier['credentials']);
        $areas = $api->get_areas($zone_id);
        if (is_array($areas)) {
            global $wpdb; $table_name = $wpdb->prefix . 'oms_pathao_areas';
             foreach ($areas as $area) { $wpdb->replace($table_name, [ 'area_id' => $area['area_id'], 'zone_id' => $zone_id, 'area_name' => $area['area_name'] ]); }
            wp_send_json_success(['message' => 'Areas for zone ' . $zone_id . ' synced.']);
        } else { wp_send_json_success(['message' => 'No areas found for zone ' . $zone_id . '.']); }
    }

    public function ajax_clear_plugin_cache() {
        check_ajax_referer('oms_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);
        global $wpdb; $count = 0;
        $transient_keys = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_oms\_pathao\_token\_%' OR option_name LIKE '\_transient\_oms\_courier\_%'");
        foreach ($transient_keys as $key) { $transient_name = str_replace('_transient_', '', $key); if (delete_transient($transient_name)) { $count++; } }
        wp_send_json_success(['message' => $count . ' cache entries cleared successfully.']);
    }

    public function ajax_get_pathao_zones_for_order_page() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $city_id = isset($_POST['city_id']) ? absint($_POST['city_id']) : 0; if (!$city_id) wp_send_json_error([]);
        global $wpdb; $table = $wpdb->prefix . 'oms_pathao_zones';
        $zones = $wpdb->get_results($wpdb->prepare("SELECT zone_id, zone_name FROM $table WHERE city_id = %d ORDER BY zone_name ASC", $city_id));
        wp_send_json_success($zones);
    }

    public function ajax_get_pathao_areas_for_order_page() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $zone_id = isset($_POST['zone_id']) ? absint($_POST['zone_id']) : 0; if (!$zone_id) wp_send_json_error([]);
        global $wpdb; $table = $wpdb->prefix . 'oms_pathao_areas';
        $areas = $wpdb->get_results($wpdb->prepare("SELECT area_id, area_name FROM $table WHERE zone_id = %d ORDER BY area_name ASC", $zone_id));
        wp_send_json_success($areas);
    }
    
    public function ajax_search_products() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $search_term = isset($_POST['search_term']) ? sanitize_text_field(trim($_POST['search_term'])) : ''; if (strlen($search_term) < 2) { wp_send_json_error([]); }
        $products = wc_get_products(['s' => $search_term, 'limit' => 10, 'status' => 'publish']);
        $found_products = [];
        if (!empty($products)) { foreach ($products as $p) { $found_products[] = ['id'=>$p->get_id(),'name'=>$p->get_name(),'sku'=>$p->get_sku()?:'N/A','price_html'=>$p->get_price_html(),'image_url'=>wp_get_attachment_image_url($p->get_image_id(),'thumbnail'),'stock_quantity'=>$p->get_stock_quantity()??'∞'];}}
        wp_send_json_success($found_products);
    }

    public function ajax_get_product_details_for_order() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $product = wc_get_product(isset($_POST['product_id']) ? absint($_POST['product_id']) : 0);
        if (!$product) { wp_send_json_error(['message' => 'Product not found.']); }
        wp_send_json_success(['id'=>$product->get_id(),'name'=>$product->get_name(),'sku'=>$product->get_sku()?:'N/A','price'=>$product->get_price(),'image_url'=>wp_get_attachment_image_url($product->get_image_id(),'thumbnail')]);
    }

    public function ajax_save_order_details() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!isset($_POST['order_data'])) throw new Exception('No data.');
            $data = json_decode(stripslashes($_POST['order_data']), true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid data.');
            $order = wc_get_order(isset($data['order_id']) ? absint($data['order_id']) : 0);
            if (!$order) throw new Exception('Order not found.');
            $cust = $data['customer']; $name = explode(' ', trim($cust['name']), 2);
            $order->set_billing_first_name($name[0]); $order->set_billing_last_name($name[1] ?? '');
            $order->set_shipping_first_name($name[0]); $order->set_shipping_last_name($name[1] ?? '');
            $order->set_billing_phone($cust['phone']); $order->set_billing_address_1($cust['address_1']);
            $order->set_shipping_address_1($cust['address_1']);
            if (isset($cust['note'])) $order->set_customer_note($cust['note']);
            if (isset($data['courier_id'])) $order->update_meta_data('_oms_selected_courier_id', sanitize_text_field($data['courier_id']));
            if (isset($data['pathao_location'])) {
                $order->update_meta_data('_oms_pathao_city_id', sanitize_text_field($data['pathao_location']['city_id']));
                $order->update_meta_data('_oms_pathao_zone_id', sanitize_text_field($data['pathao_location']['zone_id']));
                $order->update_meta_data('_oms_pathao_area_id', sanitize_text_field($data['pathao_location']['area_id']));
            }
            $order->remove_order_items('line_item');
            foreach ($data['items'] as $item_data) {
                $product = wc_get_product(absint($item_data['product_id'])); if (!$product) continue;
                $item = new WC_Order_Item_Product(); $item->set_product($product); $item->set_quantity(absint($item_data['quantity']));
                $item->set_subtotal(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $item->set_total(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $order->add_item($item);
            }
            $totals = $data['totals'];
            $order->remove_order_items('shipping');
            if ($totals['shipping'] >= 0) { $ship = new WC_Order_Item_Shipping(); $ship->set_method_title("Delivery Charge"); $ship->set_total($totals['shipping']); $order->add_item($ship); }
            $order->remove_order_items('fee');
            if ($totals['discount'] > 0) { $fee = new WC_Order_Item_Fee(); $fee->set_name('Discount'); $fee->set_amount(-wc_format_decimal($totals['discount'])); $fee->set_total(-wc_format_decimal($totals['discount'])); $fee->set_tax_status('none'); $order->add_item($fee); }
            $order->calculate_totals(); $order->save();
            wp_send_json_success(['message' => 'Order updated!', 'new_total' => $order->get_total()]);
        } catch (Exception $e) { wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]); }
    }

    public function ajax_update_order_status() {
        ob_start(); check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            $order = wc_get_order(isset($_POST['order_id']) ? absint($_POST['order_id']) : 0);
            $new_status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
            if (!$order) throw new Exception('Order not found.');
            if (!OMS_Helpers::is_valid_status_transition($order->get_status(), $new_status)) throw new Exception('This status change is not permitted.');
            if (empty($new_status) || !in_array('wc-'.$new_status, array_keys(wc_get_order_statuses()))) throw new Exception('Invalid status.');
            $order->update_status($new_status, 'Status updated from custom order page.', true);
            $order->save();
            ob_clean(); wp_send_json_success(['message' => 'Status updated!']);
        } catch (Exception $e) { ob_clean(); wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]); }
    }

    public function ajax_create_order() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!isset($_POST['order_data'])) throw new Exception('No data.');
            $data = json_decode(stripslashes($_POST['order_data']), true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid data.');
            $order = wc_create_order(); $order->set_status('wc-completed');
            $cust = $data['customer']; $name = explode(' ', trim($cust['name']), 2);
            $addr = ['first_name'=>$name[0],'last_name'=>$name[1]??'','address_1'=>$cust['address_1'],'phone'=>$cust['phone']];
            $order->set_address($addr,'billing'); $order->set_address($addr,'shipping');
            $order->set_customer_note($cust['note']);
            if (isset($data['courier_id'])) $order->update_meta_data('_oms_selected_courier_id', sanitize_text_field($data['courier_id']));
            if (isset($data['pathao_location'])) {
                $order->update_meta_data('_oms_pathao_city_id', sanitize_text_field($data['pathao_location']['city_id']));
                $order->update_meta_data('_oms_pathao_zone_id', sanitize_text_field($data['pathao_location']['zone_id']));
                $order->update_meta_data('_oms_pathao_area_id', sanitize_text_field($data['pathao_location']['area_id']));
            }
            foreach ($data['items'] as $item_data) {
                $product = wc_get_product(absint($item_data['product_id'])); if (!$product) continue;
                $item = new WC_Order_Item_Product(); $item->set_product($product); $item->set_quantity(absint($item_data['quantity']));
                $item->set_subtotal(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $item->set_total(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $order->add_item($item);
            }
            $totals = $data['totals'];
            if ($totals['shipping'] >= 0) { $ship = new WC_Order_Item_Shipping(); $ship->set_method_title("Delivery Charge"); $ship->set_total($totals['shipping']); $order->add_item($ship); }
            if ($totals['discount'] > 0) { $fee = new WC_Order_Item_Fee(); $fee->set_name('Discount'); $fee->set_amount(-wc_format_decimal($totals['discount'])); $fee->set_total(-wc_format_decimal($totals['discount'])); $fee->set_tax_status('none'); $order->add_item($fee); }
            $order->calculate_totals();
            $order_id = $order->save();
            wp_send_json_success(['message' => 'Order created!', 'order_id' => $order_id, 'redirect_url' => admin_url('admin.php?page=oms-order-details&order_id=' . $order_id)]);
        } catch (Exception $e) { wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]); }
    }

    public function ajax_get_customer_history() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (empty($phone)) wp_send_json_error(['message' => 'Phone number required.']);
        $orders = wc_get_orders(['limit' => -1, 'billing_phone' => $phone]);
        $history = ['completed'=>0,'shipped'=>0,'ready-to-ship'=>0,'delivered'=>0,'returned'=>0,'cancelled'=>0,'total_value'=>0,'total_orders'=>0];
        $conv_statuses = ['completed', 'shipped', 'ready-to-ship', 'delivered'];
        if ($orders) {
            $history['total_orders'] = count($orders);
            foreach ($orders as $order) {
                $status = $order->get_status();
                if (isset($history[$status])) $history[$status]++;
                if (in_array($status, $conv_statuses)) $history['total_value'] += $order->get_total();
            }
        }
        $history['total_value_formatted'] = wc_price($history['total_value']);
        wp_send_json_success($history);
    }
    
    public function ajax_add_order_note() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!current_user_can('edit_shop_orders')) throw new Exception('Permission denied.');
            $order = wc_get_order(isset($_POST['order_id']) ? absint($_POST['order_id']) : 0);
            $note = isset($_POST['note']) ? wp_kses_post(trim($_POST['note'])) : '';
            if (!$order || empty($note)) throw new Exception('Missing data.');
            $order->add_order_note($note, false, true); $order->save();
            wp_send_json_success(['message' => 'Note added.']);
        } catch (Exception $e) { wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]); }
    }

    public function ajax_get_courier_history() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (empty($phone)) { wp_send_json_error(['message' => 'Phone number is required.']); }
        $transient_key = 'oms_courier_history_' . md5($phone);

        if (false !== ($cached_json = get_transient($transient_key))) {
            $cached_data = json_decode($cached_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($cached_data)) {
                wp_send_json_success($cached_data);
                return;
            } else {
                delete_transient($transient_key);
            }
        }
    
        $api = new OMS_Courier_History_API();
        $all_data = $api->get_overall_history_from_search_api($phone);
    
        if (isset($all_data['error'])) {
            wp_send_json_error(['message' => $all_data['error']]);
        } else {
            set_transient($transient_key, json_encode($all_data), DAY_IN_SECONDS); 
            wp_send_json_success($all_data);
        }
    }
    
    public function ajax_save_couriers() {
        check_ajax_referer('oms_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied.']);
        $couriers_data = isset($_POST['couriers']) ? json_decode(stripslashes($_POST['couriers']), true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) wp_send_json_error(['message' => 'Invalid data format.']);
        $sanitized_couriers = $this->sanitize_couriers_settings($couriers_data);
        update_option('oms_couriers', $sanitized_couriers);
        wp_send_json_success(['message' => 'Courier settings saved.']);
    }

    private function sanitize_couriers_settings($input) {
        $sanitized_input = [];
        if (is_array($input)) {
            foreach ($input as $courier_data) {
                if (empty($courier_data['id']) || empty($courier_data['name']) || empty($courier_data['type'])) continue;
                $sanitized_courier = [ 'id' => sanitize_text_field($courier_data['id']), 'name' => sanitize_text_field($courier_data['name']), 'type' => sanitize_text_field($courier_data['type']), 'credentials' => [] ];
                 if (is_array($courier_data['credentials'])) { foreach ($courier_data['credentials'] as $cred_key => $cred_value) { $sanitized_courier['credentials'][$cred_key] = ($cred_key === 'password') ? $cred_value : sanitize_text_field($cred_value); } }
                $sanitized_input[] = $sanitized_courier;
            }
        }
        return $sanitized_input;
    }
}
