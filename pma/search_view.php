<?php
// Quick search functions
if (isset($_GET['quick'])) {
    $quick_action = $_GET['quick'];
    $page = $_GET['p'] ?? 1;
    $per_page = 30;
    $search_data = functions::handleQuickSearch($conn, $databases, $quick_action, $page, $per_page);

    if (!empty($search_data['results'])) {
        echo '<div class="search-results-header">';
        echo '<h3><i class="fas fa-bolt"></i> Quick Action Results</h3>';
        echo '<div class="search-summary">';
        echo '<span class="search-term">' . ucwords(str_replace('_', ' ', $quick_action)) . '</span>';
        echo '<span class="search-scope">' . $search_data['total'] . ' total results.</span>';
        echo '</div></div>';

        echo '<div class="search-results-grid">';
        foreach ($search_data['results'] as $result) {
            echo '<div class="search-result-card">';
            echo '<h4 title="'.htmlspecialchars($result['type'].': '.$result['database'].'.'.$result['table']).'">'.$result['type'].': '.htmlspecialchars($result['database']).'.'.htmlspecialchars($result['table']).'</h4>';
            echo '<p title="'.htmlspecialchars($result['info']).'">'.htmlspecialchars($result['info']).'</p>';
            echo '<a href="?db='.urlencode($result['database']).'&table='.urlencode($result['table']).'" class="btn btn-small">';
            echo '<i class="fas fa-external-link-alt"></i> View Table</a>';
            echo '</div>';
        }

        echo '</div>';

        // Pagination
        if ($search_data['total_pages'] > 1) {
            echo '<div class="pagination">';
            if ($page > 1) {
                echo '<a href="?page=search&quick=' . $quick_action . '&p=' . ($page - 1) . '" class="btn-small">';
                echo '<i class="fas fa-chevron-left"></i> Previous</a>';
            }

            echo '<span class="page-info">Page ' . $page . ' of ' . $search_data['total_pages'] . '</span>';
            if ($page < $search_data['total_pages']) {
                echo '<a href="?page=search&quick=' . $quick_action . '&p=' . ($page + 1) . '" class="btn-small">';
                echo 'Next <i class="fas fa-chevron-right"></i></a>';
            }

            echo '</div>';
        }
    } else {
        echo '<div class="info-box"><p>No results found for ' . ucwords(str_replace('_', ' ', $quick_action)) . '</p></div>';
    }

    echo '<div style="margin-top: 20px;"><a href="?page=search" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Search</a></div>';
    return;
}
?>

<div class="content-header">
    <h2>Global Search</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text">
        <i class="fas fa-magnifying-glass"></i>
        Search</span>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-globe"></i>
        Global Search</span>
    </div>
</div>

<!-- Search Statistics -->
<div class="search-stats">
    <div class="search-stat">
        <div class="search-stat-number"><?= count($databases) ?></div>
        <div class="search-stat-label">Databases</div>
    </div>
    <div class="search-stat">
        <div class="search-stat-number"><?php
            $total_tables = 0;
            foreach ($databases as $db) {
                $conn->select_db($db);
                $result = $conn->query("SHOW TABLES");
                $total_tables += $result ? $result->num_rows : 0;
            }
            echo $total_tables;
        ?></div>
        <div class="search-stat-label">Total Tables</div>
    </div>
    <div class="search-stat">
        <div class="search-stat-number"><?= isset($_POST['search_term']) ? (isset($found_results) && $found_results ? 'Found' : 'None') : 'Ready' ?></div>
        <div class="search-stat-label">Search Status</div>
    </div>
</div>

<!-- Search Info -->
<div class="search-info">
    <h4><i class="fas fa-info-circle"></i> Global Search Information</h4>
    <p>Search across all databases and tables for specific terms. This tool will search table names, column names, and data content to help you find what you're looking for.</p>
</div>

<!-- Advanced Search Options -->
<div class="search-advanced">
    <h4><i class="fas fa-sliders-h"></i> Search Options</h4>
    <div class="search-filters">
        <div class="search-filter">
            <label>Search Type:</label>
            <select name="search_type">
                <option value="all">All (Tables, Columns, Data)</option>
                <option value="tables">Table Names Only</option>
                <option value="columns">Column Names Only</option>
                <option value="data">Data Content Only</option>
            </select>
        </div>
        <div class="search-filter">
            <label>Case Sensitive:</label>
            <select name="case_sensitive">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>
        <div class="search-filter">
            <label>Result Limit:</label>
            <select name="result_limit">
                <option value="10">10 per table</option>
                <option value="25">25 per table</option>
                <option value="50">50 per table</option>
            </select>
        </div>
    </div>
</div>

