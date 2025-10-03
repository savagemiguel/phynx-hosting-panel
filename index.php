<?php
require_once 'config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if (isAdmin()) {
    header('Location: admin/');
} else {
    header('Location: user/');
}
exit;
?>