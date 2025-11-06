<?php
// includes/class-sync.php
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest;

class WP_User_GSheet_Sync {
    private $config;
    private $client;
    private $service;
    private $spreadsheetId;
    private $sheetTitle;
    private $range;
    private $in_sheet_to_wp = false;
    private $sheetIdCache = null;
    private $fields;
    private $roles;

    public function __construct($config) {
        $this->config = $config;
        $this->fields = $config['fields'] ?? [];
        $this->roles = $config['roles'] ?? ['company'];
        $this->spreadsheetId = $config['spreadsheet_id'] ?? '';
        $this->sheetTitle = $config['sheet_title'] ?? 'Sheet1';
        $this->range = $this->sheetTitle;

        if (empty($this->spreadsheetId) || empty($this->fields) || empty($this->roles)) {
            error_log('WP User GSheet Sync: Invalid configuration - missing spreadsheet ID, fields, or roles for ' . ($config['name'] ?? 'unnamed config'));
            return;
        }

        $this->init_google_client();
        if ($this->service) {
            $this->ensure_sheet_exists();
        }

        // Hooks for WP -> Sheet
        add_action('user_register', [$this, 'sync_user_to_sheet']);
        add_action('profile_update', [$this, 'sync_user_to_sheet']);
        add_action('delete_user', [$this, 'delete_user_from_sheet']);
    }

    private function init_google_client() {
        $this->client = new Google_Client();
        $this->client->setApplicationName('WP User Sync');
        $this->client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $credentials = !empty($this->config['credentials']) ? $this->config['credentials'] : get_option('wp_user_gsheet_global_credentials', '');
        if (empty($credentials)) {
            error_log('WP User GSheet Sync: No credentials provided for spreadsheet ID ' . $this->spreadsheetId);
            return;
        }
        $decoded_credentials = json_decode($credentials, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('WP User GSheet Sync: Invalid JSON credentials for spreadsheet ID ' . $this->spreadsheetId . ': ' . json_last_error_msg());
            return;
        }
        try {
            $this->client->setAuthConfig($decoded_credentials);
            $this->service = new Google_Service_Sheets($this->client);
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to initialize Google Client for spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
        }
    }

    private function ensure_sheet_exists(): bool {
        $sheetId = $this->get_sheet_id();
        if ($sheetId === 0) {
            try {
                $request = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                    'requests' => [[
                        'addSheet' => [
                            'properties' => [
                                'title' => $this->sheetTitle,
                                'gridProperties' => [
                                    'rowCount' => 1000,
                                    'columnCount' => 26
                                ]
                            ]
                        ]
                    ]]
                ]);
                $response = $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
                error_log('WP User GSheet Sync: Created new sheet "' . $this->sheetTitle . '" in spreadsheet ID ' . $this->spreadsheetId);
                
                // Refetch sheet ID after creation
                $this->sheetIdCache = null;
                $sheetId = $this->get_sheet_id();
                if ($sheetId === 0) {
                    error_log('WP User GSheet Sync: Failed to retrieve sheet ID after creation for spreadsheet ID ' . $this->spreadsheetId);
                    return false;
                }
                return true;
            } catch (Exception $e) {
                error_log('WP User GSheet Sync: Failed to create sheet "' . $this->sheetTitle . '" in spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /* ---------------- Utilities ---------------- */

    private function get_sheet_values(): array {
        if (!$this->service) {
            error_log('WP User GSheet Sync: No Google Sheets service initialized for spreadsheet ID ' . $this->spreadsheetId);
            return [];
        }
        try {
            $resp = $this->service->spreadsheets_values->get($this->spreadsheetId, $this->range);
            return $resp->getValues() ?: [];
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to get sheet values for spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
            return [];
        }
    }

    private function put_row(int $rowNumber1Based, array $rowValues): void {
        if (!$this->service) {
            error_log('WP User GSheet Sync: No Google Sheets service initialized for spreadsheet ID ' . $this->spreadsheetId);
            return;
        }
        try {
            $vr = new Google_Service_Sheets_ValueRange(['values' => [$rowValues]]);
            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $this->sheetTitle . '!A' . $rowNumber1Based,
                $vr,
                ['valueInputOption' => 'RAW']
            );
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to update row in spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
        }
    }

    private function append_row(array $rowValues): void {
        if (!$this->service) {
            error_log('WP User GSheet Sync: No Google Sheets service initialized for spreadsheet ID ' . $this->spreadsheetId);
            return;
        }
        try {
            $vr = new Google_Service_Sheets_ValueRange(['values' => [$rowValues]]);
            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $this->sheetTitle,
                $vr,
                ['valueInputOption' => 'RAW']
            );
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to append row in spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
        }
    }

