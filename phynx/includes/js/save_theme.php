<?php
session_start();
if (isset($_POST['theme'])) {
    $_SESSION['theme'] = $_POST['theme'];
    echo 'success';
} else {
    echo 'error';
}