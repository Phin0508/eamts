<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// SECURITY: Only managers and admins should use this page
if (!in_array($user_role, ['manager', 'admin'])) {
    header("Location: ../users/userCreateTicket.php");
    exit();
}

// Get manager's information
$manager_query = $pdo->prepare("SELECT department, first_name, last_name FROM users WHERE user_id = ?");
$manager_query->execute([$user_id]);
$manager_data = $manager_query->fetch(PDO::FETCH_ASSOC);
$manager_department = $manager_data['department'];
$manager_name = $manager_data['first_name'] . ' ' . $manager_data['last_name'];

// Fetch employees based on role
if ($user_role === 'admin') {
    // Admins can create tickets for anyone
    $employees_query = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, department 
        FROM users 
        WHERE status = 'active' 
        ORDER BY first_name, last_name
    ");
    $employees_query->execute();
} else {
    // Managers can create tickets for employees in their department
    $employees_query = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, department 
        FROM users 
        WHERE department = ? AND status = 'active' AND role = 'employee'
        ORDER BY first_name, last_name
    ");
    $employees_query->execute([$manager_department]);
}
$employees = $employees_query->fetchAll(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

// Handle AJAX request for employee assets
if (isset($_GET['action']) && $_GET['action'] === 'get_assets' && isset($_GET['employee_id'])) {
    header('Content-Type: application/json');
    
    $employee_id = $_GET['employee_id'];
    
    // Verify manager has access to this employee
    if ($user_role === 'manager') {
        $access_check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND department = ?");
        $access_check->execute([$employee_id, $manager_department]);
        if (!$access_check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
    }
    
    $assets_query = $pdo->prepare("
        SELECT id, asset_name, asset_code, category 
        FROM assets 
        WHERE assigned_to = ? AND status = 'in_use' 
        ORDER BY asset_name
    ");
    $assets_query->execute([$employee_id]);
    $assets = $assets_query->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'assets' => $assets]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_type = $_POST['ticket_type'];
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $requester_id = $_POST['requester_id']; // The employee for whom the ticket is created
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;
        $create_on_behalf = isset($_POST['create_on_behalf']) ? true : false;
        
        // If creating on behalf, use selected employee; otherwise use manager as requester
        if (!$create_on_behalf) {
            $requester_id = $user_id;
        }
        
        // SECURITY: Verify manager has access to create ticket for this employee
        if ($user_role === 'manager' && $requester_id != $user_id) {
            $access_check = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
            $access_check->execute([$requester_id]);
            $employee = $access_check->fetch(PDO::FETCH_ASSOC);
            if (!$employee || $employee['department'] !== $manager_department) {
                throw new Exception("You can only create tickets for employees in your department.");
            }
        }
        
        // Get requester's department
        $requester_query = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
        $requester_query->execute([$requester_id]);
        $requester_data = $requester_query->fetch(PDO::FETCH_ASSOC);
        $requester_department = $requester_data['department'];
        
        // SECURITY: Verify asset belongs to requester if asset_id is provided
        if ($asset_id) {
            $asset_check = $pdo->prepare("SELECT id FROM assets WHERE id = ? AND assigned_to = ?");
            $asset_check->execute([$asset_id, $requester_id]);
            if (!$asset_check->fetch()) {
                throw new Exception("Invalid asset selected. Asset must be assigned to the selected employee.");
            }
        }
        
        // Validation
        $errors = [];
        if (empty($subject)) $errors[] = "Subject is required";
        if (empty($description)) $errors[] = "Description is required";
        if (strlen($description) < 10) $errors[] = "Description must be at least 10 characters";
        if (strlen($subject) > 255) $errors[] = "Subject is too long (max 255 characters)";
        if (strlen($description) > 2000) $errors[] = "Description is too long (max 2000 characters)";
        if ($create_on_behalf && empty($requester_id)) $errors[] = "Please select an employee";
        
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
                    priority, status, approval_status, requester_id, 
                    requester_department, asset_id, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'open', 'pending', ?, ?, ?, ?, NOW(), NOW())
            ";
            
            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                $ticket_number,
                $ticket_type,
                $subject,
                $description,
                $priority,
                $requester_id,
                $requester_department,
                $asset_id,
                $user_id // Track who actually created the ticket
            ]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // Log history
            $history_action = $create_on_behalf ? "Ticket created by manager on behalf of employee" : "Ticket created by manager";
            $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'created', ?, ?, NOW())";
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([$ticket_id, "$history_action: $ticket_number", $user_id]);
            
            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../uploads/tickets/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $uploaded_files = 0;
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];
                        
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                        
                        if (in_array($file_ext, $allowed_extensions) && $file_size <= 5242880) { // 5MB limit
                            $new_file_name = $ticket_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $attach_query = "INSERT INTO ticket_attachments (ticket_id, uploaded_by, file_name, file_path, file_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                                $attach_stmt = $pdo->prepare($attach_query);
                                $attach_stmt->execute([
                                    $ticket_id,
                                    $user_id,
                                    $file_name,
                                    $new_file_name,
                                    $file_type,
                                    $file_size
                                ]);
                                $uploaded_files++;
                            }
                        }
                    }
                }
            }
            
            $_SESSION['ticket_created'] = true;
            header("Location: ../tickets/view_ticket.php?id=$ticket_id");
            exit();
        } else {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (Exception $e) {
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

        .info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-banner i {
            font-size: 2rem;
        }

        .info-banner-content h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
        }

        .info-banner-content p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .role-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin-top: 5px;
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
            transition: all 0.3s ease;
        }
        
        .form-group textarea {
            min-height: 150px;
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

        .on-behalf-section {
            background: #f0f3ff;
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .on-behalf-section .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .on-behalf-section .section-header h3 {
            margin: 0;
            color: #667eea;
            font-size: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }

        .employee-select-wrapper {
            display: none;
        }

        .employee-select-wrapper.active {
            display: block;
        }

        .employee-info {
            background: white;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            display: none;
        }

        .employee-info.active {
            display: block;
        }

        .employee-info-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .employee-info-item:last-child {
            border-bottom: none;
        }

        .employee-info-label {
            font-weight: 500;
            color: #4a5568;
        }

        .employee-info-value {
            color: #2d3748;
        }
        
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .file-upload-area:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }
        
        .file-upload-area i {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .file-upload-area p {
            margin: 10px 0 5px 0;
            color: #2d3748;
            font-weight: 500;
        }

        .file-upload-area small {
            color: #718096;
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
            padding: 12px;
            background: #f7fafc;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid #e2e8f0;
        }

        .file-item span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-item i.fa-file {
            color: #667eea;
        }
        
        .file-item button {
            background: none;
            border: none;
            color: #e53e3e;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .file-item button:hover {
            color: #c53030;
            transform: scale(1.1);
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #feb2b2;
        }
        
        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .help-text i {
            font-size: 11px;
        }

        .priority-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 10px;
            font-size: 12px;
        }

        .priority-badge {
            padding: 6px 10px;
            border-radius: 6px;
            text-align: center;
            font-weight: 500;
        }

        .priority-badge.low { background: #e6fffa; color: #047857; }
        .priority-badge.medium { background: #fef3c7; color: #b45309; }
        .priority-badge.high { background: #fee2e2; color: #b91c1c; }
        .priority-badge.urgent { background: #fce7f3; color: #9f1239; }

        .char-counter {
            font-size: 12px;
            color: #718096;
            text-align: right;
            margin-top: 5px;
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

            .priority-info {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar (Manager/Admin) -->
    <?php include("../auth/inc/Msidebar.php"); ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1><i class="fas fa-plus-circle"></i> Create Support Ticket</h1>
                    <p><?php echo $user_role === 'admin' ? 'Create tickets for any employee' : 'Create tickets for your team'; ?></p>
                </div>
                
            </header>

            <div class="form-container">
                <div class="info-banner">
                    <i class="fas fa-user-tie"></i>
                    <div class="info-banner-content">
                        <h3>Welcome, <?php echo htmlspecialchars($manager_name); ?>!</h3>
                        <p>You can create tickets for yourself or on behalf of your team members.</p>
                        <span class="role-badge"><?php echo strtoupper($user_role); ?></span>
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> 
                    <div><?php echo $error_message; ?></div>
                </div>
                <?php endif; ?>

                <div class="form-card">
                    <form method="POST" action="" enctype="multipart/form-data" id="ticketForm">
                        <!-- On Behalf Section -->
                        <div class="on-behalf-section">
                            <div class="section-header">
                                <i class="fas fa-users"></i>
                                <h3>Ticket For</h3>
                            </div>
                            
                            <div class="checkbox-group">
                                <input type="checkbox" id="create_on_behalf" name="create_on_behalf" onchange="toggleEmployeeSelect()">
                                <label for="create_on_behalf">Create ticket on behalf of an employee</label>
                            </div>

                            <div class="employee-select-wrapper" id="employeeSelectWrapper">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="requester_id">
                                        <i class="fas fa-user"></i> Select Employee <span class="required">*</span>
                                    </label>
                                    <select id="requester_id" name="requester_id" onchange="loadEmployeeAssets()">
                                        <option value="">Choose an employee...</option>
                                        <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['user_id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                                data-email="<?php echo htmlspecialchars($emp['email']); ?>"
                                                data-dept="<?php echo htmlspecialchars($emp['department']); ?>">
                                            <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' - ' . $emp['department']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="employee-info" id="employeeInfo">
                                    <div class="employee-info-item">
                                        <span class="employee-info-label">Name:</span>
                                        <span class="employee-info-value" id="empName">-</span>
                                    </div>
                                    <div class="employee-info-item">
                                        <span class="employee-info-label">Email:</span>
                                        <span class="employee-info-value" id="empEmail">-</span>
                                    </div>
                                    <div class="employee-info-item">
                                        <span class="employee-info-label">Department:</span>
                                        <span class="employee-info-value" id="empDept">-</span>
                                    </div>
                                </div>
                            </div>

                            <div class="help-text" style="margin-top: 10px;">
                                <i class="fas fa-info-circle"></i> 
                                <span id="ticketForHelp">This ticket will be created for you</span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="ticket_type">
                                    <i class="fas fa-tag"></i> Request Type <span class="required">*</span>
                                </label>
                                <select id="ticket_type" name="ticket_type" required>
                                    <option value="">Select Type</option>
                                    <option value="repair">🔧 Repair</option>
                                    <option value="maintenance">⚙️ Maintenance</option>
                                    <option value="request_item">📦 Request New Item</option>
                                    <option value="request_replacement">🔄 Request Replacement</option>
                                    <option value="inquiry">❓ Inquiry</option>
                                    <option value="other">📝 Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="priority">
                                    <i class="fas fa-exclamation-triangle"></i> Priority <span class="required">*</span>
                                </label>
                                <select id="priority" name="priority" required>
                                    <option value="low">Low - Not urgent</option>
                                    <option value="medium" selected>Medium - Normal priority</option>
                                    <option value="high">High - Important</option>
                                    <option value="urgent">Urgent - Critical</option>
                                </select>
                                <div class="priority-info">
                                    <div class="priority-badge low">Low</div>
                                    <div class="priority-badge medium">Medium</div>
                                    <div class="priority-badge high">High</div>
                                    <div class="priority-badge urgent">Urgent</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="asset_id">
                                <i class="fas fa-laptop"></i> Related Asset (Optional)
                            </label>
                            <select id="asset_id" name="asset_id">
                                <option value="">No specific asset</option>
                            </select>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i> Select an employee first to see their assets
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="subject">
                                <i class="fas fa-heading"></i> Subject <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="subject" 
                                name="subject" 
                                placeholder="Brief summary of the request" 
                                required 
                                maxlength="255"
                                oninput="updateCharCount('subject', 255)">
                            <div class="char-counter" id="subject-counter">0 / 255 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="description">
                                <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                            </label>
                            <textarea 
                                id="description" 
                                name="description" 
                                placeholder="Detailed description of the request or issue..." 
                                required
                                maxlength="2000"
                                oninput="updateCharCount('description', 2000)"></textarea>
                            <div class="char-counter" id="description-counter">0 / 2000 characters (minimum 10)</div>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-paperclip"></i> Attachments (Optional)
                            </label>
                            <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Click to upload files or drag and drop</p>
                                <small>Supported: JPG, PNG, PDF, DOC, DOCX, XLS, XLSX (Max 5MB each)</small>
                            </div>
                            <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" style="display: none;">
                            <div class="file-list" id="fileList"></div>
                        </div>

                        <div class="form-actions">
                            <a href="../tickets/tickets.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
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
        // Toggle employee select
        function toggleEmployeeSelect() {
            const checkbox = document.getElementById('create_on_behalf');
            const wrapper = document.getElementById('employeeSelectWrapper');
            const helpText = document.getElementById('ticketForHelp');
            const requesterSelect = document.getElementById('requester_id');
            const assetSelect = document.getElementById('asset_id');
            
            if (checkbox.checked) {
                wrapper.classList.add('active');
                helpText.textContent = 'Select an employee to create the ticket on their behalf';
                requesterSelect.required = true;
            } else {
                wrapper.classList.remove('active');
                helpText.textContent = 'This ticket will be created for you';
                requesterSelect.required = false;
                requesterSelect.value = '';
                document.getElementById('employeeInfo').classList.remove('active');
                
                // Clear assets
                assetSelect.innerHTML = '<option value="">No specific asset</option>';
            }
        }

        // Load employee assets when employee is selected
        function loadEmployeeAssets() {
            const select = document.getElementById('requester_id');
            const selectedOption = select.options[select.selectedIndex];
            const employeeId = select.value;
            const assetSelect = document.getElementById('asset_id');
            const employeeInfo = document.getElementById('employeeInfo');
            
            if (employeeId) {
                // Show employee info
                document.getElementById('empName').textContent = selectedOption.dataset.name;
                document.getElementById('empEmail').textContent = selectedOption.dataset.email;
                document.getElementById('empDept').textContent = selectedOption.dataset.dept;
                employeeInfo.classList.add('active');
                
                // Fetch assets
                assetSelect.innerHTML = '<option value="">Loading assets...</option>';
                assetSelect.disabled = true;
                
                fetch(`?action=get_assets&employee_id=${employeeId}`)
                    .then(response => response.json())
                    .then(data => {
                        assetSelect.disabled = false;
                        
                        if (data.success) {
                            assetSelect.innerHTML = '<option value="">No specific asset</option>';
                            
                            if (data.assets.length === 0) {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = 'No assets assigned to this employee';
                                option.disabled = true;
                                assetSelect.appendChild(option);
                            } else {
                                data.assets.forEach(asset => {
                                    const option = document.createElement('option');
                                    option.value = asset.id;
                                    option.textContent = `${asset.asset_code} - ${asset.asset_name} (${asset.category})`;
                                    assetSelect.appendChild(option);
                                });
                            }
                        } else {
                            assetSelect.innerHTML = '<option value="">Error loading assets</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        assetSelect.disabled = false;
                        assetSelect.innerHTML = '<option value="">Error loading assets</option>';
                    });
            } else {
                employeeInfo.classList.remove('active');
                assetSelect.innerHTML = '<option value="">No specific asset</option>';
            }
        }

        // Character counter
        function updateCharCount(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;
            
            if (fieldId === 'description') {
                counter.textContent = `${length} / ${maxLength} characters (minimum 10)`;
                if (length < 10) {
                    counter.style.color = '#e53e3e';
                } else {
                    counter.style.color = '#718096';
                }
            } else {
                counter.textContent = `${length} / ${maxLength} characters`;
            }
            
            if (length > maxLength * 0.9) {
                counter.style.color = '#d69e2e';
            }
            if (length === maxLength) {
                counter.style.color = '#e53e3e';
            }
        }

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
            this.style.background = '#f0f3ff';
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#f8f9fa';
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#f8f9fa';
            
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
            
            if (files.length === 0) return;
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span><i class="fas fa-file"></i> ${file.name} <small>(${fileSize} MB)</small></span>
                    <button type="button" onclick="removeFile(${i})" title="Remove file"><i class="fas fa-times"></i></button>
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
            const subject = document.getElementById('subject').value;
            const createOnBehalf = document.getElementById('create_on_behalf').checked;
            const requesterId = document.getElementById('requester_id').value;
            
            if (description.length < 10) {
                e.preventDefault();
                alert('Description must be at least 10 characters long. Please provide more details.');
                document.getElementById('description').focus();
                return false;
            }
            
            if (subject.trim().length < 5) {
                e.preventDefault();
                alert('Subject is too short. Please provide a brief but descriptive subject.');
                document.getElementById('subject').focus();
                return false;
            }
            
            if (createOnBehalf && !requesterId) {
                e.preventDefault();
                alert('Please select an employee for whom you are creating this ticket.');
                document.getElementById('requester_id').focus();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        // Initialize character counters
        document.addEventListener('DOMContentLoaded', function() {
            updateCharCount('subject', 255);
            updateCharCount('description', 2000);
        });
    </script>
</body>
</html>