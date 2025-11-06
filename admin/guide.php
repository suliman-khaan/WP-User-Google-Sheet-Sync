<?php
// admin/guide.php
add_action('admin_menu', function () {
    add_submenu_page('user-sheet-sync', 'Setup Guide', 'Setup Guide', 'manage_options', 'user-sheet-sync-guide', 'wp_user_gsheet_guide_page');
});

function wp_user_gsheet_guide_page() {
    ?>
    <div class="wrap">
        <h1>WP User Google Sheet Sync Setup Guide</h1>
        <p>This guide explains how to create a Google Service Account, generate the JSON key, and grant access to your Google Sheet so the plugin can sync data.</p>

        <h2>Step 1: Create a Google Cloud Project</h2>
        <ol>
            <li>Go to the <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</li>
            <li>Click on the project dropdown at the top and select <strong>New Project</strong> (or use an existing one).</li>
            <li>Enter a project name (e.g., "WP Sheet Sync") and create the project.</li>
        </ol>

        <h2>Step 2: Enable the Google Sheets API</h2>
        <ol>
            <li>In the Google Cloud Console, navigate to <strong>APIs & Services > Library</strong>.</li>
            <li>Search for "Google Sheets API".</li>
            <li>Click on it and select <strong>Enable</strong>.</li>
        </ol>

        <h2>Step 3: Create a Service Account</h2>
        <ol>
            <li>Go to <strong>IAM & Admin > Service Accounts</strong>.</li>
            <li>Click <strong>Create Service Account</strong>.</li>
            <li>Enter a name (e.g., "WP Sheet Sync Service Account"), ID (auto-generated), and description.</li>
            <li>Click <strong>Create and Continue</strong>.</li>
            <li>Optional: Grant roles if needed (for basic access, you can skip and grant access later via sheet sharing).</li>
            <li>Click <strong>Done</strong>.</li>
        </ol>

        <h2>Step 4: Generate the JSON Key</h2>
        <ol>
            <li>On the Service Accounts list, click on the new account.</li>
            <li>Go to the <strong>Keys</strong> tab.</li>
            <li>Click <strong>Add Key > Create new key</strong>.</li>
            <li>Select <strong>JSON</strong> as the key type.</li>
            <li>Click <strong>Create</strong> to download the JSON file.</li>
            <li>Open the JSON file in a text editor and copy its entire content.</li>
        </ol>

        <h2>Step 5: Paste the JSON into the Plugin</h2>
        <ol>
            <li>In WordPress, go to <strong>User Sheet Sync > Global Settings</strong>.</li>
            <li>Paste the JSON content into the "Default Service Account JSON" textarea.</li>
            <li>Save the settings.</li>
            <li>Alternatively, for specific configurations, paste it into the "Custom Service Account JSON" field when editing a configuration.</li>
        </ol>

        <h2>Step 6: Share Your Google Sheet with the Service Account</h2>
        <ol>
            <li>Open your Google Sheet in Google Drive.</li>
            <li>Click <strong>Share</strong> in the top right.</li>
            <li>In the JSON, find the "client_email" field (e.g., "your-service-account@project.iam.gserviceaccount.com").</li>
            <li>Paste this email into the Share dialog.</li>
            <li>Set permissions to <strong>Editor</strong>.</li>
            <li>Click <strong>Send</strong> or <strong>Share</strong>.</li>
        </ol>

        <h2>Troubleshooting</h2>
        <ul>
            <li>If you get a "Permission Denied" error, double-check that the sheet is shared with the correct client_email.</li>
            <li>If JSON is invalid, ensure no extra characters or formatting issues when pasting.</li>
            <li>Verify the Google Sheets API is enabled in your project.</li>
            <li>Check PHP error logs for detailed messages.</li>
        </ul>

        <p>For more details, refer to the official Google documentation: <a href="https://developers.google.com/workspace/guides/create-credentials" target="_blank">Create Credentials</a> and <a href="https://cloud.google.com/iam/docs/service-accounts-create" target="_blank">Create Service Accounts</a>.</p>
    </div>
    <?php
}