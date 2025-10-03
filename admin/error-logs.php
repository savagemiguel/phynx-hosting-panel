<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';
$timeframe = $_GET['timeframe'] ?? '24h';
$severity = $_GET['severity'] ?? 'all';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle error log actions
if ($_POST) {
    if (isset($_POST['acknowledge_error'])) {
        $errorPattern = $_POST['error_pattern'];
        // Mark error pattern as acknowledged (could store in database)
        $message = '<div class="alert alert-success">Error pattern acknowledged.</div>';
    }
}

// Get error log sources
function getErrorLogSources() {
    return [
        'apache_error' => [
            'name' => 'Apache Error Log',
            'path' => '/var/log/apache2/error.log',
            'pattern' => '/^\[(.*?)\] \[(.*?)\] (.*?)$/',
            'icon' => 'fas fa-globe'
        ],
        'php_error' => [
            'name' => 'PHP Error Log',
            'path' => '/var/log/php_errors.log',
            'pattern' => '/^\[(.*?)\] PHP (.*?): (.*?) in (.*?) on line (\d+)$/',
            'icon' => 'fab fa-php'
        ],
        'mysql_error' => [
            'name' => 'MySQL Error Log',
            'path' => '/var/log/mysql/error.log',
            'pattern' => '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z) \d+ \[(.*?)\] (.*?)$/',
            'icon' => 'fas fa-database'
        ],
        'system_error' => [
            'name' => 'System Errors',
            'path' => '/var/log/syslog',
            'pattern' => '/^(\w{3} \d{1,2} \d{2}:\d{2}:\d{2}) (\w+) (.*?): (.*?)$/',
            'icon' => 'fas fa-server'
        ]
    ];
}

