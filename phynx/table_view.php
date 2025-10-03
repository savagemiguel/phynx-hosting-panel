<?php
// Get the variables for the tables
$page = $_GET['tab'] ?? 'browse';
$edit_id = $_GET['edit'] ?? '';
$delete_id = $_GET['delete'] ?? '';
$message = '';
$data = [];

// Get connection info
$connection_info = $conn->host_info;

if ($selected_table) {
    // Get table structure
    $structure = functions::getTableStructure($conn, $selected_table);
    $columns = $structure['columns'];
    $primary_key = $structure['primary_key'];

    // Handle form submissions
    if ($_POST) {
        if (isset($_POST['update']) && $edit_id && $primary_key) {
            // Update the table row
            $success = functions::updateTableRow($conn, $selected_table, $_POST['update'], $primary_key, $edit_id);
            $message = $success ? "Row updated successfully!" : "ERROR: {$conn->error}.";
        } elseif (isset($_POST['insert'])) {
            // Insert a new table row - manual data extraction
            $filtered_data = [];
            
            // Manually check each field from the table structure
            foreach ($columns as $column) {
                $field = $column['Field'];
                
                // Skip if null checkbox is checked
                if (isset($_POST["null_$field"])) {
                    continue;
                }
                
                // Skip if auto increment is checked (let MySQL handle it)
                if (isset($_POST["auto_inc_$field"])) {
                    continue;
                }
                
                // Check if function is selected
                $function = $_POST["function_$field"] ?? '';
                if ($function && $function !== '') {
                    $filtered_data[$field] = $function . '()';
                    continue;
                }
                
                // Check for direct POST field
                $value = $_POST[$field] ?? '';
                
                if ($value !== '') {
                    $filtered_data[$field] = $value;
                }
            }
            
            if (empty($filtered_data)) {
                $message = "ERROR: No data to insert. Please fill at least one field.";
            } else {
                $success = functions::insertTableRow($conn, $selected_table, $filtered_data);
                if ($success) {
                    $message = "Row inserted successfully!";
                } else {
                    $message = "ERROR: " . ($conn->error ?: "Insert failed.");
                }
            }
        } elseif (isset($_POST['edit_column'])) {
            // Edit column
            $old_name = $_GET['column'] ?? '';
            $new_name = $_POST['column_name'] ?? '';
            $type = $_POST['column_type'] ?? '';
            $null = isset($_POST['null']) ? 'NULL' : 'NOT NULL';
            $default = $_POST['default'] ?? '';
            $auto_increment = isset($_POST['auto_increment']) ? 'AUTO_INCREMENT' : '';
            
            if ($old_name && $new_name && $type) {
                $default_clause = '';
                if ($auto_increment) {
                    // AUTO_INCREMENT columns cannot have default values and must be NOT NULL
                    $null = 'NOT NULL';
                    $default_clause = '';
                } elseif ($default) {
                    $default_clause = "DEFAULT '$default'";
                }
                
                $sql = "ALTER TABLE `$selected_table` CHANGE `$old_name` `$new_name` $type $null $default_clause $auto_increment";
                $success = $conn->query($sql);
                $message = $success ? "Column updated successfully!" : "ERROR: {$conn->error}.";
            }
        } elseif (isset($_POST['drop_column'])) {
            $column_name = $_GET['column'] ?? '';
            if (!empty($column_name)) {
                $result = functions::dropColumn($conn, $selected_table, $column_name);

                if ($result['success']) {
                    // Re-fetch columns after successful drop
                    $columns_result = $conn->query("DESCRIBE `$selected_table`");
                    $columns = [];
                    if ($columns_result) {
                        while ($row = $columns_result->fetch_assoc()) {
                            $columns[] = $row;
                        }
                    }
                }

                echo $result['html'];
            }
            /*
            if ($column_name) {
                $sql = "ALTER TABLE `$selected_table` DROP COLUMN `$column_name`";
                $success = $conn->query($sql);
                $message = $success ? "Column dropped successfully!" : "ERROR: {$conn->error}.";
            } */
        } elseif (isset($_POST['table_operation'])) {
            // Handle table operations
            $operation = $_POST['operation'] ?? '';
            switch ($operation) {
                case 'optimize':
                    $success = $conn->query("OPTIMIZE TABLE `$selected_table`");
                    $message = $success ? "Table optimized successfully!" : "ERROR: {$conn->error}.";
                    break;
                case 'repair':
                    $success = $conn->query("REPAIR TABLE `$selected_table`");
                    $message = $success ? "Table repaired successfully!" : "ERROR: {$conn->error}.";
                    break;
                case 'analyze':
                    $success = $conn->query("ANALYZE TABLE `$selected_table`");
                    $message = $success ? "Table analyzed successfully!" : "ERROR: {$conn->error}.";
                    break;
                case 'truncate':
                    $success = $conn->query("TRUNCATE TABLE `$selected_table`");
                    $message = $success ? "Table truncated successfully!" : "ERROR: {$conn->error}.";
                    break;
                case 'drop':
                    $success = $conn->query("DROP TABLE `$selected_table`");
                    if ($success) {
                        echo "<script>window.location.href = '?db=" . urlencode($selected_db) . "';</script>";
                        exit;
                    } else {
                        $message = "ERROR: {$conn->error}.";
                    }
                    break;
            }
        } elseif (isset($_POST['add_column'])) {
            // Add new column
            $column_name = $_POST['new_column_name'] ?? '';
            $column_type = $_POST['new_column_type'] ?? '';
            $null = isset($_POST['new_null']) ? 'NULL' : 'NOT NULL';
            $default = $_POST['new_default'] ?? '';
            $auto_increment = isset($_POST['new_auto_increment']) ? 'AUTO_INCREMENT' : '';
            
            if ($column_name && $column_type) {
                $default_clause = '';
                if ($auto_increment) {
                    // AUTO_INCREMENT columns cannot have default values and must be NOT NULL
                    $null = 'NOT NULL';
                    $default_clause = '';
                } elseif ($default) {
                    $default_clause = "DEFAULT '$default'";
                }
                
                $sql = "ALTER TABLE `$selected_table` ADD COLUMN `$column_name` $column_type $null $default_clause $auto_increment";
                $success = $conn->query($sql);
                $message = $success ? "Column added successfully!" : "ERROR: {$conn->error}.";
                
                // Refresh column structure
                if ($success) {
                    $structure = functions::getTableStructure($conn, $selected_table);
                    $columns = $structure['columns'];
                    $primary_key = $structure['primary_key'];
                }
            }
        } elseif (isset($_POST['add_index'])) {
            // Add new index
            $index_name = $_POST['index_name'] ?? '';
            $index_type = $_POST['index_type'] ?? '';
            $index_columns = $_POST['index_columns'] ?? [];
            
            if ($index_type && !empty($index_columns)) {
                $columns_str = '`' . implode('`, `', $index_columns) . '`';
                
                if ($index_type === 'PRIMARY') {
                    $sql = "ALTER TABLE `$selected_table` ADD PRIMARY KEY ($columns_str)";
                } else {
                    $index_name = $index_name ?: ($index_type . '_' . implode('_', $index_columns));
                    $sql = "ALTER TABLE `$selected_table` ADD $index_type `$index_name` ($columns_str)";
                }
                
                $success = $conn->query($sql);
                $message = $success ? "Index added successfully!" : "ERROR: {$conn->error}.";
            }
        } elseif (isset($_POST['value'])) {
            // Search functionality
            $conditions = [];
            foreach ($_POST['value'] as $field => $value) {
                if (!empty($value)) {
                    $operator = $_POST['operator'][$field] ?? '=';
                    $escaped_value = $conn->real_escape_string($value);
                    if ($operator === 'LIKE') {
                        $conditions[] = "`$field` LIKE '%$escaped_value%'";
                    } else {
                        $conditions[] = "`$field` $operator '$escaped_value'";
                    }
                }
            }

            if ($conditions) {
                $where_clause = implode(' AND ', $conditions);
                $result = $conn->query("SELECT * FROM `$selected_table` WHERE $where_clause LIMIT 30");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $data[] = $row;
                    }
                }
            }
        } elseif (isset($_POST['create_table'])) {
            $table_name = $_POST['table_name'] ?? '';
            $num_columns = (int)($_POST['num_columns'] ?? 0);

            if ($table_name && $num_columns > 0) {
                $columns_sql = [];
                $primary_keys = [];

                for ($c = 0; $c < $num_columns; $c++) {
                    $col_name = $_POST["column_name_$c"] ?? "column_$c";
                    $col_type = $_POST["column_type_$c"] ?? 'VARCHAR(255)';
                    $col_length = $_POST["column_length_$c"] ?? '';
                    $col_null = isset($_POST["column_null_$c"]) ? 'NULL' : 'NOT NULL';
                    $col_default = $_POST["column_default_$c"] ?? '';
                    $col_auto_inc = isset($_POST["column_auto_inc_$c"]) ? 'AUTO_INCREMENT' : '';
                    $col_primary = isset($_POST["column_primary_$c"]);

                    // Build column type with length
                    if ($col_length && in_array(strtoupper($col_type), ['VARCHAR', 'CHAR', 'DECIMAL', 'FLOAT', 'DOUBLE'])) {
                        $full_type = "$col_type($col_length)";
                    } else {
                        $full_type = $col_type;
                    }

                    // Build column definition
                    $column_def = "`$col_name` $full_type $coll_null";

                    if ($col_auto_inc) {
                        $column_def .= " $col_auto_inc";
                        $col_null = 'NOT NULL'; // AUTO INCREMENT must be NOT NULL
                    } elseif ($col_default) {
                        $column_def .= " DEFAULT '$col_default'";
                    }

                    $columns_sql[] = $column_def;

                    if ($col_primary) {
                        $primary_keys[] = "`$col_name`";
                    }
                }

                // Add primary key if specified
                if (!empty($primary_keys)) {
                    $columns_sql[] = "PRIMARY KEY (".implode(', ', $primary_keys).")";
                }

                $create_sql = "CREATE TABLE `$table_name` (".implode(', ', $columns_sql).")";
                $success = $conn->query($create_sql);
                $message = $success ? "Table '$table_name' created successfully!" : "ERROR: {$conn->error}.";
            }
        }
    }

    // Handle delete
    if ($delete_id && $primary_key) {
        $success = functions::deleteTableRow($conn, $selected_table, $primary_key, $delete_id);
        $message = $success ? "Row deleted successfully!" : "ERROR: {$conn->error}.";
    }

    // Get edit row data
    $edit_row = [];
    if ($edit_id && $primary_key) {
        $escaped_id = $conn->real_escape_string($edit_id);
        $result = $conn->query("SELECT * FROM `$selected_table` WHERE `$primary_key` = '$escaped_id'");
        if ($result && $result->num_rows > 0) {
            $edit_row = $result->fetch_assoc();
        }
    }

    // Get table data for browse page with pagination
    if (($page === 'browse' || !$page) && empty($data) && !$_POST && !$edit_id) {
        $page_num = $_GET['p'] ?? 1;
        $rows_per_page = 30;
        $offset = ($page_num - 1) * $rows_per_page;
        
        $result = $conn->query("SELECT * FROM `$selected_table` LIMIT $rows_per_page OFFSET $offset");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
    }
}
?>

