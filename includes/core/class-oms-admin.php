<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for the admin area.
 */
class OMS_Admin {

    public function __construct() {
        // Intentionally left blank.
    }

    /**
     * Register all hooks for the admin area.
     */
    public function load_hooks() {
        add_action('admin_menu', [$this, 'plugin_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'bulk_update_admin_notice']);
    }

    /**
     * Register the administration menu for this plugin into the WordPress Dashboard.
     */
    public function plugin_admin_menu() {
        $main_page = add_menu_page('Order Summary', 'Order', 'manage_options', 'order-management-summary', [$this, 'render_summary_page'], 'dashicons-cart', 25);
        $pages = [
            $main_page,
            add_submenu_page('order-management-summary', 'Summary', 'Summary', 'manage_options', 'order-management-summary', [$this, 'render_summary_page']),
            $order_list_page = add_submenu_page('order-management-summary', 'Order List', 'Order List', 'manage_options', 'oms-order-list', [$this, 'render_order_list_page']),
            $add_order_page = add_submenu_page('order-management-summary', 'Add Order', 'Add Order', 'manage_options', 'oms-add-order', [$this, 'render_add_order_page']),
            $incomplete_list_page = add_submenu_page('order-management-summary', 'Incomplete List', 'Incomplete List', 'manage_options', 'oms-incomplete-list', [$this, 'render_incomplete_list_page']),
            add_submenu_page('order-management-summary', 'Settings', 'Settings', 'manage_options', 'oms-settings', [$this, 'render_settings_page']),
            $order_details_page = add_submenu_page(null, 'Order Details', 'Order Details', 'manage_options', 'oms-order-details', [$this, 'render_order_details_page']),
            $incomplete_order_details_page = add_submenu_page(null, 'Incomplete Order Details', 'Incomplete Order Details', 'manage_options', 'oms-incomplete-order-details', [$this, 'render_incomplete_details_page'])
        ];
        
        foreach ($pages as $page) { 
            if ($page) { 
                add_action("admin_print_styles-{$page}", [$this, 'enqueue_styles']);
                add_action("admin_head-{$page}", [$this, 'hide_notices_css']); 
            } 
        }

        $details_scripts_pages = [$add_order_page, $order_details_page, $incomplete_order_details_page];
        foreach ($details_scripts_pages as $page) { 
            if ($page) add_action("admin_print_scripts-{$page}", [$this, 'enqueue_order_details_scripts']); 
        }
        
        if ($order_list_page) { 
            add_action("admin_print_scripts-{$order_list_page}", [$this, 'enqueue_order_list_scripts']); 
            add_action("load-{$order_list_page}", [$this, 'handle_bulk_actions']); 
        }

        $status_style_pages = [$order_list_page, $order_details_page, $incomplete_order_details_page, $incomplete_list_page];
        foreach($status_style_pages as $page) { 
            if ($page) add_action("admin_head-{$page}", [$this, 'inject_custom_status_styles']); 
        }
    }

    /**
     * Enqueue styles for the admin area.
     */
    public function enqueue_styles() {
        wp_enqueue_style('oms-admin-style', OMS_PLUGIN_URL . 'assets/css/admin-style.css', [], OMS_VERSION);
    }

    /**
     * Enqueue scripts for the order details/add pages.
     */
    public function enqueue_order_details_scripts() {
        wp_enqueue_script('oms-order-details-js', OMS_PLUGIN_URL . 'assets/js/admin-order-details.js', [], OMS_VERSION, true);
        $data_to_pass = ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('oms_ajax_nonce'), 'allowed_statuses' => []];
        if (isset($_GET['page'], $_GET['order_id']) && $_GET['page'] === 'oms-order-details' && ($order = wc_get_order(absint($_GET['order_id'])))) {
            $allowed_slugs = OMS_Helpers::get_allowed_next_statuses($order->get_status());
            $all_statuses = wc_get_order_statuses();
            foreach ($allowed_slugs as $slug) { if (isset($all_statuses['wc-' . $slug])) $data_to_pass['allowed_statuses'][$slug] = $all_statuses['wc-' . $slug]; }
        }
        wp_localize_script('oms-order-details-js', 'oms_order_details', $data_to_pass);
    }

    /**
     * Enqueue scripts for the order list page.
     */
    public function enqueue_order_list_scripts() {
        wp_enqueue_script('oms-order-list-js', OMS_PLUGIN_URL . 'assets/js/admin-order-list.js', ['jquery'], OMS_VERSION, true);
        wp_localize_script('oms-order-list-js', 'oms_order_list_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('oms_ajax_nonce')]);
    }
    
    /**
     * Injects CSS for custom statuses into the admin head.
     */
    public function inject_custom_status_styles() {
        $styles = ['no-response'=>'#7f8c8d','shipped'=>'#2980b9','delivered'=>'#27ae60','returned'=>'#c0392b','partial-return'=>'#d35400','warehouse'=>'#8e44ad','exchange'=>'#16a085','ready-to-ship'=>'#f39c12'];
        echo '<style>';
        foreach ($styles as $slug => $color) { echo '.oms-status-badge.status-'.esc_attr($slug).', .oms-status-button.status-'.esc_attr($slug).' { background-color: '.esc_attr($color).' !important; }'; }
        echo '</style>';
    }
    
    /**
     * Hides non-plugin notices from plugin pages.
     */
    public function hide_notices_css() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'oms-') !== false || strpos($screen->id, 'order-management') !== false) { 
            echo '<style>.notice:not(.oms-notice), .updated, .update-nag, #wp__notice-list .notice:not(.oms-notice) { display: none !important; }</style>'; 
        }
    }
    
    /**
     * Register the settings for the plugin.
     */
    public function register_settings() {
        $settings_group = 'oms_settings_group';
        register_setting($settings_group, 'oms_default_courier', ['sanitize_callback' => 'sanitize_text_field']);
        add_settings_section('oms_general_section', 'General Settings', null, 'oms-settings');
        add_settings_field('oms_default_courier_field', 'Select Default Courier', [$this, 'default_courier_callback'], 'oms-settings', 'oms_general_section');
        register_setting($settings_group, 'oms_workflow_enabled', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'yes']);
        add_settings_section('oms_workflow_section', 'Workflow Settings', null, 'oms-settings');
        add_settings_field('oms_workflow_enabled_field', 'Enable Status Workflow', [$this, 'workflow_enabled_callback'], 'oms-settings', 'oms_workflow_section');
    }
    
    /**
     * Callback for the default courier setting field.
     */
    public function default_courier_callback() {
        $couriers = OMS_Helpers::get_couriers();
        $default_courier = get_option('oms_default_courier');
        echo '<select name="oms_default_courier" class="regular-text"><option value="">-- None --</option>';
        if (!empty($couriers)) { 
            foreach($couriers as $courier) { 
                echo '<option value="' . esc_attr($courier['id']) . '" ' . selected($courier['id'], $default_courier, false) . '>' . esc_html($courier['name']) . '</option>'; 
            } 
        }
        echo '</select><p class="description">Select the default courier for new orders and for sending from the list page.</p>';
    }
    
    /**
     * Callback for the workflow enabled setting field.
     */
    public function workflow_enabled_callback() {
        $option = get_option('oms_workflow_enabled', 'yes');
        echo '<label class="oms-switch"><input type="checkbox" name="oms_workflow_enabled" value="yes" ' . checked('yes', $option, false) . '><span class="oms-slider round"></span></label><p class="description">When enabled, the custom order status workflow rules will be enforced.</p>';
    }

    /**
     * Handles bulk actions from the order list page.
     */
    public function handle_bulk_actions() {
        if (isset($_POST['oms_bulk_action_nonce']) && wp_verify_nonce($_POST['oms_bulk_action_nonce'], 'oms_bulk_actions')) {
            $action = sanitize_key($_POST['action'] ?? $_POST['action2'] ?? '');
            $order_ids = isset($_POST['order_ids']) ? array_map('absint', $_POST['order_ids']) : [];
            if ($action && $action !== '-1' && !empty($order_ids)) {
                $updated = 0; $skipped = 0;
                foreach ($order_ids as $order_id) { 
                    if ($order = wc_get_order($order_id)) { 
                        OMS_Helpers::is_valid_status_transition($order->get_status(), $action) ? ($order->update_status($action) and $updated++) : $skipped++; 
                    } 
                }
                wp_safe_redirect(add_query_arg(['page' => 'oms-order-list', 'tab' => sanitize_text_field($_POST['tab']), 'bulk_update_success' => 1, 'updated' => $updated, 'skipped' => $skipped], admin_url('admin.php')));
                exit;
            }
        }
    }
    
    /**
     * Displays admin notices for bulk updates.
     */
    public function bulk_update_admin_notice() {
        if (isset($_GET['page'], $_GET['bulk_update_success']) && $_GET['page'] === 'oms-order-list') {
            if ($updated = absint($_GET['updated'] ?? 0)) { 
                printf('<div class="oms-notice notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(_n('%d order updated.', '%d orders updated.', $updated), $updated))); 
            }
            if ($skipped = absint($_GET['skipped'] ?? 0)) { 
                printf('<div class="oms-notice notice notice-warning is-dismissible"><p>%s</p></div>', esc_html(sprintf(_n('%d order skipped due to workflow rules.', '%d orders skipped due to workflow rules.', $skipped), $skipped))); 
            }
        }
    }
    
    /**
     * Renders the summary page.
     */
    public function render_summary_page() { require_once OMS_PLUGIN_DIR . 'views/summary-page.php'; }
    public function render_order_list_page() { require_once OMS_PLUGIN_DIR . 'views/order-list-page.php'; }
    public function render_add_order_page() { require_once OMS_PLUGIN_DIR . 'views/add-order-page.php'; }
    public function render_settings_page() { require_once OMS_PLUGIN_DIR . 'views/settings-page.php'; }
    public function render_order_details_page() { require_once OMS_PLUGIN_DIR . 'views/order-details-page.php'; }
    public function render_incomplete_list_page() { require_once OMS_PLUGIN_DIR . 'views/incomplete-list-page.php'; }
    public function render_incomplete_details_page() { require_once OMS_PLUGIN_DIR . 'views/incomplete-order-details-page.php'; }

}

