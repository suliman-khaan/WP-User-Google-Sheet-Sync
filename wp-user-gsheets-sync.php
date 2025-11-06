<?php
/**
 * Plugin Name: WP User Google Sheet Sync
 * Description: Sync WordPress users to Google Sheets and back. Configurable roles are synced.
 * Version: 2.0
 * Author: Suliman K
 * Author URI: https://www.linkedin.com/in/suliman-khaan/
 */

if (!defined('ABSPATH')) exit;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/class-sync.php';

$configs = get_option('wp_user_gsheet_sync_configs', []);
foreach ($configs as $config) {
    new WP_User_GSheet_Sync($config);
}

// Define custom cron intervals
add_filter('cron_schedules', function ($schedules) {
    $schedules['five_minutes'] = ['interval' => 300, 'display' => __('Every 5 Minutes')];
    $schedules['hourly'] = ['interval' => 3600, 'display' => __('Hourly')];
    $schedules['daily'] = ['interval' => 86400, 'display' => __('Daily')];
    return $schedules;
});

// Schedule cron for WP-to-Sheet sync
if (!wp_next_scheduled('wp_user_gsheet_auto_sync_wp_to_sheet')) {
    wp_schedule_event(time(), 'hourly', 'wp_user_gsheet_auto_sync_wp_to_sheet');
}
add_action('wp_user_gsheet_auto_sync_wp_to_sheet', function () {
    $configs = get_option('wp_user_gsheet_sync_configs', []);
    foreach ($configs as $index => $config) {
        if (empty($config['auto_sync_wp_to_sheet']) || empty($config['sync_interval'])) continue;
        $last_sync = get_option("wp_user_gsheet_last_sync_wp_to_sheet_$index", 0);
        $interval = $config['sync_interval'] === 'five_minutes' ? 300 : ($config['sync_interval'] === 'daily' ? 86400 : 3600);
        if (time() - $last_sync >= $interval) {
            $sync = new WP_User_GSheet_Sync($config);
            $sync->sync_all_wp_to_sheet();
            update_option("wp_user_gsheet_last_sync_wp_to_sheet_$index", time());
            error_log('WP User GSheet Sync: WP-to-Sheet sync completed for config ' . ($config['name'] ?? 'unnamed #' . $index));
        }
    }
});

// Schedule cron for Sheet-to-WP sync
if (!wp_next_scheduled('wp_user_gsheet_auto_sync_sheet_to_wp')) {
    wp_schedule_event(time(), 'hourly', 'wp_user_gsheet_auto_sync_sheet_to_wp');
}
add_action('wp_user_gsheet_auto_sync_sheet_to_wp', function () {
    $configs = get_option('wp_user_gsheet_sync_configs', []);
    foreach ($configs as $index => $config) {
        if (empty($config['auto_sync_sheet_to_wp']) || empty($config['sync_interval'])) continue;
        $last_sync = get_option("wp_user_gsheet_last_sync_sheet_to_wp_$index", 0);
        $interval = $config['sync_interval'] === 'five_minutes' ? 300 : ($config['sync_interval'] === 'daily' ? 86400 : 3600);
        if (time() - $last_sync >= $interval) {
            $sync = new WP_User_GSheet_Sync($config);
            $sync->sync_sheet_to_wp();
            update_option("wp_user_gsheet_last_sync_sheet_to_wp_$index", time());
            error_log('WP User GSheet Sync: Sheet-to-WP sync completed for config ' . ($config['name'] ?? 'unnamed #' . $index));
        }
    }
});

// Admin
if (is_admin()) {
    require __DIR__ . '/admin/admin-pages.php';
    require __DIR__ . '/admin/global-settings.php';
    require __DIR__ . '/admin/guide.php';
}