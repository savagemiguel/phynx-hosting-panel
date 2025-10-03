<?php
require_once '../config.php';
require_once '../includes/functions.php';
requireAdmin();

$message = '';

if ($_POST && !csrf_verify()) { 
    http_response_code(400); 
    exit('Invalid CSRF token'); 
}

// Handle template actions
if ($_POST) {
    if (isset($_POST['create_template'])) {
        $name = sanitize($_POST['template_name']);
        $description = sanitize($_POST['template_description']);
        $records = json_encode($_POST['records'] ?? []);
        
        $query = "INSERT INTO dns_templates (name, description, records) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sss", $name, $description, $records);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">DNS template created successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error creating template</div>';
        }
    }
    
    if (isset($_POST['apply_template'])) {
        $templateId = (int)$_POST['template_id'];
        $domainIds = $_POST['domain_ids'] ?? [];
        
        // Get template
        $query = "SELECT * FROM dns_templates WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $templateId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $template = mysqli_fetch_assoc($result);
        
        if ($template && !empty($domainIds)) {
            $records = json_decode($template['records'], true);
            $successCount = 0;
            
            foreach ($domainIds as $domainId) {
                $domainId = (int)$domainId;
                
                // Get domain info
                $domainQuery = "SELECT domain_name FROM domains WHERE id = ?";
                $domainStmt = mysqli_prepare($conn, $domainQuery);
                mysqli_stmt_bind_param($domainStmt, "i", $domainId);
                mysqli_stmt_execute($domainStmt);
                $domainResult = mysqli_stmt_get_result($domainStmt);
                $domain = mysqli_fetch_assoc($domainResult);
                
                if ($domain) {
                    // Clear existing records
                    $deleteQuery = "DELETE FROM dns_zones WHERE domain_id = ?";
                    $deleteStmt = mysqli_prepare($conn, $deleteQuery);
                    mysqli_stmt_bind_param($deleteStmt, "i", $domainId);
                    mysqli_stmt_execute($deleteStmt);
                    
                    // Insert template records
                    foreach ($records as $record) {
                        $name = str_replace('{domain}', $domain['domain_name'], $record['name']);
                        $value = str_replace('{domain}', $domain['domain_name'], $record['value']);
                        
                        $insertQuery = "INSERT INTO dns_zones (domain_id, record_type, name, value, ttl, priority) VALUES (?, ?, ?, ?, ?, ?)";
                        $insertStmt = mysqli_prepare($conn, $insertQuery);
                        mysqli_stmt_bind_param($insertStmt, "isssii", $domainId, $record['type'], $name, $value, $record['ttl'], $record['priority']);
                        mysqli_stmt_execute($insertStmt);
                    }
                    
                    // Regenerate zone file
                    $allRecordsQuery = "SELECT * FROM dns_zones WHERE domain_id = ?";
                    $allRecordsStmt = mysqli_prepare($conn, $allRecordsQuery);
                    mysqli_stmt_bind_param($allRecordsStmt, "i", $domainId);
                    mysqli_stmt_execute($allRecordsStmt);
                    $allRecordsResult = mysqli_stmt_get_result($allRecordsStmt);
                    $allRecords = mysqli_fetch_all($allRecordsResult, MYSQLI_ASSOC);
                    
                    createDNSZoneFile($domain['domain_name'], $allRecords);
                    $successCount++;
                }
            }
            
            $message = "<div class=\"alert alert-success\">Template applied to $successCount domain(s)</div>";
        }
    }
    
    if (isset($_POST['delete_template'])) {
        $templateId = (int)$_POST['template_id'];
        $query = "DELETE FROM dns_templates WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $templateId);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = '<div class="alert alert-success">Template deleted successfully</div>';
        } else {
            $message = '<div class="alert alert-error">Error deleting template</div>';
        }
    }
}

