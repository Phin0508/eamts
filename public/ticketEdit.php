<?php
session_start();
require_once '../auth/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
$ticket_id = $_GET['id'] ?? 0;

// Fetch ticket details
$ticket_query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        a.asset_name,
        a.asset_code
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    WHERE t.ticket_id = ?
";

$stmt = $pdo->prepare($ticket_query);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: tickets.php");
    exit();
}

// Check permissions - only requester, managers, and admins can edit
if ($user_role === 'employee' && $ticket['requester_id'] != $user_id) {
    header("Location: tickets.php");
    exit();
}

// Fetch available assets for the requester's department
$assets_query = "
    SELECT id, asset_code, asset_name, category, brand, model 
    FROM assets 
    WHERE status = 'active' OR id = ?
    ORDER BY asset_code
";
$assets_stmt = $pdo->prepare($assets_query);
$assets_stmt->execute([$ticket['asset_id']]);
$assets = $assets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $ticket_type = $_POST['ticket_type'];
        $priority = $_POST['priority'];
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;
        
        // Validation
        $errors = [];
        
        if (empty($subject)) {
            $errors[] = "Subject is required";
        }
        
        if (empty($description)) {
            $errors[] = "Description is required";
        }
        
        if (empty($ticket_type)) {
            $errors[] = "Ticket type is required";
        }
        
        if (empty($priority)) {
            $errors[] = "Priority is required";
        }
        
        if (empty($errors)) {
            // Only allow status change for admins and managers
            if ($user_role !== 'employee' && isset($_POST['status'])) {
                $status = $_POST['status'];
                
                $update_query = "
                    UPDATE tickets 
                    SET subject = ?, 
                        description = ?, 
                        ticket_type = ?, 
                        priority = ?, 
                        asset_id = ?,
                        status = ?,
                        updated_at = NOW() 
                    WHERE ticket_id = ?
                ";
                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([
                    $subject, 
                    $description, 
                    $ticket_type, 
                    $priority, 
                    $asset_id,
                    $status,
                    $ticket_id
                ]);
            } else {
                $update_query = "
                    UPDATE tickets 
                    SET subject = ?, 
                        description = ?, 
                        ticket_type = ?, 
                        priority = ?, 
                        asset_id = ?,
                        updated_at = NOW() 
                    WHERE ticket_id = ?
                ";
                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([
                    $subject, 
                    $description, 
                    $ticket_type, 
                    $priority, 
                    $asset_id,
                    $ticket_id
                ]);
            }
            
            // Log the update in history
            $log_history = "INSERT INTO ticket_history (ticket_id, action_type, performed_by, notes, created_at) VALUES (?, 'updated', ?, 'Ticket details updated', NOW())";
            $log_stmt = $pdo->prepare($log_history);
            $log_stmt->execute([$ticket_id, $user_id]);
            
            header("Location: ticketDetails.php?id=$ticket_id&updated=1");
            exit();
        }
        
    } catch (PDOException $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?> - E-Asset System</title>
    
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .page-header p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
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

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* Form Container */
        .form-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .form-card h2 {
            font-size: 1.25rem;
            color: #2d3748;
            margin-bottom: 1.5rem;
            font-weight: 600;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label .required {
            color: #dc3545;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .form-group select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 12px 12px;
            padding-right: 2.5rem;
            appearance: none;
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .form-hint {
            font-size: 0.8rem;
            color: #718096;
            margin-top: 0.25rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-danger {
            background: #fee;
            color: #c82333;
            border: 1px solid #f5c6cb;
        }

        .alert-danger i {
            margin-top: 0.125rem;
        }

        .alert-danger ul {
            margin: 0;
            padding-left: 1.25rem;
        }

        .alert-info {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #90caf9;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
            margin-top: 1.5rem;
        }

        .info-box {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-size: 0.85rem;
            color: #718096;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.9rem;
            color: #2d3748;
            font-weight: 600;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-open { background: #e3f2fd; color: #1976d2; }
        .badge-in_progress { background: #fff3e0; color: #f57c00; }
        .badge-pending { background: #fff9c4; color: #f57f17; }
        .badge-resolved { background: #e8f5e9; color: #388e3c; }
        .badge-closed { background: #f5f5f5; color: #616161; }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .form-card {
                padding: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
                flex-wrap: wrap;
            }

            .form-actions {
                flex-direction: column-reverse;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Edit Ticket</h1>
                    <p>Update ticket information</p>
                </div>
                <div class="header-right">
                    <a href="ticketDetails.php?id=<?php echo $ticket_id; ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Details
                    </a>
                </div>
            </header>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please correct the following errors:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-container">
                <!-- Ticket Information Box -->
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label">Ticket Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($ticket['ticket_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Current Status</span>
                        <span class="info-value">
                            <span class="badge badge-<?php echo $ticket['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created</span>
                        <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Requester</span>
                        <span class="info-value"><?php echo htmlspecialchars($ticket['requester_name']); ?></span>
                    </div>
                </div>

                <?php if ($ticket['status'] === 'closed'): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Note:</strong> This ticket is closed. Some fields may be restricted from editing.
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="editTicketForm">
                    <!-- Basic Information -->
                    <div class="form-card">
                        <h2><i class="fas fa-info-circle"></i> Basic Information</h2>
                        
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="subject">
                                    Subject <span class="required">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    id="subject" 
                                    name="subject" 
                                    value="<?php echo htmlspecialchars($ticket['subject']); ?>"
                                    required 
                                    maxlength="200"
                                    placeholder="Brief description of the issue">
                                <span class="form-hint">Maximum 200 characters</span>
                            </div>

                            <div class="form-group">
                                <label for="ticket_type">
                                    Ticket Type <span class="required">*</span>
                                </label>
                                <select id="ticket_type" name="ticket_type" required>
                                    <option value="">Select Type</option>
                                    <option value="repair" <?php echo $ticket['ticket_type'] === 'repair' ? 'selected' : ''; ?>>Repair</option>
                                    <option value="maintenance" <?php echo $ticket['ticket_type'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="request_item" <?php echo $ticket['ticket_type'] === 'request_item' ? 'selected' : ''; ?>>Request Item</option>
                                    <option value="request_replacement" <?php echo $ticket['ticket_type'] === 'request_replacement' ? 'selected' : ''; ?>>Request Replacement</option>
                                    <option value="inquiry" <?php echo $ticket['ticket_type'] === 'inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                                    <option value="other" <?php echo $ticket['ticket_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="priority">
                                    Priority <span class="required">*</span>
                                </label>
                                <select id="priority" name="priority" required>
                                    <option value="">Select Priority</option>
                                    <option value="low" <?php echo $ticket['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $ticket['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $ticket['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="urgent" <?php echo $ticket['priority'] === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                </select>
                            </div>

                            <?php if ($user_role !== 'employee'): ?>
                            <div class="form-group">
                                <label for="status">
                                    Status
                                </label>
                                <select id="status" name="status">
                                    <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="pending" <?php echo $ticket['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                                <span class="form-hint">Changing status here will update the ticket immediately</span>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="asset_id">
                                    Related Asset (Optional)
                                </label>
                                <select id="asset_id" name="asset_id">
                                    <option value="">No Asset</option>
                                    <?php foreach ($assets as $asset): ?>
                                    <option 
                                        value="<?php echo $asset['id']; ?>"
                                        <?php echo $ticket['asset_id'] == $asset['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name']); ?>
                                        <?php if ($asset['brand']): ?>
                                            (<?php echo htmlspecialchars($asset['brand'] . ' ' . $asset['model']); ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="form-hint">Select an asset if this ticket is related to specific equipment</span>
                            </div>

                            <div class="form-group full-width">
                                <label for="description">
                                    Description <span class="required">*</span>
                                </label>
                                <textarea 
                                    id="description" 
                                    name="description" 
                                    required 
                                    placeholder="Provide detailed information about the issue or request"><?php echo htmlspecialchars($ticket['description']); ?></textarea>
                                <span class="form-hint">Provide as much detail as possible to help resolve the issue quickly</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-card">
                        <div class="form-actions">
                            <a href="ticketDetails.php?id=<?php echo $ticket_id; ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Ticket
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Form validation
        document.getElementById('editTicketForm').addEventListener('submit', function(e) {
            const subject = document.getElementById('subject').value.trim();
            const description = document.getElementById('description').value.trim();
            const ticketType = document.getElementById('ticket_type').value;
            const priority = document.getElementById('priority').value;

            if (!subject || !description || !ticketType || !priority) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }

            if (subject.length < 5) {
                e.preventDefault();
                alert('Subject must be at least 5 characters long');
                document.getElementById('subject').focus();
                return false;
            }

            if (description.length < 10) {
                e.preventDefault();
                alert('Description must be at least 10 characters long');
                document.getElementById('description').focus();
                return false;
            }

            return true;
        });

        // Character counter for subject
        const subjectInput = document.getElementById('subject');
        const subjectHint = subjectInput.nextElementSibling;
        
        subjectInput.addEventListener('input', function() {
            const remaining = 200 - this.value.length;
            subjectHint.textContent = `${remaining} characters remaining`;
            
            if (remaining < 20) {
                subjectHint.style.color = '#dc3545';
            } else {
                subjectHint.style.color = '#718096';
            }
        });

        // Trigger initial update
        subjectInput.dispatchEvent(new Event('input'));

        // Confirmation for status changes (admin/manager only)
        <?php if ($user_role !== 'employee'): ?>
        const statusSelect = document.getElementById('status');
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                if (this.value === 'closed') {
                    if (!confirm('Are you sure you want to change the status to Closed? This indicates the ticket is fully resolved.')) {
                        this.value = '<?php echo $ticket['status']; ?>';
                    }
                }
            });
        }
        <?php endif; ?>

        // Auto-resize textarea
        const textarea = document.getElementById('description');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Trigger on page load
        textarea.dispatchEvent(new Event('input'));
    </script>
</body>
</html>