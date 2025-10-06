<?php
// --- AJAX Installer Deletion ---
if (isset($_GET['installer_delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (file_exists('config.php')) {
        // Attempt to delete the installer file
        if (@unlink(__FILE__)) {
            // On success, destroy the session and confirm
            session_start();
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Installer removed successfully.']);
            exit;
        } else {
            // On failure, report error
            echo json_encode(['success' => false, 'message' => 'Could not delete install.php. Please check file permissions and remove it manually.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Configuration not found. Cannot delete installer.']);
        exit;
    }
}

// Include the functions api
// include_once 'includes/config/funcs.api.php'; // funcs.api.php doesn't have checkVersion

$version = '1.0.0'; // Hardcode version to avoid errors

// Start the session
session_start();

// Installation steps
$steps = [
    1 => 'Welcome',
    2 => 'Requirements Check',
    3 => 'Database Configuration',
    4 => 'Create Config File',
    5 => 'Complete Installation'
];

$current_step = $_GET['step'] ?? 1;
$force_reinstall = isset($_GET['force']) && $_GET['force'] === 'true';

// Handle fresh installation request
if ($force_reinstall && file_exists('config.php')) {
    // Backup existing config before deletion
    $backup_name = 'config.backup.' . date('Y-m-d_H-i-s') . '.php';
    @copy('config.php', $backup_name);
    @unlink('config.php');
    
    // Clear session data for fresh start
    session_destroy();
    session_start();
    
    // Set flag to allow fresh installation
    $_SESSION['fresh_install'] = true;
    
    // Redirect to step 1 for fresh installation
    header('Location: install.php?step=1');
    exit;
}

// --- Post-Installation Security Check ---
// If config.php already exists, it means the installation is complete.
// We should redirect to the completion screen or block access if trying to re-install.
// Allow fresh installation if session flag is set or force parameter is used
$is_fresh_install = isset($_SESSION['fresh_install']) && $_SESSION['fresh_install'] === true;
if (file_exists('config.php') && !isset($_GET['installer_delete']) && !$force_reinstall && !$is_fresh_install) {
    if ($current_step != 5) {
        // Redirect to completion screen to allow installer removal or fresh install
        header('Location: install.php?step=5');
        exit;
    }
}
$error = null;

// Handle step 4 submission (create config file) before rendering step 5
if ($current_step == 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SESSION['db_config'])) {
        $config = $_SESSION['db_config'];
        
        $host = addslashes($config['host']);
        $port = (int)($config['port']);
        $user = addslashes($config['username']);
        $pass = addslashes($config['password']);
        $name = addslashes($config['name'] ?? 'Local Server');

        $config_content = <<<EOT
<?php
// Config file for Phynx Manager

class Config {
    public static \$config = [];

    public static function init() {
        self::\$config[] = [];
        self::\$config['DefaultServer'] = 1;
        self::\$config['Server'] = []; // Initialize Server array first        

        \$s = 0;

        self::\$config['Server'][++\$s] = [
            'host' => '$host',
            'name' => '$name',
            'port' => $port,
            'user' => '$user',
            'pass' => '$pass'
        ];
    }

    public static function get(\$key = null) {
        if (\$key === null) {
            return self::\$config;
        }
        return self::\$config[\$key] ?? null;
    }
}

// Initialize the config
Config::init();
\$config = Config::get(); // For backward compatibility
EOT;

        if (!file_put_contents('config.php', $config_content)) {
            $error = "ERROR: Failed to create config.php. Check file permissions for the web root directory.";
            $current_step = 4; // Go back to step 4 to show the error
        } else {
            unset($_SESSION['db_config']);
            // Clear fresh install flag when installation is complete
            unset($_SESSION['fresh_install']);
        }
    } else {
        // Session lost, redirect to db config step
        header('Location: install.php?step=3');
        exit;
    }
}

