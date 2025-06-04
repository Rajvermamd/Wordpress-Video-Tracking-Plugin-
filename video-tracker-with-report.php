<?php
/**
 * Plugin Name: Enhanced Video Tracker with Report & Export
 * Description: Track multiple YouTube and self-hosted videos watched by logged-in users with comprehensive admin report and export functionality.
 * Version: 3.1
 * Author: Raj Verma
 */

if (!defined('ABSPATH')) exit;

// Create or update DB table on activation
register_activation_hook(__FILE__, 'vtr_create_table');
function vtr_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        video_id varchar(255) NOT NULL,
        percent int NOT NULL DEFAULT 0,
        last_watched datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        assessment_taken tinyint(1) NOT NULL DEFAULT 0,
        enrolment_date datetime DEFAULT NULL,
        session_name varchar(255) DEFAULT NULL,
        session_id varchar(255) DEFAULT NULL,
        full_duration varchar(8) DEFAULT '00:00:00',
        current_duration varchar(8) DEFAULT '00:00:00',
        status tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY video_id (video_id),
        KEY session_id (session_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);
    
    // Log the table creation result
    error_log('VTR: Table creation result: ' . print_r($result, true));
    
    // Verify table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    error_log('VTR: Table exists check: ' . ($table_exists ? 'Yes' : 'No'));
    
    // Store plugin version
    update_option('vtr_plugin_version', '3.1');
}

// Add debug function for troubleshooting
add_action('wp_ajax_vtr_debug_info', 'vtr_debug_info');
function vtr_debug_info() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    
    $debug_info = [];
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    $debug_info['table_exists'] = $table_exists ? true : false;
    
    if ($table_exists) {
        // Get table structure
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $debug_info['table_structure'] = $columns;
        
        // Get record count
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $debug_info['record_count'] = $count;
        
        // Get sample records
        $sample_records = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 5");
        $debug_info['sample_records'] = $sample_records;
    }
    
    $debug_info['current_user'] = get_current_user_id();
    $debug_info['plugin_version'] = get_option('vtr_plugin_version');
    
    wp_send_json_success($debug_info);
}

// Clean up on plugin deactivation
register_deactivation_hook(__FILE__, 'vtr_cleanup_plugin');
function vtr_cleanup_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    // Remove any options if you add them later
    delete_option('vtr_plugin_version');
}

// Enqueue scripts with page_id localized
add_action('wp_enqueue_scripts', function() {
    if (is_user_logged_in()) {
        wp_enqueue_script('video-tracker-js', plugin_dir_url(__FILE__) . 'js/video-tracker.js', ['jquery'], '1.0', true);
        wp_localize_script('video-tracker-js', 'vt_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'page_id' => get_the_ID(),
            'nonce' => wp_create_nonce('vtr_nonce'),
        ]);
    }
});