    private function ensure_header_and_get_map(): array {
        $rows = $this->get_sheet_values();
        $expectedHeader = array_merge(['ID'], array_values($this->fields));
        $header = $rows[0] ?? [];

        if (empty($header) || strtoupper(trim((string)$header[0])) !== 'ID') {
            $header = $expectedHeader;
            $vr = new Google_Service_Sheets_ValueRange(['values' => [$header]]);
            try {
                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    $this->sheetTitle . '!A1',
                    $vr,
                    ['valueInputOption' => 'RAW']
                );
                $rows = $this->get_sheet_values();
                $rows[0] = $header;
            } catch (Exception $e) {
                error_log('WP User GSheet Sync: Failed to update header in spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
            }
        }

        $map = [];
        foreach ($header as $i => $label) {
            $map[trim((string)$label)] = $i;
        }

        return [$rows, $header, $map];
    }

    private function idx(array $map, array $labels, $default = null) {
        foreach ($labels as $l) {
            if (isset($map[$l])) return $map[$l];
        }
        return $default;
    }

    private function build_row_from_user(WP_User $user, array $header, array $map): array {
        $width = count($header);
        $row = array_fill(0, $width, '');
        $row[0] = (string)$user->ID;

        foreach ($this->fields as $meta_key => $colName) {
            if (!isset($map[$colName])) continue;

            if ($meta_key === 'role') {
                $row[$map[$colName]] = implode(',', (array)$user->roles);
                continue;
            }

            $v = isset($user->$meta_key) ? $user->$meta_key : get_user_meta($user->ID, $meta_key, true);
            if (is_array($v)) $v = implode(',', $v);
            $row[$map[$colName]] = (string)$v;
        }

        $emailCol = $this->fields['user_email'] ?? null;
        if ($emailCol && isset($map[$emailCol])) {
            $row[$map[$emailCol]] = $user->user_email;
        }
        $loginCol = $this->fields['user_login'] ?? null;
        if ($loginCol && isset($map[$loginCol])) {
            $row[$map[$loginCol]] = $user->user_login;
        }

        return $row;
    }