// Handle AJAX for step 3
if ($current_step == 3 && isset($_POST['test_connection'])) {
    header('Content-Type: application/json');

    $host = $_POST['host'] ?? 'localhost';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $port = $_POST['port'] ?? 3306;
    $_POST['name'] = $_POST['name'] ?? 'Local Server';

    try {
        $test_conn = @new mysqli($host, $username, $password, '', (int)$port);
        if ($test_conn->connect_error) {
            throw new Exception($test_conn->connect_error);
        }
        $test_conn->close();

        $_SESSION['db_config'] = $_POST;
        echo json_encode(['success' => true, 'host' => $host, 'port' => $port]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'host' => $host, 'port' => $port]);
    }
    exit;
}
// Check requirements
function checkRequirements() {
    $requirements = [
        'PHP Version >= 8.0' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'MySQLi Extension' => extension_loaded('mysqli'),
        'Session Support' => function_exists('session_start'),
        'File Write Permission' => is_writable('.'),
        'Includes Directory' => is_dir('includes')
    ];
    return $requirements;
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>P H Y N X - Installation</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="includes/css/styles.css">
    </head>
    <body>
    <div class="top-progress-bar">
        <div class="top-progress-fill" id="topProgress"></div>
    </div>
    
    <div class="install-wrapper">
        <div class="install-sidebar">
            <div class="install-logo">
                <i class="fas fa-database"></i>
                <h1>PHYNX</h1>
                <p>Installation Wizard</p>
                <div class="install-version"><?= $version; ?></div>
            </div>
            
            <div class="progress-section">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= ($current_step / count($steps)) * 100 ?>%"></div>
                </div>
                <!-- <div class="progress-text"><?= $current_step ?> of <?= count($steps) ?> steps completed</div> -->
            </div>
            
            <div class="steps-sidebar">
                <?php foreach ($steps as $num => $name): ?>
                    <div class="step-item <?= $num == $current_step ? 'active' : ($num < $current_step ? 'completed' : 'pending') ?>">
                        <div class="step-number">
                            <?php if ($num < $current_step): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <?= $num ?>
                            <?php endif; ?>
                        </div>
                        <div class="step-info">
                            <div class="step-title"><?= $name ?></div>
                            <div class="step-status">
                                <?= $num < $current_step ? 'Completed' : ($num == $current_step ? 'In Progress' : 'Pending') ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="install-main">
            <div class="install-content">
                <?php if (isset($error)): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['fresh_install']) && $_SESSION['fresh_install'] === true): ?>
                    <div class="info-message" style="margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 5px; border-left: 4px solid #2196f3; color: #1565c0;">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Fresh Installation Mode:</strong> Previous configuration has been cleared. Proceeding with new installation.
                    </div>
                <?php endif; ?>

                <?php switch ($current_step): case 1: ?>
                    <div class="step-content">
                        <h2><i class="fas fa-rocket"></i> Welcome to PHYNX</h2>
                        <p>This installation wizard will guide you through setting up your PHYNX database management system.</p>
                        
                        <div class="feature-grid">
                            <div class="feature-item">
                                <i class="fas fa-database"></i>
                                <h4>Database Management</h4>
                                <p>Full MySQL database administration</p>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-users"></i>
                                <h4>User Management</h4>
                                <p>Complete user and privilege control</p>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-shield-alt"></i>
                                <h4>Secure Access</h4>
                                <p>Advanced security features</p>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <a href="install.php?step=2" class="btn btn-primary">
                                <i class="fas fa-arrow-right"></i> Start Installation
                            </a>
                        </div>
                    </div>

                <?php break; case 2: ?>
                    <div class="step-content">
                        <h2><i class="fas fa-check-circle"></i> System Requirements</h2>
                        <p>Checking your system compatibility...</p>
                        
                        <div class="requirements-grid">
                            <?php
                            $requirements = checkRequirements();
                            $all_passed = true;
                            foreach ($requirements as $req => $passed):
                                if (!$passed) $all_passed = false;
                            ?>
                                <div class="requirement-item <?= $passed ? 'pass' : 'fail' ?>">
                                    <div class="req-icon">
                                        <i class="fas fa-<?= $passed ? 'check-circle' : 'times-circle' ?>"></i>
                                    </div>
                                    <div class="req-info">
                                        <div class="req-name"><?= $req ?></div>
                                        <div class="req-status"><?= $passed ? 'Available' : 'Missing' ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="action-buttons">
                            <?php if ($all_passed): ?>
                                <a href="install.php?step=3" class="btn btn-primary">
                                    <i class="fas fa-arrow-right"></i> Continue
                                </a>
                            <?php else: ?>
                                <a href="install.php?step=2" class="btn btn-secondary">
                                    <i class="fas fa-refresh"></i> Recheck Requirements
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php break; case 3: ?>
                    <div class="step-content">
                        <h2><i class="fas fa-server"></i> Database Configuration</h2>
                        <p>Enter your MySQL database connection details.</p><br />
                        
                        <form id="dbTestForm" class="install-form">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="host"><i class="fas fa-server"></i> MySQL Host:</label>
                                    <input type="text" name="host" id="host" value="<?= $_POST['host'] ?? 'localhost' ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="port"><i class="fas fa-plug"></i> MySQL Port:</label>
                                    <input type="number" name="port" id="port" value="<?= $_POST['port'] ?? '3306' ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="username"><i class="fas fa-user"></i> Username:</label>
                                    <input type="text" name="username" id="username" value="<?= $_POST['username'] ?? '' ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="password"><i class="fas fa-lock"></i> Password:</label>
                                    <input type="password" name="password" id="password" value="<?= $_POST['password'] ?? '' ?>">
                                </div>
                            </div>                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="name"><i class="fas fa-tag"></i> Server Name:</label>
                                    <input type="text" name="name" id="name" value="<?= $_POST['name'] ?? 'Local Server' ?>" required>
                                </div>
                                <div class="form-group" style="visibility: hidden;"></div> <!-- Placeholder to keep alignment -->
                            </div>
                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-bolt"></i> Test Connection
                                </button>
                                <button type="button" id="continueBtn" class="btn btn-success" style="display: none;">
                                    <i class="fas fa-arrow-right"></i> Continue
                                </button>
                            </div>
                        </form>

                        <div class="console-output" id="consoleOutput" style="display: none;">
                            <div class="console-header">
                                <i class="fas fa-terminal"></i> Connection Test Results:
                            </div>
                            <div class="console-content" id="consoleContent">
                                <p>Connection test results will be displayed here.</p>
                            </div>
                    </div>

                <?php break; case 4: ?>
                    <div class="step-content">
                        <h2><i class="fas fa-cog"></i> Configuration File</h2>
                        <p>Creating your configuration file with the database settings.</p>
                        
                        <div class="config-preview">
                            <h4>Configuration Summary:</h4>
                            <div class="config-item">
                                <span>Host:</span> <strong><?= $_SESSION['db_config']['host'] ?? 'localhost' ?></strong>
                            </div>
                            <div class="config-item">
                                <span>Port:</span> <strong><?= $_SESSION['db_config']['port'] ?? '3306' ?></strong>
                            </div>
                            <div class="config-item">
                                <span>Username:</span> <strong><?= $_SESSION['db_config']['username'] ?? '' ?></strong>
                            </div>
                            <div class="config-item">
                                <span>Server Name:</span> <strong><?= $_SESSION['db_config']['name'] ?? 'Local Server' ?></strong>
                            </div>
                        </div>
                        
                        <form method="POST" action="install.php?step=5">
                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Configuration
                                </button>
                            </div>
                        </form>
                    </div>

                <?php break; case 5: ?>
                    <div class="step-content success-content">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>Installation Complete!</h2>
                        <p>PHYNX has been successfully installed and configured.</p>
                        
                        <?php if (file_exists('config.php')): ?>
                        <div class="info-message" style="margin: 15px 0; padding: 10px; background: var(--bg-secondary); border-radius: 5px; border-left: 4px solid var(--warning-color);">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Installation already exists.</strong> If you want to start fresh, use the "Fresh Installation" button below.
                        </div>
                        <?php endif; ?>
                        
                        <div class="completion-info">
                            <div class="info-item-install" id="db-check">
                                <i class="fas fa-database"></i>
                                <span>Database connection established<span class="dots"></span></span>
                                <i class="fas fa-check completion-check" style="display: none;"></i>
                            </div>
                            <div class="info-item-install" id="config-check">
                                <i class="fas fa-file-code"></i>
                                <span>Configuration file created<span class="dots"></span></span>
                                <i class="fas fa-check completion-check" style="display: none;"></i>
                            </div>
                            <div class="info-item-install" id="security-check">
                                <i class="fas fa-shield-alt"></i>
                                <span>Security settings applied<span class="dots"></span></span>
                                <i class="fas fa-check completion-check" style="display: none;"></i>
                            </div><br />
                        </div>

                        <div id="removalStatus" style="margin-top: 15px; text-align: center;"></div>

                        <div class="action-buttons">
                            <a href="login.php" id="accessPhynxBtn" class="btn btn-primary" style="display: none;">
                                <i class="fas fa-sign-in-alt"></i> Access PHYNX
                            </a>
                            <button id="removeInstallerBtn" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Remove Installer
                            </button>
                            <a href="install.php?force=true" class="btn btn-secondary" onclick="return confirm('This will delete the current configuration and start fresh. Are you sure?')">
                                <i class="fas fa-redo"></i> Fresh Installation
                            </a>
                        </div>
                    </div>
                <?php endswitch; ?>
            </div>
        </div>
    </div>
    <script>
        // Initialize installation progress
        document.addEventListener('DOMContentLoaded', function() {
            const currentStep = <?= $current_step ?>;
            const totalSteps = <?= count($steps) ?>;

            // Animate progress bar on load
            setTimeout(() => {
                const progress = (currentStep / totalSteps) * 100;
                const progressBar = document.getElementById('topProgress');
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }
            }, 300);

            // Animate step items
            const stepItems = document.querySelectorAll('.step-item');
            stepItems.forEach((item, index) => {
                setTimeout(() => {
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, index * 100);
            });

            // Animate content
            const content = document.querySelector('.step-content');
            if (content) {
                setTimeout(() => {
                    content.style.opacity = '1';
                    content.style.transform = 'translateY(0)';
                }, 200);
            }

            // Requirements check animation
            if (currentStep === 2) {
                const requirements = document.querySelectorAll('.requirement-item');
                requirements.forEach((req, index) => {
                    setTimeout(() => {
                        req.style.opacity = '1';
                        req.style.transform = 'translateX(0)';
                        
                        // Add pulse animation for passed requirements
                        if (req.classList.contains('pass')) {
                            setTimeout(() => {
                                req.querySelector('.req-icon i').style.animation = 'pulse 0.6s ease-in-out';
                            }, 300);
                        }
                    }, index * 150);
                });
            }

            // Feature grid animation
            if (currentStep === 1) {
                const features = document.querySelectorAll('.feature-item');
                features.forEach((feature, index) => {
                    setTimeout(() => {
                        feature.style.opacity = '1';
                        feature.style.transform = 'translateY(0)';
                    }, index * 200);
                });
            }

            // Check completion status with enhanced animations
            if (currentStep === 5) {
                setTimeout(() => {
                    const checks = ['db-check', 'config-check', 'security-check'];
                    let currentCheck = 0;

                    function animateDots(element) {
                        let dotCount = 0;
                        return setInterval(() => {
                            dotCount = (dotCount + 1) % 4;
                            element.textContent = '.'.repeat(dotCount);
                        }, 400);
                    }

                    function processNext() {
                        if (currentCheck >= checks.length) {
                            // All checks complete - show final animation and update step status
                            setTimeout(() => {
                                const successIcon = document.querySelector('.success-icon i');
                                if (successIcon) {
                                    successIcon.style.animation = 'successPulse 1s ease-in-out';
                                }
                                
                                // Update step 5 status to Completed
                                const step5Status = document.querySelector('.step-item.active .step-status');
                                if (step5Status) {
                                    step5Status.textContent = 'Completed';
                                }
                            }, 500);
                            return;
                        }

                        const checkElement = document.getElementById(checks[currentCheck]);
                        if (!checkElement) return;

                        // Highlight current check
                        checkElement.style.background = 'var(--bg-tertiary)';
                        checkElement.style.borderLeft = '4px solid var(--primary-color)';

                        const dotsElement = checkElement.querySelector('.dots');
                        const checkIcon = checkElement.querySelector('.completion-check');

                        if (dotsElement && checkIcon) {
                            const dotInterval = animateDots(dotsElement);

                            setTimeout(() => {
                                clearInterval(dotInterval);
                                dotsElement.style.display = 'none';
                                checkIcon.style.display = 'inline';
                                checkIcon.style.animation = 'checkAppear 0.5s ease-in-out';
                                
                                // Reset background
                                setTimeout(() => {
                                    checkElement.style.background = '';
                                    checkElement.style.borderLeft = '';
                                }, 300);
                                
                                currentCheck++;
                                setTimeout(processNext, 300);
                            }, 1800);
                        }
                    }

                    processNext();
                }, 1000);
            }

            // AJAX handler for installer removal on Step 5
            const removeInstallerBtn = document.getElementById('removeInstallerBtn');
            if (removeInstallerBtn) {
                removeInstallerBtn.addEventListener('click', function() {
                    if (!confirm('Are you sure you want to delete the installer file? This is recommended for security.')) {
                        return;
                    }

                    const statusDiv = document.getElementById('removalStatus');
                    const accessPhynxBtn = document.getElementById('accessPhynxBtn');
                    const removeBtn = this;

                    removeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing...';
                    removeBtn.disabled = true;

                    fetch('install.php?installer_delete=1', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    })
                    .then(response => {
                        if (!response.ok) { throw new Error('Server responded with an error.'); }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            statusDiv.innerHTML = '<div class="success-message" style="padding: 10px;"><i class="fas fa-check-circle"></i> Installer successfully removed.</div>';
                            
                            // Animate the button swap
                            removeBtn.style.opacity = '0';
                            setTimeout(() => {
                                removeBtn.style.display = 'none';
                                accessPhynxBtn.style.display = 'inline-block';
                                accessPhynxBtn.style.opacity = '0';
                                accessPhynxBtn.style.transform = 'translateY(10px)';
                                setTimeout(() => {
                                    accessPhynxBtn.style.opacity = '1';
                                    accessPhynxBtn.style.transform = 'translateY(0)';
                                }, 50);
                            }, 300);

                        } else {
                            statusDiv.innerHTML = `<div class="error-message" style="padding: 10px;"><i class="fas fa-exclamation-triangle"></i> ${data.message}</div>`;
                            removeBtn.innerHTML = '<i class="fas fa-trash"></i> Remove Installer';
                            removeBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        statusDiv.innerHTML = `<div class="error-message" style="padding: 10px;"><i class="fas fa-exclamation-triangle"></i> An unexpected error occurred. Please remove <strong>install.php</strong> manually.</div>`;
                        removeBtn.innerHTML = '<i class="fas fa-trash"></i> Remove Installer';
                        removeBtn.disabled = false;
                    });
                });
            }
        });

        // Enhanced database connection test with animations
        // Helper function to create a delay
        const delay = ms => new Promise(res => setTimeout(res, ms));

        // Helper function to add a line to the console with a typing effect
        const typeLine = async (contentEl, text, className, duration = 1000) => {
            // Create and add the new line with typing and blinking classes
            const line = document.createElement('div');
            line.className = `console-line ${className} typing blinking`;
            line.innerHTML = text;
            line.style.animationDuration = `${duration / 1000}s`; // Adjust animation speed
            contentEl.appendChild(line);
            contentEl.scrollTop = contentEl.scrollHeight;

            // Remove blinking from the previous line to stop its cursor
            const lastLine = contentEl.querySelector('.blinking');
            if (lastLine) {
                lastLine.classList.remove('blinking');
            }

            // Wait for the typing animation to complete before proceeding
            await delay(duration);
        };

        const dbForm = document.getElementById('dbTestForm');
        if (dbForm) {
            dbForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const consoleEl = document.getElementById('consoleOutput');
                const contentEl = document.getElementById('consoleContent');
                const continueBtn = document.getElementById('continueBtn');
                const submitBtn = this.querySelector('button[type="submit"]');
                
                // 1. Show loading state & prepare UI
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
                submitBtn.disabled = true;
                continueBtn.style.display = 'none';
                
                consoleEl.style.display = 'block';
                consoleEl.style.opacity = '0';
                await delay(50);
                consoleEl.style.opacity = '1';
                
                contentEl.innerHTML = ''; // Clear previous results

                // 2. Start the test sequence
                await typeLine(contentEl, 'Starting connection test...', 'console-info', 1200);

                // 3. Perform the fetch request
                const formData = new FormData(this);
                formData.append('test_connection', '1');

                try {
                    const response = await fetch('install.php?step=3', { method: 'POST', body: formData });
                    if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
                    const data = await response.json();

                    await typeLine(contentEl, `Connecting to ${data.host}:${data.port}...`, 'console-info', 1500);

                    // 4. Process the result
                    if (data.success) {
                        await typeLine(contentEl, '✓ Connection successful!', 'console-success', 1000);
                        await typeLine(contentEl, '✓ Database server accessible!', 'console-success', 800);

                        // Animate in the continue button
                        continueBtn.style.display = 'inline-block';
                        continueBtn.style.opacity = '0';
                        continueBtn.style.transform = 'translateY(10px)';
                        setTimeout(() => {
                            continueBtn.style.opacity = '1';
                            continueBtn.style.transform = 'translateY(0)';
                        }, 50);
                        continueBtn.onclick = () => window.location.href = 'install.php?step=4';
                    } else {
                        await typeLine(contentEl, `✗ Connection failed: ${data.error}`, 'console-error', 1000);
                        await typeLine(contentEl, 'Please check your database credentials and try again.', 'console-info', 1200);
                    }
                } catch (error) {
                    await typeLine(contentEl, '✗ Connection failed!', 'console-error', 800);
                    await typeLine(contentEl, `✗ Error: ${error.message}`, 'console-error', 1000);
                } finally {
                    // Stop the final cursor from blinking
                    const finalLine = contentEl.querySelector('.blinking');
                    if (finalLine) {
                        // Wait a moment before removing the class so it feels natural
                        setTimeout(() => {
                            finalLine.classList.remove('blinking');
                            finalLine.style.borderRightColor = 'transparent'; // Hide the static cursor
                        }, 800);
                    }

                    // 5. Reset the submit button
                    submitBtn.innerHTML = '<i class="fas fa-bolt"></i> Test Connection';
                    submitBtn.disabled = false;
                }
            });
        }
    </script>
    </body>
</html>