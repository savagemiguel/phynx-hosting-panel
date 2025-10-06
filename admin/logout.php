<?php
require_once '../config.php';
require_once '../includes/functions.php';

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: ../login.php?message=' . urlencode('You have been logged out successfully.'));
exit;
?>