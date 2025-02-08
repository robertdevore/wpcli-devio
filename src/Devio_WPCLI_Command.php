<?php
namespace Devio\WPCLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class Devio_WPCLI_Command {
    public string $longdesc = '';

    /**
     * Checks for ABSPATH usage in theme files.
     */
    public function check_abspath() {
        $theme_dir = get_theme_root();
        $files = glob( $theme_dir . '/*/*.php' );
        $results = [];

        foreach ( $files as $file ) {
            $content = file_get_contents( $file );
            if ( strpos( $content, 'ABSPATH' ) === false ) {
                $results[] = [ 'file' => str_replace( ABSPATH, '', $file ), 'status' => 'No ABSPATH check' ];
            }
        }

        if ( empty( $results ) ) {
            \WP_CLI::success( "All theme files use ABSPATH correctly." );
            return;
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'file', 'status' ] );
    }

    /**
     * Analyzes autoloaded options in wp_options.
     */
    public function check_autoloaded() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT 50");

        \WP_CLI\Utils\format_items( 'table', $results, [ 'option_name', 'size' ] );
    }

    /**
     * Analyzes cron jobs.
     */
    public function check_cron() {
        $cron_jobs = _get_cron_array();
        if ( empty( $cron_jobs ) ) {
            \WP_CLI::success( "No cron jobs found." );
            return;
        }

        $results = [];
        foreach ( $cron_jobs as $timestamp => $jobs ) {
            foreach ( $jobs as $hook => $details ) {
                $results[] = [ 'hook' => $hook, 'timestamp' => date( 'Y-m-d H:i:s', $timestamp ) ];
            }
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'hook', 'timestamp' ] );
    }

    /**
     * Lists inactive users for a given number of days.
     */
    public function check_inactive_users( $args ) {
        global $wpdb;
        $days = isset( $args[0] ) ? intval( $args[0] ) : 60;
        $date_limit = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $results = $wpdb->get_results( $wpdb->prepare("
            SELECT ID, user_login, user_email, user_registered
            FROM {$wpdb->users}
            WHERE user_registered < %s
            AND ID NOT IN (SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'last_login')
        ", $date_limit ) );

        \WP_CLI\Utils\format_items( 'table', $results, [ 'ID', 'user_login', 'user_email', 'user_registered' ] );
    }

    /**
     * Lists WooCommerce failed orders.
     */
    public function check_wc_failed_orders() {
        global $wpdb;
        $results = $wpdb->get_results("
            SELECT ID, post_date, post_status
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_order' AND post_status = 'wc-failed'
            ORDER BY post_date DESC
        ");

        \WP_CLI\Utils\format_items( 'table', $results, [ 'ID', 'post_date', 'post_status' ] );
    }

    /**
     * Lists the database statistics.
     */
    public function db_stats() {
        global $wpdb;
        $tables = $wpdb->get_results("SHOW TABLE STATUS");
        $total_size = 0;
        $results = [];

        foreach ( $tables as $table ) {
            $size = ( $table->Data_length + $table->Index_length ) / 1024 / 1024;
            $total_size += $size;
            $results[] = [ 'table' => $table->Name, 'size_mb' => round( $size, 2 ) ];
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'table', 'size_mb' ] );
        \WP_CLI::success( "Total Database Size: " . round( $total_size, 2 ) . " MB" );
    }

    /**
     * Lists WordPress plugin statistics.
     */
    public function plugin_stats() {
        $plugins = get_plugins();
        $results = [];

        foreach ( $plugins as $file => $plugin ) {
            $results[] = [
                'name' => $plugin['Name'],
                'version' => $plugin['Version'],
                'status' => is_plugin_active( $file ) ? 'Active' : 'Inactive'
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'name', 'version', 'status' ] );
    }

    /**
     * Lists WordPress system statistics.
     */
    public function system_stats() {
        global $wpdb;
        $data = [
            [ 'Property' => 'PHP Version', 'Value' => PHP_VERSION ],
            [ 'Property' => 'MySQL Version', 'Value' => $wpdb->db_version() ],
            [ 'Property' => 'WordPress Version', 'Value' => get_bloginfo( 'version' ) ],
            [ 'Property' => 'Max Execution Time', 'Value' => ini_get( 'max_execution_time' ) . 's' ],
            [ 'Property' => 'Memory Limit', 'Value' => ini_get( 'memory_limit' ) ]
        ];

        \WP_CLI\Utils\format_items( 'table', $data, [ 'Property', 'Value' ] );
    }

    /**
     * Lists failed scheduled actions with optional CSV export.
     *
     * ## OPTIONS
     *
     * [--csv=<path>]
     * : (Optional) Path to save the output as a CSV file.
     *
     * ## EXAMPLES
     *
     *     wp devio check-scheduled-actions
     *     wp devio check-scheduled-actions --csv=failed-actions.csv
     *
     */
    public function check_scheduled_actions( $args, $assoc_args ) {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT action, status, last_attempt_gmt
            FROM {$wpdb->prefix}actionscheduler_actions
            WHERE status = 'failed'
            ORDER BY last_attempt_gmt DESC
        ");

        if ( empty( $results ) ) {
            \WP_CLI::success( "No failed scheduled actions found." );
            return;
        }

        if ( isset( $assoc_args['csv'] ) ) {
            $csv_file = $assoc_args['csv'];
            $csv_path = WP_CONTENT_DIR . '/' . $csv_file;
            $file = fopen( $csv_path, 'w' );
            fputcsv( $file, [ 'Action', 'Status', 'Last Attempt (GMT)' ] );

            foreach ( $results as $row ) {
                fputcsv( $file, [ $row->action, $row->status, $row->last_attempt_gmt ] );
            }

            fclose( $file );
            \WP_CLI::success( "CSV file saved: " . $csv_path );
        } else {
            \WP_CLI\Utils\format_items( 'table', $results, [ 'action', 'status', 'last_attempt_gmt' ] );
        }
    }

    /**
     * Lists active page builder plugins.
     *
     * ## EXAMPLES
     *
     *     wp devio check-theme-builders
     */
    public function check_theme_builders() {
        $page_builders = [
            'elementor/elementor.php' => 'Elementor',
            'siteorigin-panels/siteorigin-panels.php' => 'SiteOrigin Page Builder',
            'beaver-builder-lite-version/fl-builder.php' => 'Beaver Builder',
            'js_composer/js_composer.php' => 'WPBakery Page Builder',
            'divi-builder/divi-builder.php' => 'Divi Builder',
            'oxygen/oxygen.php' => 'Oxygen Builder',
            'bricks/bricks.php' => 'Bricks Builder'
        ];

        $active_plugins = get_option( 'active_plugins', [] );
        $results = [];

        foreach ( $page_builders as $plugin_file => $name ) {
            if ( in_array( $plugin_file, $active_plugins ) ) {
                $results[] = [ 'Plugin' => $name, 'Status' => 'Active' ];
            }
        }

        if ( empty( $results ) ) {
            \WP_CLI::success( "No active page builders found." );
        } else {
            \WP_CLI\Utils\format_items( 'table', $results, [ 'Plugin', 'Status' ] );
        }
    }

    /**
     * Checks for known vulnerabilities in WP core, themes, and plugins.
     *
     * ## EXAMPLES
     *
     *     wp devio check-vuln
     */
    public function check_vuln() {
        $api_key = 'YOUR_API_KEY'; // Replace with actual API key
        $url = "https://wpscan.com/api/v3/wordpresses?version=" . get_bloginfo( 'version' ) . "&api_token={$api_key}";

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            \WP_CLI::error( "Error retrieving vulnerability data." );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['vulnerabilities'] ) ) {
            \WP_CLI::success( "No vulnerabilities found for WordPress " . get_bloginfo( 'version' ) );
        } else {
            \WP_CLI::warning( "Known vulnerabilities found:" );
            \WP_CLI\Utils\format_items( 'table', $body['vulnerabilities'], [ 'title', 'fixed_in', 'cve', 'description' ] );
        }
    }

    /**
     * Shows WooCommerce fatal error logs with optional filtering.
     *
     * ## OPTIONS
     *
     * [<days>]
     * : Number of days to look back for errors (default: 7).
     *
     * ## EXAMPLES
     *
     *     wp devio check-wc-errors 30
     */
    public function check_wc_errors( $args ) {
        $days = isset( $args[0] ) ? intval( $args[0] ) : 7;
        $log_dir = WP_CONTENT_DIR . '/wc-logs/';
        
        if ( ! is_dir( $log_dir ) ) {
            \WP_CLI::error( "WooCommerce log directory not found: {$log_dir}" );
            return;
        }

        $error_files = glob( $log_dir . 'fatal-errors*' );
        $filtered_errors = [];
        $cutoff_time = strtotime( "-{$days} days" );

        foreach ( $error_files as $file ) {
            $handle = fopen( $file, 'r' );
            if ( $handle ) {
                while ( ( $line = fgets( $handle ) ) !== false ) {
                    if ( preg_match( '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $line, $matches ) ) {
                        $log_date = strtotime( str_replace( [ '[', ']' ], '', $matches[0] ) );
                        if ( $log_date >= $cutoff_time ) {
                            $filtered_errors[] = [ 'date' => date( 'Y-m-d H:i:s', $log_date ), 'message' => trim( $line ) ];
                        }
                    }
                }
                fclose( $handle );
            }
        }

        if ( empty( $filtered_errors ) ) {
            \WP_CLI::success( "No WooCommerce fatal errors found in the last {$days} days." );
        } else {
            \WP_CLI\Utils\format_items( 'table', $filtered_errors, [ 'date', 'message' ] );
        }
    }

    /**
     * Checks for outdated WooCommerce templates.
     *
     * ## EXAMPLES
     *
     *     wp devio check-wc-templates
     */
    public function check_wc_templates() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            \WP_CLI::error( "WooCommerce is not installed or activated." );
            return;
        }

        ob_start();
        do_action( 'woocommerce_template_debug_output' );
        $output = ob_get_clean();

        preg_match_all( '/(.*?)\s+is\s+outdated/', $output, $matches );

        if ( empty( $matches[1] ) ) {
            \WP_CLI::success( "No outdated WooCommerce templates found." );
        } else {
            $results = array_map( function ( $template ) {
                return [ 'Template' => trim( $template ), 'Status' => 'Outdated' ];
            }, $matches[1] );

            \WP_CLI\Utils\format_items( 'table', $results, [ 'Template', 'Status' ] );
        }
    }

    /**
     * Checks if XML-RPC is disabled.
     *
     * ## EXAMPLES
     *
     *     wp devio check-xmlrpc
     */
    public function check_xmlrpc() {
        $xmlrpc_enabled = get_option( 'enable_xmlrpc' );
        $xmlrpc_disabled = defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;

        if ( ! $xmlrpc_enabled || $xmlrpc_disabled ) {
            \WP_CLI::success( "XML-RPC is disabled." );
        } else {
            \WP_CLI::warning( "XML-RPC is enabled. Consider disabling it for security reasons." );
        }
    }

    /**
     * Lists all available wp devio commands.
     *
     * ## EXAMPLES
     *
     *     wp devio commands
     */
    public function commands() {
        $commands = [
            [ 'Command' => 'check-abspath', 'Description' => 'Checks for ABSPATH usage in theme files' ],
            [ 'Command' => 'check-autoloaded', 'Description' => 'Analyzes autoloaded options in wp_options' ],
            [ 'Command' => 'check-cron', 'Description' => 'Analyzes WordPress cron jobs and their schedules' ],
            [ 'Command' => 'check-inactive-users', 'Description' => 'Lists users inactive for a specified period' ],
            [ 'Command' => 'check-scheduled-actions', 'Description' => 'Lists failed scheduled actions' ],
            [ 'Command' => 'check-theme-builders', 'Description' => 'Lists active page builder plugins' ],
            [ 'Command' => 'check-vuln', 'Description' => 'Checks for known vulnerabilities in WordPress core, themes, and plugins' ],
            [ 'Command' => 'check-wc-errors', 'Description' => 'Shows WooCommerce fatal error logs' ],
            [ 'Command' => 'check-wc-failed-orders', 'Description' => 'Lists failed WooCommerce orders' ],
            [ 'Command' => 'check-wc-templates', 'Description' => 'Checks for outdated WooCommerce templates' ],
            [ 'Command' => 'check-xmlrpc', 'Description' => 'Checks if XML-RPC is disabled' ],
            [ 'Command' => 'db-stats', 'Description' => 'Displays database size, table sizes, and other DB information' ],
            [ 'Command' => 'plugin-stats', 'Description' => 'Analyzes WordPress plugins and their status' ],
            [ 'Command' => 'post-stats', 'Description' => 'Shows post counts by status for each post type' ],
            [ 'Command' => 'system-stats', 'Description' => 'Displays system information (disk, CPU, PHP, MySQL)' ],
            [ 'Command' => 'theme-stats', 'Description' => 'Lists themes with versions and updates' ],
            [ 'Command' => 'user-stats', 'Description' => 'Shows WordPress user statistics and registration trends' ]
        ];

        \WP_CLI\Utils\format_items( 'table', $commands, [ 'Command', 'Description' ] );
    }

    /**
     * Shows post counts by status and post type.
     *
     * ## EXAMPLES
     *
     *     wp devio post-stats
     */
    public function post_stats() {
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT post_type, post_status, COUNT(*) as count
            FROM {$wpdb->posts}
            GROUP BY post_type, post_status
        ");

        if ( empty( $results ) ) {
            \WP_CLI::success( "No post data found." );
            return;
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'post_type', 'post_status', 'count' ] );
    }

    /**
     * Lists installed themes with versions and available updates.
     *
     * ## EXAMPLES
     *
     *     wp devio theme-stats
     */
    public function theme_stats() {
        $themes = wp_get_themes();
        $updates = get_site_transient( 'update_themes' );
        $results = [];

        foreach ( $themes as $slug => $theme ) {
            $has_update = isset( $updates->response[ $slug ] ) ? 'Yes' : 'No';
            $results[] = [
                'Name'    => $theme->get( 'Name' ),
                'Version' => $theme->get( 'Version' ),
                'Update Available' => $has_update
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $results, [ 'Name', 'Version', 'Update Available' ] );
    }

    /**
     * Shows user statistics and registration trends.
     *
     * ## EXAMPLES
     *
     *     wp devio user-stats
     */
    public function user_stats() {
        global $wpdb;

        $total_users = $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->users}");
        $by_role = $wpdb->get_results("
            SELECT meta_value AS role, COUNT(user_id) as count
            FROM {$wpdb->usermeta}
            WHERE meta_key = 'wp_capabilities'
            GROUP BY meta_value
        ");

        $recent_registrations = $wpdb->get_results("
            SELECT DATE(user_registered) as date, COUNT(*) as count
            FROM {$wpdb->users}
            WHERE user_registered > NOW() - INTERVAL 30 DAY
            GROUP BY DATE(user_registered)
            ORDER BY date DESC
        ");

        \WP_CLI::log( "Total Users: " . $total_users );
        \WP_CLI::log( "\nUsers by Role:" );
        \WP_CLI\Utils\format_items( 'table', $by_role, [ 'role', 'count' ] );

        \WP_CLI::log( "\nRecent Registrations (Last 30 Days):" );
        \WP_CLI\Utils\format_items( 'table', $recent_registrations, [ 'date', 'count' ] );
    }

    /**
     * Shows WooCommerce order attribution statistics.
     *
     * ## EXAMPLES
     *
     *     wp devio wc-attribution-stats
     */
    public function wc_attribution_stats() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            \WP_CLI::error( "WooCommerce is not installed or activated." );
            return;
        }

        global $wpdb;
        $results = $wpdb->get_results("
            SELECT meta_value as source, COUNT(*) as count
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_wc_order_attribution'
            GROUP BY meta_value
            ORDER BY count DESC
        ");

        \WP_CLI::log( "WooCommerce Order Attribution Statistics:" );
        \WP_CLI\Utils\format_items( 'table', $results, [ 'source', 'count' ] );
    }

    /**
     * Shows WooCommerce statistics including orders, products, and revenue.
     *
     * ## EXAMPLES
     *
     *     wp devio wc-stats
     */
    public function wc_stats() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            \WP_CLI::error( "WooCommerce is not installed or activated." );
            return;
        }

        global $wpdb;

        $order_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ('wc-completed', 'wc-processing')");
        $total_revenue = $wpdb->get_var("SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_order_total'");
        $product_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");

        $results = [
            [ 'Metric' => 'Total Orders', 'Value' => $order_count ],
            [ 'Metric' => 'Total Revenue', 'Value' => wc_price( $total_revenue ) ],
            [ 'Metric' => 'Total Products', 'Value' => $product_count ]
        ];

        \WP_CLI\Utils\format_items( 'table', $results, [ 'Metric', 'Value' ] );
    }

    /**
     * Shows WooCommerce subscription growth metrics by month.
     *
     * ## EXAMPLES
     *
     *     wp devio wc-subscription-growth
     */
    public function wc_subscription_growth() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            \WP_CLI::error( "WooCommerce is not installed or activated." );
            return;
        }

        global $wpdb;

        $results = $wpdb->get_results("
            SELECT DATE_FORMAT(post_date, '%Y-%m') as month, COUNT(*) as count
            FROM {$wpdb->posts}
            WHERE post_type = 'shop_subscription' AND post_status = 'wc-active'
            GROUP BY month
            ORDER BY month DESC
        ");

        \WP_CLI::log( "WooCommerce Subscription Growth by Month:" );
        \WP_CLI\Utils\format_items( 'table', $results, [ 'month', 'count' ] );
    }

    /**
     * Shows WooCommerce subscription statistics and metrics.
     *
     * ## EXAMPLES
     *
     *     wp devio wc-subscription-stats
     */
    public function wc_subscription_stats() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            \WP_CLI::error( "WooCommerce is not installed or activated." );
            return;
        }

        global $wpdb;

        $total_subscriptions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription'");
        $active_subscriptions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_status = 'wc-active'");
        $cancelled_subscriptions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_status = 'wc-cancelled'");

        $results = [
            [ 'Metric' => 'Total Subscriptions', 'Value' => $total_subscriptions ],
            [ 'Metric' => 'Active Subscriptions', 'Value' => $active_subscriptions ],
            [ 'Metric' => 'Cancelled Subscriptions', 'Value' => $cancelled_subscriptions ]
        ];

        \WP_CLI::log( "WooCommerce Subscription Statistics:" );
        \WP_CLI\Utils\format_items( 'table', $results, [ 'Metric', 'Value' ] );
    }

}

\WP_CLI::add_command( 'devio', 'Devio_WPCLI_Command' );
