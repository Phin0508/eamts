<?php
session_start();
require_once '../auth/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';

// Get user department
$user_query = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
$user_query->execute([$user_id]);
$user_data = $user_query->fetch(PDO::FETCH_ASSOC);
$user_department = $user_data['department'];

// Fetch user's assets for asset-related tickets
$assets_query = $pdo->prepare("SELECT id, asset_name, asset_code, category FROM assets WHERE assigned_to = ? AND status = 'in_use' ORDER BY asset_name");
$assets_query->execute([$user_id]);
$user_assets = $assets_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch all assets (for admins/managers)
$all_assets_query = $pdo->prepare("SELECT id, asset_name, asset_code, category FROM assets ORDER BY asset_name");
$all_assets_query->execute();
$all_assets = $all_assets_query->fetchAll(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_type = $_POST['ticket_type'];
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;
        
        // Validation
        $errors = [];
        if (empty($subject)) $errors[] = "Subject is required";
        if (empty($description)) $errors[] = "Description is required";
        if (strlen($description) < 10) $errors[] = "Description must be at least 10 characters";
        
        if (empty($errors)) {
            // Generate ticket number
            $year = date('Y');
            $month = date('m');
            
            $ticket_count_query = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?");
            $ticket_count_query->execute([$year, $month]);
            $count = $ticket_count_query->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            
            $ticket_number = sprintf("TKT-%s%s-%05d", $year, $month, $count);
            
            // Insert ticket
            $insert_query = "
                INSERT INTO tickets (
                    ticket_number, ticket_type, subject, description, 
                    priority, status, requester_id, requester_department, 
                    asset_id, created_at
                ) VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, NOW())
            ";
            
            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                $ticket_number,
                $ticket_type,
                $subject,
                $description,
                $priority,
                $user_id,
                $user_department,
                $asset_id
            ]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // Log history
            $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'created', ?, ?, NOW())";
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([$ticket_id, $ticket_number, $user_id]);
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../uploads/tickets/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                        
                        if (in_array($file_ext, $allowed_extensions) && $file_size <= 5242880) { // 5MB limit
                            $new_file_name = $ticket_id . '_' . time() . '_' . $file_name;
                            $file_path = $upload_dir . $new_file_name;
                            
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $attach_query = "INSERT INTO ticket_attachments (ticket_id, uploaded_by, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?, ?)";
                                $attach_stmt = $pdo->prepare($attach_query);
                                $attach_stmt->execute([$ticket_id, $user_id, $file_name, $file_path, $file_type, $file_size]);
                            }
                        }
                    }
                }
            }
            
            header("Location: ticketDetails.php?id=$ticket_id&created=1");
            exit();
        } else {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        $error_message = "Error creating ticket: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ticket - E-Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc;
            color: #2d3748;
            min-height: 100vh;
        }

        /* Main Container */
        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-content h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-content h1 i {
            color: #7c3aed;
        }

        .header-content p {
            color: #718096;
            font-size: 15px;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }

        .btn-outline {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 900px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .form-group label .required {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }

        /* File Upload Area */
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafbfc;
        }

        .file-upload-area:hover {
            border-color: #7c3aed;
            background: #f7f4fe;
        }

        .file-upload-area i {
            font-size: 48px;
            color: #a0aec0;
            margin-bottom: 16px;
            display: block;
        }

        .file-upload-area p {
            font-size: 15px;
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .file-upload-area small {
            color: #718096;
            font-size: 13px;
        }

        .file-list {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        .file-item i {
            margin-right: 8px;
            color: #7c3aed;
        }

        .file-item button {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .file-item button:hover {
            color: #dc2626;
            transform: scale(1.1);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert i {
            font-size: 20px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .header {
                padding: 20px;
            }

            .header-content h1 {
                font-size: 22px;
            }

            .form-card {
                padding: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-plus-circle"></i> Create New Ticket</h1>
                <p>Submit a support request or report an issue</p>
            </div>
            <a href="ticket.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Tickets
            </a>
        </div>

        <div class="form-card">
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message; ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" id="ticketForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="ticket_type">Ticket Type <span class="required">*</span></label>
                        <select id="ticket_type" name="ticket_type" required>
                            <option value="">Select Type</option>
                            <option value="repair">Repair</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="request_item">Request Item</option>
                            <option value="request_replacement">Request Replacement</option>
                            <option value="inquiry">Inquiry</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="help-text">Select the type of request</div>
                    </div>

                    <div class="form-group">
                        <label for="priority">Priority <span class="required">*</span></label>
                        <select id="priority" name="priority" required>
                            <option value="low">Low - Can wait</option>
                            <option value="medium" selected>Medium - Normal priority</option>
                            <option value="high">High - Important</option>
                            <option value="urgent">Urgent - Critical</option>
                        </select>
                        <div class="help-text">How urgent is this issue?</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="asset_id">Related Asset (Optional)</label>
                    <select id="asset_id" name="asset_id">
                        <option value="">No asset selected</option>
                        <?php if (!empty($user_assets)): ?>
                        <optgroup label="My Assets">
                            <?php foreach ($user_assets as $asset): ?>
                            <option value="<?php echo $asset['id']; ?>">
                                <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if ($user_role !== 'employee' && !empty($all_assets)): ?>
                        <optgroup label="All Assets">
                            <?php foreach ($all_assets as $asset): ?>
                            <option value="<?php echo $asset['id']; ?>">
                                <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <div class="help-text">Link this ticket to a specific asset if applicable</div>
                </div>

                <div class="form-group">
                    <label for="subject">Subject <span class="required">*</span></label>
                    <input type="text" id="subject" name="subject" placeholder="Brief summary of your request" required maxlength="255">
                    <div class="help-text">A clear, concise subject line</div>
                </div>

                <div class="form-group">
                    <label for="description">Description <span class="required">*</span></label>
                    <textarea id="description" name="description" placeholder="Please provide detailed information about your request or issue. Include any relevant details, error messages, or steps to reproduce the problem..." required></textarea>
                    <div class="help-text">Minimum 10 characters. Be as detailed as possible.</div>
                </div>

                <div class="form-group">
                    <label>Attachments (Optional)</label>
                    <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click to upload files or drag and drop</p>
                        <small>Supported: JPG, PNG, PDF, DOC, DOCX, XLS, XLSX (Max 5MB each)</small>
                    </div>
                    <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" style="display: none;">
                    <div class="file-list" id="fileList"></div>
                    <div class="help-text">Upload screenshots, documents, or other relevant files</div>
                </div>

                <div class="form-actions">
                    <a href="ticket.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContainer = document.getElementById('mainContainer');
            const toggleBtn = document.querySelector('.sidebar-toggle');
            
            if (toggleBtn && sidebar && mainContainer) {
                toggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContainer.classList.toggle('sidebar-collapsed');
                });
            }

            // Mobile menu toggle
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                });
            }
        });

        // File upload handling
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const uploadArea = document.querySelector('.file-upload-area');

        fileInput.addEventListener('change', function() {
            displayFiles(this.files);
        });

        // Drag and drop
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = '#7c3aed';
            this.style.background = '#f7f4fe';
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#fafbfc';
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#fafbfc';
            
            const dt = new DataTransfer();
            const files = e.dataTransfer.files;
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileExt = file.name.split('.').pop().toLowerCase();
                const allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                
                if (allowedExts.includes(fileExt) && file.size <= 5242880) {
                    dt.items.add(file);
                }
            }
            
            fileInput.files = dt.files;
            displayFiles(dt.files);
        });

        function displayFiles(files) {
            fileList.innerHTML = '';
            
            if (files.length === 0) return;
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span><i class="fas fa-file"></i> ${file.name} (${fileSize} MB)</span>
                    <button type="button" onclick="removeFile(${i})" title="Remove file">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                fileList.appendChild(fileItem);
            }
        }

        function removeFile(index) {
            const dt = new DataTransfer();
            const files = fileInput.files;
            
            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }
            
            fileInput.files = dt.files;
            displayFiles(dt.files);
        }

        // Form validation
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            const description = document.getElementById('description').value.trim();
            const subject = document.getElementById('subject').value.trim();
            
            if (subject.length === 0) {
                e.preventDefault();
                alert('Please enter a subject');
                return false;
            }
            
            if (description.length < 10) {
                e.preventDefault();
                alert('Description must be at least 10 characters long');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>