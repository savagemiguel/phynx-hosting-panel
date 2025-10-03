<?php
// ensure error handling for mysqli doesn't kill the page; wrap operations in try/catch where used
$current_tab = $_GET['tab'] ?? 'structure';
$table_info = [];

// helper to render SQL errors into your existing .error-message div and include friendly hints
function renderSqlError($error, $sql = '') {
    $error = (string) ($error ?? '');
    $sql = (string) ($sql ?? '');
    $friendly = '';
    if (class_exists('functions') && method_exists('functions', 'getFriendlySQLError')) {
        $friendly = functions::getFriendlySQLError($error, $sql);
    }
    echo '<div class="error-message"><strong>ERROR:</strong> ' . htmlspecialchars($error);
    if ($friendly) {
        echo '<br><span style="color:#ffc107;">' . $friendly . '</span>';
    }
    echo '</div>';
}

// Load table list for sidebar (wrap to avoid fatal on DB errors)
if (!empty($selected_db)) {
    try {
        $q = "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($selected_db) . "'";
        $result = $conn->query($q);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $table_info[] = $row;
            }
            $result->free();
        }
    } catch (mysqli_sql_exception $e) {
        // show error but continue rendering page
        renderSqlError($e->getMessage());
    } catch (Exception $e) {
        renderSqlError($e->getMessage());
    }
}
?>

<div class="content-header">
    <h2>Database: <?= htmlspecialchars($selected_db) ?></h2>
    <?php echo functions::generateBreadcrumbs(); ?>
</div>

<div class="tabs">
    <a href="?db=<?= urlencode($selected_db) ?>&tab=structure" class="tab <?= $current_tab === 'structure' ? 'active' : '' ?>">
        <i class="fas fa-folder-tree"></i> Structure
    </a>
    <a href="?db=<?= urlencode($selected_db) ?>&tab=create_table" class="tab <?= $current_tab === 'create_table' ? 'active' : '' ?>">
        <i class="fas fa-table"></i> Create Table
    </a>
    <a href="?db=<?= urlencode($selected_db) ?>&tab=sql" class="tab <?= $current_tab === 'sql' ? 'active' : '' ?>">
        <i class="fas fa-code"></i> SQL
    </a>
    <a href="?db=<?= urlencode($selected_db) ?>&tab=search" class="tab <?= $current_tab === 'search' ? 'active' : '' ?>">
        <i class="fas fa-search"></i> Search
    </a>
    <a href="?db=<?= urlencode($selected_db) ?>&tab=export" class="tab <?= $current_tab === 'export' ? 'active' : '' ?>">
        <i class="fas fa-download"></i> Export
    </a>
    <a href="?page=delete_database&database=<?= urlencode($selected_db) ?>" class="tab <?= $current_tab === 'delete' ? 'active' : '' ?>">
        <i class="fas fa-trash"></i> Delete
    </a>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="stat-number"><?= count($table_info) ?></div>
        <div class="stat-label">Tables</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= array_sum(array_column($table_info, 'TABLE_ROWS') ?: [0]) ?></div>
        <div class="stat-label">Total Rows</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= number_format(array_sum(array_column($table_info, 'size_mb') ?: [0]), 2) ?> MB</div>
        <div class="stat-label">Size</div>
    </div>
</div>