<div class="content-header">
    <h2>Table: <?= htmlspecialchars($selected_table); ?></h2>
    <?php echo functions::generateBreadcrumbs(); ?>
</div>

<div class="tabs">
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>" class="tab <?= ($page === 'browse' || !$page) ? 'active' : ''; ?>">
        <i class="fas fa-browser"></i> Browse
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=structure" class="tab <?= $page === 'structure' ? 'active' : ''; ?>">
        <i class="fas fa-table"></i> Structure
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=sql" class="tab <?= $page === 'sql' ? 'active' : ''; ?>">
        <i class="fas fa-code"></i> SQL
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=search" class="tab <?= $page === 'search' ? 'active' : ''; ?>">
        <i class="fas fa-search"></i> Search
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=insert" class="tab <?= $page === 'insert' ? 'active' : ''; ?>">
        <i class="fas fa-plus"></i> Insert
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=operations" class="tab <?= $page === 'operations' ? 'active' : ''; ?>">
        <i class="fas fa-clipboard-list"></i> Operations
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=triggers" class="tab <?= $page === 'triggers' ? 'active' : ''; ?>">
        <i class="fas fa-bug"></i> Triggers
    </a>
    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=foreign_keys" class="tab <?= $page === 'foreign_keys' ? 'active' : ''; ?>">
        <i class="fas fa-link"></i> Foreign Keys
    </a>
