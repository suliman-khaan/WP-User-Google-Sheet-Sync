# WP User Google Sheet Sync

**Contributors:** Suliman K
**Tags:** google, sheets, user, sync, import
**Requires at least:** 5.0
**Tested up to:** 6.4
**Stable tag:** 2.6
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress users from a Google Sheet. This plugin provides a one-way sync, where your Google Sheet acts as the master source of user data.

## Description

WP User Google Sheet Sync allows you to keep your WordPress user database in sync with a Google Sheet. It's perfect for situations where user data is managed externally in a spreadsheet. The plugin will:

*   Create new WordPress users from rows in your Google Sheet.
*   Update existing user data based on changes in the sheet.
*   Write the newly created WordPress User ID back to an "ID" column in your sheet for easy reference.
*   Allow for both manual and automatic (cron-based) syncing.
*   Support multiple sheet configurations for different user roles or data sets.

This plugin requires a Google Service Account to securely connect to the Google Sheets API.

## Installation

1.  Upload the `wp-user-gsheets-sync` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Go to **User Sheet Sync > Setup Guide** and follow the instructions to configure the plugin.

## How to Use

To use this plugin, you will need to create Google API credentials. The plugin includes a detailed guide to help you with this process.

1.  **Create Google API Credentials:**
    *   Navigate to **User Sheet Sync > Setup Guide** in your WordPress admin dashboard.
    *   Follow the step-by-step instructions to:
        1.  Create a Google Cloud Project.
        2.  Enable the Google Sheets API.
        3.  Create a Service Account.
        4.  Generate and download a JSON key for the service account.

2.  **Configure the Plugin:**
    *   Go to **User Sheet Sync > Settings**.
    *   Paste the entire content of the downloaded JSON file into the **Default Service Account JSON** field.
    *   Save the settings.

3.  **Share Your Google Sheet:**
    *   Open your Google Sheet.
    *   Click the **Share** button.
    *   In the downloaded JSON file, find the `client_email` (e.g., `your-service-account@your-project.iam.gserviceaccount.com`).
    *   Paste this email address into the share dialog in Google Sheets and give it **Editor** permissions.

4.  **Add and Configure a Sheet:**
    *   Go to **User Sheet Sync > All Sheets** and click **Add New Sheet**.
    *   Give your configuration a name.
    *   Enter the **Spreadsheet ID** (you can find this in the URL of your Google Sheet).
    *   Enter the **Sheet Title** (the name of the specific sheet/tab, e.g., "Sheet1").
    *   Map your WordPress user fields (like `user_login`, `user_email`, `first_name`) to the corresponding column names in your Google Sheet.
    *   Save the sheet configuration.

5.  **Sync Your Users:**
    *   You can trigger a manual sync by clicking the **Sheet → WP** button next to your configuration on the "All Sheets" page.
    *   You can also enable auto-sync and set an interval in the sheet configuration page.

## Requirements

*   PHP 7.4 or higher.
*   A Google Account and a Google Cloud Platform project.
*   The plugin uses the official **Google API Client Library for PHP**. This library is included with the plugin, so you do not need to install it separately.

## Frequently Asked Questions

**Can I sync data from WordPress back to the Google Sheet?**

This plugin currently only supports a one-way sync from Google Sheets to WordPress. The only data written back to the sheet is the WordPress User ID when a new user is created.

**What happens if I have users with the same email address in my sheet?**

The plugin will update the existing user with that email address. It will not create a duplicate user.

**Can I sync custom user meta fields?**

Yes. On the sheet configuration page, you can add custom field mappings. Enter the WordPress meta key in the "WordPress Field" column and the corresponding Google Sheet column name in the "Google Sheet Column" column.
