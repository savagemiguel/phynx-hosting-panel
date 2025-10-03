<?php
require_once 'config.php';

// Get pending emails from queue
$query = "SELECT * FROM email_queue WHERE status = 'pending' AND attempts < 3 ORDER BY created_at ASC LIMIT 10";
$result = mysqli_query($conn, $query);

while ($email = mysqli_fetch_assoc($result)) {
    $success = false;
    
    // Try to send email using PHP mail function
    $headers = "From: " . $email['from_email'] . "\r\n";
    $headers .= "Reply-To: " . $email['from_email'] . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    if (mail($email['to_email'], $email['subject'], $email['message'], $headers)) {
        $success = true;
    }
    
    // Update email status
    if ($success) {
        $update_query = "UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "i", $email['id']);
        mysqli_stmt_execute($stmt);
        echo "Email sent to " . $email['to_email'] . "\n";
    } else {
        $attempts = $email['attempts'] + 1;
        $status = $attempts >= 3 ? 'failed' : 'pending';
        
        $update_query = "UPDATE email_queue SET attempts = ?, status = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "isi", $attempts, $status, $email['id']);
        mysqli_stmt_execute($stmt);
        echo "Failed to send email to " . $email['to_email'] . " (attempt $attempts)\n";
    }
}

mysqli_close($conn);
?>