<?php
$ini_path = php_ini_loaded_file();
$ini_content = $ini_path ? file_get_contents($ini_path) : 'PHP ini file not found or not readable.';
?>

<div class="content-header">
    <h2><i class="fas fa-file-code"></i> PHP Configuration Editor</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fas fa-file-code"></i>
        PHP.ini</span>
    </div>
</div>

<div class="config-editor">
    <div class="config-header">
        <h3><i class="fab fa-php"></i> php.ini File Editor</h3>
        <div class="config-actions">
            <span class="config-path">PATH: <?= $ini_path ?: 'Not Found'; ?></span>
            <button class="code-editor btn btn-success" onclick="savePHPIni()">Save</button>
        </div>
    </div>

    <div class="code-editor">
        <textarea id="php-ini-textarea" spellcheck="false" <?= !$ini_path ? 'readonly' : '' ?>><?= htmlspecialchars($ini_content); ?></textarea>
    </div>

    <div class="config-info">
        <h4><i class="fas fa-exclamation-triangle"></i> Important Notes</h4>
        <div class="info-grid">
            <div class="info-item">
                <strong>Warning:</strong>
                <p>Editing the php.ini file can have a significant impact on your website's performance. Make sure you understand the implications of your changes before proceeding.</p>
                <p>Be cautious when making changes to the php.ini file. Incorrect or outdated settings can lead to security vulnerabilities and other issues. Always test your changes in a staging environment before making them live.</p>
                <p>Remember that php.ini is a critical file for your website's performance and security. Make sure you understand the implications of your changes before   proceeding.</p>
                <p>Changes to php.ini file require a web server restart to take effect.</p>
            </div>
            <div class="info-item">
                <strong>Backup First:</strong>
                <p>Changes to php.ini file require a web server restart to take effect.</p>
            </div>
        </div>
    </div>
</div>