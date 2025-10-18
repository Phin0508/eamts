<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
include("../auth/config/database.php");

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'];
        $user_id = $_POST['user_id'];
        
        if ($action === 'approve') {
            // Update user status to verified and active
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_verified = 1, is_active = 1, verified_at = NOW(), verified_by = ?
                WHERE user_id = ? AND is_verified = 0
            ");
            if ($stmt->execute([$_SESSION['user_id'], $user_id])) {
                $success_message = "User account approved successfully!";
            } else {
                $error_message = "Failed to approve user account.";
            }
        } elseif ($action === 'reject') {
            // Delete the user account or mark as rejected
            $rejection_reason = $_POST['rejection_reason'] ?? '';
            
            // Option 1: Delete the user entirely
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND is_verified = 0");
            
            // Option 2: Mark as rejected (uncomment if you prefer to keep records)
            /*
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_verified = 0, is_active = 0, rejection_reason = ?, rejected_at = NOW(), rejected_by = ?
                WHERE user_id = ? AND is_verified = 0
            ");
            */
            
            if ($stmt->execute([$user_id])) {
                $success_message = "User account rejected and removed successfully!";
            } else {
                $error_message = "Failed to reject user account.";
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch pending users (unverified accounts)
try {
    $stmt = $pdo->prepare("
        SELECT 
            user_id, first_name, last_name, email, username, phone, 
            department, role, employee_id, created_at
        FROM users 
        WHERE is_verified = 0 AND is_active = 1
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $pending_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Failed to fetch pending users: " . $e->getMessage();
    $pending_users = [];
}

// Fetch recently approved users
try {
    $stmt = $pdo->prepare("
        SELECT 
            user_id, first_name, last_name, email, username, department, 
            role, verified_at, 
            (SELECT CONCAT(first_name, ' ', last_name) FROM users u2 WHERE u2.user_id = users.verified_by) as verified_by_name
        FROM users 
        WHERE is_verified = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY verified_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $approved_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $approved_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Verification - E-Asset Management</title>

    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="../style/userV.css">
</head>
<body>
    <!-- Include Navigation Bar -->
    <?php include("../auth/inc/navbar.php"); ?>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container">
        <div class="header">
            <h1>👥 User Verification</h1>
            <p>Review and approve new user registrations</p>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message">
            ✅ <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message">
            ❌ <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number pending"><?php echo count($pending_users); ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card">
                <div class="stat-number approved"><?php echo count($approved_users); ?></div>
                <div class="stat-label">Recently Approved</div>
            </div>
        </div>

        <!-- Pending Users Section -->
        <div class="section">
            <div class="section-header">
                <h2>📋 Pending User Registrations</h2>
                <p>Users waiting for account approval</p>
            </div>

            <?php if (empty($pending_users)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎉</div>
                <h3>No Pending Registrations</h3>
                <p>All user registrations have been processed.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Contact</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Employee ID</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                                        <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <div><?php echo htmlspecialchars($user['email']); ?></div>
                                    <?php if ($user['phone']): ?>
                                    <div style="color: #7f8c8d; font-size: 0.9rem; margin-top: 2px;">
                                        <?php echo htmlspecialchars($user['phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['department']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($user['employee_id'] ?: 'N/A'); ?></td>
                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-approve" 
                                                onclick="return confirm('Are you sure you want to approve this user?')">
                                            ✅ Approve
                                        </button>
                                    </form>
                                    <button class="btn btn-reject" 
                                            onclick="showRejectModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                        ❌ Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recently Approved Users Section -->
        <?php if (!empty($approved_users)): ?>
        <div class="section">
            <div class="section-header">
                <h2>✅ Recently Approved Users</h2>
                <p>Users approved in the last 30 days</p>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Contact</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Approved On</th>
                            <th>Approved By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved_users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                                        <p>@<?php echo htmlspecialchars($user['username']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['department']); ?></td>
                            <td>
                                <span class="badge <?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y H:i', strtotime($user['verified_at'])); ?></td>
                            <td><?php echo htmlspecialchars($user['verified_by_name'] ?: 'System'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject User Registration</h3>
                <p>Are you sure you want to reject <strong id="rejectUserName"></strong>'s registration?</p>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="user_id" id="rejectUserId">
                <input type="hidden" name="action" value="reject">
                
                <div class="form-group">
                    <label for="rejection_reason">Reason for rejection (optional):</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="4" 
                              placeholder="Enter the reason for rejecting this registration..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Reject User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showRejectModal(userId, userName) {
            document.getElementById('rejectUserId').value = userId;
            document.getElementById('rejectUserName').textContent = userName;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejection_reason').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target === modal) {
                closeRejectModal();
            }
        }

        // Auto-hide success/error messages
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            const errorMsg = document.querySelector('.error-message');
            if (successMsg) successMsg.style.display = 'none';
            if (errorMsg) errorMsg.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>