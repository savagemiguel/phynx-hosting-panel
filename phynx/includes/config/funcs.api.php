<?php
class functions {
    #!SECTION: SSL activated?
    public static function getServerInfo($conn): array {
        return [
            'server_version' => $conn->get_server_info(),
            'protocol_version' => $conn->protocol_version,
            'server_status' => $conn ? 'ALIVE' : 'DEAD',
            'server_type' => $conn->get_server_info() ? 'MySQL' : 'UNKNOWN',
            'connection_info' => $conn->host_info,
            'ssl_enabled' => self::getSSLStatus($conn),
            'php_version' => getenv('PHP_VERSION')
        ];
    }

    #!SECTION: PHP Version Checker
    public static function getPHPVersion() {
        $current_version = phpversion();

        // Try to create cache directory
        $cache_dir = 'includes/cache';
        if (!is_dir($cache_dir)) {
            if (!@mkdir($cache_dir, 0777, true)) {
                // If cache cannot be created, just return current version
                return [
                    'current' => $current_version,
                    'latestl' => null,
                    'outdated' => null
                ];
            }
        }

        $cache_file = $cache_dir .'/php_version.json';
        $cache_duration = 3600; // 1 hour

        // Check if cache exists and is still valid
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if ($cached_data && isset($cached_data['latest'])) {
                return [
                    'current' => $current_version,
                    'latest' => $cached_data['latest'],
                    'last_updated' => date('m-d-Y h:i:sA', filemtime($cache_file)),
                    'outdated' => version_compare($current_version, $cached_data['latest'], '<'),
                    'is_latest' => version_compare($current_version, $cached_data['latest'], '='),                    
                ];
            }
        }

        // Try API call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://php.watch/api/v1/versions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHYNX Admin');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response && $http_code == 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['version'])) {
                $latest_version = $data['version'];
                file_put_contents($cache_file, json_encode(['latest' => $latest_version, 'last_updated' => date('m-d-Y h:i:sA')]));