<?php if ($current_tab === 'structure'): ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Rows</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table_info as $table): ?>
                    <tr>
                        <td><a href="?db=<?= urlencode($selected_db) ?>&table=<?= urlencode($table['TABLE_NAME']) ?>"><?= htmlspecialchars($table['TABLE_NAME']) ?></a></td>
                        <td><?= number_format($table['TABLE_ROWS']) ?></td>
                        <td><a href="?db=<?= urlencode($selected_db) ?>&table=<?= urlencode($table['TABLE_NAME']) ?>" class="btn-small"><i class="fas fa-magnifying-glass-arrow-right"></i> Browse</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($current_tab === 'create_table'): ?>
    <div class="form-box">
        <h3>Create New Table</h3>
        <form method="POST">
            <div class="form-group">
                <label for="table_name">Table Name:</label>
                <input type="text" name="table_name" id="table_name" required>
            </div>

            <div class="form-group">
                <label for="num_columns">Number of Columns:</label>
                <input type="number" name="num_columns" id="num_columns" value="4" min="1" max="50">
            </div>

            <button type="submit" name="create_table_form" class="btn">Create Table</button>
        </form>
    </div>

    <?php if (isset($_POST['create_table_form'])): ?>
        <?php
        $table_name = $_POST['table_name'] ?? '';
        $num_columns = (int)($_POST['num_columns'] ?? 4);
        ?>

        <div class="form-box">
            <h3>Define Columns for <?= htmlspecialchars($table_name); ?></h3>
            <form method="POST">
                <input type="hidden" name="table_name" value="<?= htmlspecialchars($table_name); ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Length</th>
                            <th>Default</th>
                            <th>Null</th>
                            <th>Auto Increment</th>
                            <th>Unique</th>
                            <th>Primary Key</th>
                            <th>Index</th>
                            <th>On Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < $num_columns; $i++): ?>
                            <tr>
                                <td><input type="text" name="columns[<?= $i; ?>][name]" required></td>
                                <td>
                                    <?php
                                    functions::renderTypeSelect("columns[{$i}][type]", $_POST['columns'][$i]['type'] ?? '', ['onchange' => 'handleColumnTypeChange(this)']);
                                    ?>
                                    <!-- <select name="columns[<?= $i; ?>][type]" onchange="handleColumnTypeChange(this)">
                                        <option value="INT">INT</option>
                                        <option value="TINYINT">TINYINT</option>
                                        <option value="VARCHAR">VARCHAR</option>
                                        <option value="TEXT">TEXT</option>
                                        <option value="DATE">DATE</option>
                                        <option value="DATETIME">DATETIME</option>
                                        <option value="TIMESTAMP">TIMESTAMP</option>
                                        <option value="FLOAT">FLOAT</option>
                                        <option value="DOUBLE">DOUBLE</option>
                                        <option value="DECIMAL">DECIMAL</option>
                                        <option value="BOOLEAN">BOOLEAN</option>
                                        <option value="BLOB">BLOB</option>
                                        <option value="ENUM">ENUM</option>
                                        <option value="SET">SET</option>
                                    </select> -->
                                </td>
                                <td><input type="text" name="columns[<?= $i; ?>][length]" placeholder="255"></td>
                                <td><input type="text" name="columns[<?= $i; ?>][default]"></td>
                                <td><input type="checkbox" name="columns[<?= $i; ?>][null]" value="1"></td>
                                <td><input type="checkbox" name="columns[<?= $i; ?>][auto_increment]"></td>
                                <td><input type="checkbox" name="columns[<?= $i; ?>][unique]"></td>
                                <td><input type="checkbox" name="columns[<?= $i; ?>][primary_key]"></td>
                                <td>
                                    <select name="columns[<?= $i; ?>][index]">
                                        <option value="">None</option>
                                        <option value="PRIMARY">Primary</option>
                                        <option value="INDEX">Index</option>
                                        <option value="UNIQUE">Unique</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="checkbox" name="columns[<?= $i; ?>][on_update]" value="1" style="display: none;" id="on_update_<?= $i; ?>">
                                    <label for="on_update_<?= $i; ?>" style="display: none; font-size: 12px; margin-left: 5px;">ON UPDATE CURRENT_TIMESTAMP</label>
                                    <span class="no-update" style="color: #888888;">-</span>
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <button type="submit" name="create_table_final" class="btn">Create Table</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (isset($_POST['create_table_final'])): ?>
        <?php
        $table_name = $_POST['table_name'] ?? '';
        $columns = $_POST['columns'] ?? [];

        // build CREATE TABLE SQL
        $sql = "CREATE TABLE `" . $conn->real_escape_string($table_name) . "` (";
        $column_definitions = [];
        $indexes = [];
        foreach ($columns as $column) {
            $col_name = $column['name'] ?? '';
            if ($col_name === '') continue;

            $type = strtoupper($column['type'] ?? 'VARCHAR');
            $len = trim($column['length'] ?? '');
            $def = "`" . $conn->real_escape_string($col_name) . "` " . $type;
            if ($len !== '') {
                $def .= "({$len})";
            } elseif ($type === 'VARCHAR') {
                $def .= "(255)";
            }

            $is_null = !empty($column['null']);
            if (!$is_null) {
                $def .= " NOT NULL";
            } else {
                $def .= " NULL";
            }

            $default = $column['default'] ?? '';
            if ($default !== '') {
                // handle boolean/false values and CURRENT_TIMESTAMP correctly
                if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                    $def .= " DEFAULT CURRENT_TIMESTAMP";
                } elseif (strtoupper($default) === 'FALSE') {
                    $def .= " DEFAULT 0";
                } elseif (strtoupper($default) === 'TRUE') {
                    $def .= " DEFAULT 1";
                } else {
                    $def .= " DEFAULT '" . $conn->real_escape_string($default) . "'";
                }
            }

            if ($type === 'TIMESTAMP' && !empty($column['on_update'])) {
                $def .= " ON UPDATE CURRENT_TIMESTAMP";
            }

            if (!empty($column['auto_increment'])) {
                $def .= " AUTO_INCREMENT";
            }

            $column_definitions[] = $def;

            // indexes
            if (!empty($column['primary_key']) || (!empty($column['auto_increment']))) {
                $indexes[] = "PRIMARY KEY (`" . $conn->real_escape_string($col_name) . "`)";
            } elseif (!empty($column['unique'])) {
                $indexes[] = "UNIQUE (`" . $conn->real_escape_string($col_name) . "`)";
            } elseif (($column['index'] ?? '') === 'INDEX') {
                $indexes[] = "INDEX (`" . $conn->real_escape_string($col_name) . "`)";
            }
        }

        $sql .= implode(', ', array_merge($column_definitions, $indexes)) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $res = $conn->query($sql);
            if ($res) {
                echo "<div class='success-message'>Table '" . htmlspecialchars($table_name, ENT_QUOTES) . "' created successfully.</div>";
                // refresh table_info so sidebar keeps the new table
                $table_info = [];
                try {
                    $q2 = "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($selected_db) . "'";
                    $r2 = $conn->query($q2);
                    if ($r2) {
                        while ($row = $r2->fetch_assoc()) $table_info[] = $row;
                        $r2->free();
                    }
                } catch (Exception $e) { /* ignore reload errors */ }

                echo "<script>setTimeout(() => window.location.href = '?db=" . urlencode($selected_db) . "&table=" . urlencode($table_name) . "', 1500);</script>";
            } else {
                // non-exceptional error: show friendly
                renderSqlError($conn->error, $sql);
            }
        } catch (mysqli_sql_exception $e) {
            renderSqlError($e->getMessage(), $sql);
        } catch (Exception $e) {
            renderSqlError($e->getMessage(), $sql);
        }
        ?>
    <?php endif; ?>
