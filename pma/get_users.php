<?php
session_start();
require_once 'config.php';


?>
    <table class="table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Host Name</th>
                <th>Password</th>
                <th>Global Privileges</th>
                <th>User Group</th>
                <th>Grant</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['username']; ?></td>
                    <td><?= $user['hostname']; ?></td>
                    <td>
                        <span class="<?= $user['password'] === 'Yes' ? 'text-success' : 'text-warning' ?>">
                            <?= $user['password']; ?>
                        </span>
                    </td>
                    <td><?= $user['privileges']; ?></td>
                    <td><?= $user['user_group']; ?></td>
                    <td>
                        <span class="<?= $user['grant'] === 'Yes' ? 'text-success' : 'text-muted' ?>">
                            <?= $user['grant']; ?>
                        </span>
                    </td>
                    <td>
                        <a href="?page=edit_user&user=<?= urlencode($user['username']); ?>&host=<?= urlencode($user['hostname']); ?>" class="btn-small">
                            <i class="fas fa-edit"></i> Edit Privileges
                        </a>
                        <a href="?page=export_user&user=<?= urlencode($user['username']); ?>&host=<?= urlencode($user['hostname']); ?>" class="btn-small">
                            <i class="fas fa-download"></i> Export
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>