                return [
                    'current' => $current_version,
                    'latest' => $latest_version,
                    'last_updated' => date('m-d-Y h:i:sA'),
                    'outdated' => version_compare($current_version, $latest_version, '<'),
                    'is_latest' => version_compare($current_version, $latest_version, '=')
                ];
            }
        }

        return [
            'current' => $current_version,
            'latest' => null,
            'outdated' => null
        ];
    }

    #!SECTION: MySQL Version Checker
    public static function getMySQLVersion($conn) {
        $current_version = $conn->get_server_info();

        // Try to create cache directory
        $cache_dir = 'includes/cache';
        if (!is_dir($cache_dir)) {
            if (!mkdir($cache_dir, 0777, true)) {
                return [
                    'current' => $current_version,
                    'latest' => null,
                    'outdated' => null
                ];
            }
        }

        $cache_file = $cache_dir . '/mysql_version.json';
        $cache_duration = 3600; // 1 hour
    
        // Check if cache exists and is valid
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
            $cached_data = json_decode(file_get_contents($cache_file), true);
            if ($cached_data && isset($cached_data['latest'])) {
                return [
                    'current' => $current_version,
                    'latest' => $cached_data['latest'],
                    'outdated' => version_compare($current_version, $cached_data['latest'], '<')
                ];
            }
        }
    
        // Fetch from GitHub API with better error handling
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/mysql/mysql-server/releases/latest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHYNX Admin');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response && $http_code == 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['tag_name'])) {
                $latest_version = ltrim($data['tag_name'], 'v');
                file_put_contents($cache_file, json_encode(['latest' => $latest_version]));

                return [
                    'current' => $current_version,
                    'latest' => $latest_version,
                    'outdated' => version_compare($current_version, $latest_version, '<')
                ];
            }
        }

        // If API fails and no cache, return null for latest
        return [
            'current' => $current_version,
            'latest' => null,
            'outdated' => null
        ];
    }

    #!SECTION: MYSQL TABLE SQL ERROR QUERIES
    public static function getFriendlySQLError($error, $sql = '') {
        // Normalize inputs to avoid null/deprecated warnings
        $error = (string) ($error ?? '');
        $sql = (string) ($sql ?? '');
        $sql_trimmed = trim($sql);

        // Split top-level comma separated items
        $splitTopLevel = function(string $s): array {
            $items = [];
            $buf = '';
            $depth = 0;
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $ch = $s[$i];
                if ($ch === '(') { $depth++; $buf .= $ch; continue; }
                if ($ch === ')') { $depth = max(0, $depth - 1); $buf .= $ch; continue; }
                if ($ch === ',' && $depth === 0) {
                    $items[] = trim($buf);
                    $buf = '';
                    continue;
                }
                $buf .= $ch;
            }
            
            if (strlen(trim($buf)) > 0) $items[] = trim($buf);
            return $items;
        };

        // Extract table name and logic for CREATE TABLE from user's SQL query
        if (preg_match('/CREATE\s+TABLE\s+`?([A-Za-z0-9_\-]+)`?\s*\((.*?)\)\s*(?:ENGINE|DEFAULT|CHARSET|;|$)/is', $sql_trimmed, $m)) {
            $table_name = $m[1];
            $inner = trim($m[2]);

            // No columns defined
            if ($inner === '') {
                return "No columns are defined in your CREATE TABLE statement for <strong>{$table_name}</strong>.<br />You must specify at least one column.";
            }

            // Check for trailing commas
            if (preg_match('/,\s*$/', rtrim($inner))) {
                $fixed = rtrim($inner);
                $fixed = rtrim($fixed, ', ');
                return "It looks like you have a trailing comma before the closing parenthesis in your CREATE TABLE for <strong>{$table_name}</strong>.<br />
                <strong>EXAMPLE:</strong><br /><code>CREATE TABLE {$table_name} (".htmlspecialchars($fixed).");</code><br />Remove the extra comma after the last column and try again.";
            }
        
            // Missing comma between columns
            if (preg_match('/`?\w+`?\s+\w+(?:\([^\)]*\))?\s+`?\w+`?\s+\w+/i', $inner)) {
                return "It looks like you may have forgotten a comma between column definitions in your CREATE TABLE statement for <strong>$table_name</strong>.<br />
                Make sure EACH column definition is separated by a comma.";
            }

            // No columns defined
            if (strlen(trim($inner)) === 0) {
                return "No columns are defined in your CREATE TABLE statement for <strong>$table_name</strong>.<br />
                You MUST specify at least ONE column in your statement.";
            }

            // Duplicate column name
            $cols = $splitTopLevel($inner);
            $names = [];
            foreach ($cols as $col_def) {
                if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/i', $col_def, $cm)) {
                    $cname = strtolower($cm[1]);
                    if (in_array($cname, $names, true)) {
                        return "Duplicate column name '<strong>{$cname}</strong>' found in your CREATE TABLE statement for <strong>{$table_name}</strong>.<br />Each column name must be unique.";
                    }
                    $names[] = $cname;
                }
            }
        }

        // Unmatches parenthesis
        $open = substr_count($sql, '(');
        $close = substr_count($sql, ')');
        if ($open > $close) {
            return "It looks like you have an unmatched opening parenthesis in your SQL query.<br />
            Make sure every '(' has a corresponding ')'.";
        }

        if ($close > $open) {
            return "It looks like you have an unmatched closing parenthesis in your SQL query.<br />
            Make sure every ')' has a corresponding '('.";
        }

        // Reserved word as column/table name
        $reserved = ['key', 'order', 'group', 'select', 'from', 'where'];
        foreach ($reserved as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b\s+`?[A-Za-z0-9_]+`?/i', $sql) && stripos($sql, "`{$word}`") === false) {
                return "You are using the reserved word '<strong>$word</strong>' as a column or table name. Enclose it in backtiks: <code>`{$word}`</code>.";
            }
        }

        // Incorrect data type or typo in data type
        if (preg_match('/\b(VARCAHR|STRIGN|NUMLERIC|DATTE|TIMESTEMP|INIT)\b/i', $sql, $typo)) {
            $found = strtoupper($typo[1]);
            return "Possible typo in data type: '<strong>{$found}</strong>'.<br />
            Please check your column data types for spelling errors.";
        }

        // Missing or extra quotes
        if (substr_count($sql, "'") % 2 !== 0) {
            return "It looks like you have an unmatched single quote in your SQL. Make sure all single quotes (') are closed.";
        }

        // Missing or unbalanced quotes
        if ((substr_count($sql, '"') % 2) !== 0) {
            return "It looks like you have an unmatched double quote in your SQL. Make sure all double quotes (\") are closed.";
        }

        // Incorrect use of AUTO_INCREMENT
        if (strpos($error, 'auto column') !== false || stripos($error, 'AUTO_INCREMENT') !== false) {
            return "AUTO_INCREMENT columns must be defined as a PRIMARY KEY or UNIQUE.<br />
            EXAMPLE: <code>id INT PRIMARY KEY AUTO_INCREMENT</code>";
        }

        // Foreign key constraint errors
        if (stripos($error, 'foreign key constraint') !== false || stripos($error, 'cannot add foreign key') !== false) {
            return "There is a problem with your FOREIGN KEY constraint. Ensure the referenced table/column exist and data types/indexes match between the referencing and referenced columns.";
        }

        // Syntax error near ENGINE or table options
        if (stripos($error, 'near') !== false && stripos($error, 'engine') !== false || stripos($error, 'ENGINE=') !== false) {
            $example_table = $table_name ?? 'mytable';
            return "There may be a typo or misplaced table option (like ENGINE) in your SQL.<br />EXAMPLE:<br /><code>CREATE TABLE {$example_table} (... ) ENGINE=InnoDB;</code>";
        }

        // Missing semicolon between multiple statements
        if (preg_match('/You have an error in your SQL syntax;.*near.*CREATE TABLE/i', $error) && substr_count($sql, ';') < 1) {
            return "You may be missing a semicolon between multiple SQL statements.<br />
            Separate each statement with a semicolon (;).";
        }

        // General fallback
        return '';
    }

    /**
     * Global database operation handler with integrated error display
     * @param mysqli $conn Database connection
     * @param string $sql SQL query to execute
     * @param string $success_message Success message to display
     * @param string $operation Operation type for error context
     * @param bool $return_result Whether to return the result set
     * @param bool $silent Whether to suppress output (for AJAX calls)
     * @return array Operation result with status, data, and HTML output
     */
    public static function executeSQL($conn, $sql, $success_message = '', $operation = 'Database Operation', $return_result = false, $silent = false) {
        // Enable strict error reporting
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        try {
            $result = $conn->query($sql);
            
            if ($result) {
                $output = '';
                $data = null;
                
                // Handle different result types
                if ($return_result && $result instanceof mysqli_result) {
                    $data = [];
                    while ($row = $result->fetch_assoc()) {
                        $data[] = $row;
                    }
                }
                
                // Generate success output if not silent
                if (!$silent) {
                    if (function_exists('renderSQL')) {
                        ob_start();
                        renderSQL($sql, $success_message ?: "$operation completed successfully.", false);
                        $output = ob_get_clean();
                    } else {
                        $output = '<div class="sql-result-container success">
                            <div class="sql-query">' . htmlspecialchars($sql) . '</div>
                            <div class="sql-message success">' . htmlspecialchars($success_message ?: "$operation completed successfully.") . '</div>
                        </div>';
                    }
                }
                
                return [
                    'success' => true,
                    'result' => $result,
                    'data' => $data,
                    'html' => $output,
                    'message' => $success_message ?: "$operation completed successfully.",
                    'sql' => $sql,
                    'affected_rows' => $conn->affected_rows,
                    'insert_id' => $conn->insert_id
                ];
            }
            
        } catch (mysqli_sql_exception $e) {
            return self::handleSQLError($e, $sql, $operation, $silent);
        } catch (Exception $e) {
            return self::handleSQLError($e, $sql, $operation, $silent);
        }
        
        // Fallback for other errors
        return self::handleSQLError(new Exception($conn->error ?: 'Unknown database error', $conn->errno), $sql, $operation, $silent);
    }
    
    /**
     * Handle SQL errors with friendly messages and renderSQL integration
     * @param Exception $e Exception object
     * @param string $sql SQL query that failed
     * @param string $operation Operation type
     * @param bool $silent Whether to suppress output
     * @return array Error result
     */
    private static function handleSQLError($e, $sql, $operation, $silent = false) {
        $error_message = $e->getMessage();
        $error_code = $e->getCode();
        
        // Get friendly error message
        $friendly_error = self::getFriendlySQLError($error_message, $sql);
        $display_message = $friendly_error ?: $error_message;
        
        $output = '';
        
        // Generate error output if not silent
        if (!$silent) {
            if (function_exists('renderSQL')) {
                ob_start();
                renderSQL($sql, $display_message, true);
                $output = ob_get_clean();
            } else {
                $output = '<div class="sql-result-container error">
                    <div class="sql-query">' . htmlspecialchars($sql) . '</div>
                    <div class="sql-message error">' . htmlspecialchars($display_message) . '</div>
                    <div class="sql-error-code">Error Code: ' . $error_code . '</div>
                </div>';
            }
        }
        
        return [
            'success' => false,
            'error' => true,
            'result' => false,
            'data' => null,
            'html' => $output,
            'message' => $display_message,
            'raw_error' => $error_message,
            'error_code' => $error_code,
            'sql' => $sql,
            'operation' => $operation
        ];
    }
    
    /**
     * Execute multiple SQL statements in a transaction
     * @param mysqli $conn Database connection
     * @param array $sql_statements Array of SQL statements
     * @param string $success_message Success message
     * @param string $operation Operation description
     * @param bool $silent Whether to suppress output
     * @return array Transaction result
     */
    public static function executeTransaction($conn, $sql_statements, $success_message = '', $operation = 'Transaction', $silent = false) {
        $conn->autocommit(false);
        $conn->begin_transaction();
        
        $results = [];
        $all_sql = implode(";\n", $sql_statements);
        
        try {
            foreach ($sql_statements as $sql) {
                $result = self::executeSQL($conn, $sql, '', $operation, false, true); // Silent execution
                
                if (!$result['success']) {
                    throw new Exception($result['message'], $result['error_code']);
                }
                
                $results[] = $result;
            }
            
            $conn->commit();
            $conn->autocommit(true);
            
            $output = '';
            if (!$silent) {
                if (function_exists('renderSQL')) {
                    ob_start();
                    renderSQL($all_sql, $success_message ?: "$operation completed successfully.", false);
                    $output = ob_get_clean();
                } else {
                    $output = '<div class="sql-result-container success">
                        <div class="sql-query">' . htmlspecialchars($all_sql) . '</div>
                        <div class="sql-message success">' . htmlspecialchars($success_message ?: "$operation completed successfully.") . '</div>
                    </div>';
                }
            }
            
            return [
                'success' => true,
                'results' => $results,
                'html' => $output,
                'message' => $success_message ?: "$operation completed successfully.",
                'sql' => $all_sql
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            $conn->autocommit(true);
            
            return self::handleSQLError($e, $all_sql, $operation, $silent);
        }
    }
    
    /**
     * Quick helper methods for common operations
     */
    
    public static function dropColumn($conn, $table, $column, $silent = false) {
        $sql = "ALTER TABLE `$table` DROP COLUMN `$column`";
        return self::executeSQL($conn, $sql, "Column '$column' dropped successfully from table '$table'.", "Drop Column", false, $silent);
    }
    
    public static function addColumn($conn, $table, $column_definition, $silent = false) {
        $sql = "ALTER TABLE `$table` ADD COLUMN $column_definition";
        return self::executeSQL($conn, $sql, "Column added successfully to table '$table'.", "Add Column", false, $silent);
    }
    
    public static function createTable($conn, $table, $definition, $silent = false) {
        $sql = "CREATE TABLE `$table` ($definition)";
        return self::executeSQL($conn, $sql, "Table '$table' created successfully.", "Create Table", false, $silent);
    }
    
    public static function dropTable($conn, $table, $silent = false) {
        $sql = "DROP TABLE `$table`";
        return self::executeSQL($conn, $sql, "Table '$table' dropped successfully.", "Drop Table", false, $silent);
    }
    
    public static function insertRow($conn, $table, $data, $silent = false) {
        $fields = array_keys($data);
        $values = array_values($data);
        
        $escaped_fields = array_map(function($field) { return "`$field`"; }, $fields);
        $escaped_values = array_map(function($value) use ($conn) { 
            return "'" . $conn->real_escape_string($value) . "'"; 
        }, $values);
        
        $sql = "INSERT INTO `$table` (" . implode(', ', $escaped_fields) . ") VALUES (" . implode(', ', $escaped_values) . ")";
        return self::executeSQL($conn, $sql, "Row inserted successfully into table '$table'.", "Insert Row", false, $silent);
    }
    
    public static function updateRow($conn, $table, $data, $where_condition, $silent = false) {
        $updates = [];
        foreach ($data as $field => $value) {
            $updates[] = "`$field` = '" . $conn->real_escape_string($value) . "'";
        }
        
        $sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE $where_condition";
        return self::executeSQL($conn, $sql, "Row updated successfully in table '$table'.", "Update Row", false, $silent);
    }
    
    public static function deleteRow($conn, $table, $where_condition, $silent = false) {
        $sql = "DELETE FROM `$table` WHERE $where_condition";
        return self::executeSQL($conn, $sql, "Row deleted successfully from table '$table'.", "Delete Row", false, $silent);
    }
    
    public static function selectData($conn, $sql, $silent = false) {
        return self::executeSQL($conn, $sql, "Query executed successfully.", "Select Data", true, $silent);
    }

    #!SECTION: SSL function
    private static function getSSLStatus($ssl_status) {
        $ssl_status = '';

        if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) {
            return $ssl_status = 'ENABLED';
        } else {
            $ssl_status = 'DISABLED';
        }

        return $ssl_status ? 'ENABLED' : 'DISABLED';
    }

    /**
     * Check if a message indicates a successful operation
     * @param string $message The message to check
     * @return bool True if it's a success message
     */
    public static function isSuccessMessage($message) {
        if (empty($message)) return false;
        
        $success_keywords = [
            'successfully', 'success', 'completed', 'added', 'updated', 
            'inserted', 'created', 'dropped', 'deleted', 'removed', 
            'optimized', 'repaired', 'analyzed', 'truncated', 'exported',
            'imported', 'copied', 'renamed', 'modified', 'altered'
        ];
        
        $message_lower = strtolower($message);
        foreach ($success_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get appropriate success icon based on message content
     * @param string $message The success message
     * @return string FontAwesome icon class
     */
    public static function getSuccessIcon($message) {
        if (empty($message)) return 'fa-check-circle';
        
        $message_lower = strtolower($message);
        
        if (strpos($message_lower, 'dropped') !== false || strpos($message_lower, 'deleted') !== false) {
            return 'fa-trash-check';
        } elseif (strpos($message_lower, 'added') !== false || strpos($message_lower, 'created') !== false) {
            return 'fa-plus-circle';
        } elseif (strpos($message_lower, 'updated') !== false || strpos($message_lower, 'modified') !== false || strpos($message_lower, 'altered') !== false) {
            return 'fa-edit';
        } elseif (strpos($message_lower, 'inserted') !== false) {
            return 'fa-database';
        } elseif (strpos($message_lower, 'optimized') !== false || strpos($message_lower, 'repaired') !== false || strpos($message_lower, 'analyzed') !== false) {
            return 'fa-tools';
        } elseif (strpos($message_lower, 'exported') !== false) {
            return 'fa-download';
        } elseif (strpos($message_lower, 'imported') !== false) {
            return 'fa-upload';
        } elseif (strpos($message_lower, 'copied') !== false) {
            return 'fa-copy';
        } elseif (strpos($message_lower, 'renamed') !== false) {
            return 'fa-signature';
        } elseif (strpos($message_lower, 'truncated') !== false) {
            return 'fa-cut';
        } else {
            return 'fa-check-circle';
        }
    }

    /**
     * Get contextual actions based on current page and operation
     * @param string $page Current page/tab
     * @param string $selected_db Current database
     * @param string $selected_table Current table
     * @param string $message Success message for context
     * @return array Array of action arrays with url, icon, text, and class
     */
    public static function getContextualActions($page, $selected_db, $selected_table, $message = '') {
        $actions = [];
        $message_lower = strtolower($message);
        
        // Base URLs
        $base_url = "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table);
        
        switch ($page) {
            case 'structure':
            case 'edit_column':
            case 'drop_column':
                $actions[] = [
                    'url' => $base_url . "&tab=structure",
                    'icon' => 'fa-table',
                    'text' => 'View Table Structure',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn btn-secondary'
                ];
                
                // If column was added/modified, suggest adding another
                if (strpos($message_lower, 'added') !== false || strpos($message_lower, 'column') !== false) {
                    $actions[] = [
                        'url' => $base_url . "&tab=structure#add-column",
                        'icon' => 'fa-plus',
                        'text' => 'Add Another Column',
                        'class' => 'btn btn-success'
                    ];
                }
                break;
                
            case 'insert':
                $actions[] = [
                    'url' => $base_url . "&tab=insert",
                    'icon' => 'fa-plus',
                    'text' => 'Insert Another Row',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn btn-secondary'
                ];
                break;
                
            case 'operations':
                $actions[] = [
                    'url' => $base_url . "&tab=operations",
                    'icon' => 'fa-clipboard-list',
                    'text' => 'More Operations',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn btn-secondary'
                ];
                
                // If table was truncated, suggest inserting data
                if (strpos($message_lower, 'truncated') !== false) {
                    $actions[] = [
                        'url' => $base_url . "&tab=insert",
                        'icon' => 'fa-plus',
                        'text' => 'Insert New Data',
                        'class' => 'btn btn-success'
                    ];
                }
                break;
                
            case 'sql':
                $actions[] = [
                    'url' => $base_url . "&tab=sql",
                    'icon' => 'fa-code',
                    'text' => 'Execute More SQL',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn btn-secondary'
                ];
                break;
                
            case 'export':
                $actions[] = [
                    'url' => $base_url . "&tab=export",
                    'icon' => 'fa-download',
                    'text' => 'Export Again',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn btn-secondary'
                ];
                break;
                
            case 'search':
                $actions[] = [
                    'url' => $base_url . "&tab=search",
                    'icon' => 'fa-search',
                    'text' => 'Search Again',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse All Data',
                    'class' => 'btn btn-secondary'
                ];
                break;
                
            default:
                // Browse page or unknown page
                $actions[] = [
                    'url' => $base_url,
                    'icon' => 'fa-eye',
                    'text' => 'Browse Table Data',
                    'class' => 'btn'
                ];
                $actions[] = [
                    'url' => $base_url . "&tab=structure",
                    'icon' => 'fa-table',
                    'text' => 'View Table Structure',
                    'class' => 'btn btn-secondary'
                ];
                
                // If row was inserted/updated, suggest inserting another
                if (strpos($message_lower, 'inserted') !== false || strpos($message_lower, 'updated') !== false) {
                    $actions[] = [
                        'url' => $base_url . "&tab=insert",
                        'icon' => 'fa-plus',
                        'text' => 'Insert Another Row',
                        'class' => 'btn btn-success'
                    ];
                }
                break;
        }
        
        return $actions;
    }

    /**
     * Render success state HTML
     * @param string $message Success message
     * @param string $page Current page
     * @param string $selected_db Current database
     * @param string $selected_table Current table
     * @return string HTML for success state
     */
    public static function renderSuccessState($message, $page, $selected_db, $selected_table) {
        $icon = self::getSuccessIcon($message);
        $actions = self::getContextualActions($page, $selected_db, $selected_table, $message);
        
        $html = '<div class="success-state">';
        $html .= '<div class="success-icon">';
        $html .= '<i class="fas ' . $icon . '"></i>';
        $html .= '</div>';
        $html .= '<h3>Operation Completed Successfully</h3>';
        $html .= '<p class="success-message">' . htmlspecialchars($message) . '</p>';
        
        if (!empty($actions)) {
            $html .= '<div class="success-actions">';
            foreach ($actions as $action) {
                $html .= '<a href="' . $action['url'] . '" class="' . $action['class'] . '">';
                $html .= '<i class="fas ' . $action['icon'] . '"></i> ' . $action['text'];
                $html .= '</a>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Check if message is an error message
     * @param string $message The message to check
     * @return bool True if it's an error message
     */
    public static function isErrorMessage($message) {
        if (empty($message)) return false;
        
        $error_keywords = [
            'error:', 'failed', 'cannot', 'unable', 'invalid', 'denied', 
            'forbidden', 'not found', 'duplicate', 'syntax error', 'timeout'
        ];
        
        $message_lower = strtolower($message);
        foreach ($error_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Render message with appropriate styling
     * @param string $message The message to display
     * @param string $page Current page (optional, for contextual actions)
     * @param string $selected_db Current database (optional)
     * @param string $selected_table Current table (optional)
     * @return string HTML for the message display
     */
    public static function renderMessage($message, $page = '', $selected_db = '', $selected_table = '') {
        if (empty($message)) return '';
        
        if (self::isSuccessMessage($message)) {
            return self::renderSuccessState($message, $page, $selected_db, $selected_table);
        } elseif (self::isErrorMessage($message)) {
            return '<div class="info-box error-message">
                <i class="fas fa-exclamation-triangle"></i>
                ' . htmlspecialchars($message) . '
            </div>';
        } else {
            // Neutral/info message
            return '<div class="info-box info-message">
                <i class="fas fa-info-circle"></i>
                ' . htmlspecialchars($message) . '
            </div>';
        }
    }

    #!SECTION: Breadcrumb function
    /**
     * Generate hierarchical breadcrumbs from a given path.
     * @uses Font-Awesome icons for separator
     * @param array $conn => Database connection
     * @return => string HTML breadcrumb navigation with propper icons
     */

    public static function generateBreadcrumbs($conn = null) {
        $breadcrumbs = [];

        // Get URL parameters
        $db = $_GET['db'] ?? $_POST['db'] ?? '';
        $table = $_GET['table'] ?? $_POST['table'] ?? '';
        $page = $_GET['page'] ?? $_POST['page'] ?? '';
        $tab = $_GET['tab'] ?? $_POST['tab'] ?? '';

        // Get server/host info
        $host_info = 'localhost';
        if ($conn) {
            $connection_info = self::getServerInfo($conn)['connection_info'];
            $host_info = preg_replace('/ via .+$/', '', $connection_info);
        }

        // Host level - awlays present, links to home page
        $breadcrumbs[] = ['label' => $host_info, 'icon' => 'fas fa-server', 'url' => '?'];

        // Database level
        if ($db) {
            $breadcrumbs[] = ['label' => $db, 'icon' => 'fas fa-database', 'url' => '?db='.urlencode($db)];
        }

        // Table level
        if ($table && $db) {
            $breadcrumbs[] = ['label' => $table, 'icon' => 'fas fa-table', 'url' => '?db=' . urlencode($db) . '&table=' . urlencode($table)];
        }

        // Page/Tab level structure
        $current_section = $page ?: $tab;
        if ($current_section) {
            $page_config = [
                // Table pages
                'structure' => ['label' => 'Structure', 'icon' => 'fas fa-table'],
                'browse' => ['label' => 'Browse', 'icon' => 'fas fa-browser'],
                'sql' => ['label' => 'SQL', 'icon' => 'fas fa-code'],
                'search' => ['label' => 'Search', 'icon' => 'fas fa-search'],
                'insert' => ['label' => 'Insert', 'icon' => 'fas fa-plus'],
                'operations' => ['label' => 'Operations', 'icon' => 'fas fa-clipboard-list'],
                'triggers' => ['label' => 'Triggers', 'icon' => 'fas fa-bug'],
                'foreign_keys' => ['label' => 'Foreign Keys', 'icon' => 'fas fa-link'],
                'export' => ['label' => 'Export', 'icon' => 'fas fa-download'],
                'import' => ['label' => 'Import', 'icon' => 'fas fa-upload'],
                
                // Database pages
                'create_table' => ['label' => 'Create Table', 'icon' => 'fas fa-table'],
                'delete' => ['label' => 'Delete Table', 'icon' => 'fas fa-trash'],
                
                // Global pages
                'config' => ['label' => 'Configuration', 'icon' => 'fas fa-cogs'],
                'php_ini' => ['label' => 'PHP Configuration', 'icon' => 'fas fa-file-code'],
                'user_accounts' => ['label' => 'User Accounts', 'icon' => 'fas fa-users'],
                'users' => ['label' => 'User Accounts', 'icon' => 'fas fa-users'],
                'settings' => ['label' => 'Settings', 'icon' => 'fas fa-sliders-h'],
                'backup' => ['label' => 'Backup', 'icon' => 'fas fa-backup'],
                'restore' => ['label' => 'Restore', 'icon' => 'fas fa-window-restore'],
                'create_database' => ['label' => 'Create Database', 'icon' => 'fas fa-plus'],
                'delete_database' => ['label' => 'Delete Database', 'icon' => 'fas fa-trash'],
                'export_users' => ['label' => 'Export Users', 'icon' => 'fas fa-download'],
                'edit_user' => ['label' => 'Edit User', 'icon' => 'fas fa-user-edit'],
                'edit_privileges' => ['label', 'Edit Privileges', 'icon' => 'fas fa-user-edit'],

                // Quick actions and search
                'global_search' => ['label' => 'Global Search', 'icon' => 'fas fa-globe'],
                'empty_tables' => ['label' => 'Empty Tables', 'icon' => 'fas fa-table'],
                'large_tables' => ['label' => 'Large Tables', 'icon' => 'fas fa-weight-hanging'],
                'recent_tables' => ['label' => 'Recent Tables', 'icon' => 'fas fa-clock'],
                'indexes' => ['label' => 'Indexes', 'icon' => 'fas fa-key'],

                // Operations
                'rename' => ['label' => 'Rename', 'icon' => 'fas fa-edit'],
                'copy' => ['label' => 'Copy', 'icon' => 'fas fa-copy'],
                'optimize' => ['label' => 'Optimize', 'icon' => 'fas fa-tools'],
                'repair' => ['label' => 'Repair', 'icon' => 'fas fa-wrench'],
                'analyze' => ['label' => 'Analyze', 'icon' => 'fas fa-chart-line'],
                'empty' => ['label' => 'Empty', 'icon' => 'fas fa-cut'],
                
                // Column Operations
                'edit_column' => ['label' => 'Edit Column', 'icon' => 'fas fa-edit'],
                'drop_column' => ['label' => 'Drop Column', 'icon' => 'fas fa-trash'],
                'add_column' => ['label' => 'Add Column', 'icon' => 'fas fa-plus'],
                'add_index' => ['label' => 'Add Index', 'icon' => 'fas fa-key']
            ];

            $config = $page_config[$current_section] ?? ['label' => ucfirst($current_section), 'icon' => 'fas fa-file'];

            $breadcrumbs[] = ['label' => $config['label'], 'icon' => $config['icon'], 'url' => '']; // Current page
        }

        return self::generateBreadcrumbsWithIcons($breadcrumbs, ' <i class="fas fa-angle-right"></i> ');
    }

    /**
     * Generate breadcrumbs with icons HTML strings
     * @param array $breadcrumbs => Array of breadcrumb items with label, icon, URL
     * @param string $separator => Separator between breadcrumb items
     * @return string HTML breadcrumb navigation
     */

    public static function generateBreadcrumbsWithIcons($breadcrumbs = [], $separator = ' <i class="fas fa-angle-right"></i> ') {
        if (empty($breadcrumbs)) {
            return '';
        }

        $html = '<div class="breadcrumb">';
        $breadcrumb_items = [];

        foreach ($breadcrumbs as $index => $crumb) {
            $label = htmlspecialchars($crumb['label']);
            $icon = $crumb['icon'] ?? '';
            $is_last = ($index === count($breadcrumbs) - 1);
            $is_root = (count($breadcrumbs) === 1);

            // The last item is only text if it's not the only item (the root)
            if (($is_last && !$is_root) || empty($crumb['url'])) {
                if ($icon) {
                    $breadcrumb_items[] = '<span class="breadcrumb_text"><i class="'.$icon.'"></i> '.$label.'</span>';
                } else {
                    $breadcrumb_items[] = '<span class="breadcrumb_text">'.$label.'</span>';
                }
            } else {
                // Clickable link with icon
                $url = htmlspecialchars($crumb['url']);
                if ($icon) {
                    $breadcrumb_items[] = '<a href="'.$url.'"><i class="'.$icon.'"></i> ' . $label . '</a>';
                } else {
                    $breadcrumb_items[] = '<a href="'.$url.'">' . $label . '</a>';
                }
            }
        }

        $html .= implode($separator, $breadcrumb_items);
        $html .= '</div>';

        return $html;
    }

    /**
     * Quick breadcrumb function for specific contexts
     * @param string $context => Context type (table, database, config, etc.)
     * @param array $params => Parameters for the context
     * @param array $conn => Database connection
     * @return string HTML breadcrumb navigation
     */

    public static function getContextBreadcrumbs($context, $params = [], $conn = null) {
        switch ($context) {
            case 'table_structure':
                return self::generateTableBreadcrumbs($params['db'], $params['table'], 'structure', $conn);

            case 'database_view':
                return self::generateDatabaseBreadcrumbs($params['db'], $params['tab'] ?? '', $conn);

            case 'config_page':
                return self::generateConfigBreadcrumbs($params['page'], $conn);

            case 'user_management':
                return self::generateUserBreadcrumbs($params['action'] ?? '', $params['user'] ?? '', $conn);

            default:
                return self::generateBreadcrumbs($conn);
        }
    }

    /**
     * Generate table-specific breadcrumbs
     */

    private static function generateTableBreadcrumbs($db, $table, $page, $conn = null) {
        $breadcrumbs = [];

        $host_info = self::getHostInfo($conn);
        $breadcrumbs[] = ['label' => $host_info, 'icon' => 'fas fa-server', 'url' => '?'];
        $breadcrumbs[] = ['label' => $db, 'icon' => 'fas fa-database', 'url' => '?db='.urlencode($db)];
        $breadcrumbs[] = ['label' => $table, 'icon' => 'fas fa-table', 'url' => '?db='.urlencode($db).'&table='.urlencode($table)];

        $page_icons = [
            'structure' => 'fas fa-table',
            'browse' => 'fas fa-browser',
            'sql' => 'fas fa-code',
            'search' => 'fas fa-search',
            'insert' => 'fas fa-plus',
            'operations' => 'fas fa-clipboard-list',
            'triggers' => 'fas fa-bug',
            'foreign_keys' => 'fas fa-link',
            'export' => 'fas fa-download',
            'import' => 'fas fa-upload'
        ];

        if ($page && isset($page_icons[$page])) {
            $breadcrumbs[] = ['label' => ucfirst($page), 'icon' => $page_icons[$page], 'url' => ''];
        }

        return self::generateBreadcrumbsWithIcons($breadcrumbs);
    }

    /**
     * Generate database-specific breadcrumbs
     */
    private static function generateDatabaseBreadcrumbs($db, $tab, $conn = null) {
        $breadcrumbs = [];

        $host_info = self::getHostInfo($conn);
        $breadcrumbs[] = ['label' => $host_info, 'icon' => 'fas fa-server', 'url' => '?'];
        $breadcrumbs[] = ['label' => $db, 'icon' => 'fas fa-database', 'url' => '?db=' . urlencode($db)];

        $tab_icons = [
            'structure' => 'fas fa-folder-tree',
            'create_table' => 'fas fa-table',
            'sql' => 'fas fa-code',
            'search' => 'fas fa-search',
            'export' => 'fas fa-download',
            'delete' => 'fas fa-trash'
        ];

        if ($tab && isset($tab_icons[$tab])) {
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $tab)), 'icon' => $tab_icons[$tab], 'url' => ''];
        } elseif ($tab) {
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $tab)), 'icon' => 'fas fa-file', 'url' => ''];
        }

        return self::generateBreadcrumbsWithIcons($breadcrumbs);
    }

    /**
     * Generate config-specific breadcrumbs
     */
    private static function generateConfigBreadcrumbs($page, $conn = null) {
        $breadcrumbs = [];

        $host_info = self::getHostInfo($conn);
        $breadcrumbs[] = ['label' => $host_info, 'icon' => 'fas fa-server', 'url' => '?'];

        $page_icons = [
            'config' => 'fas fa-cogs',
            'php_ini' => 'fas fa-file-code',
            'settings' => 'fas fa-sliders-h'
        ];

        if ($page && isset($page_icons[$page])) {
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $page)), 'icon' => $page_icons[$page], 'url' => ''];
        } elseif ($page) {
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $page)), 'icon' => 'fas fa-file', 'url' => ''];
        }

        return self::generateBreadcrumbsWithIcons($breadcrumbs);
    }

    /**
     * Generate user management-specific breadcrumbs
     */
    private static function generateUserBreadcrumbs($action, $user, $conn = null) {
        $breadcrumbs = [];

        $host_info = self::getHostInfo($conn);
        $breadcrumbs[] = ['label' => $host_info, 'icon' => 'fas fa-server', 'url' => '?'];
        $breadcrumbs[] = ['label' => 'User Accounts', 'icon' => 'fas fa-users', 'url' => '?page=users'];

        $action_icons = [
            'edit_user' => 'fas fa-user-edit',
            'edit_privileges' => 'fas fa-user-edit',
            'export_users' => 'fas fa-download',
            'add_user' => 'fas fa-user-plus'
        ];

        if ($action && isset($action_icons[$action])) {
            $label = ucfirst(str_replace('_', ' ', $action));
            if ($user) {
                $label .= ' (' . htmlspecialchars($user) . ')';
            }
            $breadcrumbs[] = ['label' => $label, 'icon' => $action_icons[$action], 'url' => ''];
        } elseif ($action) {
            $breadcrumbs[] = ['label' => ucfirst(str_replace('_', ' ', $action)), 'icon' => 'fas fa-file', 'url' => ''];
        }

        return self::generateBreadcrumbsWithIcons($breadcrumbs);
    }

    /**
     * Helper function to auto-generate <td data-label>
     * Outputs a table width <td data-label="$row[$header]"> for each cell.
     * @param array $columns => Array of column header strings (Columns: Name, Type, Null, Default, Extra, Action...)
     * @param array $rows => Array of associative arrays with keys matching $columns variable.
     * @param string $tableClass => CSS class for the associated table (data-table).
     */
    public static function generateDataLabel($columns, $rows, $tableClass = '') {
        echo '<table class="'.htmlspecialchars($tableClass).'">';
        echo '<thead><tr>';
        foreach ($columns as $column) {
            echo '<th>'.htmlspecialchars($column).'</th>';
        }
        echo '</tr></thead></tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                echo '<td data-label="'.htmlspecialchars($column).'">'.$value.'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Helper function to generate <TYPE SELECT> in editing table.
     * Outputs a MySQL type <select> with option groups and tooltips.
     * @param string $name => The name/id of the select element to use.
     * @param string $selected => The selected value to use.
     * @param array $attrs => Additional attributes formatted as $key => $value.
     */
    public static function renderTypeSelect($name = 'column_type', $selected = '', $attrs = []) {
        $type_groups = [
            'Numeric' => [
                ['value' => 'TINYINT', 'title' => 'A 1-byte integer, signed range is -128 to 127, unsigned range is 0 to 255'],
                ['value' => 'SMALLINT', 'title' => 'A 2-byte integer, signed range is -32,768 to 32,767, unsigned range is 0 to 65,535'],
                ['value' => 'MEDIUMINT', 'title' => 'A 3-byte integer, signed range is -8,388,608 to 8,388,607, unsigned range is 0 to 16,777,215'],
                ['value' => 'INT', 'title' => 'A 4-byte integer, signed range is -2,147,483,648 to 2,147,483,647, unsigned range is 0 to 4,294,967,295'],
                ['value' => 'BIGINT', 'title' => 'An 8-byte integer, signed range is -9,223,372,036,854,775,808 to 9,223,372,036,854,775,807, unsigned range is 0 to 18,446,744,073,709,551,615'],
                ['value' => 'DECIMAL', 'title' => 'A fixed-point number (M, D) - the maximum number of digits (M) is 65 (default 10), the maximum number of decimals (D) is 30 (default 0)'],
                ['value' => 'FLOAT', 'title' => 'A small floating-point number, allowable values are -3.402823466E+38 to -1.175494351E-38, 0, and 1.175494351E-38 to 3.402823466E+38'],
                ['value' => 'DOUBLE', 'title' => 'A double-precision floating-point number, allowable values are -1.7976931348623157E+308 to -2.2250738585072014E-308, 0, and 2.2250738585072014E-308 to 1.7976931348623157E+308'],
                ['value' => 'REAL', 'title' => 'Synonym for DOUBLE (exception: in REAL_AS_FLOAT SQL mode it is a synonym for FLOAT)'],
                ['value' => 'BIT', 'title' => 'A bit-field type (M), storing M of bits per value (default is 1, maximum is 64)'],
                ['value' => 'BOOLEAN', 'title' => 'A synonym for TINYINT(1), a value of zero is considered false, nonzero values are considered true'],
                ['value' => 'SERIAL', 'title' => 'An alias for BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE'],
            ],
            'Date and time' => [
                ['value' => 'DATE', 'title' => 'A date, supported range is 1000-01-01 to 9999-12-31'],
                ['value' => 'DATETIME', 'title' => 'A date and time combination, supported range is 1000-01-01 00:00:00 to 9999-12-31 23:59:59'],
                ['value' => 'TIMESTAMP', 'title' => 'A timestamp, range is 1970-01-01 00:00:01 UTC to 2038-01-09 03:14:07 UTC, stored as the number of seconds since the epoch (1970-01-01 00:00:00 UTC)'],
                ['value' => 'TIME', 'title' => 'A time, range is -838:59:59 to 838:59:59'],
                ['value' => 'YEAR', 'title' => 'A year in four-digit (4, default) or two-digit (2) format, the allowable values are 70 (1970) to 69 (2069) or 1901 to 2155 and 0000'],
            ],
            'String' => [
                ['value' => 'CHAR', 'title' => 'A fixed-length (0-255, default 1) string that is always right-padded with spaces to the specified length when stored'],
                ['value' => 'VARCHAR', 'title' => 'A variable-length (0-65,535) string, the effective maximum length is subject to the maximum row size'],
                ['value' => 'TINYTEXT', 'title' => 'A TEXT column with a maximum length of 255 (2^8 - 1) characters, stored with a one-byte prefix indicating the length of the value in bytes'],
                ['value' => 'TEXT', 'title' => 'A TEXT column with a maximum length of 65,535 (2^16 - 1) characters, stored with a two-byte prefix indicating the length of the value in bytes'],
                ['value' => 'MEDIUMTEXT', 'title' => 'A TEXT column with a maximum length of 16,777,215 (2^24 - 1) characters, stored with a three-byte prefix indicating the length of the value in bytes'],
                ['value' => 'LONGTEXT', 'title' => 'A TEXT column with a maximum length of 4,294,967,295 or 4GiB (2^32 - 1) characters, stored with a four-byte prefix indicating the length of the value in bytes'],
                ['value' => 'BINARY', 'title' => 'Similar to the CHAR type, but stores binary byte strings rather than non-binary character strings'],
                ['value' => 'VARBINARY', 'title' => 'Similar to the VARCHAR type, but stores binary byte strings rather than non-binary character strings'],
                ['value' => 'TINYBLOB', 'title' => 'A BLOB column with a maximum length of 255 (2^8 - 1) bytes, stored with a one-byte prefix indicating the length of the value'],
                ['value' => 'BLOB', 'title' => 'A BLOB column with a maximum length of 65,535 (2^16 - 1) bytes, stored with a two-byte prefix indicating the length of the value'],
                ['value' => 'MEDIUMBLOB', 'title' => 'A BLOB column with a maximum length of 16,777,215 (2^24 - 1) bytes, stored with a three-byte prefix indicating the length of the value'],
                ['value' => 'LONGBLOB', 'title' => 'A BLOB column with a maximum length of 4,294,967,295 or 4GiB (2^32 - 1) bytes, stored with a four-byte prefix indicating the length of the value'],
                ['value' => 'ENUM', 'title' => 'An enumeration, chosen from the list of up to 65,535 values or the special \'\' error value'],
                ['value' => 'SET', 'title' => 'A single value chosen from a set of up to 64 members'],
                ['value' => 'INET6', 'title' => 'Intended for storage of IPv6 addresses, as well as IPv4 addresses assuming conventional mapping of IPv4 addresses into IPv6 addresses'],
            ],
            'Spatial' => [
                ['value' => 'GEOMETRY', 'title' => 'A type that can store a geometry of any type'],
                ['value' => 'POINT', 'title' => 'A point in 2-dimensional space'],
                ['value' => 'LINESTRING', 'title' => 'A curve with linear interpolation between points'],
                ['value' => 'POLYGON', 'title' => 'A polygon'],
                ['value' => 'MULTIPOINT', 'title' => 'A collection of points'],
                ['value' => 'MULTILINESTRING', 'title' => 'A collection of curves with linear interpolation between points'],
                ['value' => 'MULTIPOLYGON', 'title' => 'A collection of polygons'],
                ['value' => 'GEOMETRYCOLLECTION', 'title' => 'A collection of geometry objects of any type'],
            ],
            'JSON' => [
                ['value' => 'JSON', 'title' => 'Stores and enables efficient access to data in JSON (JavaScript Object Notation) documents'],
            ], 
        ];

        // Add top-level options before groups
        $top_options = [
            ['value' => 'INT', 'title' => 'A 4-byte integer, signed range is -2,147,483,648 to 2,147,483,647, unsigned range is 0 to 4,294,967,295'],
            ['value' => 'VARCHAR', 'title' => 'A variable-length (0-65,535) string, the effective maximum length is subject to the maximum row size'],
            ['value' => 'TEXT', 'title' => 'A TEXT column with a maximum length of 65,535 (2^16 - 1) characters, stored with a two-byte prefix indicating the length of the value in bytes'],
            ['value' => 'DATE', 'title' => 'A date, supported range is 1000-01-01 to 9999-12-31'],
        ];

        // Build attributes string
        $attr_str = '';
        foreach ($attrs as $key => $value) {
            $attr_str .= ' '.htmlspecialchars($key).'="'.htmlspecialchars($value).'"';
        }

        echo '<select name="'.htmlspecialchars($name).'" id="'.htmlspecialchars($name).'"'.$attr_str.'>';
        
        foreach ($top_options as $opt) {
            $is_selected = ($selected === $opt['value']) ? ' selected="selected"' : '';
            echo '<option value="'.htmlspecialchars($opt['value']).'" title="'.htmlspecialchars($opt['title']).'"'.$is_selected.'>'.htmlspecialchars($opt['value']).'</option>';
        }
        
        foreach ($type_groups as $label => $options) {
            echo '<optgroup label="'.htmlspecialchars($label).'">';
            foreach ($options as $opt) {
                $is_selected = ($selected === $opt['value']) ? ' selected="selected"' : '';
                echo '<option value="'.htmlspecialchars($opt['value']).'" title="'.htmlspecialchars($opt['title']).'"'.$is_selected.'>'.htmlspecialchars($opt['value']).'</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
    }

    /**
     * Helper function to get host info
     */

    private static function getHostInfo($conn) {
        if ($conn) {
            $server_info = self::getServerInfo($conn);
            $connection_info = $server_info['connection_info'] ?? 'localhost';
            return preg_replace('/ via .+$/', '', $connection_info);
        }

        return 'localhost';
    }

    public static function realsize(string|int $size = 0): string {
        if (!$size) return 0;

        // First convert to bytes
        $bytes = self::toBytes($size);

        // Convert to best unit
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $factor = 1024; // Divide by 1024 (1)

        for ($f = 0; $bytes >= $factor && $f < count($units) - 1; $f++) {
            $bytes /= $factor;
        }

        return round($bytes, 2) . ' ' . $units[$f];
    }

    public static function toBytes(string|int $size): int {
        if (!$size) return 0;

        $size = (string)$size;

        if (is_numeric($size)) {
            return (int)$size;
        }

        if (preg_match('/^([0-9,]+)\s*([a-zA-Z]+)$/i', $size, $matches)) {
            $number = (float)$matches[1];
            $unit = strtoupper($matches[2]);

            $multipliers = [
                'PB' => 1125899906842624,
                'TB' => 1099511627776,
                'GB' => 1073741824,
                'MB' => 1048576,
                'KB' => 1024,
                'B' => 1
            ];

            return isset($multipliers[$unit]) ? (int)($number * $multipliers[$unit]) : (int)$size;
        }

        return (int)$size;
    }

    public static function getThemes(): array {
        $themes = [];
        $themeDir = 'includes/css/themes/';

        if (is_dir($themeDir)) {
            $files = glob("{$themeDir}*.css");
            foreach ($files as $file) {
                $name = basename($file, '.css');
                $themes[$name] = ucfirst($name);
            }
        }

        return $themes;
    }

    public static function themeSelector(): string {
        $themes = self::getThemes();
        $selected = $_SESSION['theme'] ?? 'default';
        $html = '<div class="theme-selector">
                    <label for="theme-select">Theme:</label>
                    <select id="theme-select" name="theme">';

                    foreach ($themes as $value => $label) {
                        $selected = $value === $selected ? 'selected' : '';
                        $html .= "<option value=\"{$value}\">{$label}</option>";
                    }
        $html .= '</select>
                </div>';
        return $html;
    }

    // #!SECTION: table_view.php functions
    public static function getTableData($conn, $table, $limit = 30) {
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$table_check || $table_check->num_rows === 0) {
            $error = 'Table not found. Please try again.';
            return ['error' => $error]; // Return error message
        }

        $data = [];
        $result = $conn->query("SELECT * FROM `$table` LIMIT $limit");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }

        return $data;
    }

    public static function getTableStructure($conn, $table) {
        $columns = [];
        $primary_key = '';

        $result = $conn->query("DESCRIBE `$table`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row;
                if ($row['Key'] === 'PRI') {
                    $primary_key = $row['Field'];
                }
            }
        }

        return ['columns' => $columns, 'primary_key' => $primary_key];
    }

    public static function updateTableRow($conn, $table, $data, $primary_key, $id) {
        $updates = [];
        foreach ($data as $field => $value) {
            $escaped_value = $conn->real_escape_string($value);
            $updates[] = "`$field` = '$escaped_value'";
        }

        $updates_str = implode(', ', $updates);
        $escaped_id = $conn->real_escape_string($id);

        return $conn->query("UPDATE `$table` SET $updates_str WHERE `$primary_key` = '$escaped_id'");
    }

    public static function insertTableRow($conn, $table, $data) {
        if (!is_array($data)) {
            return false;
        }
        
        $fields = [];
        $values = [];

        foreach ($data as $field => $value) {
            if ($value !== '') {
                $fields[] = "`$field`";
                $values[] = "'".$conn->real_escape_string($value)."'";
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields_str = implode(', ', $fields);
        $values_str = implode(', ', $values);

        return $conn->query("INSERT INTO `$table` ($fields_str) VALUES ($values_str)");
    }

    public static function deleteTableRow($conn, $table, $primary_key, $id) {
        $escaped_id = $conn->real_escape_string($id);
        return $conn->query("DELETE FROM `$table` WHERE `$primary_key` = '$escaped_id'");
    }

    public static function mysqlFunctions(): array {
        return [
            'ABS', 'ACOS', 'ADDDATE', 'ADDTIME', 'AES_DECRYPT', 'AES_ENCRYPT', 'ASCII', 'ASIN', 'ATAN', 'ATAN2', 'AVG', 'BIN', 'BINARY', 'BIT_AND', 'BIT_COUNT', 'BIT_LENGTH', 'BIT_OR', 'BIT_XOR', 'CAST', 'CEILING', 'CHAR', 'CHAR_LENGTH', 'CHARACTER_LENGTH', 'COALESCE', 'COMPRESS', 'CONCAT', 'CONCAT_WS', 'CONNECTION_ID', 'CONV', 'CONVERT', 'COS', 'COT', 'COUNT', 'CRC32', 'CURDATE', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER', 'CURTIME', 'DATABASE', 'DATE', 'DATE_ADD', 'DATE_FORMAT', 'DATE_SUB', 'DATEDIFF', 'DAYNAME', 'DAYOFMONTH', 'DAYOFWEEK', 'DAYOFYEAR', 'DECODE', 'DEGREES', 'DES_DECRYPT', 'DES_ENCRYPT', 'ELT', 'ENCODE', 'ENCRYPT', 'EXP', 'EXPORT_SET', 'EXTRACT', 'FIELD', 'FIND_IN_SET', 'FLOOR', 'FORMAT', 'FROM_DAYS', 'FROM_UNIXTIME', 'GET_LOCK', 'GREATEST', 'GROUP_CONCAT', 'HEX', 'HOUR', 'IF', 'IFNULL', 'INET_ATON', 'INET_NTOA', 'INSERT', 'INSTR', 'INTERVAL', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'LAST_DAY', 'LAST_INSERT_ID', 'LCASE', 'LEAST', 'LEFT', 'LENGTH', 'LN', 'LOAD_FILE', 'LOCATE', 'LOG', 'LOG10', 'LOG2', 'LOWER', 'LPAD', 'LTRIM', 'MAKE_SET', 'MASTER_POS_WAIT', 'MAX', 'MD5', 'MID', 'MIN', 'MINUTE', 'MOD', 'MONTH', 'MONTHNAME', 'NOW', 'NULLIF', 'OCT', 'OCTET_LENGTH', 'OLD_PASSWORD', 'ORD', 'PASSWORD', 'PERIOD_ADD', 'PERIOD_DIFF', 'PI', 'POSITION', 'POW', 'POWER', 'QUARTER', 'QUOTE', 'RADIANS', 'RAND', 'RELEASE_LOCK', 'REPEAT', 'REPLACE', 'REVERSE', 'RIGHT', 'ROUND', 'RPAD', 'RTRIM', 'SECOND', 'SEC_TO_TIME', 'SESSION_USER', 'SHA', 'SHA1', 'SIGN', 'SIN', 'SOUNDEX', 'SPACE', 'SQRT', 'STD', 'STDDEV', 'STRCMP', 'SUBDATE', 'SUBSTR', 'SUBSTRING', 'SUBSTRING_INDEX', 'SUBTIME', 'SUM', 'SYSDATE', 'SYSTEM_USER', 'TAN', 'TIME', 'TIME_FORMAT', 'TIME_TO_SEC', 'TIMEDIFF', 'TIMESTAMP', 'TIMESTAMPADD', 'TIMESTAMPDIFF', 'TO_DAYS', 'TO_SECONDS', 'TRIM', 'TRUNCATE', 'UCASE', 'UNCOMPRESS', 'UNCOMPRESSED_LENGTH', 'UNHEX', 'UNIX_TIMESTAMP', 'UPPER', 'USER', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'UUID', 'UUID_SHORT', 'VALUES', 'VAR_POP', 'VAR_SAMP', 'VARIANCE', 'VERSION', 'WEEK', 'WEEKDAY', 'WEEKOFYEAR', 'YEAR', 'YEARWEEK'
        ];
    }

    // Quick search functionality
    public static function handleQuickSearch($conn, $databases, $quick_action, $page = 1, $per_page = 30) {
        $offset = ($page - 1) * $per_page;
        $all_results = [];

        switch ($quick_action) {
            case 'empty_tables':
                foreach ($databases as $database) {
                    $conn->select_db($database);
                    $tables = $conn->query("SHOW TABLES");
                    if ($tables) {
                        while ($table = $tables->fetch_array(MYSQLI_NUM)) {
                            $table_name = $table[0];
                            $count_result = $conn->query("SELECT COUNT(*) AS count FROM `$table_name`");
                            if ($count_result && $count_result->fetch_assoc()['count'] == 0) {
                                $all_results[] = [
                                    'type' => 'Empty Table',
                                    'database' => $database,
                                    'table' => $table_name,
                                    'info' => '0 rows'
                                ];
                            }
                        }
                    }
                }
                break;
            
            case 'large_tables':
                foreach ($databases as $database) {
                    $conn->select_db($database);
                    $result = $conn->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$database' AND TABLE_ROWS > 1000 ORDER BY size_mb DESC LIMIT 10");

                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $all_results[] = [
                                'type' => 'Large Table',
                                'database' => $database,
                                'table' => $row['TABLE_NAME'],
                                'info' => number_format($row['TABLE_ROWS']) . ' rows, ' . $row['size_mb'] . ' MB'
                            ];
                        }
                    }
                }
                break;
            
            case 'recent_tables':
                foreach ($databases as $database) {
                    $conn->select_db($database);
                    $result = $conn->query("SELECT TABLE_NAME, UPDATE_TIME, CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$database' AND (UPDATE_TIME IS NOT NULL OR CREATE_TIME IS NOT NULL) ORDER BY COALESCE(UPDATE_TIME, CREATE_TIME) DESC LIMIT 10");

                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $last_modified = $row['UPDATE_TIME'] ?? $row['CREATE_TIME'];
                            $all_results[] = [
                                'type' => 'Recent Table',
                                'database' => $database,
                                'table' => $row['TABLE_NAME'],
                                'info' => 'Modified: ' . date('m-d-Y h:i:sA', strtotime($last_modified))
                            ];
                        }
                    }
                }
                break;

            case 'indexes':
                foreach ($databases as $database) {
                    $conn->select_db($database);
                    $result = $conn->query("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$database' ORDER BY TABLE_NAME, INDEX_NAME");

                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $all_results[] = [
                                'type' => 'Index',
                                'database' => $database,
                                'table' => $row['TABLE_NAME'],
                                'info' => $row['INDEX_NAME'] . ' (' . $row['COLUMN_NAME'] . ') - ' . $row['INDEX_TYPE']
                            ];
                        }
                    }
                }
                break;
        }

        $total_results = count($all_results);
        $total_pages = ceil($total_results / $per_page);
        $paginated_results = array_slice($all_results, $offset, $per_page);

        return [
            'results' => $paginated_results,
            'total' => $total_results,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages
        ];
    }

    // Version checking functionality
    public static function checkVersion($current_version = '1.0.0') {
        $update_server = 'http://localhost/pma/updates'; // https://api.phynx.app/updates
        $product_name = 'PHYNX Admin';

        try {
            // Create context for HTTP request
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: PHYNX-Admin/'.$current_version,
                        'Content-Type: application/json'
                    ],
                    'timeout' => 10
                ]
            ]);

            // Make request to update server
            $response = @file_get_contents($update_server.'?product=phynx&version='.urlencode($current_version), false, $context);

            if ($response === false) {
                return [
                    'status' => 'error',
                    'message' => 'Unable to check for updates',
                    'current_version' => $current_version
                ];
            }

            $data = json_decode($response, true);

            if (!$data || !isset($data['latest_version'])) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid response from update server',
                    'current_version' => $current_version
                ];
            }

            $latest_version = $data['latest_version'];
            $download_url = $data['download_url'] ?? '';
            $filename = $data['filename'] ?? 'phynx-{$latest_version}.zip';
            $release_notes = $data['release_notes'] ?? '';
            $release_date = $data['release_date'] ?? '';

            // Compare versions
            $update_available = version_compare($latest_version, $current_version, '>');

            return [
                'status' => 'success',
                'update_available' => $update_available,
                'current_version' => $current_version,
                'latest_version' => $latest_version,
                'download_url' => $download_url,
                'filename' => $filename,
                'release_notes' => $release_notes,
                'release_date' => $release_date,
                'product_name' => $product_name
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error checking for updates: '.$e->getMessage(),
                'current_version' => $current_version
            ];
        }
    }

    // Get formatted update information
    public static function getUpdateInfo($current_version = '1.0.0') {
        $update_info = self::checkVersion($current_version);

        if ($update_info['status'] === 'error') {
            return [
                'html' => '<div class="update-error"><i class="fas fa-exclamation-triangle"></i> '.$update_info['message'].'</div>',
                'has_update' => false
            ];
        }

        if ($update_info['update_available']) {
            $html = '
            <div class="update-available">
                <div class="update-header">
                    <i class="fas fa-download"></i>
                        <span>Update Available!</span>
                </div>
                <div class="update-details">
                    <p><strong>Current:</strong> v'.$update_info['current_version'].'</p>
                    <p><strong>Latest:</strong> v'.$update_info['latest_version'].'</p>
            ';
            
            if ($update_info['release_date']) {
                $html .= '<p><strong>Release Date:</strong> '.date('M d, Y', strtotime($update_info['release_date'])).'</p>';
            }

            $html .= '</div>';

            if ($update_info['download_url']) {
                $html .= '
                    <a href="'.htmlspecialchars($update_info['download_url']).'" class="update-download-btn" target="_blank">
                        <i class="fas fa-download"></i> Download '.htmlspecialchars($update_info['filename']).'</a>
                ';
            }

            if ($update_info['release_notes']) {
                $html .= '
                    <div class="update-notes">
                        <strong>Release Notes:</strong>
                        <p>'.nl2br(htmlspecialchars($update_info['release_notes'])).'</p>
                    </div>
                ';
            }

            $html .= '</div>';

            return [
                'html' => $html,
                'has_update' => true,
                'data' => $update_info
            ];
        } else {
            $html = '
                <div class="update-current">
                    <i class="fas fa-check-circle"></i>
                        <span>You are running the latest version (v'.$update_info['current_version'].')</span>
                </div>
            ';

            return [
                'html' => $html,
                'has_update' => false,
                'data' => $update_info
            ];
        }
    }
}

// Initialize server variables for backward compatibility
if (isset($conn)) {
    $server_version = $conn->get_server_info();
    $protocol_version = $conn->protocol_version;
    $server_status = $conn ? 'Alive' : 'Dead';
    $server_type = $conn->get_server_info() ? 'MySQL' : 'Unknown';
    $connection_info = $conn->host_info;
    $ssl_status = $conn->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch_assoc()['Value'];
    $ssl_enabled = $ssl_status ? 'Enabled' : 'Disabled';
}