<?php elseif ($current_tab === 'sql'): ?>
    <div class="sql-editor">
    <?php $safe_db = isset($selected_db) && $selected_db !== null ? $selected_db : ''; ?>
    <form method="POST" action="?db=<?= urlencode($safe_db); ?>&tab=sql" id="sqlForm">
        <input type="hidden" name="db" value="<?php $safe_db ?? ''; ?>">
        <textarea name="sql" id="sqlTextarea" placeholder="Enter SQL query here..."><?= htmlspecialchars($_POST['sql'] ?? ''); ?></textarea>
        <div class="sql-form-options">
            <div class="button-row">
                <button type="submit" name="execute_sql" id="execute_sql" class="btn">Go</button>
                <button type="reset" name="cancel" id="cancel" class="btn">Cancel</button>
            </div>
        </div>
    </form>
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

<?php elseif ($current_tab === 'search'): ?>
    <div class="form-box">
        <h3>Search Database: <?= htmlspecialchars($selected_db) ?></h3>
        <form method="POST">
            <div class="form-group">
                <label for="search_table">Search In:</label>
                <select name="search_table" id="search_table">
                    <option value="">All Tables</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= $table ?>" <?= ($_POST['search_table'] ?? '') === $table ? 'selected' : '' ?>>
                            <?= htmlspecialchars($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="search_term">Search Term:</label>
                <input type="text" name="search_term" id="search_term" value="<?= htmlspecialchars($_POST['search_term'] ?? '') ?>" placeholder="Enter search terms here...">
            </div>
            
            <button type="submit" class="btn">Search</button>
        </form>
    </div>

    <?php if (isset($_POST['search_term']) && $_POST['search_term']): ?>
        <?php
        $search_term = $conn->real_escape_string($_POST['search_term']);
        $search_table = $_POST['search_table'] ?? '';
        $search_tables = $search_table ? [$search_table] : $tables;

        foreach ($search_tables as $table):
            $columns_result = $conn->query("SHOW COLUMNS FROM `$table`");
            $columns = [];
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = $column['Field'];
            }

            $where_conditions = [];
            foreach ($columns as $column) {
                $where_conditions[] = "`$column` LIKE '%" . $search_term . "%'";
            }

            if ($where_conditions) {
                $search_query = "SELECT * FROM `$table` WHERE " . implode(' OR ', $where_conditions) . " LIMIT 100";
                $result = $conn->query($search_query);

                if ($result && $result->num_rows > 0):
            ?>
                    <h3>Results in <?= htmlspecialchars($table) ?></h3>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <th><?= htmlspecialchars($column) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td><?= htmlspecialchars($value) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif;
            }
        endforeach;
        ?>
    <?php endif; ?>

