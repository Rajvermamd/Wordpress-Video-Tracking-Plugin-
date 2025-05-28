<?php
/**
 * Plugin Name: Video Tracker with Report
 * Description: Track multiple YouTube and self-hosted videos watched by logged-in users and show admin report.
 * Version: 1.0
 * Author: Raj Verma
 */

if (!defined('ABSPATH')) exit;

// Create custom DB table on activation
register_activation_hook(__FILE__, 'vtr_create_table');
function vtr_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        video_id varchar(255) NOT NULL,
        percent int NOT NULL,
        last_watched datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY video_id (video_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Enqueue scripts
add_action('wp_enqueue_scripts', function() {
    if (is_user_logged_in()) {
        wp_enqueue_script('video-tracker-js', plugin_dir_url(__FILE__) . 'js/video-tracker.js', ['jquery'], '1.0', true);
        wp_localize_script('video-tracker-js', 'vt_ajax_object', ['ajax_url' => admin_url('admin-ajax.php')]);
    }
});

// AJAX handler to save progress
add_action('wp_ajax_save_video_progress', 'vtr_save_video_progress');
function vtr_save_video_progress() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';

    $user_id = get_current_user_id();
    $video_id = sanitize_text_field($_POST['video_id']);
    $percent = intval($_POST['percent']);

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND video_id = %s",
        $user_id, $video_id
    ));

    if ($existing) {
        if ($percent > $existing->percent) {
            $wpdb->update(
                $table_name,
                ['percent' => $percent, 'last_watched' => current_time('mysql')],
                ['id' => $existing->id]
            );
        }
    } else {
        $wpdb->insert(
            $table_name,
            ['user_id' => $user_id, 'video_id' => $video_id, 'percent' => $percent, 'last_watched' => current_time('mysql')]
        );
    }

    wp_send_json_success(['message' => 'Progress saved']);
}

// Add admin menu page
add_action('admin_menu', function() {
    add_menu_page('Video Watch Reports', 'Video Reports', 'manage_options', 'video-watch-reports', 'vtr_render_report_page');
});

// Render admin report page
function vtr_render_report_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'video_watch_progress';

    // Handle delete action
    if (isset($_POST['delete_id']) && check_admin_referer('vtr_delete_progress')) {
        $delete_id = intval($_POST['delete_id']);
        $wpdb->delete($table_name, ['id' => $delete_id]);
        echo '<div class="updated notice"><p>Record deleted.</p></div>';
    }

    $search_user = isset($_GET['search_user']) ? sanitize_text_field($_GET['search_user']) : '';
    $search_video = isset($_GET['search_video']) ? sanitize_text_field($_GET['search_video']) : '';

    $query = "SELECT vp.*, u.user_login, u.user_email FROM $table_name vp
              LEFT JOIN {$wpdb->users} u ON vp.user_id = u.ID
              WHERE 1=1 ";

    if ($search_user) {
        $query .= $wpdb->prepare(" AND (u.user_login LIKE %s OR u.user_email LIKE %s) ", "%$search_user%", "%$search_user%");
    }

    if ($search_video) {
        $query .= $wpdb->prepare(" AND vp.video_id LIKE %s ", "%$search_video%");
    }

    $query .= " ORDER BY vp.last_watched DESC LIMIT 50";

    $results = $wpdb->get_results($query);

    ?>
    <div class="wrap">
        <h1>Video Watch Progress Reports</h1>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="video-watch-reports" />
            <input type="text" name="search_user" placeholder="Search by username or email" value="<?php echo esc_attr($search_user); ?>" />
            <input type="text" name="search_video" placeholder="Search by video ID" value="<?php echo esc_attr($search_video); ?>" />
            <input type="submit" class="button" value="Search" />
            <a href="<?php echo admin_url('admin.php?page=video-watch-reports'); ?>" class="button">Reset</a>
        </form>

        <?php if(empty($results)) : ?>
            <p>No records found.</p>
        <?php else: ?>
            <table class="widefat fixed striped" cellspacing="0">
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Video ID</th>
                        <th>Percent Watched</th>
                        <th>Last Watched</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($results as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->user_id); ?></td>
                        <td><?php echo esc_html($row->user_login); ?></td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo esc_html($row->video_id); ?></td>
                        <td><?php echo esc_html($row->percent); ?>%</td>
                        <td><?php echo esc_html($row->last_watched); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                <?php wp_nonce_field('vtr_delete_progress'); ?>
                                <input type="hidden" name="delete_id" value="<?php echo esc_attr($row->id); ?>" />
                                <input type="submit" class="button button-danger" value="Delete" />
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