// AJAX handler to save progress
add_action('wp_ajax_save_video_progress', 'vtr_save_video_progress');
function vtr_save_video_progress() {
    // Enable error reporting for debugging
    error_log('VTR: Save progress called with data: ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vtr_nonce')) {
        error_log('VTR: Nonce verification failed');
        wp_send_json_error(['message' => 'Security check failed']);
    }

    if (!is_user_logged_in()) {
        error_log('VTR: User not logged in');
        wp_send_json_error(['message' => 'User not logged in']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';

    $user_id = get_current_user_id();
    $video_id = sanitize_text_field($_POST['video_id']);
    $percent = max(0, min(100, intval($_POST['percent'])));

    // Required session data
    $session_name = sanitize_text_field($_POST['session_name']);
    $session_id = sanitize_text_field($_POST['session_id']);

    // Validate required fields
    if (empty($video_id) || empty($session_id) || empty($session_name)) {
        error_log('VTR: Missing required fields - video_id: ' . $video_id . ', session_id: ' . $session_id . ', session_name: ' . $session_name);
        wp_send_json_error(['message' => 'Missing required fields']);
    }

    // Durations in "HH:MM:SS"
    $full_duration = isset($_POST['full_duration']) ? sanitize_text_field($_POST['full_duration']) : '00:00:00';
    $current_duration = isset($_POST['current_duration']) ? sanitize_text_field($_POST['current_duration']) : '00:00:00';

    // Get enrolment date (publish date) if session_id is a post ID
    $enrolment_date = null;
    if (!empty($session_id) && is_numeric($session_id)) {
        $post = get_post(intval($session_id));
        if ($post && $post->post_status === 'publish') {
            $enrolment_date = $post->post_date;
            error_log('VTR: Found enrolment date from post ' . $session_id . ': ' . $enrolment_date);
        } else {
            error_log('VTR: No valid post found for session_id: ' . $session_id);
        }
    } else {
        error_log('VTR: Session ID is not numeric: ' . $session_id);
    }

    // Calculate status based on percentage and enrolment date
    $status = vtr_calculate_status($percent, $enrolment_date);

    // Check for existing record
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND video_id = %s AND session_id = %s",
        $user_id, $video_id, $session_id
    ));

    error_log('VTR: Existing record found: ' . ($existing ? 'Yes (ID: ' . $existing->id . ')' : 'No'));

    $data = [
        'percent' => $percent,
        'last_watched' => current_time('mysql'),
        'assessment_taken' => 0, // default No
        'session_name' => $session_name,
        'session_id' => $session_id,
        'full_duration' => $full_duration,
        'current_duration' => $current_duration,
        'status' => $status,
        'enrolment_date' => $enrolment_date, // Always include this field
    ];

    $result = false;
    
    if ($existing) {
        // Only update if progress increased or status changed
        if ($percent >= $existing->percent || $status != $existing->status) {
            $result = $wpdb->update($table_name, $data, ['id' => $existing->id]);
            error_log('VTR: Updated existing record. Result: ' . ($result !== false ? 'Success' : 'Failed'));
            if ($result === false) {
                error_log('VTR: Update error: ' . $wpdb->last_error);
            }
        } else {
            $result = true; // No update needed but not an error
            error_log('VTR: No update needed - progress not increased');
        }
    } else {
        $data['user_id'] = $user_id;
        $data['video_id'] = $video_id;
        $result = $wpdb->insert($table_name, $data);
        error_log('VTR: Inserted new record. Result: ' . ($result !== false ? 'Success (ID: ' . $wpdb->insert_id . ')' : 'Failed'));
        if ($result === false) {
            error_log('VTR: Insert error: ' . $wpdb->last_error);
        }
    }

    if ($result !== false) {
        wp_send_json_success([
            'message' => 'Progress saved', 
            'status' => $status,
            'debug' => [
                'existing' => $existing ? true : false,
                'percent' => $percent,
                'user_id' => $user_id,
                'video_id' => $video_id,
                'session_id' => $session_id
            ]
        ]);
    } else {
        error_log('VTR: Database operation failed');
        wp_send_json_error(['message' => 'Failed to save progress', 'db_error' => $wpdb->last_error]);
    }
}

// Calculate status based on percentage and enrolment date
function vtr_calculate_status($percent, $enrolment_date = null) {
    // Check if overdue (enrolment date > 2 days ago)
    if ($enrolment_date) {
        $enrolment_timestamp = strtotime($enrolment_date);
        $two_days_ago = strtotime('-2 days');
        if ($enrolment_timestamp < $two_days_ago) {
            return 3; // Overdue
        }
    }

    // Status based on percentage
    if ($percent == 0) {
        return 0; // Not Started
    } elseif ($percent > 0 && $percent < 100) {
        return 1; // In Progress
    } elseif ($percent == 100) {
        return 2; // Completed
    }

    return 0; // Default to Not Started
}

// Get status label
function vtr_get_status_label($status) {
    $statuses = [
        0 => 'Not Started',
        1 => 'In Progress',
        2 => 'Completed',
        3 => 'Overdue'
    ];
    return isset($statuses[$status]) ? $statuses[$status] : 'Unknown';
}

// Add admin menu page
add_action('admin_menu', function() {
    add_menu_page(
        'Video Watch Reports', 
        'Video Reports', 
        'manage_options', 
        'video-watch-reports', 
        'vtr_render_report_page',
        'dashicons-video-alt3',
        30
    );
});

// AJAX handlers for admin actions
add_action('wp_ajax_vtr_delete_record', 'vtr_ajax_delete_record');
add_action('wp_ajax_vtr_update_record', 'vtr_ajax_update_record');
add_action('wp_ajax_vtr_get_record', 'vtr_ajax_get_record');

