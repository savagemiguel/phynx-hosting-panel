<?php
// Modern Admin Sidebar with enhanced navigation and better UX
$current = basename($_SERVER['SCRIPT_NAME']);
function is_active($file) { 
    global $current; 
    return $current === $file ? 'class="active"' : ''; 
}

function get_current_section() {
    global $current;
    $sections = [
        'dashboard' => ['index.php'],
        'users' => ['users.php', 'packages.php'],
        'hosting' => ['domains.php', 'database-manager.php', 'ssl-automation.php'],
        'dns' => ['dns-records.php', 'dns-templates.php'],
        'email' => ['email-manager.php', 'email-queue.php', 'create-email.php'],
        'system' => ['php-settings.php', 'php-versions.php', 'general-settings.php'],
        'database' => ['mysql-settings.php', 'phpmyadmin.php', 'db-backup.php', 'db-users.php'],
        'files' => ['file-manager.php', 'ftp-accounts.php', 'disk-usage.php', 'file-permissions.php'],
        'docker' => ['docker.php', 'docker-images.php', 'docker-templates.php'],
        'terminal' => ['web-terminal.php', 'ssh-keys.php', 'command-scheduler.php', 'process-monitor.php'],
        'monitoring' => ['server-stats.php', 'log-viewer.php', 'error-logs.php', 'bandwidth-monitor.php'],
        'backup' => ['backup-manager.php', 'backup-scheduler.php', 'restore-manager.php', 'snapshot-manager.php'],
        'security' => ['firewall-config.php', 'fail2ban.php', 'security-scan.php', 'malware-scan.php', 'setup.php', 'vhost-templates.php', 'migrations.php']
    ];
    
    foreach ($sections as $section => $files) {
        if (in_array($current, $files)) return $section;
    }
    return 'dashboard';
}
$currentSection = get_current_section();
?>
<div class="modern-sidebar">
  <!-- Sidebar Header -->
  <div class="sidebar-header">
    <div class="admin-profile">
      <div class="profile-avatar">
        <i class="fas fa-user-shield"></i>
      </div>
      <div class="profile-info">
        <h4>Hosting Panel</h4>
        <p class="profile-role">Full Access</p>
      </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions">
      <button class="quick-btn" id="refreshBtn" title="Refresh Page">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>
  </div>
  
  <!-- Search Bar -->
  <div class="sidebar-search">
    <div class="search-container">
      <i class="fas fa-search search-icon"></i>
      <input type="text" placeholder="Search admin panels..." class="search-input" id="sidebarSearch">
    </div>
  </div>
  
  <!-- Main Navigation -->
  <div class="modern-nav">
    <!-- Primary Navigation (Always Visible) -->
    <div class="nav-section primary-nav">
      <a href="index.php" class="nav-item <?= $current === 'index.php' ? 'active' : '' ?>" data-section="dashboard">
        <div class="nav-icon"><i class="fas fa-tachometer-alt"></i></div>
        <span class="nav-label">Dashboard</span>
        <div class="nav-indicator"></div>
      </a>
    </div>

    <!-- Main Sections -->
    <div class="nav-section main-sections">
      <div class="section-label">Management</div>
      
      <!-- Users & Access -->
      <div class="nav-group <?= $currentSection === 'users' ? 'expanded' : '' ?>" data-group="users">
        <div class="nav-item group-toggle">
          <div class="nav-icon"><i class="fas fa-users"></i></div>
          <span class="nav-label">Users & Access</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'users'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="users.php" class="sub-item <?= $current === 'users.php' ? 'active' : '' ?>">
            <i class="fas fa-user-friends"></i>Manage Users
          </a>
          <a href="packages.php" class="sub-item <?= $current === 'packages.php' ? 'active' : '' ?>">
            <i class="fas fa-box"></i>Hosting Packages
          </a>
        </div>
      </div>

      <!-- Hosting Services -->
      <div class="nav-group <?= $currentSection === 'hosting' ? 'expanded' : '' ?>" data-group="hosting">
        <div class="nav-item group-toggle" data-tooltip="Hosting Services">
          <div class="nav-icon"><i class="fas fa-server"></i></div>
          <span class="nav-label">Hosting Services</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'hosting'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="domains.php" class="sub-item <?= $current === 'domains.php' ? 'active' : '' ?>">
            <i class="fas fa-globe"></i>Domain Manager
            <?php
            $domainCount = 0;
            if (isset($conn)) {
              $domain_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM domains WHERE status = 'pending'");
              if ($domain_result) $domainCount = mysqli_fetch_assoc($domain_result)['count'];
            }
            if ($domainCount > 0): ?>
            <span class="nav-badge"><?= $domainCount ?></span>
            <?php endif; ?>
          </a>
          <a href="database-manager.php" class="sub-item <?= $current === 'database-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-database"></i>Database Manager
          </a>
          <a href="ssl-automation.php" class="sub-item <?= $current === 'ssl-automation.php' ? 'active' : '' ?>">
            <i class="fas fa-shield-alt"></i>SSL Certificates
          </a>
        </div>
      </div>

      <!-- DNS Management -->
      <div class="nav-group <?= $currentSection === 'dns' ? 'expanded' : '' ?>" data-group="dns">
        <div class="nav-item group-toggle" data-tooltip="DNS Management">
          <div class="nav-icon"><i class="fas fa-route"></i></div>
          <span class="nav-label">DNS Management</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'dns'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="dns-records.php" class="sub-item <?= $current === 'dns-records.php' ? 'active' : '' ?>">
            <i class="fas fa-network-wired"></i>DNS Records
          </a>
          <a href="dns-templates.php" class="sub-item <?= $current === 'dns-templates.php' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i>DNS Templates
          </a>
        </div>
      </div>

      <!-- Email Services -->
      <div class="nav-group <?= $currentSection === 'email' ? 'expanded' : '' ?>" data-group="email">
        <div class="nav-item group-toggle" data-tooltip="Email Services">
          <div class="nav-icon"><i class="fas fa-envelope"></i></div>
          <span class="nav-label">Email Services</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'email'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="email-manager.php" class="sub-item <?= $current === 'email-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-inbox"></i>Email Accounts
          </a>
          <a href="email-queue.php" class="sub-item <?= $current === 'email-queue.php' ? 'active' : '' ?>">
            <i class="fas fa-paper-plane"></i>Mail Queue
            <?php
            $queueCount = 0;
            if (isset($conn)) {
              $queue_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM email_queue WHERE status = 'pending'");
              if ($queue_result) $queueCount = mysqli_fetch_assoc($queue_result)['count'];
            }
            if ($queueCount > 0): ?>
            <span class="nav-badge warning"><?= $queueCount ?></span>
            <?php endif; ?>
          </a>
          <a href="create-email.php" class="sub-item <?= $current === 'create-email.php' ? 'active' : '' ?>">
            <i class="fas fa-plus-circle"></i>Create Account
          </a>
        </div>
      </div>
    </div>

    <!-- System Configuration -->
    <div class="nav-section system-sections">
      <div class="section-label">System</div>
      
      <!-- Server Configuration -->
      <div class="nav-group <?= $currentSection === 'system' ? 'expanded' : '' ?>" data-group="system">
        <div class="nav-item group-toggle" data-tooltip="Server Config">
          <div class="nav-icon"><i class="fas fa-cogs"></i></div>
          <span class="nav-label">Server Config</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'system'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="php-settings.php" class="sub-item <?= $current === 'php-settings.php' ? 'active' : '' ?>">
            <i class="fab fa-php"></i>PHP Configuration
          </a>
          <a href="php-versions.php" class="sub-item <?= $current === 'php-versions.php' ? 'active' : '' ?>">
            <i class="fas fa-code-branch"></i>PHP Versions
          </a>
          <a href="general-settings.php" class="sub-item <?= $current === 'general-settings.php' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h"></i>General Settings
          </a>
        </div>
      </div>

      <!-- Database Management -->
      <div class="nav-group <?= $currentSection === 'database' ? 'expanded' : '' ?>" data-group="database">
        <div class="nav-item group-toggle" data-tooltip="Database Config">
          <div class="nav-icon"><i class="fas fa-database"></i></div>
          <span class="nav-label">Database Config</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'database'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="mysql-settings.php" class="sub-item <?= $current === 'mysql-settings.php' ? 'active' : '' ?>">
            <i class="fas fa-cogs"></i>MySQL Configuration
          </a>
          <a href="phpmyadmin.php" class="sub-item <?= $current === 'phpmyadmin.php' ? 'active' : '' ?>">
            <i class="fas fa-tools"></i>phpMyAdmin Access
          </a>
          <a href="db-backup.php" class="sub-item <?= $current === 'db-backup.php' ? 'active' : '' ?>">
            <i class="fas fa-download"></i>Database Backups
          </a>
          <a href="db-users.php" class="sub-item <?= $current === 'db-users.php' ? 'active' : '' ?>">
            <i class="fas fa-users-cog"></i>DB User Management
          </a>
        </div>
      </div>

      <!-- File Management -->
      <div class="nav-group <?= $currentSection === 'files' ? 'expanded' : '' ?>" data-group="files">
        <div class="nav-item group-toggle" data-tooltip="File Management">
          <div class="nav-icon"><i class="fas fa-folder-open"></i></div>
          <span class="nav-label">File Management</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'files'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="file-manager.php" class="sub-item <?= $current === 'file-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-folder"></i>Web File Manager
          </a>
          <a href="ftp-accounts.php" class="sub-item <?= $current === 'ftp-accounts.php' ? 'active' : '' ?>">
            <i class="fas fa-server"></i>FTP Accounts
          </a>
          <a href="disk-usage.php" class="sub-item <?= $current === 'disk-usage.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i>Disk Usage Analysis
          </a>
          <a href="file-permissions.php" class="sub-item <?= $current === 'file-permissions.php' ? 'active' : '' ?>">
            <i class="fas fa-key"></i>File Permissions
          </a>
        </div>
      </div>

      <!-- Container Management -->
      <div class="nav-group <?= $currentSection === 'docker' ? 'expanded' : '' ?>" data-group="docker">
        <div class="nav-item group-toggle" data-tooltip="Containers">
          <div class="nav-icon"><i class="fab fa-docker"></i></div>
          <span class="nav-label">Containers</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'docker'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="docker.php" class="sub-item <?= $current === 'docker.php' ? 'active' : '' ?>">
            <i class="fas fa-cube"></i>Docker Manager
          </a>
          <a href="docker-images.php" class="sub-item <?= $current === 'docker-images.php' ? 'active' : '' ?>">
            <i class="fas fa-layer-group"></i>Images & Registry
          </a>
          <a href="docker-templates.php" class="sub-item <?= $current === 'docker-templates.php' ? 'active' : '' ?>">
            <i class="fas fa-file-code"></i>Container Templates
          </a>
        </div>
      </div>
    </div>

    <!-- Advanced System Tools -->
    <div class="nav-section advanced-sections">
      <div class="section-label">Advanced Tools</div>

      <!-- Terminal & SSH -->
      <div class="nav-group <?= $currentSection === 'terminal' ? 'expanded' : '' ?>" data-group="terminal">
        <div class="nav-item group-toggle" data-tooltip="Terminal & SSH">
          <div class="nav-icon"><i class="fas fa-terminal"></i></div>
          <span class="nav-label">Terminal & SSH</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'terminal'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="web-terminal.php" class="sub-item <?= $current === 'web-terminal.php' ? 'active' : '' ?>">
            <i class="fas fa-window-maximize"></i>Web Terminal
          </a>
          <a href="ssh-keys.php" class="sub-item <?= $current === 'ssh-keys.php' ? 'active' : '' ?>">
            <i class="fas fa-key"></i>SSH Key Manager
          </a>
          <a href="command-scheduler.php" class="sub-item <?= $current === 'command-scheduler.php' ? 'active' : '' ?>">
            <i class="fas fa-clock"></i>Cron Jobs
          </a>
          <a href="process-monitor.php" class="sub-item <?= $current === 'process-monitor.php' ? 'active' : '' ?>">
            <i class="fas fa-tasks"></i>Process Monitor
          </a>
        </div>
      </div>

      <!-- Server Monitoring -->
      <div class="nav-group <?= $currentSection === 'monitoring' ? 'expanded' : '' ?>" data-group="monitoring">
        <div class="nav-item group-toggle" data-tooltip="Monitoring">
          <div class="nav-icon"><i class="fas fa-chart-line"></i></div>
          <span class="nav-label">Monitoring</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'monitoring'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="server-stats.php" class="sub-item <?= $current === 'server-stats.php' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i>Performance Monitor
          </a>
          <a href="log-viewer.php" class="sub-item <?= $current === 'log-viewer.php' ? 'active' : '' ?>">
            <i class="fas fa-file-alt"></i>Log Viewer
          </a>
          <a href="error-logs.php" class="sub-item <?= $current === 'error-logs.php' ? 'active' : '' ?>">
            <i class="fas fa-exclamation-triangle"></i>Error Analysis
          </a>
          <a href="bandwidth-monitor.php" class="sub-item <?= $current === 'bandwidth-monitor.php' ? 'active' : '' ?>">
            <i class="fas fa-wifi"></i>Bandwidth Monitor
          </a>
        </div>
      </div>

      <!-- Backup & Recovery -->
      <div class="nav-group <?= $currentSection === 'backup' ? 'expanded' : '' ?>" data-group="backup">
        <div class="nav-item group-toggle" data-tooltip="Backup & Recovery">
          <div class="nav-icon"><i class="fas fa-shield-alt"></i></div>
          <span class="nav-label">Backup & Recovery</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'backup'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="backup-manager.php" class="sub-item <?= $current === 'backup-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-save"></i>Backup Manager
          </a>
          <a href="backup-scheduler.php" class="sub-item <?= $current === 'backup-scheduler.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>Backup Scheduler
          </a>
          <a href="restore-manager.php" class="sub-item <?= $current === 'restore-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-undo"></i>Restore Manager
          </a>
          <a href="snapshot-manager.php" class="sub-item <?= $current === 'snapshot-manager.php' ? 'active' : '' ?>">
            <i class="fas fa-camera"></i>Server Snapshots
          </a>
        </div>
      </div>

      <!-- Security & Maintenance -->
      <div class="nav-group <?= $currentSection === 'security' ? 'expanded' : '' ?>" data-group="security">
        <div class="nav-item group-toggle" data-tooltip="Security">
          <div class="nav-icon"><i class="fas fa-shield-alt"></i></div>
          <span class="nav-label">Security</span>
          <i class="fas fa-chevron-right nav-arrow"></i>
          <?php if ($currentSection === 'security'): ?>
          <div class="nav-indicator active"></div>
          <?php endif; ?>
        </div>
        <div class="nav-submenu">
          <a href="firewall-config.php" class="sub-item <?= $current === 'firewall-config.php' ? 'active' : '' ?>">
            <i class="fas fa-fire"></i>Firewall Configuration
          </a>
          <a href="fail2ban.php" class="sub-item <?= $current === 'fail2ban.php' ? 'active' : '' ?>">
            <i class="fas fa-ban"></i>Fail2Ban Settings
          </a>
          <a href="security-scan.php" class="sub-item <?= $current === 'security-scan.php' ? 'active' : '' ?>">
            <i class="fas fa-search"></i>Security Scanner
          </a>
          <a href="malware-scan.php" class="sub-item <?= $current === 'malware-scan.php' ? 'active' : '' ?>">
            <i class="fas fa-bug"></i>Malware Scanner
          </a>
          <a href="setup.php" class="sub-item <?= $current === 'setup.php' ? 'active' : '' ?>">
            <i class="fas fa-wrench"></i>System Setup
          </a>
          <a href="vhost-templates.php" class="sub-item <?= $current === 'vhost-templates.php' ? 'active' : '' ?>">
            <i class="fas fa-file-alt"></i>VHost Templates
          </a>
          <a href="migrations.php" class="sub-item <?= $current === 'migrations.php' ? 'active' : '' ?>">
            <i class="fas fa-database"></i>DB Migrations
          </a>
        </div>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="nav-section quick-links">
      <div class="section-label">Quick Access</div>
      <a href="../logout.php" class="nav-item logout">
        <div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>
        <span class="nav-label">Logout</span>
      </a>
    </div>
  </div>

  <!-- System Status Footer -->
  <div class="sidebar-footer">
    <div class="status-section">
      <div class="status-title">System Status</div>
      <div class="status-items">
        <div class="status-item">
          <i class="fas fa-server status-icon status-online"></i>
          <span class="status-text">Apache: Online</span>
        </div>
        <div class="status-item">
          <i class="fas fa-database status-icon status-online"></i>
          <span class="status-text">MySQL: Online</span>
        </div>
        <div class="status-item">
          <i class="fas fa-memory status-icon status-warning"></i>
          <span class="status-text">Memory: 68%</span>
        </div>
        <div class="status-item">
          <i class="fas fa-hdd status-icon status-online"></i>
          <span class="status-text">Disk: 45%</span>
        </div>
      </div>
    </div>
  </div>
</div>
