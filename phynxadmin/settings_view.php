<div class="content-header">
    <h2>Settings</h2>
    <div class="breadcrumb">
        <?php echo functions::getServerInfo($conn)['connection_info']; ?>
        <i class="fa fa-angle-right"></i>
        <span class="breadcrumb_text"><i class="fa fa-cogs"></i>
        Settings</span>
    </div>
</div>

<div class="info-box">
    <h4>PHP Extensions</h4>
    <p>These are the PHP extensions that are currently loaded.</p>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Extension Name</th>
                    <th>Version</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $extensions = get_loaded_extensions();
                sort($extensions);
                foreach ($extensions as $extension):
                $version = phpversion($extension) ?: 'N/A';
                ?>
                <tr>
                    <td><?= htmlspecialchars($extension); ?></td>
                    <td><?= htmlspecialchars($version); ?></td>
                    <td><span style="color: var(--success-color);">✓ Loaded</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="info-box">
    <h4>MySQL Variables</h4>
    <p>These are the MySQL variables that are currently set.</p>
    <?php $variables = $conn->query("SHOW VARIABLES"); ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Variable</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($var = $variables->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($var['Variable_name']); ?></td>
                    <td><?= htmlspecialchars($var['Value']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>