</div>

<?php if ($message): ?>
    <div class="global-message-container">
        <?php if (functions::isSuccessMessage($message)): ?>
            <!-- Global Success State -->
            <div class="success-state">
                <div class="success-icon">
                    <i class="fas <?= functions::getSuccessIcon($message) ?>"></i>
                </div>
                <h3>Operation Completed Successfully</h3>
                <p class="success-message"><?= htmlspecialchars($message) ?></p>
                
                <div class="success-actions">
                    <?php 
                    $actions = functions::getContextualActions($page, $selected_db, $selected_table, $message);
                    foreach ($actions as $action): 
                    ?>
                        <a href="<?= $action['url'] ?>" class="<?= $action['class'] ?>">
                            <i class="fas <?= $action['icon'] ?>"></i> <?= $action['text'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Global Warning/Error Box -->
            <div class="warning-box error">
                <div class="warning-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span class="warning-title">Error</span>
                </div>
                <p><strong>Error:</strong> <?= htmlspecialchars(str_replace('ERROR: ', '', $message)); ?></p>
                
                <?php
                // Get contextual actions for error states
                $error_actions = [];
                
                switch ($page) {
                    case 'drop_column':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=structure",
                            'text' => 'Back to Structure',
                            'icon' => 'fa-arrow-left',
                            'class' => 'btn-secondary'
                        ];
                        if (isset($_GET['column'])) {
                            $error_actions[] = [
                                'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=drop_column&column=" . urlencode($_GET['column']),
                                'text' => 'Try Again',
                                'icon' => 'fa-refresh',
                                'class' => 'btn'
                            ];
                        }
                        break;
                        
                    case 'edit_column':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=structure",
                            'text' => 'Back to Structure',
                            'icon' => 'fa-arrow-left',
                            'class' => 'btn-secondary'
                        ];
                        if (isset($_GET['column'])) {
                            $error_actions[] = [
                                'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=edit_column&column=" . urlencode($_GET['column']),
                                'text' => 'Try Again',
                                'icon' => 'fa-refresh',
                                'class' => 'btn'
                            ];
                        }
                        break;
                        
                    case 'insert':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=insert",
                            'text' => 'Try Again',
                            'icon' => 'fa-refresh',
                            'class' => 'btn'
                        ];
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table),
                            'text' => 'Browse Table',
                            'icon' => 'fa-eye',
                            'class' => 'btn-secondary'
                        ];
                        break;
                        
                    case 'operations':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=operations",
                            'text' => 'Back to Operations',
                            'icon' => 'fa-clipboard-list',
                            'class' => 'btn'
                        ];
                        break;
                        
                    case 'sql':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=sql",
                            'text' => 'Back to SQL',
                            'icon' => 'fa-code',
                            'class' => 'btn'
                        ];
                        break;
                        
                    case 'structure':
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table) . "&tab=structure",
                            'text' => 'Back to Structure',
                            'icon' => 'fa-table',
                            'class' => 'btn'
                        ];
                        break;
                        
                    default:
                        $error_actions[] = [
                            'url' => "?db=" . urlencode($selected_db) . "&table=" . urlencode($selected_table),
                            'text' => 'Back to Table',
                            'icon' => 'fa-arrow-left',
                            'class' => 'btn-secondary'
                        ];
                        break;
                }
                ?>
                
                <?php if (!empty($error_actions)): ?>
                <div class="action-buttons" style="margin-top: 20px;">
                    <?php foreach ($error_actions as $action): ?>
                        <a href="<?= $action['url'] ?>" class="<?= $action['class'] ?>">
                            <i class="fas <?= $action['icon'] ?>"></i>
                            <?= $action['text'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($page === 'structure'): ?>
<div class="structures-section">
    <h4 class="structure">Table Structure</h4>
    <?php
    // Prepare headers and rows for the structure table
    $headers = ['Name', 'Type', 'Null', 'Default', 'Extra', 'Action'];
    $rows = [];
    foreach ($columns as $col) {
        $rows[] = [
            'Name'    => '<strong>' . htmlspecialchars($col['Field']) . '</strong>',
            'Type'    => '<span style="text-transform: uppercase;">' . htmlspecialchars($col['Type']) . '</span>',
            'Null'    => $col['Null'] === 'YES' ? 'YES' : 'NO',
            'Default' => htmlspecialchars($col['Default'] ?? 'NONE'),
            'Extra'   => '<span style="text-transform: uppercase;">' . htmlspecialchars($col['Extra']) . '</span>',
            'Action'  => '<div class="action-buttons">
                <a href="?db=' . urlencode($selected_db) . '&table=' . urlencode($selected_table) . '&tab=edit_column&column=' . urlencode($col['Field']) . '" title="Edit" class="btn-action edit"><i class="fas fa-edit"></i></a>
                <a href="?db=' . urlencode($selected_db) . '&table=' . urlencode($selected_table) . '&tab=drop_column&column=' . urlencode($col['Field']) . '" title="Drop" class="btn-action delete" onclick="return confirm(\'Drop column ' . $col['Field'] . '?\')"><i class="fas fa-trash-alt"></i></a>
            </div>'
        ];
    }
    // Output the table using your helper function
    functions::generateDataLabel($headers, $rows, 'data-table');
    ?>
</div>

    <div class="structures-section">
    <h4 class="structure">Add New Column</h4>
    <form method="POST">
        <table class="data-table">
            <tr>
                <td><label>Column Name:</label></td>
                <td><input type="text" name="new_column_name" required></td>
            </tr>
            <tr>
                <td><label>Type:</label></td>
                <td>
                    <?php
                    functions::renderTypeSelect('new_column_type', '', ['required' => 'required', 'onchange' => 'handleNewColumnTypeChange(this)']);
                    ?>
                    <!--
                    <select name="new_column_type" required onchange="handleNewColumnTypeChange(this)">
                        <option value="VARCHAR(255)">VARCHAR(255)</option>
                        <option value="INT">INT</option>
                        <option value="TINYINT">TINYINT</option>
                        <option value="TEXT">TEXT</option>
                        <option value="DATE">DATE</option>
                        <option value="DATETIME">DATETIME</option>
                        <option value="TIMESTAMP">TIMESTAMP</option>
                        <option value="TIME">TIME</option>
                        <option value="FLOAT">FLOAT</option>
                        <option value="BOOLEAN">BOOLEAN</option>
                        <option value="DECIMAL(10,2)">DECIMAL(10,2)</option>
                    </select>
                    -->
                </td>
            </tr>
            <tr>
                <td><label>Null:</label></td>
                <td style="text-align: left;"><input type="checkbox" name="new_null"> <span class="checkbox">Allow NULL</span></td>
            </tr>
            <tr>
                <td><label>Default:</label></td>
                <td><input type="text" name="new_default" placeholder="Leave empty for no default"></td>
            </tr>
            <tr>
                <td><label>Auto Increment:</label></td>
                <td style="text-align: left;"><input type="checkbox" name="new_auto_increment" onchange="handleAutoIncrement(this)">
                <span class="checkbox">AUTO_INCREMENT</span></td>
            </tr>
        </table><br>
        <button type="submit" name="add_column" class="btn">
            <i class="fas fa-plus"></i> Add Column
        </button>
    </form>
    </div>

    <div class="structures-section">
    <h4 class="structure">Add New Index</h4>
    <form method="POST">
        <table class="data-table">
            <tr>
                <td><label>Index Name:</label></td>
                <td><input type="text" name="index_name" placeholder="Leave empty for auto-generated name"></td>
            </tr>
            <tr>
                <td><label>Index Type:</label></td>
                <td>
                    <select name="index_type" required>
                        <option value="INDEX">INDEX (Regular)</option>
                        <option value="UNIQUE">UNIQUE</option>
                        <option value="PRIMARY">PRIMARY KEY</option>
                        <option value="FULLTEXT">FULLTEXT</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td><label>Column(s):</label></td>
                <td>
                    <select name="index_columns[]" multiple required style="height: 100px;">
                        <?php foreach ($columns as $col): ?>
                            <option value="<?= $col['Field']; ?>"><?= $col['Field']; ?> (<?= $col['Type']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small>Hold Ctrl/Cmd to select multiple columns</small>
                </td>
            </tr>
        </table><br>
        <button type="submit" name="add_index" class="btn">
            <i class="fas fa-key"></i> Add Index
        </button>
    </form>
    </div>

<?php elseif ($page === 'operations'): ?>
    <h3>Table Operations</h3>
    <div class="operations grid">
        <div class="operations card">
            <h4>Table Maintenance</h4>
            <form method="POST">
                <input type="hidden" name="operation" value="optimize">
                <button type="submit" name="table_operation" class="btn">
                    <i class="fas fa-tools"></i> Optimize Table
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="operation" value="repair">
                <button type="submit" name="table_operation" class="btn">
                    <i class="fas fa-wrench"></i> Repair Table
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="operation" value="analyze">
                <button type="submit" name="table_operation" class="btn">
                    <i class="fas fa-chart-line"></i> Analyze Table
                </button>
            </form>
        </div>
        <div class="operations card">
            <h4>Table Options</h4>
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=rename" class="btn">
                <i class="fas fa-edit"></i> Rename Table
            </a>
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=copy" class="btn">
                <i class="fas fa-copy"></i> Copy Table
            </a>
            <form method="POST" onsubmit="return confirm('Drop table <?= $selected_table ?>?')">
                <input type="hidden" name="operation" value="drop">
                <button type="submit" name="table_operation" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Drop Table
                </button>
            </form>
        </div>
        <div class="operations card">
            <h4>Data Operations</h4>
            <form method="POST" onsubmit="return confirm('Truncate table <?= $selected_table ?>?')">
                <input type="hidden" name="operation" value="truncate">
                <button type="submit" name="table_operation" class="btn btn-warning">
                    <i class="fas fa-cut"></i> Empty Table
                </button>
            </form>
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=export" class="btn">
                <i class="fas fa-download"></i> Export
            </a>
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=import" class="btn">
                <i class="fas fa-upload"></i> Import
            </a>
        </div>
    </div>

<?php elseif ($page === 'sql'): ?>
<div class="sql-editor">
    <form method="POST" action="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=sql" id="sqlForm">
        <input type="hidden" name="db" value="<?php echo htmlspecialchars($selected_db ?? ''); ?>">
        <textarea name="sql" id="sqlTextarea" placeholder="Enter SQL query here..."><?php echo htmlspecialchars($_POST['sql'] ?? ''); ?></textarea>
        <div class="sql-toolbar">
            <button type="button" onclick="insertSQL('SELECT * FROM `'+getSelectedTable()+'`;')">SELECT *</button>
            <button type="button" onclick="insertSQL('SELECT column1, column2 FROM `'+getSelectedTable()+'`;')">SELECT</button>
            <button type="button" onclick="insertSQL('INSERT INTO `'+getSelectedTable()+'` (column1, column2) VALUES (value1, value2);')">INSERT</button>
            <button type="button" onclick="insertSQL('UPDATE `'+getSelectedTable()+'` SET column1 = value1 WHERE condition;')">UPDATE</button>
            <button type="button" onclick="insertSQL('DELETE FROM `'+getSelectedTable()+'` WHERE condition;')">DELETE</button>
            <button type="button" onclick="clearSQL()">CLEAR</button>

            <label style="margin-left: 16px;">
                DELIMITER: <input type="text" name="delimiter" value=";" style="width: 30px;">
            </label>
            
        </div>
        
        <div class="sql-form-options">
            <label for="bind_params">
                <input type="checkbox" name="bind_params" id="bind_params"> BIND PARAMETERS
                <span class="help-tooltip" data-tooltip="Bind parameters allows you to use placeholders (?) in your SQL and provide values separately." tabindex="0">&#x2753;</span>
            </label>
            <label for="show_query">
                <input type="checkbox" name="show_query"> SHOW THIS QUERY HERE AGAIN
            </label>
            <label for="retain_box">
                <input type="checkbox" name="retain_box"> RETAIN QUERY BOX
            </label>
            <label for="rollback">
                <input type="checkbox" name="rollback"> ROLLBACK WHEN FINISHED
            </label>
            <label for="fk_checks">
                <input type="checkbox" name="fk_checks" checked> ENABLE FOREIGN KEY CHECKS
            </label>
            <div class="button-row">
                <button type="submit" name="execute_sql" id="execute_sql" class="btn">GO</button>
                <button type="reset" name="cancel" id="cancel" class="btn">CANCEL</button>
            </div>
        </div>
    </form>
        <div class="sql-sidebar">
            <label for="tableList"><strong>TABLE</strong></label>
            <select id="tableList" size="1" onchange="loadColumnsForTable()">
                <option value="<?= htmlspecialchars($selected_table) ?>"><?= htmlspecialchars($selected_table) ?></option>
            </select>
        <div>
            <label for="columnList"><strong>COLUMNS</strong></label>
            <select id="columnList" name="columns[]" multiple size="8">
                <?php foreach ($columns as $column): ?>
                    <option value="<?= htmlspecialchars($column['Field']); ?>">
                        <span class="icon" aria-hidden="true">📄</span>
                        <span class="option-label"><?= htmlspecialchars($column['Field']); ?></span>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="insert-column-btn" onclick="insertColumn()"><i class="fa-solid fa-arrow-left"></i><i class="fa-solid fa-arrow-left"></i> INSERT</button>
        </div>
        </div>
</div>
<?php
// Start output buffering
ob_start();
if (isset($_POST['execute_sql']) && !empty($_POST['sql'])) {
    $sql_query = (string) ($_POST['sql'] ?? '');
    $start_time = microtime(true);
    $query_count = 0;
    try {
        if ($conn->multi_query($sql_query)) {
            do {
                $query_count++;
                if ($result = $conn->store_result()) {
                    if ($result->num_rows > 0) {
                        echo '<div class="success-message">Query #' . $query_count . ': Showing ' . $result->num_rows . ' row(s).</div>';
                        $sql_data = [];
                        while ($row = $result->fetch_assoc()) $sql_data[] = $row;
                        if (!empty($sql_data)) {
                            echo '<table class="data-table"><thead><tr>';
                            foreach (array_keys($sql_data[0]) as $column) {
                                echo '<th>' . htmlspecialchars($column) . '</th>';
                            }
                            echo '</tr></thead><tbody>';
                            foreach ($sql_data as $row) {
                                echo '<tr>';
                                foreach ($row as $value) {
                                    echo '<td>' . htmlspecialchars($value ?? 'NULL') . '</td>';
                                }
                                echo '</tr>';
                            }
                            echo '</tbody></table>';
                        }
                    } else {
                        echo '<div class="warning-message">Query #' . $query_count . ': MySQL returned an empty result set (i.e. zero rows).</div>';
                    }
                    $result->free();
                } else {
                    if ($conn->errno) {
                        renderSqlError($conn->error, $sql_query);
                    } else {
                        $affected_rows = $conn->affected_rows;
                        echo '<div class="success-message">Query #' . $query_count . ': ' . $affected_rows . ' row(s) affected.</div>';
                    }
                }
            } while ($conn->more_results() && $conn->next_result());
        }
    } catch (Exception $e) {
        renderSqlError($e->getMessage(), $sql_query);
    }
    $query_time = round(microtime(true) - $start_time, 4);
    if (!empty($conn->error)) {
        renderSqlError($conn->error, $sql_query);
    } else {
        echo '<div class="success-message">All queries executed. (Total time: ' . $query_time . ' seconds)</div>';
    }
}
// Get the buffered output
$sql_result_output = trim(ob_get_clean());
?>

<?php if ($sql_result_output): ?>
    <div class="sql-result-container">
        <?= $sql_result_output ?>
    </div>
<?php endif; ?>
<?php elseif ($page === 'search'): ?>
    <form method="POST">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Column</th>
                    <th>Operator</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $column): ?>
                    <tr>
                        <td><?= htmlspecialchars($column['Field']); ?></td>
                        <td>
                            <select name="operator[<?= $column['Field']; ?>]">
                                <option value="=">=</option>
                                <option value="LIKE">LIKE</option>
                                <option value=">">></option>
                                <option value="<"><</option>
                            </select>
                        </td>
                        <td><input type="text" name="value[<?= $column['Field']; ?>]"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" name="search" class="btn">
            <i class="fas fa-search"></i> Search
        </button>
    </form>

<?php elseif ($page === 'edit_column'): ?>
    <?php
    $column_name = $_GET['column'] ?? '';
    $column_info = null;
    foreach ($columns as $col) {
        if ($col['Field'] === $column_name) {
            $column_info = $col;
            break;
        }
    }
    ?>
    <h3>Edit Column: <?= htmlspecialchars($column_name); ?></h3>
    <?php if ($column_info): ?>
        <form method="POST">
            <table class="data-table">
                <tr>
                    <td><label>Column Name:</label></td>
                    <td><input type="text" name="column_name" value="<?= htmlspecialchars($column_info['Field']); ?>" required></td>
                </tr>
                <tr>
                    <td><label>Type:</label></td>
                    <td>
                        <?php
                        // Extract the base type for selection (e.g. INT, VARCHAR, etc.)
                        $base_type = strtoupper(preg_replace('/\(.*/', '', $column_info['Type']));
                        functions::renderTypeSelect('column_type', $base_type, ['required' => 'required']);
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><label>Null:</label></td>
                    <td><input type="checkbox" name="null" <?= $column_info['Null'] === 'YES' ? 'checked' : ''; ?>> Allow NULL</td>
                </tr>
                <tr>
                    <td><label>Default:</label></td>
                    <td><input type="text" name="default" value="<?= htmlspecialchars($column_info['Default'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <td><label>Auto Increment:</label></td>
                    <td><input type="checkbox" name="auto_increment" onchange="handleAutoIncrement(this)" <?= strpos($column_info['Extra'], 'auto_increment') !== false ? 'checked' : ''; ?>> AUTO_INCREMENT (INT only)</td>
                </tr>
            </table><br>
            <button type="submit" name="edit_column" class="btn">Update Column</button>
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=structure" class="btn btn-secondary">Cancel</a>
        </form>
    <?php else: ?>
        <p>Column not found.</p>
    <?php endif; ?>

<?php elseif ($page === 'drop_column'): ?>
    <?php
    $column_name = $_GET['column'] ?? '';
    $column_info = null;
    foreach ($columns as $col) {
        if ($col['Field'] === $column_name) {
            $column_info = $col;
            break;
        }
    }

    // Check if we just successfully dropped a column
    $showDropForm = !$message && !functions::isSuccessMessage($message);
    ?>

    <?php if (!$showDropForm): // Only show drop section if we haven't just dropped the column ?>
    <div class="drop-section">
        <div class="warning-box">
            <div class="warning-header">
                <i class="fas fa-skull-crossbones"></i>
                <span class="warning-title">Danger Zone</span>
            </div>
            <div class="drop-message">
                <p><strong>Warning:</strong> This will permanently delete the column "<em><?= htmlspecialchars($column_name); ?></em>" and all of its data from the table "<em><?= htmlspecialchars($selected_table); ?></em>"!</p>
            </div>

            <?php if ($column_info): ?>
            <div class="column-info">
                <h4><i class="fas fa-info-circle"></i> Column Information</h4>
                <dl class="column-details">
                    <dt>Name:</dt>
                    <dd><?= htmlspecialchars($column_info['Field']); ?></dd>
                    <dt>Type:</dt>
                    <dd><?= htmlspecialchars($column_info['Type']); ?></dd>
                    <dt>Null:</dt>
                    <dd><?= $column_info['Null'] === 'YES' ? 'Allowed' : 'Not Allowed'; ?></dd>
                    <dt>Default:</dt>
                    <dd><?= htmlspecialchars($column_info['Default'] ?? 'None'); ?></dd>
                    <dt>Extra:</dt>
                    <dd><?= htmlspecialchars($column_info['Extra'] ?: 'None'); ?></dd>
                </dl>
            </div>
            <?php endif; ?>

            <p class="drop-warning">
                <i class="fas fa-exclamation-triangle"></i>
                This action cannot be undone!
            </p>

            <div class="action-buttons">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="drop_column" class="btn-danger">
                        <i class="fas fa-trash-alt"></i>
                        Yes, DROP Column Forever
                    </button>
                </form>
                <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=structure" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Cancel & Go Back
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($page === 'insert'): ?>
    <h3>Insert a New Row</h3>
    <form method="POST">
        <table class="data-table insert-table">
            <thead>
                <tr>
                    <th>Column</th>
                    <th>Type</th>
                    <th>Function</th>
                    <th>Null</th>
                    <th>Auto Inc</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $column): ?>
                    <tr>
                        <td data-label="Column"><strong><?= htmlspecialchars($column['Field']); ?></strong></td>
                        <td data-label="Type"><?= htmlspecialchars($column['Type']); ?></td>
                        <td data-label="Function">
                            <select name="function_<?= $column['Field']; ?>">
                                <option value="">--</option>
                                <?php foreach (functions::mysqlFunctions() as $func): ?>
                                    <option value="<?= $func ?>"><?= $func ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td data-label="Null">
                            <?php if ($column['Null'] === 'YES'): ?>
                                <input type="checkbox" name="null_<?= $column['Field']; ?>" value="1">
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Auto Inc">
                            <?php if (strpos($column['Type'], 'int') !== false): ?>
                                <input type="checkbox" name="auto_inc_<?= $column['Field']; ?>" value="1" <?= strpos($column['Extra'], 'auto_increment') !== false ? 'checked disabled' : ''; ?>>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Value">
                        <?php
                        $inputType = 'text';
                        $placeholder = htmlspecialchars($column['Type']);
                        $fieldName = $column['Field'];

                        // Handle auto-increment ID field
                        if ($column['Key'] === 'PRI' && strpos($column['Extra'], 'auto_increment') !== false) {
                            // Get next auto-increment ID
                            $autoIncQuery = $conn->query("SHOW TABLE STATUS LIKE '$selected_table'");
                            $autoIncID = $autoIncQuery ? $autoIncQuery->fetch_assoc()['Auto_increment'] : 1;
                            echo '<input type="text" name="'.$fieldName.'" value="'.$autoIncID.'" class="table-input" placeholder="Auto-Increment ID" readonly style="background: #2A2A2A; color: #888888;">';
                        }

                        // Handle timestamp fields (created_at, updated_at, release_date)
                        elseif (in_array(strtolower($fieldName), ['created_at', 'updated_at', 'release_date']) || strpos($column['Type'], 'timestamp') !== false || strpos($column['Type'], 'datetime') !== false) {
                            $currentDateTime = date('m-d-Y\Th:i:s');
                            echo '<input type="datetime-local" name="'.$fieldName.'" value="'.$currentDateTime.'" class="table-input">';
                        }

                        // Handle date fields
                        elseif (strpos($column['Type'], 'date') !== false) {
                            $currentDate = date('m-d-Y');
                            echo '<input type="date" name="'.$fieldName.'" value="'.$currentDate.'" class="table-input">';
                        }

                        // Handle time fields
                        elseif (strpos($column['Type'], 'time') !== false) {
                            $currentTime = date('h:i:s');
                            echo '<input type="time" name="'.$fieldName.'" value="'.$currentTime.'" class="table-input">';
                        }

                        // Handle numeric fields
                        elseif (strpos($column['Type'], 'int') !== false || strpos($column['Type'], 'decimal') !== false || strpos($column['Type'], 'float') !== false || strpos($column['Type'], 'double') !== false) {
                            $inputType = 'number';
                            echo '<input type="'.$inputType.'" name="'.$fieldName.'" value="'.htmlspecialchars($column['Default'] ?? '').'" placeholder="'.$placeholder.'" class="table-input">';
                        }

                        // Handle enum fields
                        elseif (strpos($column['Type'], 'enum') !== false) {
                            preg_match("/enum\((.+)\)/i", $column['Type'], $matches);
                            if (isset($matches[1])) {
                                $enumValues = str_getcsv($matches[1], ',',  "'");
                                echo '<select name="'.$fieldName.'">';
                                echo '<option value=""> -- SELECT --</option>';
                                foreach ($enumValues as $value) {
                                    echo '<option value="'.htmlspecialchars($value).'">'.htmlspecialchars($value).'</option>';
                                }
                                echo '</select>';
                            } else {
                                echo '<input type="text" name="'.$fieldName.'" placeholder="'.$placeholder.'" class="table-input">';
                            }
                        } // Handle all other fields
                        else {
                            echo '<input type="text" name="'.$fieldName.'" value="'.htmlspecialchars($column['Default'] ?? '').'" placeholder="'.$placeholder.'" class="table-input">';
                        }
                        ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="action-btns">
        <button type="submit" name="insert" class="btn">
            <i class="fas fa-plus"></i> Insert
        </button>
        <button type="reset" class="btn btn-secondary">
            <i class="fas fa-sync-alt"></i> Reset
        </button>
        </div>
    </form>
    
    <?php elseif ($page === 'export'): ?>
    <div class="export-section">
        <h3><i class="fas fa-download"></i> Export Table: <?= htmlspecialchars($selected_table); ?></h3>
        
        <form method="POST" action="export_table.php">
            <input type="hidden" name="db" value="<?= htmlspecialchars($selected_db); ?>">
            <input type="hidden" name="table" value="<?= htmlspecialchars($selected_table); ?>">
            
            <div class="export-options">
                <div class="export-card">
                    <h4>Export Method</h4>
                    <label><input type="radio" name="export_type" value="quick" checked> Quick - display only the minimal options</label>
                    <label><input type="radio" name="export_type" value="custom"> Custom - display all possible options</label>
                </div>

                <div class="export-card">
                    <h4>Format</h4>
                    <select name="format" required>
                        <option value="sql">SQL</option>
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                        <option value="xml">XML</option>
                        <option value="excel">Excel</option>
                    </select>
                </div>

                <div class="export-card">
                    <h4>Rows</h4>
                    <label><input type="radio" name="rows" value="all" checked> Dump all rows</label>
                    <label><input type="radio" name="rows" value="range"> Dump some row(s)</label>
                    <div id="row-range" style="display: none; margin-top: 10px;">
                        Start row: <input type="number" name="start_row" value="0" min="0">
                        Number of rows: <input type="number" name="num_rows" value="25" min="1">
                    </div>
                </div>

                <div class="export-card">
                    <h4>Output</h4>
                    <label><input type="radio" name="output" value="save" checked> Save output to a file</label>
                    <label><input type="radio" name="output" value="view"> View output as text</label>
                    
                    <div id="filename-options" style="margin-top: 10px;">
                        File name template: 
                        <input type="text" name="filename" value="<?= htmlspecialchars($selected_table); ?>" style="width: 200px;">
                        <small>Use @TABLE@ for table name, @DATABASE@ for database name</small>
                    </div>
                </div>

                <div class="export-card" id="sql-options">
                    <h4>Object creation options</h4>
                    <label><input type="checkbox" name="structure" checked> Structure</label>
                    <label><input type="checkbox" name="data" checked> Data</label>
                    <label><input type="checkbox" name="drop_table"> Add DROP TABLE</label>
                    <label><input type="checkbox" name="if_not_exists"> Add IF NOT EXISTS</label>
                </div>
            </div>

            <div class="export-actions">
                <button type="submit" name="export" class="btn">
                    <i class="fas fa-download"></i> Export
                </button>
                <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Table
                </a>
            </div>
        </form>
    </div><!--
    // add a trash can delete icon for each row
    // make it so that you can insert another blank row using javascript like phpmyadmin does-->


