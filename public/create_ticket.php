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
    <title>Create Ticket - E-Asset System</title>
    
    <!-- Include navigation styles -->
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Dashboard Content Styles */
        .dashboard-content {
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 60px);
        }

        .form-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-left h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .header-left p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Buttons */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2d3748;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #e53e3e;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover {
            border-color: #667eea;
            background: #f7fafc;
        }
        
        .file-upload-area i {
            font-size: 48px;
            color: #a0aec0;
            margin-bottom: 10px;
        }
        
        .file-list {
            margin-top: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #f7fafc;
            border-radius: 6px;
            font-size: 13px;
        }
        
        .file-item button {
            background: none;
            border: none;
            color: #e53e3e;
            cursor: pointer;
            font-size: 16px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #feb2b2;
        }
        
        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navigation Bar -->
    <?php include("../auth/inc/navbar.php"); ?>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Create New Ticket</h1>
                    <p>Submit a support request or report an issue</p>
                </div>
                <div class="header-right">
                    <a href="tickets.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                </div>
            </header>

            <div class="form-container">
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <div class="form-card">
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
                            </div>

                            <div class="form-group">
                                <label for="priority">Priority <span class="required">*</span></label>
                                <select id="priority" name="priority" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="asset_id">Related Asset (Optional)</label>
                            <select id="asset_id" name="asset_id">
                                <option value="">Select Asset (Optional)</option>
                                <optgroup label="My Assets">
                                    <?php foreach ($user_assets as $asset): ?>
                                    <option value="<?php echo $asset['id']; ?>">
                                        <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if ($user_role !== 'employee'): ?>
                                <optgroup label="All Assets">
                                    <?php foreach ($all_assets as $asset): ?>
                                    <option value="<?php echo $asset['id']; ?>">
                                        <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <div class="help-text">Select an asset if this ticket is related to a specific item</div>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject <span class="required">*</span></label>
                            <input type="text" id="subject" name="subject" placeholder="Brief description of the issue" required maxlength="255">
                        </div>

                        <div class="form-group">
                            <label for="description">Description <span class="required">*</span></label>
                            <textarea id="description" name="description" placeholder="Please provide detailed information about your request or issue..." required></textarea>
                            <div class="help-text">Minimum 10 characters</div>
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
                        </div>

                        <div class="form-actions">
                            <a href="tickets.php" class="btn btn-outline">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
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
            this.style.borderColor = '#667eea';
            this.style.background = '#f7fafc';
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = 'transparent';
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = 'transparent';
            
            const dt = new DataTransfer();
            const files = e.dataTransfer.files;
            
            for (let i = 0; i < files.length; i++) {
                dt.items.add(files[i]);
            }
            
            fileInput.files = dt.files;
            displayFiles(dt.files);
        });

        function displayFiles(files) {
            fileList.innerHTML = '';
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span><i class="fas fa-file"></i> ${file.name} (${fileSize} MB)</span>
                    <button type="button" onclick="removeFile(${i})"><i class="fas fa-times"></i></button>
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
            const description = document.getElementById('description').value;
            
            if (description.length < 10) {
                e.preventDefault();
                alert('Description must be at least 10 characters long');
                return false;
            }
        });
    </script>
</body>
</html>