// Analyze errors from logs
function analyzeErrors($timeframe = '24h', $severity = 'all') {
    $sources = getErrorLogSources();
    $errors = [];
    $patterns = [];
    $summary = [
        'total_errors' => 0,
        'critical_errors' => 0,
        'warning_errors' => 0,
        'sources' => []
    ];
    
    // Convert timeframe to seconds
    $timeMap = [
        '1h' => 3600,
        '6h' => 21600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000
    ];
    $timeSeconds = $timeMap[$timeframe] ?? 86400;
    $cutoffTime = time() - $timeSeconds;
    
    foreach ($sources as $sourceKey => $source) {
        if (!file_exists($source['path']) || !is_readable($source['path'])) {
            continue;
        }
        
        // Read recent entries
        exec("tail -n 1000 " . escapeshellarg($source['path']), $lines);
        
        $sourceErrors = [];
        $sourcePatterns = [];
        
        foreach ($lines as $line) {
            $errorInfo = parseErrorLine($line, $source, $cutoffTime);
            
            if ($errorInfo && ($severity === 'all' || $errorInfo['severity'] === $severity)) {
                $errors[] = $errorInfo;
                $sourceErrors[] = $errorInfo;
                
                // Track patterns
                $pattern = $errorInfo['pattern'];
                if (!isset($patterns[$pattern])) {
                    $patterns[$pattern] = [
                        'pattern' => $pattern,
                        'count' => 0,
                        'first_seen' => $errorInfo['timestamp'],
                        'last_seen' => $errorInfo['timestamp'],
                        'severity' => $errorInfo['severity'],
                        'source' => $sourceKey,
                        'examples' => []
                    ];
                }
                
                $patterns[$pattern]['count']++;
                $patterns[$pattern]['last_seen'] = max($patterns[$pattern]['last_seen'], $errorInfo['timestamp']);
                
                if (count($patterns[$pattern]['examples']) < 3) {
                    $patterns[$pattern]['examples'][] = $errorInfo;
                }
                
                // Update summary
                $summary['total_errors']++;
                if ($errorInfo['severity'] === 'critical' || $errorInfo['severity'] === 'error') {
                    $summary['critical_errors']++;
                } elseif ($errorInfo['severity'] === 'warning') {
                    $summary['warning_errors']++;
                }
            }
        }
        
        $summary['sources'][$sourceKey] = [
            'name' => $source['name'],
            'error_count' => count($sourceErrors),
            'accessible' => true
        ];
    }
    
    // Sort patterns by frequency
    uasort($patterns, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    
    // Sort errors by timestamp (most recent first)
    usort($errors, function($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });
    
    return [
        'errors' => array_slice($errors, 0, 100), // Limit to recent 100
        'patterns' => array_slice($patterns, 0, 20), // Top 20 patterns
        'summary' => $summary
    ];
}

// Parse error line based on source format
function parseErrorLine($line, $source, $cutoffTime) {
    if (empty(trim($line))) return null;
    
    $timestamp = time(); // Default to current time
    $severity = 'unknown';
    $message = $line;
    $pattern = '';
    
    switch ($source['name']) {
        case 'Apache Error Log':
            if (preg_match('/^\[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                $severity = determineSeverity($matches[2], $matches[3]);
                $message = $matches[3];
                $pattern = preg_replace('/\b\d+\b/', 'XXX', $matches[3]); // Normalize numbers
                $pattern = preg_replace('/\b[0-9a-f]{8,}\b/i', 'HASH', $pattern); // Normalize hashes
            }
            break;
            
        case 'PHP Error Log':
            if (preg_match('/^\[(.*?)\] PHP (.*?): (.*?) in (.*?) on line (\d+)$/', $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                $severity = strtolower($matches[2]) === 'fatal error' ? 'critical' : 'error';
                $message = $matches[3] . ' in ' . basename($matches[4]) . ':' . $matches[5];
                $pattern = $matches[3] . ' in [FILE]:[LINE]';
            }
            break;
            
        case 'MySQL Error Log':
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                $severity = determineSeverityFromContent($line);
                $message = $line;
                $pattern = preg_replace('/\b\d+\b/', 'XXX', $line);
            }
            break;
            
        default:
            // Generic parsing
            $severity = determineSeverityFromContent($line);
            $pattern = preg_replace('/\b\d+\b/', 'XXX', $line);
            break;
    }
    
    // Skip if outside timeframe
    if ($timestamp < $cutoffTime) {
        return null;
    }
    
    return [
        'timestamp' => $timestamp,
        'severity' => $severity,
        'message' => $message,
        'pattern' => substr($pattern, 0, 100), // Limit pattern length
        'source' => $source['name'],
        'raw_line' => $line
    ];
}

// Determine error severity
function determineSeverity($level, $message) {
    $level = strtolower($level);
    $message = strtolower($message);
    
    if (in_array($level, ['emerg', 'alert', 'crit', 'error']) || 
        strpos($message, 'fatal') !== false || 
        strpos($message, 'critical') !== false) {
        return 'critical';
    } elseif (in_array($level, ['warn', 'warning']) || strpos($message, 'warning') !== false) {
        return 'warning';
    } elseif (in_array($level, ['info', 'notice'])) {
        return 'info';
    } else {
        return 'error';
    }
}

function determineSeverityFromContent($content) {
    $content = strtolower($content);
    
    if (preg_match('/\b(fatal|critical|emergency|panic)\b/', $content)) {
        return 'critical';
    } elseif (preg_match('/\b(error|fail|exception)\b/', $content)) {
        return 'error';
    } elseif (preg_match('/\b(warn|warning|deprecated)\b/', $content)) {
        return 'warning';
    } elseif (preg_match('/\b(info|notice|debug)\b/', $content)) {
        return 'info';
    } else {
        return 'unknown';
    }
}

$analysis = analyzeErrors($timeframe, $severity);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Log Analysis - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
<?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-exclamation-triangle"></i> Error Log Analysis</h1>
        
        <?= $message ?>
        
        <!-- Error Summary -->
        <div class="card">
            <h3>Error Summary (<?= ucfirst($timeframe) ?>)</h3>
            <div class="error-summary">
                <div class="summary-stat critical">
                    <div class="stat-icon"><i class="fas fa-fire"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $analysis['summary']['critical_errors'] ?></div>
                        <div class="stat-label">Critical Errors</div>
                    </div>
                </div>
                <div class="summary-stat warning">
                    <div class="stat-icon"><i class="fas fa-exclamation"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $analysis['summary']['warning_errors'] ?></div>
                        <div class="stat-label">Warnings</div>
                    </div>
                </div>
                <div class="summary-stat total">
                    <div class="stat-icon"><i class="fas fa-list"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $analysis['summary']['total_errors'] ?></div>
                        <div class="stat-label">Total Errors</div>
                    </div>
                </div>
                <div class="summary-stat sources">
                    <div class="stat-icon"><i class="fas fa-database"></i></div>
                    <div class="stat-info">
                        <div class="stat-number"><?= count($analysis['summary']['sources']) ?></div>
                        <div class="stat-label">Log Sources</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card">
            <h3>Analysis Filters</h3>
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Time Period:</label>
                    <select name="timeframe" class="form-control">
                        <option value="1h" <?= $timeframe === '1h' ? 'selected' : '' ?>>Last Hour</option>
                        <option value="6h" <?= $timeframe === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                        <option value="24h" <?= $timeframe === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="7d" <?= $timeframe === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="30d" <?= $timeframe === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Severity:</label>
                    <select name="severity" class="form-control">
                        <option value="all" <?= $severity === 'all' ? 'selected' : '' ?>>All Severities</option>
                        <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Critical Only</option>
                        <option value="error" <?= $severity === 'error' ? 'selected' : '' ?>>Errors Only</option>
                        <option value="warning" <?= $severity === 'warning' ? 'selected' : '' ?>>Warnings Only</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Analyze
                </button>
                <a href="error-logs.php" class="btn btn-secondary">
                    <i class="fas fa-sync-alt"></i> Refresh
                </a>
            </form>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <!-- Top Error Patterns -->
            <div class="card">
                <h3>Most Common Error Patterns</h3>
                <?php if (empty($analysis['patterns'])): ?>
                    <div class="alert alert-info">No error patterns found in the selected timeframe.</div>
                <?php else: ?>
                    <div class="pattern-list">
                        <?php foreach (array_slice($analysis['patterns'], 0, 10) as $pattern): ?>
                        <div class="pattern-item">
                            <div class="pattern-header">
                                <span class="severity-badge severity-<?= $pattern['severity'] ?>">
                                    <?= strtoupper($pattern['severity']) ?>
                                </span>
                                <span class="pattern-count"><?= $pattern['count'] ?> times</span>
                            </div>
                            <div class="pattern-message">
                                <?= htmlspecialchars(substr($pattern['pattern'], 0, 100)) ?>
                                <?= strlen($pattern['pattern']) > 100 ? '...' : '' ?>
                            </div>
                            <div class="pattern-meta">
                                Source: <?= htmlspecialchars($pattern['source']) ?> | 
                                First: <?= date('M j, H:i', $pattern['first_seen']) ?> | 
                                Last: <?= date('M j, H:i', $pattern['last_seen']) ?>
                            </div>
                            <div class="pattern-actions">
                                <button onclick="showPattern('<?= htmlspecialchars($pattern['pattern']) ?>')" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View Examples
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Source Breakdown -->
            <div class="card">
                <h3>Error Sources</h3>
                <div class="source-list">
                    <?php foreach ($analysis['summary']['sources'] as $sourceKey => $source): ?>
                    <div class="source-item">
                        <div class="source-info">
                            <div class="source-name"><?= htmlspecialchars($source['name']) ?></div>
                            <div class="source-stats">
                                <?= $source['error_count'] ?> errors
                                <?php if (!$source['accessible']): ?>
                                    <span class="text-error">(Not accessible)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="source-chart">
                            <div class="error-bar" style="width: <?= $analysis['summary']['total_errors'] > 0 ? ($source['error_count'] / $analysis['summary']['total_errors']) * 100 : 0 ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Recent Errors -->
        <div class="card">
            <h3>Recent Error Details</h3>
            <?php if (empty($analysis['errors'])): ?>
                <div class="alert alert-info">No errors found in the selected timeframe and severity level.</div>
            <?php else: ?>
                <div class="error-list">
                    <?php foreach (array_slice($analysis['errors'], 0, 50) as $error): ?>
                    <div class="error-item severity-<?= $error['severity'] ?>">
                        <div class="error-header">
                            <span class="severity-badge severity-<?= $error['severity'] ?>">
                                <?= strtoupper($error['severity']) ?>
                            </span>
                            <span class="error-time"><?= date('M j, Y H:i:s', $error['timestamp']) ?></span>
                            <span class="error-source"><?= htmlspecialchars($error['source']) ?></span>
                        </div>
                        <div class="error-message">
                            <?= htmlspecialchars($error['message']) ?>
                        </div>
                        <div class="error-actions">
                            <button onclick="showRawLog('<?= htmlspecialchars($error['raw_line']) ?>')" class="btn btn-sm btn-secondary">
                                <i class="fas fa-code"></i> Raw Log
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (count($analysis['errors']) >= 50): ?>
                <div class="load-more">
                    <p>Showing first 50 errors. Use filters to narrow results or view log files directly.</p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pattern Details Modal -->
    <div id="patternModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('patternModal')">&times;</span>
            <h3>Error Pattern Examples</h3>
            <div id="patternContent"></div>
        </div>
    </div>

    <!-- Raw Log Modal -->
    <div id="rawLogModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal('rawLogModal')">&times;</span>
            <h3>Raw Log Entry</h3>
            <pre id="rawLogContent"></pre>
        </div>
    </div>

    <style>

    </style>

    <script>
        function showPattern(pattern) {
            // Find pattern data
            const patterns = <?= json_encode($analysis['patterns']) ?>;
            const patternData = patterns.find(p => p.pattern === pattern);
            
            if (patternData) {
                let content = `<div class="pattern-details">`;
                content += `<p><strong>Pattern:</strong> ${pattern}</p>`;
                content += `<p><strong>Occurrences:</strong> ${patternData.count}</p>`;
                content += `<p><strong>Source:</strong> ${patternData.source}</p>`;
                content += `<h4>Recent Examples:</h4>`;
                
                patternData.examples.forEach((example, index) => {
                    content += `<div class="example-item">`;
                    content += `<div class="example-time">${new Date(example.timestamp * 1000).toLocaleString()}</div>`;
                    content += `<div class="example-message">${example.message}</div>`;
                    content += `</div>`;
                });
                
                content += `</div>`;
                
                document.getElementById('patternContent').innerHTML = content;
                document.getElementById('patternModal').style.display = 'block';
            }
        }
        
        function showRawLog(rawLine) {
            document.getElementById('rawLogContent').textContent = rawLine;
            document.getElementById('rawLogModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const patternModal = document.getElementById('patternModal');
            const rawLogModal = document.getElementById('rawLogModal');
            
            if (event.target == patternModal) {
                patternModal.style.display = 'none';
            }
            if (event.target == rawLogModal) {
                rawLogModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>