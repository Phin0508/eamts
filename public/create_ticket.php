<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only managers and admins can access this page
if ($user_role === 'employee') {
    header("Location: ../users/userCreateTicket.php");
    exit();
}

$success_message = '';
$error_message = '';

// Get user info
$user_query = $pdo->prepare("SELECT first_name, last_name, email, department FROM users WHERE user_id = ?");
$user_query->execute([$user_id]);
$user_data = $user_query->fetch(PDO::FETCH_ASSOC);
$user_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
$user_email = $user_data['email'];
$user_department = $user_data['department'];

// Fetch all users for assignment (if admin wants to create ticket on behalf of someone)
$users_query = $pdo->query("SELECT user_id, first_name, last_name, email, department FROM users WHERE status = 'active' ORDER BY first_name");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch all assets
$assets_query = $pdo->query("SELECT id, asset_name, asset_code, category, assigned_to FROM assets WHERE status = 'in_use' ORDER BY asset_name");
$all_assets = $assets_query->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_type = $_POST['ticket_type'];
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $requester_id = !empty($_POST['requester_id']) ? $_POST['requester_id'] : $user_id;
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;
        
        // Get requester department
        $requester_query = $pdo->prepare("SELECT department FROM users WHERE user_id = ?");
        $requester_query->execute([$requester_id]);
        $requester_dept = $requester_query->fetch(PDO::FETCH_ASSOC)['department'];
        
        // Validation
        $errors = [];
        if (empty($subject)) $errors[] = "Subject is required";
        if (empty($description)) $errors[] = "Description is required";
        if (strlen($description) < 20) $errors[] = "Description must be at least 20 characters";
        if (strlen($subject) > 255) $errors[] = "Subject is too long (max 255 characters)";
        if (strlen($description) > 2000) $errors[] = "Description is too long (max 2000 characters)";
        
        if (empty($errors)) {
            // Generate ticket number
            $year = date('Y');
            $month = date('m');
            
            $ticket_count_query = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?");
            $ticket_count_query->execute([$year, $month]);
            $count = $ticket_count_query->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            
            $ticket_number = sprintf("TKT-%s%s-%05d", $year, $month, $count);
            
            // Admins can create pre-approved tickets
            $approval_status = ($user_role === 'admin') ? 'approved' : 'pending';
            
            // Insert ticket
            $insert_query = "
                INSERT INTO tickets (
                    ticket_number, ticket_type, subject, description, 
                    priority, status, approval_status, requester_id, 
                    requester_department, asset_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, NOW(), NOW())
            ";
            
            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                $ticket_number,
                $ticket_type,
                $subject,
                $description,
                $priority,
                $approval_status,
                $requester_id,
                $requester_dept,
                $asset_id
            ]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // Log history
            $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'created', ?, ?, NOW())";
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([
                $ticket_id, 
                "Ticket created: $ticket_number" . ($requester_id != $user_id ? " (on behalf of user)" : ""), 
                $user_id
            ]);
            
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
            
            $success_message = "Ticket created successfully! Ticket Number: " . $ticket_number;
            $_POST = array(); // Clear form
        } else {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Support Ticket - E-Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
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
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            color: #7c3aed;
        }

        .header-left p {
            color: #718096;
            font-size: 15px;
        }

        .header-right .btn {
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .header-right .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        /* Messages */
        .success-message, .error-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
            font-weight: 500;
        }

        .success-message {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Info Banner */
        .info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .info-banner i {
            font-size: 32px;
            opacity: 0.9;
        }

        .info-banner-content h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            font-weight: 600;
        }

        .info-banner-content p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }

        /* Form Section */
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label i {
            margin-right: 6px;
            color: #7c3aed;
        }

        .required {
            color: #ef4444;
            margin-left: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 150px;
        }

        .form-group input:read-only {
            background: #f7fafc;
            cursor: not-allowed;
            color: #718096;
        }

        .form-help {
            font-size: 13px;
            color: #718096;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-help i {
            color: #7c3aed;
        }

        .char-counter {
            font-size: 12px;
            color: #718096;
            text-align: right;
            margin-top: 5px;
        }

        /* Priority Options */
        .priority-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .priority-option {
            position: relative;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .priority-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            text-align: center;
        }

        .priority-option input[type="radio"]:checked + .priority-label {
            border-color: #7c3aed;
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
        }

        .priority-label:hover {
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .priority-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .priority-text {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .priority-low .priority-icon { color: #10b981; }
        .priority-medium .priority-icon { color: #f59e0b; }
        .priority-high .priority-icon { color: #ef4444; }
        .priority-urgent .priority-icon { color: #dc2626; }

        /* File Upload */
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .file-upload-area:hover {
            border-color: #7c3aed;
            background: #f7f4fe;
        }

        .file-upload-area i {
            font-size: 48px;
            color: #7c3aed;
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
            border-radius: 8px;
            font-size: 13px;
            border: 1px solid #e2e8f0;
        }

        .file-item span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-item i.fa-file {
            color: #7c3aed;
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

        /* Admin Badge */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Submit Button */
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        .btn-submit {
            padding: 14px 32px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-reset {
            padding: 14px 32px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: white;
            color: #718096;
        }

        .btn-reset:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
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

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-left h1 {
                font-size: 22px;
            }

            .form-section {
                padding: 24px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .priority-options {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-submit,
            .btn-reset {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-ticket-alt"></i> Create Support Ticket
                        <?php if ($user_role === 'admin'): ?>
                        <span class="admin-badge"><i class="fas fa-crown"></i> Admin</span>
                        <?php endif; ?>
                    </h1>
                    <p>Submit a new support request or report an issue</p>
                </div>
                <div class="header-right">
                    <a href="ticket.php" class="btn">
                        <i class="fas fa-list"></i> View All Tickets
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error_message; ?></span>
        </div>
        <?php endif; ?>

        <div class="info-banner">
            <i class="fas fa-user-shield"></i>
            <div class="info-banner-content">
                <h3>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h3>
                <p><?php echo $user_role === 'admin' ? 'As an admin, you can create tickets for yourself or on behalf of other users. Your tickets are automatically approved.' : 'As a manager, you can create tickets that will be reviewed by administrators.'; ?></p>
            </div>
        </div>

        <div class="form-section">
            <form method="POST" enctype="multipart/form-data" id="ticketForm">
                <div class="form-grid">
                    <?php if ($user_role === 'admin'): ?>
                    <div class="form-group">
                        <label for="requester_id">
                            <i class="fas fa-user"></i> Create For <span class="required">*</span>
                        </label>
                        <select id="requester_id" name="requester_id">
                            <option value="<?php echo $user_id; ?>">Myself (<?php echo htmlspecialchars($user_name); ?>)</option>
                            <optgroup label="Other Users">
                                <?php foreach ($all_users as $user): ?>
                                    <?php if ($user['user_id'] != $user_id): ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' - ' . $user['department']); ?>
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <div class="form-help">
                            <i class="fas fa-info-circle"></i> 
                            Select yourself or create a ticket on behalf of another user
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label for="user_name">
                            <i class="fas fa-user"></i> Your Name <span class="required">*</span>
                        </label>
                        <input type="text" id="user_name" name="user_name" 
                               value="<?php echo htmlspecialchars($user_name); ?>" readonly>
                    </div>
                    <?php endif; ?>

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
                        <div class="form-help">
                            <i class="fas fa-lightbulb"></i> Choose the type that best describes your request
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="asset_id">
                        <i class="fas fa-laptop"></i> Related Asset (Optional)
                    </label>
                    <select id="asset_id" name="asset_id">
                        <option value="">No specific asset</option>
                        <?php foreach ($all_assets as $asset): ?>
                        <option value="<?php echo $asset['id']; ?>">
                            <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i> 
                        Select an asset if this request is related to a specific item
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
                        placeholder="Brief description of your issue (e.g., 'Laptop keyboard not working')" 
                        required 
                        maxlength="255"
                        oninput="updateCharCount('subject', 255)"
                        value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>">
                    <div class="char-counter" id="subject-counter">0 / 255 characters</div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-exclamation-triangle"></i> Priority Level <span class="required">*</span>
                    </label>
                    <div class="priority-options">
                        <div class="priority-option">
                            <input type="radio" id="priority-low" name="priority" value="low">
                            <label for="priority-low" class="priority-label priority-low">
                                <div class="priority-icon">🟢</div>
                                <div class="priority-text">Low</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-medium" name="priority" value="medium" checked>
                            <label for="priority-medium" class="priority-label priority-medium">
                                <div class="priority-icon">🟡</div>
                                <div class="priority-text">Medium</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-high" name="priority" value="high">
                            <label for="priority-high" class="priority-label priority-high">
                                <div class="priority-icon">🔴</div>
                                <div class="priority-text">High</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-urgent" name="priority" value="urgent">
                            <label for="priority-urgent" class="priority-label priority-urgent">
                                <div class="priority-icon">🔥</div>
                                <div class="priority-text">Urgent</div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="description">
                        <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                    </label>
                    <textarea 
                        id="description" 
                        name="description" 
                        placeholder="Please provide detailed information:&#10;- What is the problem or request?&#10;- When did it start?&#10;- What have you tried?&#10;- Any error messages or additional context?" 
                        required
                        minlength="20"
                        maxlength="2000"
                        oninput="updateCharCount('description', 2000)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <div class="char-counter" id="description-counter">0 / 2000 characters (minimum 20)</div>
                </div>

                <div class="form-group full-width">
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
                    <button type="reset" class="btn-reset">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Handle sidebar toggle
        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        // Check on load
        document.addEventListener('DOMContentLoaded', updateMainContainer);

        // Listen for sidebar toggle clicks
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }

        // Auto-hide success/error messages
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            const errorMsg = document.querySelector('.error-message');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.style.display = 'none', 500);
            }
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 5000);

        // Character counter
        function updateCharCount(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;
            
            if (fieldId === 'description') {
                counter.textContent = `${length} / ${maxLength} characters (minimum 20)`;
                if (length < 20) {
                    counter.style.color = '#ef4444';
                } else if (length > maxLength * 0.9) {
                    counter.style.color = '#d69e2e';
                } else {
                    counter.style.color = '#718096';
                }
            } else {
                counter.textContent = `${length} / ${maxLength} characters`;
                if (length > maxLength * 0.9) {
                    counter.style.color = '#d69e2e';
                } else {
                    counter.style.color = '#718096';
                }
            }
            
            if (length === maxLength) {
                counter.style.color = '#ef4444';
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
            this.style.borderColor = '#7c3aed';
            this.style.background = '#f7f4fe';
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
            const subject = document.getElementById('subject').value.trim();
            const description = document.getElementById('description').value.trim();
            const ticketType = document.getElementById('ticket_type').value;

            if (subject.length < 5) {
                e.preventDefault();
                alert('Subject must be at least 5 characters long');
                document.getElementById('subject').focus();
                return;
            }

            if (description.length < 20) {
                e.preventDefault();
                alert('Description must be at least 20 characters long. Please provide more details.');
                document.getElementById('description').focus();
                return;
            }

            if (!ticketType) {
                e.preventDefault();
                alert('Please select a request type');
                document.getElementById('ticket_type').focus();
                return;
            }

            // Disable submit button to prevent double submission
            const submitBtn = this.querySelector('.btn-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        // Initialize character counters
        document.addEventListener('DOMContentLoaded', function() {
            updateCharCount('subject', 255);
            updateCharCount('description', 2000);
        });

        // Character counter live update for description
        const descriptionField = document.getElementById('description');
        descriptionField.addEventListener('input', function() {
            updateCharCount('description', 2000);
        });

        // Character counter live update for subject
        const subjectField = document.getElementById('subject');
        subjectField.addEventListener('input', function() {
            updateCharCount('subject', 255);
        });
    </script>
</body>
</html>