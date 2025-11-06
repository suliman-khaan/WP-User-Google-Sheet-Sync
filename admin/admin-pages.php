<?php
// admin/admin-pages.php
add_action('admin_menu', function () {
    add_menu_page('User Sheet Sync', 'User Sheet Sync', 'manage_options', 'user-sheet-sync', 'wp_user_gsheet_list_page');
    add_submenu_page('user-sheet-sync', 'All Sheets', 'All Sheets', 'manage_options', 'user-sheet-sync', 'wp_user_gsheet_list_page');
    add_submenu_page('user-sheet-sync', 'Add New Sheet', 'Add Sheet', 'manage_options', 'user-sheet-sync-add', 'wp_user_gsheet_edit_page');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'user-sheet-sync') === false) return;
    wp_enqueue_style('wp-user-gsheet-admin-style', plugin_dir_url(__FILE__) . '../assets/admin-style.css');
    wp_enqueue_script('jquery');
});

function wp_user_gsheet_list_page() {
    $configs = get_option('wp_user_gsheet_sync_configs', []);
    $action = $_GET['action'] ?? '';
    $index = isset($_GET['index']) ? intval($_GET['index']) : -1;

    if ($action === 'delete' && $index >= 0 && isset($configs[$index]) && check_admin_referer('delete_config_' . $index)) {
        unset($configs[$index]);
        $configs = array_values($configs);
        update_option('wp_user_gsheet_sync_configs', $configs);
        // Clear last sync times for deleted config
        delete_option("wp_user_gsheet_last_sync_wp_to_sheet_$index");
        delete_option("wp_user_gsheet_last_sync_sheet_to_wp_$index");
        echo '<div class="updated"><p>Sheet deleted!</p></div>';
    }

    if (isset($_POST['sync_sheet'])) {
        $index = intval($_POST['index']);
        $config = $configs[$index] ?? null;
        if ($config) {
            $sync = new WP_User_GSheet_Sync($config);
            $sync->sync_sheet_to_wp();
            update_option("wp_user_gsheet_last_sync_sheet_to_wp_$index", time());
            echo '<div class="updated"><p>Sheet → WP sync done for ' . esc_html($config['name']) . '!</p></div>';
        } else {
            echo '<div class="error"><p>Invalid configuration index!</p></div>';
        }
    }
    if (isset($_POST['sync_wp'])) {
        $index = intval($_POST['index']);
        $config = $configs[$index] ?? null;
        if ($config) {
            $sync = new WP_User_GSheet_Sync($config);
            $sync->sync_all_wp_to_sheet();
            update_option("wp_user_gsheet_last_sync_wp_to_sheet_$index", time());
            echo '<div class="updated"><p>WP → Sheet sync done for ' . esc_html($config['name']) . '!</p></div>';
        } else {
            echo '<div class="error"><p>Invalid configuration index!</p></div>';
        }
    }

    if (empty($configs)) {
        $default_fields = [
            'user_login'        => 'Username',
            'user_email'        => 'Email',
            'country'           => 'Country',
            'film_tv'           => 'Cinema or Television',
            'role_company'      => 'Role Company',
            'type_production'   => 'Type',
            'company'           => 'Company',
            'role'              => 'User Role',
            'email_company'     => 'Email Company',
            'street'            => 'Street',
            'postal_code'       => 'Postal Code',
            'city'              => 'City',
            'phone'             => 'Phone',
            'first_name'        => 'First Name',
            'last_name'         => 'Last Name',
            'position'          => 'Position',
            'website_company'   => 'Website',
            'linkedin'          => 'LinkedIn',
            'reference'         => 'Reference',
        ];
        for ($i = 1; $i <= 50; $i++) {
            $default_fields["collaborator{$i}_first_name"] = "Collaborator{$i} First Name";
            $default_fields["collaborator{$i}_last_name"]  = "Collaborator{$i} Last Name";
            $default_fields["collaborator{$i}_email"]      = "Collaborator{$i} Email";
            $default_fields["collaborator{$i}_phone"]      = "Collaborator{$i} Phone";
            $default_fields["collaborator{$i}_position"]   = "Collaborator{$i} Position";
            $default_fields["collaborator{$i}_linkedin"]   = "Collaborator{$i} LinkedIn";
        }
        $configs = [
            [
                'name' => 'Default',
                'spreadsheet_id' => '1Zxx65VoU0Mg7xLh7GGOgGZc58wXQzjW9TDdIs-lfCc8',
                'sheet_title' => 'Sheet1',
                'credentials' => '',
                'fields' => $default_fields,
                'roles' => ['company'],
                'auto_sync_wp_to_sheet' => false,
                'auto_sync_sheet_to_wp' => false,
                'sync_interval' => 'hourly',
            ]
        ];
        update_option('wp_user_gsheet_sync_configs', $configs);
    }
    ?>
    <div class="wrap gsheet-config-list">
        <h1>User Sheet Sync Configurations</h1>
        <p>Manage multiple Google Sheet sync configurations for selected user roles.</p>
        <?php if (empty(get_option('wp_user_gsheet_global_credentials', ''))): ?>
            <div class="error"><p>Warning: No global Service Account JSON is set. Please configure it in <a href="<?php echo admin_url('admin.php?page=user-sheet-sync-global'); ?>">Global Settings</a>.</p></div>
        <?php endif; ?>
        <p style="display:inline;"><a href="<?php echo admin_url('admin.php?page=user-sheet-sync-global'); ?>" class="button">Settings</a></p>
        <p style="display:inline;"><a href="<?php echo admin_url('admin.php?page=user-sheet-sync-guide'); ?>" class="button">Setup Guide</a></p>
        <a href="<?php echo admin_url('admin.php?page=user-sheet-sync-add'); ?>" class="button button-primary">Add New Sheet</a>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Sheet Title</th>
                    <th>Roles</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($configs as $i => $config): ?>
                    <tr>
                        <td><?php echo esc_html($config['name'] ?: 'Unnamed #' . ($i + 1)); ?></td>
                        <td><?php echo esc_html($config['sheet_title']); ?></td>
                        <td style="text-transform: capitalize;"><?php echo esc_html(implode(', ', $config['roles'] ?? [])); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=user-sheet-sync-add&action=edit&index=' . $i); ?>">Edit</a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=user-sheet-sync&action=delete&index=' . $i), 'delete_config_' . $i); ?>" onclick="return confirm('Are you sure?');">Delete</a> |
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="index" value="<?php echo $i; ?>">
                                <button type="submit" name="sync_sheet" class="button-link">Sheet → WP</button>
                            </form> |
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="index" value="<?php echo $i; ?>">
                                <button type="submit" name="sync_wp" class="button-link">WP → Sheet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>
    <?php
}