function vtr_ajax_delete_record() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
        return;
    }

    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vtr_admin_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    // Validate record ID
    if (!isset($_POST['record_id']) || empty($_POST['record_id'])) {
        wp_send_json_error(['message' => 'Record ID is required']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    $record_id = intval($_POST['record_id']);

    // Check if record exists
    $record_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE id = %d", $record_id
    ));

    if (!$record_exists) {
        wp_send_json_error(['message' => 'Record not found']);
        return;
    }

    $result = $wpdb->delete($table_name, ['id' => $record_id], ['%d']);
    
    if ($result !== false) {
        wp_send_json_success(['message' => 'Record deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete record: ' . $wpdb->last_error]);
    }
}

function vtr_ajax_get_record() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
        return;
    }

    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vtr_admin_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    // Validate record ID
    if (!isset($_POST['record_id']) || empty($_POST['record_id'])) {
        wp_send_json_error(['message' => 'Record ID is required']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    $record_id = intval($_POST['record_id']);

    $record = $wpdb->get_row($wpdb->prepare(
        "SELECT vp.*, u.user_login, u.user_email FROM $table_name vp
         LEFT JOIN {$wpdb->users} u ON vp.user_id = u.ID
         WHERE vp.id = %d",
        $record_id
    ));

    if ($record) {
        wp_send_json_success($record);
    } else {
        wp_send_json_error(['message' => 'Record not found']);
    }
}

function vtr_ajax_update_record() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
        return;
    }

    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vtr_admin_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    // Validate required fields
    if (!isset($_POST['record_id']) || empty($_POST['record_id'])) {
        wp_send_json_error(['message' => 'Record ID is required']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    
    $record_id = intval($_POST['record_id']);
    $percent = isset($_POST['percent']) ? max(0, min(100, intval($_POST['percent']))) : 0;
    $assessment_taken = isset($_POST['assessment_taken']) ? intval($_POST['assessment_taken']) : 0;
    $current_duration = isset($_POST['current_duration']) ? sanitize_text_field($_POST['current_duration']) : '00:00:00';
    $full_duration = isset($_POST['full_duration']) ? sanitize_text_field($_POST['full_duration']) : '00:00:00';

    // Check if record exists and get current record to check enrolment date for status calculation
    $current_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d", $record_id
    ));

    if (!$current_record) {
        wp_send_json_error(['message' => 'Record not found']);
        return;
    }

    $status = vtr_calculate_status($percent, $current_record->enrolment_date);

    $result = $wpdb->update(
        $table_name,
        [
            'percent' => $percent,
            'assessment_taken' => $assessment_taken,
            'current_duration' => $current_duration,
            'full_duration' => $full_duration,
            'status' => $status,
            'last_watched' => current_time('mysql'),
        ],
        ['id' => $record_id],
        ['%d', '%d', '%s', '%s', '%d', '%s'],
        ['%d']
    );

    if ($result !== false) {
        wp_send_json_success(['message' => 'Record updated successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to update record: ' . $wpdb->last_error]);
    }
}

// Function to get filtered data for export
function vtr_get_filtered_data($search_user = '', $search_session = '', $filter_status = -1, $limit = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';

    $query = "SELECT vp.*, u.user_login, u.user_email FROM $table_name vp
              LEFT JOIN {$wpdb->users} u ON vp.user_id = u.ID
              WHERE 1=1 ";

    $params = [];

    if ($search_user) {
        $query .= " AND (u.user_login LIKE %s OR u.user_email LIKE %s) ";
        $params[] = "%$search_user%";
        $params[] = "%$search_user%";
    }
    if ($search_session) {
        $query .= " AND vp.session_name LIKE %s ";
        $params[] = "%$search_session%";
    }
    if ($filter_status >= 0) {
        $query .= " AND vp.status = %d ";
        $params[] = $filter_status;
    }
    
    $query .= " ORDER BY vp.last_watched DESC";
    
    if ($limit) {
        $query .= " LIMIT " . intval($limit);
    }

    if (!empty($params)) {
        return $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        return $wpdb->get_results($query);
    }
}

// Export handlers
add_action('init', 'vtr_handle_export_requests');
function vtr_handle_export_requests() {
    if (!isset($_GET['vtr_export']) || !current_user_can('manage_options')) {
        return;
    }

    // Verify nonce
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'vtr_export_nonce')) {
        wp_die('Security check failed');
    }

    $format = sanitize_text_field($_GET['vtr_export']);
    $search_user = isset($_GET['search_user']) ? sanitize_text_field($_GET['search_user']) : '';
    $search_session = isset($_GET['search_session']) ? sanitize_text_field($_GET['search_session']) : '';
    $filter_status = isset($_GET['filter_status']) ? intval($_GET['filter_status']) : -1;

    // Get filtered data
    $results = vtr_get_filtered_data($search_user, $search_session, $filter_status);

    if (empty($results)) {
        wp_die('No data found to export');
    }

    $filename = 'video-watch-report-' . date('Y-m-d-H-i-s');

    switch ($format) {
        case 'csv':
            vtr_export_csv($results, $filename);
            break;
        case 'excel':
            vtr_export_excel($results, $filename);
            break;
        case 'json':
            vtr_export_json($results, $filename);
            break;
        default:
            wp_die('Invalid export format');
    }
}