    private function find_row_index_by_id_or_email(array $rows, array $map, $id, $email): ?int {
        $idIdx = 0;
        $emailCol = $this->fields['user_email'] ?? 'Email';
        $emailIdx = $this->idx($map, [$emailCol], null);
        if ($emailIdx === null) return null;

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if ($id && (string)($r[$idIdx] ?? '') === (string)$id) return $i;
            if ($email && (string)($r[$emailIdx] ?? '') === (string)$email) return $i;
        }
        return null;
    }

    /* ---------------- WP → SHEET ---------------- */

    public function sync_user_to_sheet($user_id) {
        if ($this->in_sheet_to_wp || !$this->service) return;
        $user = get_userdata($user_id);
        if (!$user) return;

        // Check if user has at least one role in config roles
        if (empty(array_intersect($this->roles, (array)$user->roles))) return;

        list($rows, $header, $map) = $this->ensure_header_and_get_map();
        $row = $this->build_row_from_user($user, $header, $map);
        $targetIdx = $this->find_row_index_by_id_or_email($rows, $map, $user->ID, $user->user_email);

        if ($targetIdx !== null) {
            $this->put_row($targetIdx + 1, $row);
        } else {
            $this->append_row($row);
        }
    }

    public function sync_all_wp_to_sheet() {
        if (!$this->service) return;
        $users = get_users();
        foreach ($users as $user) {
            if (!empty(array_intersect($this->roles, (array)$user->roles))) {
                $this->sync_user_to_sheet($user->ID);
            }
        }
    }

    /* ---------------- SHEET → WP ---------------- */

    public function sync_sheet_to_wp() {
        if (!$this->service) return;
        $this->in_sheet_to_wp = true;
        try {
            list($rows, $header, $map) = $this->ensure_header_and_get_map();
            if (count($rows) <= 1) return;

            $emailCol = $this->fields['user_email'] ?? 'Email';
            $emailIdx = $this->idx($map, [$emailCol], null);
            if ($emailIdx === null) {
                error_log('WP User GSheet Sync: No email column mapping for spreadsheet ID ' . $this->spreadsheetId);
                return;
            }

            $loginCol = $this->fields['user_login'] ?? 'Username';
            $loginIdx = $this->idx($map, [$loginCol], null);

            $roleCol = $this->fields['role'] ?? 'User Role';
            $roleIdx = $this->idx($map, [$roleCol], null);

            $firstNameCol = $this->fields['first_name'] ?? 'First Name';
            $firstNameIdx = $this->idx($map, [$firstNameCol], null);

            $lastNameCol = $this->fields['last_name'] ?? 'Last Name';
            $lastNameIdx = $this->idx($map, [$lastNameCol], null);

            foreach (array_slice($rows, 1) as $offset => $row) {
                $rowIndex1Based = $offset + 2;
                $id = trim((string)($row[0] ?? ''));
                $email = trim((string)($row[$emailIdx] ?? ''));
                $role = strtolower(trim((string)($row[$roleIdx] ?? '')));

                // Only process rows with role in config roles
                if (!in_array($role, $this->roles)) continue;

                if ($id === '' && $email === '') continue;

                $existingUser = null;
                if ($id && ($u = get_user_by('ID', (int)$id))) {
                    $existingUser = $u;
                } elseif ($email && ($u = get_user_by('email', $email))) {
                    $existingUser = $u;
                }

                $user_login = trim((string)($row[$loginIdx] ?? ''));
                if ($user_login === '' && $email !== '') {
                    $user_login = sanitize_user(current(explode('@', $email)));
                }

                $userdata = [
                    'user_login' => $user_login,
                    'user_email' => $email,
                ];

                if ($firstNameIdx !== null) {
                    $userdata['first_name'] = trim((string)($row[$firstNameIdx] ?? ''));
                }
                if ($lastNameIdx !== null) {
                    $userdata['last_name'] = trim((string)($row[$lastNameIdx] ?? ''));
                }

                if ($existingUser) {
                    $userdata['ID'] = $existingUser->ID;
                    wp_update_user($userdata);

                    $u = new WP_User($existingUser->ID);
                    $u->set_role($role); // Set to the role from sheet

                    foreach ($this->fields as $meta_key => $colName) {
                        if (!isset($map[$colName])) continue;
                        if (in_array($meta_key, ['user_login', 'user_email', 'first_name', 'last_name'], true)) continue;
                        update_user_meta($existingUser->ID, $meta_key, (string)($row[$map[$colName]] ?? ''));
                    }
                } else {
                    $userdata['user_pass'] = wp_generate_password(16);
                    $userdata['role'] = $role; // Set to the role from sheet
                    if (empty($userdata['user_email'])) continue;

                    $new_id = wp_insert_user($userdata);
                    if (!is_wp_error($new_id)) {
                        foreach ($this->fields as $meta_key => $colName) {
                            if (!isset($map[$colName])) continue;
                            if (in_array($meta_key, ['user_login', 'user_email', 'first_name', 'last_name'], true)) continue;
                            update_user_meta($new_id, $meta_key, (string)($row[$map[$colName]] ?? ''));
                        }
                        $this->put_row($rowIndex1Based, array_replace(
                            array_fill(0, count($header), ''),
                            $rows[$rowIndex1Based - 1],
                            [0 => (string)$new_id]
                        ));
                    }
                }
            }
        } finally {
            $this->in_sheet_to_wp = false;
        }
    }

    /* ---------------- Delete ---------------- */

    public function delete_user_from_sheet($user_id) {
        if (!$this->service) return;
        list($rows, $header, $map) = $this->ensure_header_and_get_map();
        $u = get_userdata($user_id);
        if (!$u || empty(array_intersect($this->roles, (array)$u->roles))) return;
        $email = $u->user_email;
        $idx = $this->find_row_index_by_id_or_email($rows, $map, $user_id, $email);
        if ($idx !== null) {
            $this->delete_sheet_row($idx + 1);
        }
    }

    private function delete_sheet_row(int $rowNumber1Based): void {
        if (!$this->service) return;
        try {
            $sheetId = $this->get_sheet_id();
            $req = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [[
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $rowNumber1Based - 1,
                            'endIndex' => $rowNumber1Based
                        ]
                    ]
                ]]
            ]);
            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $req);
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to delete row in spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
        }
    }

    private function get_sheet_id(): int {
        if ($this->sheetIdCache !== null) return $this->sheetIdCache;
        if (!$this->service) {
            error_log('WP User GSheet Sync: No Google Sheets service initialized for spreadsheet ID ' . $this->spreadsheetId);
            return 0;
        }
        try {
            $ss = $this->service->spreadsheets->get($this->spreadsheetId);
            foreach ($ss->getSheets() as $sheet) {
                $p = $sheet->getProperties();
                if ($p->getTitle() === $this->sheetTitle) {
                    $this->sheetIdCache = (int)$p->getSheetId();
                    error_log('WP User GSheet Sync: Found sheet "' . $this->sheetTitle . '" with ID ' . $this->sheetIdCache . ' in spreadsheet ID ' . $this->spreadsheetId);
                    return $this->sheetIdCache;
                }
            }
            // Sheet not found, return 0 to trigger creation in ensure_sheet_exists
            error_log('WP User GSheet Sync: Sheet "' . $this->sheetTitle . '" not found in spreadsheet ID ' . $this->spreadsheetId);
            return 0;
        } catch (Exception $e) {
            error_log('WP User GSheet Sync: Failed to get sheet ID for spreadsheet ID ' . $this->spreadsheetId . ': ' . $e->getMessage());
            return 0;
        }
    }
}