<?php
// admin/global-settings.php
add_action('admin_menu', function () {
    add_submenu_page('user-sheet-sync', 'Settings', 'Settings', 'manage_options', 'user-sheet-sync-global', 'wp_user_gsheet_global_page');
});

function wp_user_gsheet_global_page() {
    $error = '';
    if (isset($_POST['save_global']) && check_admin_referer('wp_user_gsheet_global_save')) {
        $credentials = isset($_POST['global_credentials']) ? wp_unslash(sanitize_textarea_field($_POST['global_credentials'])) : '';
        if (!empty($credentials)) {
            $decoded = json_decode($credentials, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Invalid JSON credentials: ' . esc_html(json_last_error_msg());
            } else {
                update_option('wp_user_gsheet_global_credentials', $credentials);
                echo '<div class="updated"><p>Global credentials saved!</p></div>';
            }
        } else {
            update_option('wp_user_gsheet_global_credentials', '');
            echo '<div class="updated"><p>Global credentials cleared!</p></div>';
        }
    }
    $global_credentials = get_option('wp_user_gsheet_global_credentials', '');
    ?>
    <div class="wrap gsheet-global-settings">
        <h1>Global Google Sheet Sync Settings</h1>
        <p>Enter the default Google Service Account JSON credentials used for all sheet configurations unless overridden.</p>
        <?php if ($error): ?>
            <div class="error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('wp_user_gsheet_global_save'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="global_credentials">Default Service Account JSON</label></th>
                    <td>
                        <textarea id="global_credentials" name="global_credentials" rows="10" cols="50"><?php echo esc_textarea($global_credentials); ?></textarea>
                        <p class="description">Paste your Google Service Account JSON here. See the <a href="<?php echo admin_url('admin.php?page=user-sheet-sync-guide'); ?>">Setup Guide</a> for instructions on creating and using this JSON.</p>
                    </td>
                </tr>
            </table>
            <input type="submit" name="save_global" class="button button-primary" value="Save Global Settings">
        </form>
    </div>
    <?php
}