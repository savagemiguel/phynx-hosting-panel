<?php
include 'includes/var.funcs.php';

// Handle user deletion
if ($_POST['delete_user']) {
    $delete_user = $_POST['delete_user'];

    try {
        // Get all hosts for this user
        $hosts_query = "SELECT Host FROM mysql.user WHERE User = '$delete_user'";
        $hosts_result = $conn->query($hosts_query);

        // Delete user from all hosts
        while ($host_row = $hosts_result->fetch_assoc()) {
            $hostname = $host_row['Host'];
            $conn->query("DROP USER `$delete_user`@`$hostname`");
        }

        $conn->query("FLUSH PRIVILEGES");
        $success_message = "User '$delete_user' deleted successfully.";

    } catch (Exception $e) {
        $error_message = "ERROR: Failed to delete user: " . $e->getMessage();
    }
}