// CSV Export
function vtr_export_csv($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    
    // CSV headers
    $headers = [
        'ID',
        'User ID',
        'Username',
        'Email',
        'Video ID',
        'Session Name',
        'Session ID',
        'Progress (%)',
        'Training Status',
        'Assessment Taken',
        'Total Minutes Consumed',
        'Total Duration',
        'Last Watched',
        'Enrolment Date',
        // 'Session Publish Date'
    ];
    
    fputcsv($output, $headers);
    
    // CSV data
    foreach ($data as $row) {
        $csv_row = [
            $row->id,
            $row->user_id,
            $row->user_login ?: 'N/A',
            $row->user_email ?: 'N/A',
            $row->video_id,
            $row->session_name,
            $row->session_id,
            $row->percent,
            vtr_get_status_label($row->status),
            $row->assessment_taken ? 'Yes' : 'No',
            $row->current_duration,
            $row->full_duration,
            $row->last_watched,
            $row->created_at
            // $row->enrolment_date ?: 'N/A',
        ];
        fputcsv($output, $csv_row);
    }
    
    fclose($output);
    exit;
}

// Excel Export (as HTML table that Excel can read)
function vtr_export_excel($data, $filename) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<table border="1">';
    echo '<tr style="background-color: #f0f0f0; font-weight: bold;">';
    echo '<td>ID</td>';
    echo '<td>User ID</td>';
    echo '<td>Username</td>';
    echo '<td>Email</td>';
    echo '<td>Video ID</td>';
    echo '<td>Session Name</td>';
    echo '<td>Session ID</td>';
    echo '<td>Progress (%)</td>';
    echo '<td>Training Status</td>';
    echo '<td>Assessment Taken</td>';
    echo '<td>Total Minutes Consumed</td>';
    echo '<td>Total Duration</td>';
    echo '<td>Last Watched</td>';
    echo '<td>Enrolment Date</td>';
    // echo '<td>Created At</td>';
    echo '</tr>';

    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row->id) . '</td>';
        echo '<td>' . esc_html($row->user_id) . '</td>';
        echo '<td>' . esc_html($row->user_login ?: 'N/A') . '</td>';
        echo '<td>' . esc_html($row->user_email ?: 'N/A') . '</td>';
        echo '<td>' . esc_html($row->video_id) . '</td>';
        echo '<td>' . esc_html($row->session_name) . '</td>';
        echo '<td>' . esc_html($row->session_id) . '</td>';
        echo '<td>' . esc_html($row->percent) . '</td>';
        echo '<td>' . esc_html(vtr_get_status_label($row->status)) . '</td>';
        echo '<td>' . esc_html($row->assessment_taken ? 'Yes' : 'No') . '</td>';
        echo '<td>' . esc_html($row->current_duration) . '</td>';
        echo '<td>' . esc_html($row->full_duration) . '</td>';
        echo '<td>' . esc_html($row->last_watched) . '</td>';
        echo '<td>' . esc_html($row->created_at) . '</td>';
        // echo '<td>' . esc_html($row->enrolment_date ?: 'N/A') . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

// JSON Export
function vtr_export_json($data, $filename) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $json_data = [];
    foreach ($data as $row) {
        $json_data[] = [
            'id' => $row->id,
            'user_id' => $row->user_id,
            'username' => $row->user_login ?: null,
            'email' => $row->user_email ?: null,
            'video_id' => $row->video_id,
            'session_name' => $row->session_name,
            'session_id' => $row->session_id,
            'progress_percent' => $row->percent,
            'training_status' => vtr_get_status_label($row->status),
            'status_code' => $row->status,
            'assessment_taken' => (bool)$row->assessment_taken,
            'total_minutes_consumed' => $row->current_duration,
            'total_duration' => $row->full_duration,
            'last_watched' => $row->last_watched,
            'enrolment_date' => $row->created_at
            // 'enrolment_date' => $row->enrolment_date,
        ];
    }

    echo json_encode([
        'export_date' => current_time('mysql'),
        'total_records' => count($json_data),
        'data' => $json_data
    ], JSON_PRETTY_PRINT);
    exit;
}