<?php elseif ($current_tab === 'export'): ?>
    <div class="form-box">
        <h3>Export Database: <?= htmlspecialchars($selected_db) ?></h3>
        <form method="POST">
            <div class="form-group">
                <label for="export_format">Export Format:</label>
                <select name="export_format" id="export_format">
                    <option value="sql">SQL</option>
                    <option value="csv">CSV</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="custom_filename">Custom Filename (optional):</label>
                <input type="text" name="custom_filename" id="custom_filename" placeholder="<?= $selected_db ?>_export_<?= date('m-d-Y_h-i-sA') ?>" value="<?= $_POST['custom_filename'] ?? '' ?>">
            </div>
            
            <button type="submit" name="export_db" class="btn">Export Database</button>
        </form>
    </div>

    <?php if (isset($_POST['export_db'])): ?>
        <?php
        $format = $_POST['export_format'] ?? 'sql';
        $custom_filename = trim($_POST['custom_filename'] ?? '');
        
        if ($custom_filename) {
            $filename = $custom_filename;
            if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                $filename .= '.' . $format;
            }
        } else {
            $filename = $selected_db . '_export_' . date('m-d-Y_h-i-sA') . '.' . $format;
        }

        if ($format === 'sql') {
            $export_content = "--- Database export for $selected_db ---\n\n";
            $export_content .= "-- Exported on " . date('m-d-Y h:i:s A') . "\n\n";

            foreach ($tables as $table) {
                $export_content .= "-- Table: $table\n";
                $result = $conn->query("SELECT * FROM `$table`");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $values = array_map(function($v) { return "'" . addslashes($v) . "'"; }, $row);
                        $export_content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                    }
                }

                $export_content .= "\n";
            }
        }
        
        if (!is_dir('exports')) @mkdir('exports', 0777, true);
        file_put_contents("exports/$filename", $export_content);
        ?>

        <div class="success-message">
            <h4>Export Complete!</h4>
            <p>Exported database to <a href="exports/<?= htmlspecialchars($filename) ?>" download><?= htmlspecialchars($filename) ?></a>.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>