// Get all templates
$templates = [];
$result = mysqli_query($conn, "SELECT * FROM dns_templates ORDER BY name");
if ($result) {
    $templates = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Get all domains
$domains = [];
$result = mysqli_query($conn, "SELECT id, domain_name, user_id FROM domains ORDER BY domain_name");
if ($result) {
    $domains = mysqli_fetch_all($result, MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>DNS Zone Templates - Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script defer src="../assets/js/modern-sidebar.js"></script>
</head>
<body>
    <?php require_once '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <h1><i class="fas fa-clipboard-list"></i> DNS Zone Templates</h1>
        
        <?= $message ?>
        
        <!-- Create Template -->
        <div class="card">
            <h3>Create New Template</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Template Name</label>
                        <input type="text" name="template_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="template_description" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>DNS Records</label>
                    <div id="records-container">
                        <div class="record-row" style="display: grid; grid-template-columns: 150px 200px 300px 80px 80px auto; gap: 10px; align-items: end; margin-bottom: 10px;">
                            <div>
                                <label>Type</label>
                                <select name="records[0][type]" class="form-control">
                                    <option value="A">A</option>
                                    <option value="AAAA">AAAA</option>
                                    <option value="CNAME">CNAME</option>
                                    <option value="MX">MX</option>
                                    <option value="TXT">TXT</option>
                                    <option value="NS">NS</option>
                                </select>
                            </div>
                            <div>
                                <label>Name</label>
                                <input type="text" name="records[0][name]" class="form-control" placeholder="@, www, mail, etc.">
                            </div>
                            <div>
                                <label>Value</label>
                                <input type="text" name="records[0][value]" class="form-control" placeholder="IP or target">
                            </div>
                            <div>
                                <label>TTL</label>
                                <input type="number" name="records[0][ttl]" class="form-control" value="3600">
                            </div>
                            <div>
                                <label>Priority</label>
                                <input type="number" name="records[0][priority]" class="form-control" value="0">
                            </div>
                            <div>
                                <button type="button" onclick="removeRecord(this)" class="btn btn-danger">Remove</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="addRecord()" class="btn btn-secondary">Add Record</button>
                </div>
                
                <button type="submit" name="create_template" class="btn btn-primary">Create Template</button>
            </form>
        </div>
        
        <!-- Existing Templates -->
        <div class="card">
            <h3>Existing Templates</h3>
            <?php if (!empty($templates)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Records</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): ?>
                            <?php $records = json_decode($template['records'], true) ?: []; ?>
                            <tr>
                                <td><?= htmlspecialchars($template['name']) ?></td>
                                <td><?= htmlspecialchars($template['description']) ?></td>
                                <td><?= count($records) ?> record(s)</td>
                                <td>
                                    <button onclick="showApplyModal(<?= $template['id'] ?>, '<?= htmlspecialchars($template['name']) ?>')" class="btn btn-success">Apply</button>
                                    <button onclick="showRecords(<?= htmlspecialchars(json_encode($records)) ?>)" class="btn btn-secondary">View</button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this template?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                        <button type="submit" name="delete_template" class="btn btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No templates found. Create your first template above.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Apply Template Modal -->
    <div id="applyModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: var(--bg-secondary); padding: 30px; border-radius: 8px; max-width: 600px; width: 90%;">
            <h3 id="modalTitle">Apply Template</h3>
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" id="modalTemplateId" name="template_id">
                
                <div class="form-group">
                    <label>Select Domains</label>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 4px; padding: 10px;">
                        <?php foreach ($domains as $domain): ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="domain_ids[]" value="<?= $domain['id'] ?>">
                                <?= htmlspecialchars($domain['domain_name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="submit" name="apply_template" class="btn btn-primary">Apply Template</button>
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let recordIndex = 1;
        
        function addRecord() {
            const container = document.getElementById('records-container');
            const recordRow = document.createElement('div');
            recordRow.className = 'record-row';
            recordRow.style.cssText = 'display: grid; grid-template-columns: 150px 200px 300px 80px 80px auto; gap: 10px; align-items: end; margin-bottom: 10px;';
            
            recordRow.innerHTML = `
                <div>
                    <select name="records[${recordIndex}][type]" class="form-control">
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                        <option value="MX">MX</option>
                        <option value="TXT">TXT</option>
                        <option value="NS">NS</option>
                    </select>
                </div>
                <div>
                    <input type="text" name="records[${recordIndex}][name]" class="form-control" placeholder="@, www, mail, etc.">
                </div>
                <div>
                    <input type="text" name="records[${recordIndex}][value]" class="form-control" placeholder="IP or target">
                </div>
                <div>
                    <input type="number" name="records[${recordIndex}][ttl]" class="form-control" value="3600">
                </div>
                <div>
                    <input type="number" name="records[${recordIndex}][priority]" class="form-control" value="0">
                </div>
                <div>
                    <button type="button" onclick="removeRecord(this)" class="btn btn-danger">Remove</button>
                </div>
            `;
            
            container.appendChild(recordRow);
            recordIndex++;
        }
        
        function removeRecord(button) {
            button.parentElement.parentElement.remove();
        }
        
        function showApplyModal(templateId, templateName) {
            document.getElementById('modalTemplateId').value = templateId;
            document.getElementById('modalTitle').textContent = 'Apply Template: ' + templateName;
            document.getElementById('applyModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('applyModal').style.display = 'none';
        }
        
        function showRecords(records) {
            let content = 'Records in this template:\n\n';
            records.forEach(record => {
                content += `${record.type} ${record.name} -> ${record.value} (TTL: ${record.ttl})\n`;
            });
            alert(content);
        }
        
        // Close modal when clicking outside
        document.getElementById('applyModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>