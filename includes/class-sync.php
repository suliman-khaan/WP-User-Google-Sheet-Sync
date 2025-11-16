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
    }

    private function lazy_init() {
        if ($this->service !== null) {
            return true;
        }

        if (empty($this->spreadsheetId) || empty($this->fields)) {
            return false;
        }

        $this->init_google_client();
        if ($this->service) {
            $this->ensure_sheet_exists();
            return true;
        }
        return false;
    }

    private function init_google_client() {
        $this->client = new Google_Client();
        $this->client->setApplicationName('WP User Sync');
        $this->client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $credentials = !empty($this->config['credentials']) ? $this->config['credentials'] : get_option('wp_user_gsheet_global_credentials', '');
        if (empty($credentials)) {
            return;
        }
        $decoded_credentials = json_decode($credentials, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }
        try {
            $this->client->setAuthConfig($decoded_credentials);
            $this->service = new Google_Service_Sheets($this->client);
        } catch (Exception $e) {
        }
    }

    private function ensure_sheet_exists(): bool {
        $cache_key = 'wp_user_gsheet_sheet_id_' . md5($this->spreadsheetId . '_' . $this->sheetTitle);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->sheetIdCache = (int)$cached;
            return true;
        }

        $sheetId = $this->get_sheet_id();
        if ($sheetId !== null) {
            set_transient($cache_key, $sheetId, 3600);
            return true;
        }
        
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
            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $request);
            $this->sheetIdCache = null;
            delete_transient($cache_key);
            $sheetId = $this->get_sheet_id();
            if ($sheetId !== null) {
                set_transient($cache_key, $sheetId, 3600);
            }
            return $sheetId !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    private function get_sheet_values(): array {
        if (!$this->service) {
            return [];
        }
        try {
            $resp = $this->service->spreadsheets_values->get($this->spreadsheetId, $this->range, ['majorDimension' => 'ROWS']);
            return $resp->getValues() ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function put_row(int $rowNumber1Based, array $rowValues): void {
        if (!$this->service) {
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
        }
    }

    private function append_row(array $rowValues): void {
        if (!$this->service) {
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
        }
    }

    private function ensure_header_and_get_map(): array {
        $cache_key = 'wp_user_gsheet_data_' . md5($this->spreadsheetId . '_' . $this->sheetTitle);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false && is_array($cached_data)) {
            return $cached_data;
        }

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
            }
        }

        $map = [];
        foreach ($header as $i => $label) {
            $map[trim((string)$label)] = $i;
        }
        
        $result = [$rows, $header, $map];
        set_transient($cache_key, $result, 300);
        
        return $result;
    }

    private function idx(array $map, array $labels, $default = null) {
        foreach ($labels as $l) {
            if (isset($map[$l])) return $map[$l];
        }
        return $default;
    }

    private function find_row_index_by_id_or_email(array $rows, array $map, $id, $email): ?int {
        $idIdx = 0;
        $emailCol = $this->fields['user_email'] ?? 'Email';
        $emailIdx = $this->idx($map, [$emailCol], null);
        if ($emailIdx === null) {
            return null;
        }

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if ($id && isset($r[$idIdx]) && (string)$r[$idIdx] === (string)$id) {
                return $i;
            }
            if ($email && isset($r[$emailIdx]) && (string)$r[$emailIdx] === (string)$email) {
                return $i;
            }
        }
        return null;
    }

    public function sync_sheet_to_wp() {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        if (!$this->lazy_init()) {
            $result['errors'][] = 'No Google Sheets service initialized for spreadsheet ID ' . $this->spreadsheetId;
            return $result;
        }

        $this->in_sheet_to_wp = true;
        try {
            list($rows, $header, $map) = $this->ensure_header_and_get_map();
            if (count($rows) <= 1) {
                $result['errors'][] = 'No data rows to sync in sheet "' . $this->sheetTitle . '"';
                return $result;
            }

            $emailCol = $this->fields['user_email'] ?? 'Email';
            $emailIdx = $this->idx($map, [$emailCol], null);
            if ($emailIdx === null) {
                $result['errors'][] = 'No email column mapping for "' . $emailCol . '"';
                return $result;
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
                $id = isset($row[0]) ? trim((string)$row[0]) : '';
                $email = isset($row[$emailIdx]) ? trim((string)$row[$emailIdx]) : '';
                $role = $roleIdx !== null && isset($row[$roleIdx]) ? strtolower(trim((string)$row[$roleIdx])) : '';

                if ($id === '' && $email === '') {
                    $result['skipped']++;
                    continue;
                }

                $existingUser = null;
                if ($id && ($u = get_user_by('ID', (int)$id))) {
                    $existingUser = $u;
                } elseif ($email && ($u = get_user_by('email', $email))) {
                    $existingUser = $u;
                }

                $user_login = $loginIdx !== null && isset($row[$loginIdx]) ? trim((string)$row[$loginIdx]) : '';
                if ($user_login === '' && $email !== '') {
                    $user_login = sanitize_user(current(explode('@', $email)), true);
                }

                $userdata = [
                    'user_email' => $email,
                    'user_login' => $user_login,
                ];

                if ($firstNameIdx !== null && isset($row[$firstNameIdx])) {
                    $userdata['first_name'] = trim((string)$row[$firstNameIdx]);
                }
                if ($lastNameIdx !== null && isset($row[$lastNameIdx])) {
                    $userdata['last_name'] = trim((string)$row[$lastNameIdx]);
                }

                if ($existingUser) {
                    $userdata['ID'] = $existingUser->ID;
                    $result_update = wp_update_user($userdata);
                    if (is_wp_error($result_update)) {
                        $result['errors'][] = 'Failed to update user ID ' . $existingUser->ID . ': ' . $result_update->get_error_message();
                        continue;
                    }

                    if ($role && in_array($role, array_keys(wp_roles()->get_names()))) {
                        $u = new WP_User($existingUser->ID);
                        $u->set_role($role);
                    }

                    foreach ($this->fields as $meta_key => $colName) {
                        if (!isset($map[$colName])) {
                            continue;
                        }
                        if (in_array($meta_key, ['user_login', 'user_email', 'first_name', 'last_name', 'role'], true)) {
                            continue;
                        }
                        $value = isset($row[$map[$colName]]) ? (string)$row[$map[$colName]] : '';
                        update_user_meta($existingUser->ID, $meta_key, $value);
                    }
                    $result['updated']++;
                } else {
                    if (empty($userdata['user_email'])) {
                        $result['skipped']++;
                        continue;
                    }
                    if (empty($userdata['user_login'])) {
                        $result['skipped']++;
                        continue;
                    }

                    $userdata['user_pass'] = wp_generate_password(16, true);
                    $userdata['role'] = $role && in_array($role, array_keys(wp_roles()->get_names())) ? $role : 'company';
                    $new_id = wp_insert_user($userdata);
                    if (is_wp_error($new_id)) {
                        $result['errors'][] = 'Failed to create user for row ' . $rowIndex1Based . ': ' . $new_id->get_error_message();
                        continue;
                    }

                    foreach ($this->fields as $meta_key => $colName) {
                        if (!isset($map[$colName])) {
                            continue;
                        }
                        if (in_array($meta_key, ['user_login', 'user_email', 'first_name', 'last_name', 'role'], true)) {
                            continue;
                        }
                        $value = isset($row[$map[$colName]]) ? (string)$row[$map[$colName]] : '';
                        update_user_meta($new_id, $meta_key, $value);
                    }

                    $this->update_sheet_id($rowIndex1Based, $new_id);
                    $result['created']++;
                }
            }
            
            $cache_key = 'wp_user_gsheet_data_' . md5($this->spreadsheetId . '_' . $this->sheetTitle);
            delete_transient($cache_key);
            
        } catch (Exception $e) {
            $result['errors'][] = 'Sync failed for sheet "' . $this->sheetTitle . '": ' . $e->getMessage();
        } finally {
            $this->in_sheet_to_wp = false;
        }

        return $result;
    }

    private function update_sheet_id(int $rowNumber1Based, int $new_id): void {
        if (!$this->service) {
            return;
        }
        try {
            $vr = new Google_Service_Sheets_ValueRange(['values' => [[(string)$new_id]]]);
            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $this->sheetTitle . '!A' . $rowNumber1Based,
                $vr,
                ['valueInputOption' => 'RAW']
            );
        } catch (Exception $e) {
        }
    }

    private function get_sheet_id(): ?int {
        if ($this->sheetIdCache !== null) {
            return $this->sheetIdCache;
        }
        
        $cache_key = 'wp_user_gsheet_sheet_id_' . md5($this->spreadsheetId . '_' . $this->sheetTitle);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->sheetIdCache = (int)$cached;
            return $this->sheetIdCache;
        }
        
        if (!$this->service) {
            return null;
        }
        
        try {
            $ss = $this->service->spreadsheets->get($this->spreadsheetId, ['includeGridData' => false]);
            foreach ($ss->getSheets() as $sheet) {
                $p = $sheet->getProperties();
                if (strcasecmp($p->getTitle(), $this->sheetTitle) === 0) {
                    $this->sheetIdCache = (int)$p->getSheetId();
                    set_transient($cache_key, $this->sheetIdCache, 3600);
                    return $this->sheetIdCache;
                }
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}