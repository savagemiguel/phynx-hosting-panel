<?php
// Handle trigger creation
if (isset($_POST['action']) && $_POST['action'] === 'create_trigger') {
    $trigger_name = $_POST['trigger_name'];
    $table_name = $_POST['table_name'];
    $timing = $_POST['timing'];
    $event = $_POST['event'];
    $definition = $_POST['definition'];
    $definer = $_POST['definer'];
    
    $sql = "CREATE TRIGGER `$trigger_name` $timing $event ON `$table_name` FOR EACH ROW $definition";
    
    if ($conn->query($sql)) {
        echo '<div class="success-message">Trigger created successfully!</div>';
    } else {
        echo '<div class="error-message">ERROR: ' . $conn->error . '</div>';
    }
}

// Handle trigger deletion
if (isset($_POST['action']) && $_POST['action'] === 'drop_trigger') {
    $trigger_name = $_POST['trigger_name'];
    $sql = "DROP TRIGGER IF EXISTS `$trigger_name`";
    
    if ($conn->query($sql)) {
        echo '<div class="success-message">Trigger dropped successfully!</div>';
    } else {
        echo '<div class="error-message">Error: ' . $conn->error . '</div>';
    }
}

// Get existing triggers for this table
$triggers_result = $conn->query("SHOW TRIGGERS FROM `$selected_db` WHERE `Table` = '$selected_table'");
$triggers = [];
if ($triggers_result) {
    while ($row = $triggers_result->fetch_assoc()) {
        $triggers[] = $row;
    }
}
?>
<div class="info-box">
    <h4>Existing Triggers</h4>
    <?php if (empty($triggers)): ?>
        <p>No triggers found for this table.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Trigger Name</th>
                    <th>Timing</th>
                    <th>Event</th>
                    <th>Definer</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($triggers as $trigger): ?>
                    <tr>
                        <td><?= htmlspecialchars($trigger['Trigger']); ?></td>
                        <td><?= htmlspecialchars($trigger['Timing']); ?></td>
                        <td><?= htmlspecialchars($trigger['Event']); ?></td>
                        <td><?= htmlspecialchars($trigger['Definer']); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="drop_trigger">
                                <input type="hidden" name="trigger_name" value="<?= htmlspecialchars($trigger['Trigger']); ?>">
                                <button type="submit" class="btn-small" onclick="return confirm('Are you sure you want to drop this trigger?');">
                                    <i class="fas fa-trash-alt"> Drop</i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="triggers-layout">
    <div class="info-box">
        <h4>Create New Trigger</h4>
        <form method="POST">
            <input type="hidden" name="action" value="create_trigger">
            
            <table class="data-table">
                <tr>
                    <td><label for="trigger_name">Trigger Name:</label></td>
                    <td><input type="text" name="trigger_name" id="trigger_name" required></td>
                </tr>
                <tr>
                    <td><label for="table_name">Table:</label></td>
                    <td>
                        <select name="table_name" id="table_name" required>
                            <?php foreach ($tables as $table): ?>
                                <option value="<?= htmlspecialchars($table); ?>" <?= $table === $selected_table ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($table); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><label for="timing">Timing:</label></td>
                    <td>
                        <select name="timing" id="timing" required>
                            <option value="BEFORE">BEFORE</option>
                            <option value="AFTER">AFTER</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><label for="event">Event:</label></td>
                    <td>
                        <select name="event" id="event" required>
                            <option value="INSERT">INSERT</option>
                            <option value="UPDATE">UPDATE</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><label for="definer">Definer:</label></td>
                    <td><input type="text" name="definer" id="definer" value="<?= htmlspecialchars($user.'@'.$host); ?>"></td>
                </tr>
                <tr>
                    <td><label for="definition">Definition:</label></td>
                    <td>
                        <textarea name="definition" id="definition" rows="10" required placeholder="BEGIN -- Your trigger definition here -- END"></textarea>
                    </td>
                </tr>
            </table>
            <div class="action-buttons triggers">
                <button type="submit" class="btn">
                    <i class="fas fa-plus"></i> Create Trigger
                </button>
                <button type="reset" class="btn">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </form>
    </div>
    <div class="info-box">
        <h4>Trigger Examples</h4>
        <p>Here are some examples of triggers you can create:</p>
        <div class="example-item">
            <h5>Audit Log Trigger</h5>
            <div class="example-code">
                BEGIN INSERT INTO audit_log (table_name, action, user, timestamp) VALUES ('users', 'INSERT', USER(), NOW()); END
            </div>
        </div>

        <div class="example-item">
            <h5>Update Timestamp</h5>
            <div class="example-code">
                BEGIN SET NEW.updated_at = NOW(); END
            </div>
        </div>

        <div class="example-item">
            <h5>Backup Before Delete</h5>
            <div class="example-code">
                BEGIN INSERT INTO users_backup SELECT * FROM users WHERE id = OLD.id; END
            </div>
        </div>

        <div class="example-item">
            <h5>Validate Data</h5>
            <div class="example-code">
                BEGIN IF NEW.email NOT LIKE '%@%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid email formdat'; END IF; END
            </div>
        </div>
    </div>
</div>