<!-- Main Search Form -->
<div class="form-box">
    <h3><i class="fas fa-search"></i> Global Database Search</h3>
    <form method="POST">
        <div class="search-options">
            <div class="form-group">
                <label for="search_db">Target Database:</label>
                <select name="search_db" id="search_db">
                    <option value="">🌐 All Databases</option>
                    <?php foreach ($databases as $database): ?>
                        <option value="<?= $database; ?>" <?= ($_POST['search_db'] ?? '') === $database ? 'selected' : ''; ?>>
                            🗄️ <?= htmlspecialchars($database); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="search_term">Search Term:</label>
                <input type="text" name="search_term" id="search_term" placeholder="Enter search term..." value="<?= $_POST['search_term'] ?? '' ?>" required>
            </div>
        </div>
        
        <button type="submit" class="btn">
            <i class="fas fa-search"></i> Start Global Search
        </button>
        <button type="reset" class="btn btn-secondary">
            <i class="fas fa-sync-alt"></i> Clear
        </button>
    </form>
</div>

<!-- Quick Search Shortcuts -->
<div class="search-advanced">
    <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
    <div class="search-filters">
        <a href="?page=search&quick=empty_tables" class="btn btn-small">
            <i class="fas fa-table"></i> Find Empty Tables
        </a>
        <a href="?page=search&quick=large_tables" class="btn btn-small">
            <i class="fas fa-weight-hanging"></i> Find Large Tables
        </a>
        <a href="?page=search&quick=recent_tables" class="btn btn-small">
            <i class="fas fa-clock"></i> Recently Modified
        </a>
        <a href="?page=search&quick=indexes" class="btn btn-small">
            <i class="fas fa-key"></i> Search Indexes
        </a>
    </div>
</div>

<?php if ($_POST['search_term'] ?? ''): ?>
    <?php
    $search_term = $conn->real_escape_string($_POST['search_term']);
    $search_db = $_POST['search_db'] ?? '';
    $search_databases = $search_db ? [$search_db] : $databases;
    $found_results = false;
    ?>

    <!-- Search Results Header -->
    <div class="search-results-header">
        <h3><i class="fas fa-list-ul"></i> Search Results</h3>
        <div class="search-summary">
            <span class="search-term">"<?= htmlspecialchars($search_term) ?>"</span>
            <span class="search-scope">in <?= $search_db ? htmlspecialchars($search_db) : 'all databases' ?></span>
        </div>
    </div>
    <?php foreach ($search_databases as $database): ?>
        <?php
        $conn->select_db($database);
        $tables = $conn->query("SHOW TABLES");
        if ($tables):
            while ($table = $tables->fetch_array(MYSQLI_NUM)):
                $table_name = $table[0];

                // Check if table name matches search term
                if (stripos($table_name, $search_term) !== false) {
                    $found_results = true;
                    echo "<div class='info-box'>";
                    echo "<h4>Table Match: " . htmlspecialchars($datahbase) . "." . htmlspecialchars($table_name) . "</h4>";
                    echo "<p>Table name contains: <strong>" . htmlspecialchars($search_term) . "</strong></p>";
                    echo "</div>";
                }

                $columns = $conn->query("SHOW COLUMNS FROM `$table_name`");
                $text_columns = [];
                $matching_columns = [];

                if ($columns) {
                    while ($col = $columns->fetch_assoc()) {
                        // Check if column name matches search term
                        if (stripos($col['Field'], $search_term) !== false) {
                            $matching_columns[] = $col['Field'];
                        }
                        
                        // Check if the column type is text-based
                        if (strpos($col['Type'], 'varchar') !== false ||
                            strpos($col['Type'], 'text') !== false ||
                            strpos($col['Type'], 'char') !== false) {
                                $text_columns[] = $col['Field'];
                        }
                    }
                }

                // Show column matches
                if ($matching_columns) {
                    $found_results = true;
                    echo "<div class='info-box'>";
                    echo "<h4>Column Match: " . htmlspecialchars($database) . "." . htmlspecialchars($table_name) . "</h4>";
                    echo "<p>Columns containing <strong>" . htmlspecialchars($search_term) . "</strong>: " . implode(', ', $matching_columns) . "</p>";
                    echo "</div>";
                }

                // Search data content
                if ($text_columns) {
                    $where_conditions = [];
                    foreach ($text_columns as $column) {
                        $where_conditions[] = "`$column` LIKE '%$search_term%'";
                    }

                    $where_clause = implode(' OR ', $where_conditions);
                    $result = $conn->query("SELECT * FROM `$table_name` WHERE $where_clause LIMIT 10");

                    if ($result && $result->num_rows > 0):
                        $found_results = true;
        ?>
        <div class="info-box">
            <h4>Data Match: <?= htmlspecialchars($database); ?>.<?= htmlspecialchars($table_name); ?> (<?= $result->num_rows ?> matches)</h4>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php $first_row = $result->fetch_assoc(); $result->data_seek(0); ?>
                            <?php foreach (array_keys($first_row) as $column): ?>
                                <th><?= htmlspecialchars($column); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <?php foreach ($row as $value): ?>
                                    <td><?= htmlspecialchars($value ?? ''); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        endif;
        }
        endwhile;
        endif;
        ?>
    <?php endforeach; ?>

    <?php if (!$found_results): ?>
        <div class="info-box">
            <p>No results found for "<?= htmlspecialchars($search_term); ?>"</p>
        </div>
    <?php endif; ?>
<?php endif; ?>