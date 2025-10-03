<div class="content-header">
    <h2>SQL Query</h2>
    <?php echo functions::generateBreadcrumbs(); ?>
</div>

<div class="sql-editor">
    <?php $safe_db = isset($selected_db) && $selected_db !== null ? $selected_db : ''; ?>
    <form method="POST" action="?db=<?= urlencode($safe_db); ?>&tab=sql" id="sqlForm">
        <input type="hidden" name="db" value="<?php $safe_db ?? ''; ?>">
        <textarea name="sql" id="sqlTextarea" placeholder="Enter SQL query here..."><?= htmlspecialchars($_POST['sql'] ?? ''); ?></textarea>
        <button type="submit" name="execute_sql" id="execute_sql" class="btn">Go</button>
    </form>
</div>

<div class="sql-result-container">
    <?php
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
        } catch (mysqli_sql_exception $e) {
            renderSqlError($e->getMessage(), $sql_query);
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
    ?>
</div>