<?php elseif ($edit_id && $edit_row): ?>
    <h3>Edit Row</h3>
    <form method="POST">
        <table class="data-table">
            <tr>
                <th>Column</th>
                <th>Type</th>
                <th>Value</th>
            </tr>
            <?php foreach ($columns as $column): ?>
                <tr>
                    <td><?= htmlspecialchars($column['Field']); ?></td>
                    <td><?= htmlspecialchars($column['Type']); ?></td>
                    <td>
                        <input type="text" name="update[<?= $column['Field']; ?>]" value="<?= htmlspecialchars($edit_row[$column['Field']] ?? '') ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table><br />
        <button type="submit" class="btn">Update</button>
        <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>" class="btn btn-secondary">Cancel</a>
    </form>

<?php elseif ($page === 'triggers'): ?>
    <?php include 'triggers_view.php'; ?>

<?php elseif ($page === 'foreign_keys'): ?>
    <?php include 'foreign_keys_views.php'; ?>

    <?php else: ?>
    <!-- BROWSE VIEW -->
    <?php
    // Get total row count
    $count_result = $conn->query("SELECT COUNT(*) as total FROM `$selected_table`");
    $total_rows = $count_result ? $count_result->fetch_assoc()['total'] : 0;
    
    // Pagination
    $page_num = $_GET['p'] ?? 1;
    $rows_per_page = 30;
    $offset = ($page_num - 1) * $rows_per_page;
    $total_pages = ceil($total_rows / $rows_per_page);
    ?>
    
    <div class="browse-header">
        <div class="browse-info">
            <span class="row-count">Showing <?= min($offset + 1, $total_rows) ?>-<?= min($offset + $rows_per_page, $total_rows) ?> of <?= $total_rows ?> rows</span>
        </div>
        <div class="browse-actions">
            <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&tab=insert" class="btn">
                <i class="fas fa-plus"></i> Insert Row
            </a>
        </div>
    </div>

    <?php if ($data): ?>
        <div class="table-wrapper">
            <table class="data-table enhanced-table">
                <thead>
                    <tr>
                        <th class="action-col">Actions</th>
                        <?php foreach (array_keys($data[0]) as $column): ?>
                            <th class="<?= strtolower($column); ?>-col"><?= htmlspecialchars($column); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr class="data-row">
                            <td class="action-col">
                                <div class="action-buttons">
                                    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&edit=<?= urlencode($row[$primary_key] ?? ''); ?>" class="btn-action edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&delete=<?= urlencode($row[$primary_key] ?? ''); ?>" onclick="return confirm('Delete this row?')" class="btn-action delete" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </td>
                            <?php foreach ($row as $field => $value): ?>
                                <td class="<?= strtolower($field); ?>" data-label="<?= htmlspecialchars($field); ?>">
                                    <?php
                                    // Format different data types
                                    if (is_null($value)) {
                                        echo '<span class="null-value">NULL</span>';
                                    } elseif (in_array(strtolower($field), ['created_at', 'updated_at', 'release_date']) && $value) {
                                        echo '<span class="datetime-value" title="'.htmlspecialchars($value).'">'.date('M j, Y g:i A', strtotime($value)).'</span>';
                                    } elseif (strlen($value) > 50) {
                                        echo '<span class="long-text" title="'.htmlspecialchars($value).'">'.htmlspecialchars(substr($value, 0, 50)).'...</span>';
                                    } else {
                                        echo '<span class="cell-value">'.htmlspecialchars($value).'</span>';
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page_num > 1): ?>
                <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&p=<?= $page_num - 1 ?>" class="btn-small">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
            <?php endif; ?>
            
            <span class="page-info">Page <?= $page_num ?> of <?= $total_pages ?></span>
            
            <?php if ($page_num < $total_pages): ?>
                <a href="?db=<?= urlencode($selected_db); ?>&table=<?= urlencode($selected_table); ?>&p=<?= $page_num + 1 ?>" class="btn-small">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="empty-table">
            <i class="fas fa-table" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
            <p>No data found in the table.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    // Functions to handle Insert, Clear, InsertColumn
