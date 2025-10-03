<?php
// Handle foreign key creation
if (isset($_POST['action']) && $_POST['action'] === 'create_fk') {
    $constraint_name = $_POST['constraint_name'];
    $column_name = $_POST['column_name'];
    $referenced_table = $_POST['referenced_table'];
    $referenced_column = $_POST['referenced_column'];
    $on_delete = $_POST['on_delete'];
    $on_update = $_POST['on_update'];

    $sql = "ALTER TABLE `$selected_table` ADD CONSTRAINT `$constraint_name` FOREIGN KEY (`$column_name`) REFERENCES `$referenced_table` (`$referenced_column`) ON DELETE $on_delete ON UPDATE $on_update";
    
    if ($conn->query($sql)) {
        echo '<div class="success-message">FOREIGN KEY CREATED SUCCESSFULLY!</div>';
    } else {
        echo '<div class="error-message">FOREIGN KEY CREATION FAILED! {$conn->error}</div>';
    }
}

// Handle foreign key deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_fk') {
    $constraint_name = $_POST['constraint_name'];
    $sql = "ALTER TABLE `$selected_table` DROP FOREIGN KEY `$constraint_name`";

    if ($conn->query($sql)) {
        echo '<div class="success-message">FOREIGN KEY DELETED SUCCESSFULLY!</div>';
    } else {
        echo '<div class="error-message">FOREIGN KEY DELETION FAILED! {$conn->error}</div>';
    }
}

// Get existing foreign keys for this table
$fk_result = $conn->query("SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA WHERE kcu.TABLE_SCHEMA = '$selected_db' AND kcu.TABLE_NAME = '$selected_table' AND kcu.REFERENCED_TABLE_NAME IS NOT NULL");

$foreign_keys = [];
if ($fk_result) {
    while ($row = $fk_result->fetch_assoc()) {
        $foreign_keys[] = $row;
    }
}

// Get columns for current table
$columns_result = $conn->query("SHOW COLUMNS FROM `$selected_table`");
$columns = [];
if ($columns_result) {
    while ($row = $columns_result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}
?>

<div class="info-box">
    <h4>Existing Foreign Keys</h4>
    <?php if (empty($foreign_keys)): ?>
        <p>No Foreign Keys Found for This Table</p>
    <?php else: ?>
        <table class="settings-table">
            <thead>
                <tr>
                    <th>Constraint Name</th>
                    <th>Column</th>
                    <th>Referenced Table</th>
                    <th>Referenced Column</th>
                    <th>On Delete</th>
                    <th>On Update</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($foreign_keys as $fk): ?>
                    <tr>
                        <td><?= htmlspecialchars($fk['CONSTRAINT_NAME']); ?></td>
                        <td><?= htmlspecialchars($fk['COLUMN_NAME']); ?></td>
                        <td><?= htmlspecialchars($fk['REFERENCED_TABLE_NAME']); ?></td>
                        <td><?= htmlspecialchars($fk['REFERENCED_COLUMN_NAME']); ?></td>
                        <td><?= htmlspecialchars($fk['DELETE_RULE']); ?></td>
                        <td><?= htmlspecialchars($fk['UPDATE_RULE']); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="drop_fk">
                                <input type="hidden" name="constraint_name" value="<?= htmlspecialchars($fk['CONSTRAINT_NAME']); ?>">
                                <button type="submit" class="btn-small" onclick="return confirm('Are you sure you want to drop this foreign key?');">       <i class="fas fa-trash"></i> Drop
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
    <div class="triggers-form">
        <div class="info-box">
            <h4>Create New Foreign Key</h4>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_fk">
                <table class="data-table">
                    <tr>
                        <td><label for="constraint_name">Constraint Name:</label></td>
                        <td><input type="text" name="constraint_name" id="constraint_name" required></td>
                    </tr>
                    <tr>
                        <td><label for="column_name">Column:</label></td>
                        <td>
                            <select name="column_name" id="column_name" required>
                                <?php foreach ($columns as $column): ?>
                                    <option value="<?= htmlspecialchars($column); ?>">
                                        <?= htmlspecialchars($column); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="referenced_table">Referenced Table:</label></td>
                        <td>
                            <select name="referenced_table" id="referenced_table" required>
                                <?php foreach ($tables as $table): ?>
                                    <option value="<?= htmlspecialchars($table); ?>">
                                        <?= htmlspecialchars($table); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="referenced_column">Referenced Column:</label></td>
                        <td><input type="text" name="referenced_column" id="referenced_column" required placeholder="id"></td>
                    </tr>
                    <tr>
                        <td><label for="on_delete">On Delete:</label></td>
                        <td>
                            <select name="on_delete" id="on_delete" required>
                                <option value="RESTRICT">RESTRICT</option>
                                <option value="CASCADE">CASCADE</option>
                                <option value="SET NULL">SET NULL</option>
                                <option value="NO ACTION">NO ACTION</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="on_update">On Update:</label></td>
                        <td>
                            <select name="on_update" id="on_update" required>
                                <option value="RESTRICT">RESTRICT</option>
                                <option value="CASCADE">CASCADE</option>
                                <option value="SET NULL">SET NULL</option>
                                <option value="NO ACTION">NO ACTION</option>
                            </select>
                        </td>
                    </tr>
                </table><br />
                <button type="submit" class="btn">
                    <i class="fas fa-plus"></i> Create Foreign Key
                </button>
                <button type="reset" class="btn">
                    <i class="fas fa-eraser"></i> Reset
                </button>
            </form>
        </div>
    </div>

    <div class="triggers-examples">
        <div class="info-box">
            <h4>Foreign Key Examples</h4>
            <div class="example-item">
                <h5>User Posts Relationship</h5>
                <div class="example-code">
                    ALTER TABLE posts ADD CONSTRAINT fk_posts_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE;
                </div>
            </div>

            <div class="example-item">
                <h5>Category Reference</h5>
                <div class="example-code">
                    ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE;
                </div>
            </div>

            <div class="example-item">
                <h5>Order Items</h5>
                <div class="example-code">
                    ALTER TABLE order_items ADD CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
                </div>
            </div>
        </div>
    </div>
</div>
