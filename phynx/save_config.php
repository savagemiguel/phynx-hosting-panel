<?php
if ($_POST['config_content']) {
    $result = file_put_contents('config.php', $_POST['config_content']);
    echo $result !== false ? 'success' : 'error';
} else {
    echo 'Error';
}