function wp_user_gsheet_edit_page() {
    $configs = get_option('wp_user_gsheet_sync_configs', []);
    $action = $_GET['action'] ?? 'add';
    $index = isset($_GET['index']) ? intval($_GET['index']) : -1;
    $config = ($action === 'edit' && $index >= 0 && isset($configs[$index])) ? $configs[$index] : [
        'name' => '',
        'spreadsheet_id' => '',
        'sheet_title' => 'Sheet1',
        'credentials' => '',
        'fields' => [],
        'roles' => ['company'],
        'auto_sync_wp_to_sheet' => false,
        'auto_sync_sheet_to_wp' => false,
        'sync_interval' => 'hourly',
    ];
    // Ensure roles is always an array
    $config['roles'] = isset($config['roles']) && is_array($config['roles']) ? $config['roles'] : [];
    $error = '';

    if (isset($_POST['save_config']) && check_admin_referer('wp_user_gsheet_config_save')) {
        $new_config = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'spreadsheet_id' => sanitize_text_field($_POST['spreadsheet_id'] ?? ''),
            'sheet_title' => sanitize_text_field($_POST['sheet_title'] ?? 'Sheet1'),
            'credentials' => isset($_POST['credentials']) ? wp_unslash(sanitize_textarea_field($_POST['credentials'])) : '',
            'fields' => [],
            'roles' => isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('sanitize_text_field', $_POST['roles']) : [],
            'auto_sync_wp_to_sheet' => !empty($_POST['auto_sync_wp_to_sheet']),
            'auto_sync_sheet_to_wp' => !empty($_POST['auto_sync_sheet_to_wp']),
            'sync_interval' => in_array($_POST['sync_interval'] ?? '', ['five_minutes', 'hourly', 'daily']) ? sanitize_text_field($_POST['sync_interval']) : 'hourly',
        ];
        if (!empty($new_config['credentials'])) {
            $decoded = json_decode($new_config['credentials'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Invalid JSON credentials: ' . esc_html(json_last_error_msg());
            }
        }
        if (isset($_POST['fields']['wp']) && isset($_POST['fields']['sheet'])) {
            foreach ($_POST['fields']['wp'] as $i => $wp) {
                $wp = trim(sanitize_text_field($wp));
                $sheet = trim(sanitize_text_field($_POST['fields']['sheet'][$i]));
                if ($wp && $sheet) {
                    $new_config['fields'][$wp] = $sheet;
                }
            }
        }
        if (!empty($new_config['spreadsheet_id']) && empty($error)) {
            if ($action === 'edit' && $index >= 0) {
                $configs[$index] = $new_config;
            } else {
                $configs[] = $new_config;
            }
            update_option('wp_user_gsheet_sync_configs', $configs);
            echo '<div class="updated"><p>Sheet saved!</p></div>';
            $config = $new_config;
        }
    }
    $all_roles = wp_roles()->get_names();
    ?>
    <div class="wrap gsheet-config-edit">
        <h1><?php echo ($action === 'edit') ? 'Edit Sheet' : 'Add New Sheet'; ?></h1>
        <?php if ($error): ?>
            <div class="error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('wp_user_gsheet_config_save'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="name">Name (for identification)</label></th>
                    <td><input type="text" id="name" name="name" value="<?php echo esc_attr($config['name']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="spreadsheet_id">Spreadsheet ID</label></th>
                    <td><input type="text" id="spreadsheet_id" name="spreadsheet_id" value="<?php echo esc_attr($config['spreadsheet_id']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="sheet_title">Sheet Title</label></th>
                    <td><input type="text" id="sheet_title" name="sheet_title" value="<?php echo esc_attr($config['sheet_title']); ?>" class="regular-text"><p class="description">If the sheet does not exist, it will be created automatically.</p></td>
                </tr>
                <tr>
                    <th><label for="credentials">Custom Service Account JSON (Optional)</label></th>
                    <td>
                        <textarea id="credentials" name="credentials" rows="10" cols="50"><?php echo esc_textarea($config['credentials']); ?></textarea>
                        <p class="description">Leave blank to use the global Service Account JSON from <a href="<?php echo admin_url('admin.php?page=user-sheet-sync-global'); ?>">Global Settings</a>.</p>
                    </td>
                </tr>
                <tr>
                    <th>Synced User Roles</th>
                    <td>
                        <p>Select the user roles that should be synced with this sheet.</p>
                        <?php foreach ($all_roles as $role_key => $role_name): ?>
                            <label>
                                <input type="checkbox" name="roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $config['roles'])); ?>>
                                <?php echo esc_html($role_name); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th>Auto-Sync Settings</th>
                    <td>
                        <p>Configure automatic syncing via cron.</p>
                        <label>
                            <input type="checkbox" name="auto_sync_wp_to_sheet" value="1" <?php checked(!empty($config['auto_sync_wp_to_sheet'])); ?>>
                            Enable WP → Sheet Auto-Sync
                        </label><br>
                        <label>
                            <input type="checkbox" name="auto_sync_sheet_to_wp" value="1" <?php checked(!empty($config['auto_sync_sheet_to_wp'])); ?>>
                            Enable Sheet → WP Auto-Sync
                        </label><br>
                        <label for="sync_interval">Sync Interval:</label>
                        <select id="sync_interval" name="sync_interval">
                            <option value="five_minutes" <?php selected($config['sync_interval'], 'five_minutes'); ?>>Every 5 Minutes</option>
                            <option value="hourly" <?php selected($config['sync_interval'], 'hourly'); ?>>Hourly</option>
                            <option value="daily" <?php selected($config['sync_interval'], 'daily'); ?>>Daily</option>
                        </select>
                        <p class="description">Select how often auto-sync should run for this Sheet.</p>
                    </td>
                </tr>
                <tr>
                    <th>Field Mappings</th>
                    <td>
                        <p>Map WordPress fields to Google Sheet columns for this Sheet.</p>
                        <table class="wp-list-table widefat fixed striped mappings-table">
                            <thead>
                                <tr>
                                    <th>WordPress Field</th>
                                    <th>Google Sheet Column</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($config['fields'] as $wp => $sheet): ?>
                                    <tr>
                                        <td><input type="text" name="fields[wp][]" value="<?php echo esc_attr($wp); ?>"></td>
                                        <td><input type="text" name="fields[sheet][]" value="<?php echo esc_attr($sheet); ?>"></td>
                                        <td><button type="button" class="button button-small remove-row">Remove</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button add-mapping-row">Add New Mapping</button>
                    </td>
                </tr>
            </table>
            <input type="submit" name="save_config" class="button button-primary" value="Save Sheet">
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('.add-mapping-row').click(function() {
            var row = '<tr><td><input type="text" name="fields[wp][]" value=""></td><td><input type="text" name="fields[sheet][]" value=""></td><td><button type="button" class="button button-small remove-row">Remove</button></td></tr>';
            $('.mappings-table tbody').append(row);
        });

        $(document).on('click', '.remove-row', function() {
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}