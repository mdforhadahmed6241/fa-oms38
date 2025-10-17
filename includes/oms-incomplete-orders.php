<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all logic for capturing and managing incomplete orders.
 */
class OMS_Incomplete_Orders {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'oms_incomplete_orders';

        // AJAX handler for capturing checkout data
        add_action('wp_ajax_oms_capture_incomplete_order', [$this, 'capture_incomplete_order']);
        add_action('wp_ajax_nopriv_oms_capture_incomplete_order', [$this, 'capture_incomplete_order']);

        // AJAX handler for creating a real order from an incomplete one
        add_action('wp_ajax_oms_ajax_create_incomplete_order', [$this, 'ajax_create_order_from_incomplete']);
        
        // AJAX handler for deleting an incomplete order
        add_action('wp_ajax_oms_ajax_delete_incomplete_order', [$this, 'ajax_delete_incomplete_order']);

        // AJAX handler for adding a note to an incomplete order
        add_action('wp_ajax_oms_ajax_add_incomplete_order_note', [$this, 'ajax_add_incomplete_order_note']);

        // Hook to delete the incomplete order record when a real order is placed
        add_action('woocommerce_checkout_order_processed', [$this, 'delete_incomplete_order_on_completion'], 10, 1);
        
        add_action('init', [$this, 'ensure_wc_session']);
    }

    public function ensure_wc_session() {
        if (!is_admin() && !is_user_logged_in() && isset(WC()->session) && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    public function capture_incomplete_order() {
        global $wpdb;

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $customer_data_json = isset($_POST['customer_data']) ? stripslashes($_POST['customer_data']) : '{}';
        $customer_data = json_decode($customer_data_json, true);
        
        $session_id = WC()->session->get_customer_id();

        if (empty($phone) || strlen($phone) < 5 || empty($session_id)) {
            wp_send_json_error(['message' => 'Insufficient data.']);
            return;
        }

        $cart = WC()->cart->get_cart_for_session();
        $cart_contents = serialize($cart);
        $serialized_customer_data = serialize($customer_data);
        $current_time = current_time('mysql');

        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $this->table_name WHERE session_id = %s", $session_id));

        if ($existing_id) {
            $wpdb->update(
                $this->table_name,
                [
                    'phone' => $phone,
                    'customer_data' => $serialized_customer_data,
                    'cart_contents' => $cart_contents,
                    'updated_at' => $current_time
                ],
                ['id' => $existing_id]
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                [
                    'session_id' => $session_id,
                    'phone' => $phone,
                    'customer_data' => $serialized_customer_data,
                    'cart_contents' => $cart_contents,
                    'created_at' => $current_time,
                    'updated_at' => $current_time
                ]
            );
        }

        wp_send_json_success(['message' => 'Data captured.']);
    }

    public function delete_incomplete_order_on_completion($order_id) {
        global $wpdb;
        $session_id = WC()->session->get_customer_id();
        if ($session_id) {
            $wpdb->delete($this->table_name, ['session_id' => $session_id]);
        }
    }

    public function ajax_create_order_from_incomplete() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!current_user_can('edit_shop_orders')) {
                throw new Exception('Permission denied.');
            }
            if (!isset($_POST['order_data'])) throw new Exception('No order data received.');
            
            $order_data = json_decode(stripslashes($_POST['order_data']), true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid data format.');

            $incomplete_order_id = isset($_POST['incomplete_order_id']) ? absint($_POST['incomplete_order_id']) : 0;
            if (!$incomplete_order_id) {
                throw new Exception('Incomplete order ID is missing.');
            }

            $order = wc_create_order();
            $order->set_status('wc-processing');

            $customer_details = $order_data['customer'];
            $name_parts = explode(' ', trim($customer_details['name']), 2);
            $address = [
                'first_name' => $name_parts[0],
                'last_name'  => $name_parts[1] ?? '',
                'address_1'  => $customer_details['address_1'],
                'phone'      => $customer_details['phone']
            ];
            $order->set_address($address, 'billing');
            $order->set_address($address, 'shipping');
            $order->set_customer_note($customer_details['note']);

            if (isset($order_data['pathao_location'])) {
                $order->update_meta_data('_oms_pathao_city_id', sanitize_text_field($order_data['pathao_location']['city_id']));
                $order->update_meta_data('_oms_pathao_zone_id', sanitize_text_field($order_data['pathao_location']['zone_id']));
                $order->update_meta_data('_oms_pathao_area_id', sanitize_text_field($order_data['pathao_location']['area_id']));
            }

            foreach ($order_data['items'] as $item_data) {
                $product = wc_get_product(absint($item_data['product_id']));
                if (!$product) continue;
                $item = new WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_quantity(absint($item_data['quantity']));
                $item->set_subtotal(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $item->set_total(wc_format_decimal($item_data['price']) * absint($item_data['quantity']));
                $order->add_item($item);
            }
            
            $totals = $order_data['totals'];
            if (!empty($totals['shipping'])) {
                $shipping_rate = new WC_Order_Item_Shipping();
                $shipping_rate->set_method_title("Delivery Charge");
                $shipping_rate->set_total($totals['shipping']);
                $order->add_item($shipping_rate);
            }
            if (!empty($totals['discount'])) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name('Discount');
                $fee->set_amount(-wc_format_decimal($totals['discount']));
                $fee->set_total(-wc_format_decimal($totals['discount']));
                $order->add_item($fee);
            }

            $order->calculate_totals();
            $order_id = $order->save();

            if ($order_id) {
                global $wpdb;
                $wpdb->delete($this->table_name, ['id' => $incomplete_order_id]);
            }
            
            wp_send_json_success([
                'message' => 'Order created successfully!',
                'order_id' => $order_id,
                'redirect_url' => admin_url('admin.php?page=oms-order-details&order_id=' . $order_id)
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    public function ajax_delete_incomplete_order() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!current_user_can('manage_woocommerce')) {
                throw new Exception('Permission denied.');
            }
            $incomplete_order_id = isset($_POST['incomplete_order_id']) ? absint($_POST['incomplete_order_id']) : 0;
            if (!$incomplete_order_id) {
                throw new Exception('Invalid ID.');
            }
            
            global $wpdb;
            $result = $wpdb->delete($this->table_name, ['id' => $incomplete_order_id]);
            
            if ($result === false) {
                 throw new Exception('Database error occurred.');
            }

            wp_send_json_success([
                'message' => 'Incomplete order record deleted successfully.',
                'redirect_url' => admin_url('admin.php?page=oms-incomplete-list')
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function ajax_add_incomplete_order_note() {
        check_ajax_referer('oms_ajax_nonce', 'nonce');
        try {
            if (!current_user_can('edit_shop_orders')) {
                throw new Exception('Permission denied.');
            }
            $incomplete_order_id = isset($_POST['incomplete_order_id']) ? absint($_POST['incomplete_order_id']) : 0;
            $note = isset($_POST['note']) ? wp_kses_post(trim($_POST['note'])) : '';

            if (empty($incomplete_order_id)) {
                throw new Exception('Missing required data.');
            }

            global $wpdb;
            $existing_data_serialized = $wpdb->get_var($wpdb->prepare(
                "SELECT customer_data FROM $this->table_name WHERE id = %d",
                $incomplete_order_id
            ));

            if (!$existing_data_serialized) {
                throw new Exception('Invalid incomplete order.');
            }

            $customer_data = unserialize($existing_data_serialized);
            $customer_data['order_comments'] = $note; // Update the note
            
            $wpdb->update(
                $this->table_name,
                ['customer_data' => serialize($customer_data)],
                ['id' => $incomplete_order_id]
            );

            wp_send_json_success(['message' => 'Note updated successfully.']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
}

new OMS_Incomplete_Orders();

