<div class="content-header">
    <h2><i class="fas fa-cogs"></i> Server Configuration</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
            <i class="fa fa-angle-right"></i>
            <span class="breadcrumb_text"><i class="fas fa-paperclip"></i>
        Configuration</span>
    </div>
</div>

<div class="config-editor">
    <div class="config-header">
        <h3><i class="fas fa-file-code"></i> Configuration Editor</h3>
        <div class="config-actions">
            <button class="btn btn-success" onclick="saveConfig();">
                <i class="fas fa-save"></i> Save
            </button>
            <button class="btn btn-danger" onclick="resetConfig();">
                <i class="fas fa-trash-alt"></i> Reset
            </button>
        </div>
    </div>

    <div class="code-editor">
        <textarea id="config-textarea" placeholder="Loading configuration..."><?= htmlspecialchars(file_get_contents('config.php')) ?></textarea>
    </div>

    <div class="config-info">
        <h4><i class="fas fa-info-circle"></i> Configuration Guide</h4>
        <div class="info-grid">
            <div class="info-item">
                <strong>Server Configuration:</strong>
                <p>Each server entry contains hostname, port, username, password, and database name settings for database connections.</p>
            </div>
            <div class="info-item">
                <strong>Default Server:</strong>
                <p>Set which server configuration to use by default when logging in.</p>
            </div>
        </div>
    </div>
</div>