function vtr_render_report_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';

    // Search filters
    $search_user = isset($_GET['search_user']) ? sanitize_text_field($_GET['search_user']) : '';
    $search_session = isset($_GET['search_session']) ? sanitize_text_field($_GET['search_session']) : '';
    $filter_status = isset($_GET['filter_status']) ? intval($_GET['filter_status']) : -1;

    // Get filtered results with limit for display
    $results = vtr_get_filtered_data($search_user, $search_session, $filter_status, 100);

    // Generate export URLs
    $export_params = [
        'search_user' => $search_user,
        'search_session' => $search_session,
        'filter_status' => $filter_status,
        'nonce' => wp_create_nonce('vtr_export_nonce')
    ];

    $csv_url = add_query_arg(array_merge($export_params, ['vtr_export' => 'csv']), admin_url('admin.php'));
    $excel_url = add_query_arg(array_merge($export_params, ['vtr_export' => 'excel']), admin_url('admin.php'));
    $json_url = add_query_arg(array_merge($export_params, ['vtr_export' => 'json']), admin_url('admin.php'));

    ?>
    <div class="wrap">
        <h1>Video Watch Progress Reports</h1>

        <!-- Debug Section -->
        <div id="debug-section" style="margin-bottom: 20px;">
            <button id="debug-btn" class="button">Show Debug Info</button>
            <div id="debug-info" style="display:none; background:#f0f0f0; padding:15px; margin-top:10px; border:1px solid #ddd;">
                <h3>Debug Information</h3>
                <pre id="debug-content"></pre>
            </div>
        </div>

        <!-- Export Section -->
        <div style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin-top: 0;">Export Data</h3>
            <p>Export current filtered results in your preferred format:</p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url($csv_url); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-media-spreadsheet" style="margin-right: 5px;"></span>
                    Export as CSV
                </a>
                <a href="<?php echo esc_url($excel_url); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-media-spreadsheet" style="margin-right: 5px;"></span>
                    Export as Excel
                </a>
                <a href="<?php echo esc_url($json_url); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-media-code" style="margin-right: 5px;"></span>
                    Export as JSON
                </a>
            </div>
            <small style="color: #666; display: block; margin-top: 10px;">
                Note: Exports include all records matching current filters (not limited to 100 shown below).
            </small>
        </div>

        <!-- Search Form -->
        <form method="get" style="margin-bottom:20px; background:#fff; padding:15px; border:1px solid #ddd;">
            <input type="hidden" name="page" value="video-watch-reports" />
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="text" name="search_user" placeholder="Search by username or email" value="<?php echo esc_attr($search_user); ?>" />
                <input type="text" name="search_session" placeholder="Search by session name" value="<?php echo esc_attr($search_session); ?>" />
                <select name="filter_status">
                    <option value="-1">All Training Status</option>
                    <option value="0" <?php selected($filter_status, 0); ?>>Not Started</option>
                    <option value="1" <?php selected($filter_status, 1); ?>>In Progress</option>
                    <option value="2" <?php selected($filter_status, 2); ?>>Completed</option>
                    <option value="3" <?php selected($filter_status, 3); ?>>Overdue</option>
                </select>
                <input type="submit" class="button button-primary" value="Search" />
                <a href="<?php echo admin_url('admin.php?page=video-watch-reports'); ?>" class="button">Reset</a>
            </div>
        </form>

        <?php if(empty($results)) : ?>
            <div class="notice notice-info"><p>No records found.</p></div>
        <?php else: ?>
            <div style="margin-bottom: 10px;">
                <small style="color: #666;">
                    Showing up to 100 records. Use export options above to get all filtered data.
                </small>
            </div>
            <table class="widefat fixed striped" cellspacing="0">
                <thead>
                    <tr>
                        <th style="width:60px;">User ID</th>
                        <th style="width:120px;">Username</th>
                        <th style="width:150px;">Email</th>
                        <th style="width:150px;">Session Name</th>
                        <th style="width:80px;">Progress</th>
                        <th style="width:120px;">Last Watched</th>
                        <th style="width:100px;">Training Status</th>
                        <th style="width:130px;">Total Minutes Consumed</th>
                        <th style="width:120px;">Total Duration</th>
                        <th style="width:120px;">Enrolment Date</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($results as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->user_id); ?></td>
                        <td><?php echo esc_html($row->user_login); ?></td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo esc_html($row->session_name); ?></td>
                        <td>
                            <div style="background:#eee; border-radius:10px; overflow:hidden; height:20px; position:relative;">
                                <div style="background:#2271b1; height:100%; width:<?php echo $row->percent; ?>%; transition:width 0.3s;"></div>
                                <span style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:11px; font-weight:bold;">
                                    <?php echo $row->percent; ?>%
                                </span>
                            </div>
                        </td>
                        <td><?php echo esc_html(date('M j, Y H:i', strtotime($row->last_watched))); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $row->status; ?>">
                                <?php echo vtr_get_status_label($row->status); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($row->current_duration); ?></td>
                        <td><?php echo esc_html($row->full_duration); ?></td>
                        <td><?php echo $row->created_at ? esc_html(date('M j, Y', strtotime($row->created_at))) : 'N/A'; ?></td>
                        <td>
                            <button class="button button-small view-record" data-id="<?php echo $row->id; ?>">View</button>
                            <button class="button button-small edit-record" data-id="<?php echo $row->id; ?>">Edit</button>
                            <button class="button button-small button-link-delete delete-record" data-id="<?php echo $row->id; ?>">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- View Modal -->
    <div id="view-modal" class="vtr-modal" style="display:none;">
        <div class="vtr-modal-content">
            <div class="vtr-modal-header">
                <h2>View Record Details</h2>
                <span class="vtr-modal-close">&times;</span>
            </div>
            <div class="vtr-modal-body" id="view-modal-body">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="edit-modal" class="vtr-modal" style="display:none;">
        <div class="vtr-modal-content">
            <div class="vtr-modal-header">
                <h2>Edit Record</h2>
                <span class="vtr-modal-close">&times;</span>
            </div>
            <div class="vtr-modal-body">
                <form id="edit-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="edit-percent">Progress (%)</label></th>
                            <td><input type="number" id="edit-percent" name="percent" min="0" max="100" /></td>
                        </tr>
                        <tr>
                            <th><label for="edit-assessment">Assessment Taken</label></th>
                            <td>
                                <select id="edit-assessment" name="assessment_taken">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="edit-current-duration">Total Minutes Consumed</label></th>
                            <td><input type="text" id="edit-current-duration" name="current_duration" placeholder="00:00:00" /></td>
                        </tr>
                        <tr>
                            <th><label for="edit-full-duration">Total Duration</label></th>
                            <td><input type="text" id="edit-full-duration" name="full_duration" placeholder="00:00:00" /></td>
                        </tr>
                    </table>
                    <input type="hidden" id="edit-record-id" name="record_id" />
                    <p class="submit">
                        <button type="submit" class="button button-primary">Update Record</button>
                        <button type="button" class="button vtr-modal-close">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
    </div>

    <style>
    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }
    .status-0 { background: #f0f0f1; color: #646970; } /* Not Started */
    .status-1 { background: #fff3cd; color: #856404; } /* In Progress */
    .status-2 { background: #d1edff; color: #0073aa; } /* Completed */
    .status-3 { background: #f8d7da; color: #721c24; } /* Overdue */

    .vtr-modal {
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        overflow-y: auto; /* Enable scrolling on the overlay */
    }
    
    .vtr-modal-content {
        background-color: #fefefe;
        margin: 2% auto;
        padding: 0;
        border: 1px solid #888;
        width: 90%;
        max-width: 800px;
        border-radius: 4px;
        max-height: 90vh; /* Limit height to 90% of viewport */
        display: flex;
        flex-direction: column;
        position: relative;
    }
    
    .vtr-modal-header {
        padding: 15px 20px;
        background: #f1f1f1;
        border-bottom: 1px solid #ddd;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0; /* Don't shrink the header */
    }
    
    .vtr-modal-header h2 {
        margin: 0;
        font-size: 18px;
    }
    
    .vtr-modal-close {
        color: #aaa;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
        padding: 0;
        background: none;
        border: none;
    }
    
    .vtr-modal-close:hover,
    .vtr-modal-close:focus {
        color: #000;
        text-decoration: none;
    }
    
    .vtr-modal-body {
        padding: 20px;
        overflow-y: auto; /* Make the body scrollable */
        flex: 1; /* Take up remaining space */
        max-height: calc(90vh - 80px); /* Account for header height */
    }
    
    /* Better table styling in modals */
    .vtr-modal .form-table {
        width: 100%;
        margin: 0;
    }
    
    .vtr-modal .form-table th {
        width: 30%;
        padding: 10px;
        font-weight: bold;
        background: #f9f9f9;
        border-bottom: 1px solid #eee;
        vertical-align: top;
    }
    
    .vtr-modal .form-table td {
        padding: 10px;
        border-bottom: 1px solid #eee;
        word-break: break-word;
    }
    
    /* Form styling in edit modal */
    .vtr-modal input[type="text"],
    .vtr-modal input[type="number"],
    .vtr-modal select {
        width: 100%;
        max-width: 300px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .vtr-modal .submit {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #eee;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .vtr-modal-content {
            width: 95%;
            margin: 5% auto;
            max-height: 85vh;
        }
        
        .vtr-modal-header {
            padding: 10px 15px;
        }
        
        .vtr-modal-body {
            padding: 15px;
        }
        
        .vtr-modal .form-table th,
        .vtr-modal .form-table td {
            display: block;
            width: 100%;
            padding: 8px 0;
        }
        
        .vtr-modal .form-table th {
            background: none;
            font-size: 14px;
            margin-bottom: 5px;
        }
    }
    
    /* Loading state */
    .vtr-modal-loading {
        text-align: center;
        padding: 40px;
    }
    
    .vtr-modal-loading::after {
        content: "";
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        const nonce = '<?php echo wp_create_nonce('vtr_admin_nonce'); ?>';

        // Debug functionality
        $('#debug-btn').click(function() {
            $('#debug-info').toggle();
            if ($('#debug-info').is(':visible')) {
                $.post(ajaxurl, {
                    action: 'vtr_debug_info',
                    nonce: nonce
                }, function(response) {
                    $('#debug-content').text(JSON.stringify(response, null, 2));
                });
            }
        });

        // View record - using event delegation
        $(document).on('click', '.view-record', function(e) {
            e.preventDefault();
            const recordId = $(this).data('id');
            
            if (!recordId) {
                alert('Invalid record ID');
                return;
            }
            
            $.post(ajaxurl, {
                action: 'vtr_get_record',
                record_id: recordId,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    const statusLabels = {
                        0: 'Not Started',
                        1: 'In Progress', 
                        2: 'Completed',
                        3: 'Overdue'
                    };
                    const html = `
                        <table class="form-table">
                            <tr><th>User ID:</th><td>${data.user_id}</td></tr>
                            <tr><th>Username:</th><td>${data.user_login || 'N/A'}</td></tr>
                            <tr><th>Email:</th><td>${data.user_email || 'N/A'}</td></tr>
                            <tr><th>Video ID:</th><td>${data.video_id}</td></tr>
                            <tr><th>Session Name:</th><td>${data.session_name}</td></tr>
                            <tr><th>Session ID:</th><td>${data.session_id}</td></tr>
                            <tr><th>Progress:</th><td>${data.percent}%</td></tr>
                            <tr><th>Assessment Taken:</th><td>${data.assessment_taken ? 'Yes' : 'No'}</td></tr>
                            <tr><th>Training Status:</th><td>${statusLabels[data.status] || 'Unknown'}</td></tr>
                            <tr><th>Total Minutes Consumed:</th><td>${data.current_duration}</td></tr>
                            <tr><th>Total Duration:</th><td>${data.full_duration}</td></tr>
                            <tr><th>Last Watched:</th><td>${data.last_watched}</td></tr>
                            <tr><th>Enrolment Date:</th><td>${data.created_at || 'N/A'}</td></tr>
                        </table>
                    `;
                    $('#view-modal-body').html(html);
                    $('#view-modal').show();
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                console.log('Response:', xhr.responseText);
            });
        });

        // Edit record - using event delegation
        $(document).on('click', '.edit-record', function(e) {
            e.preventDefault();
            const recordId = $(this).data('id');
            
            if (!recordId) {
                alert('Invalid record ID');
                return;
            }
            
            $.post(ajaxurl, {
                action: 'vtr_get_record',
                record_id: recordId,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#edit-record-id').val(data.id);
                    $('#edit-percent').val(data.percent);
                    $('#edit-assessment').val(data.assessment_taken);
                    $('#edit-current-duration').val(data.current_duration);
                    $('#edit-full-duration').val(data.full_duration);
                    $('#edit-modal').show();
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                console.log('Response:', xhr.responseText);
            });
        });

        // Delete record - using event delegation
        $(document).on('click', '.delete-record', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this record?')) return;
            
            const recordId = $(this).data('id');
            
            if (!recordId) {
                alert('Invalid record ID');
                return;
            }
            
            $.post(ajaxurl, {
                action: 'vtr_delete_record',
                record_id: recordId,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    alert('Record deleted successfully');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                console.log('Response:', xhr.responseText);
            });
        });

        // Handle edit form submission
        $('#edit-form').submit(function(e) {
            e.preventDefault();
            
            const recordId = $('#edit-record-id').val();
            const percent = $('#edit-percent').val();
            const assessmentTaken = $('#edit-assessment').val();
            const currentDuration = $('#edit-current-duration').val();
            const fullDuration = $('#edit-full-duration').val();
            
            // Basic validation
            if (!recordId || !percent) {
                alert('Please fill in all required fields');
                return;
            }
            
            $.post(ajaxurl, {
                action: 'vtr_update_record',
                record_id: recordId,
                percent: percent,
                assessment_taken: assessmentTaken,
                current_duration: currentDuration,
                full_duration: fullDuration,
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    alert('Record updated successfully');
                    $('#edit-modal').hide();
                    location.reload();
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            }).fail(function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                console.log('Response:', xhr.responseText);
            });
        });

        // Close modals
        $(document).on('click', '.vtr-modal-close', function() {
            $('.vtr-modal').hide();
        });

        // Close modal when clicking outside
        $(document).on('click', '.vtr-modal', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Prevent modal content clicks from closing modal
        $(document).on('click', '.vtr-modal-content', function(e) {
            e.stopPropagation();
        });
    });
    </script>
    <?php
}

// Add admin notice for successful exports
add_action('admin_notices', function() {
    if (isset($_GET['exported']) && current_user_can('manage_options')) {
        $format = sanitize_text_field($_GET['exported']);
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>Video report successfully exported as ' . esc_html(strtoupper($format)) . '!</p>';
        echo '</div>';
    }
});

// Bulk actions for export (optional enhancement)
add_action('wp_ajax_vtr_bulk_export', 'vtr_ajax_bulk_export');
function vtr_ajax_bulk_export() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Access denied']);
    }

    if (!wp_verify_nonce($_POST['nonce'], 'vtr_admin_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }

    $selected_ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
    $format = sanitize_text_field($_POST['format']);

    if (empty($selected_ids)) {
        wp_send_json_error(['message' => 'No records selected']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    
    $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
    $query = "SELECT vp.*, u.user_login, u.user_email FROM $table_name vp
              LEFT JOIN {$wpdb->users} u ON vp.user_id = u.ID
              WHERE vp.id IN ($placeholders)
              ORDER BY vp.last_watched DESC";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $selected_ids));

    if (empty($results)) {
        wp_send_json_error(['message' => 'No records found']);
    }

    $filename = 'video-watch-report-selected-' . date('Y-m-d-H-i-s');

    // Generate download URL with temporary token
    $temp_token = wp_generate_password(32, false);
    set_transient('vtr_temp_export_' . $temp_token, [
        'data' => $results,
        'format' => $format,
        'filename' => $filename
    ], 300); // 5 minutes

    $download_url = add_query_arg([
        'vtr_temp_export' => $temp_token,
        'nonce' => wp_create_nonce('vtr_temp_export_nonce')
    ], admin_url('admin.php'));

    wp_send_json_success(['download_url' => $download_url]);
}

// Handle temporary export downloads
add_action('init', 'vtr_handle_temp_export');
function vtr_handle_temp_export() {
    if (!isset($_GET['vtr_temp_export']) || !current_user_can('manage_options')) {
        return;
    }

    if (!wp_verify_nonce($_GET['nonce'], 'vtr_temp_export_nonce')) {
        wp_die('Security check failed');
    }

    $token = sanitize_text_field($_GET['vtr_temp_export']);
    $export_data = get_transient('vtr_temp_export_' . $token);

    if (!$export_data) {
        wp_die('Export link expired or invalid');
    }

    // Delete the temporary data
    delete_transient('vtr_temp_export_' . $token);

    switch ($export_data['format']) {
        case 'csv':
            vtr_export_csv($export_data['data'], $export_data['filename']);
            break;
        case 'excel':
            vtr_export_excel($export_data['data'], $export_data['filename']);
            break;
        case 'json':
            vtr_export_json($export_data['data'], $export_data['filename']);
            break;
        default:
            wp_die('Invalid export format');
    }
}

?>