function insertSQL(sql) {
    var textarea = document.getElementById('sqlTextarea');
    if (textarea) {
        textarea.value += (textarea.value ? '\n' : '') + sql;
        textarea.focus();
    }
}

function clearSQL() {
    var textarea = document.getElementById('sqlTextarea');
    if (textarea) textarea.value = '';
}

function insertColumn() {
    var textarea = document.getElementById('sqlTextarea');
    var list = document.getElementById('columnList');
    if (textarea && list && list.value) {
        textarea.value += (textarea.value && !textarea.value.endsWith(' ') ? ' ' : '') + '`' + list.value + '`';
        textarea.focus();
    }
}

function getSelectedTable() {
    var tableList = document.getElementById('tableList');
    return tableList && tableList.value ? tableList.value : '';
}

function loadColumnsForTable() {
    var table = getSelectedTable();
    var columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    if (!table) return;
    var db = document.querySelector('input[name="db"]').value;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'http://onyx.local/pma/get_columns.php', true);
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var columns = JSON.parse(xhr.responseText);
                columns.forEach(function(col) {
                    var opt = document.createElement('option');
                    opt.value = col;
                    opt.textContent = col;
                    columnList.appendChild(opt);
                });
            } catch(e) {}
        }
    };
    xhr.send('table=' + encodeURIComponent(table) + '&db=' + encodeURIComponent(db));
}

    document.addEventListener('DOMContentLoaded', function() {
        // Show/hide row range options
        document.querySelectorAll('input[name="rows"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('row-range').style.display = 
                    this.value === 'range' ? 'block' : 'none';
            });
        });

        // Show/hide filename options
        document.querySelectorAll('input[name="output"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('filename-options').style.display = 
                    this.value === 'save' ? 'block' : 'none';
            });
        });

        // Show/hide SQL-specific options
        document.querySelector('select[name="format"]').addEventListener('change', function() {
            document.getElementById('sql-options').style.display = 
                this.value === 'sql' ? 'block' : 'none';
        });
    });

document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('columnList');
    if (select && select.multiple) {
        const maxSize = 10;
        const optionCount = select.options.length;
        select.size = Math.min(optionCount, maxSize);
    }
    loadColumnsForTable();
});
</script>