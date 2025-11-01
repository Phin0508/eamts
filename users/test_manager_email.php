<?php
session_start();
require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php';

echo "<h2>Manager Email Diagnostic Test</h2>";
echo "<hr>";

// Get current user info
$user_id = 19; // Change this to the user ID creating the ticket
$user_query = $pdo->prepare("SELECT department, first_name, last_name, email, role FROM users WHERE user_id = ?");
$user_query->execute([$user_id]);
$user_data = $user_query->fetch(PDO::FETCH_ASSOC);

echo "<h3>1. Current User Info (Ticket Creator)</h3>";
echo "<pre>";
print_r($user_data);
echo "</pre>";

$user_department = $user_data['department'];
echo "<p><strong>User Department:</strong> '" . $user_department . "'</p>";
echo "<p><strong>Department Length:</strong> " . strlen($user_department) . " characters</p>";
echo "<p><strong>Department (with special chars visible):</strong> ";
for ($i = 0; $i < strlen($user_department); $i++) {
    echo ord($user_department[$i]) . " ";
}
echo "</p>";

echo "<hr>";

// Search for manager - EXACT MATCH
echo "<h3>2. Searching for Manager (Exact Match)</h3>";
$manager_query = $pdo->prepare("
    SELECT 
        user_id, 
        email, 
        first_name, 
        last_name, 
        department,
        role,
        is_active,
        is_deleted
    FROM users 
    WHERE department = ?
    AND role = 'manager' 
    AND is_active = 1 
    AND is_deleted = 0
");

$manager_query->execute([$user_department]);
$manager = $manager_query->fetch(PDO::FETCH_ASSOC);

if ($manager) {
    echo "<p style='color: green;'><strong>✓ MANAGER FOUND!</strong></p>";
    echo "<pre>";
    print_r($manager);
    echo "</pre>";
} else {
    echo "<p style='color: red;'><strong>✗ NO MANAGER FOUND</strong></p>";
}

echo "<hr>";

// Show ALL managers
echo "<h3>3. All Active Managers in System</h3>";
$all_managers_query = $pdo->query("
    SELECT user_id, first_name, last_name, email, department, role, is_active, is_deleted
    FROM users 
    WHERE role = 'manager'
    ORDER BY user_id
");
$all_managers = $all_managers_query->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Department</th><th>Active</th><th>Deleted</th><th>Match?</th></tr>";

foreach ($all_managers as $mgr) {
    $match = ($mgr['department'] === $user_department && $mgr['is_active'] == 1 && $mgr['is_deleted'] == 0);
    $rowColor = $match ? "background-color: lightgreen;" : "";
    
    echo "<tr style='$rowColor'>";
    echo "<td>" . $mgr['user_id'] . "</td>";
    echo "<td>" . $mgr['first_name'] . " " . $mgr['last_name'] . "</td>";
    echo "<td>" . $mgr['email'] . "</td>";
    echo "<td>'" . $mgr['department'] . "' (len: " . strlen($mgr['department']) . ")</td>";
    echo "<td>" . $mgr['is_active'] . "</td>";
    echo "<td>" . $mgr['is_deleted'] . "</td>";
    echo "<td>" . ($match ? "✓ YES" : "✗ NO") . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";

// Test sending email to manager if found
if ($manager) {
    echo "<h3>4. Testing Email Send to Manager</h3>";
    
    $emailHelper = new EmailHelper();
    $manager_name = $manager['first_name'] . ' ' . $manager['last_name'];
    $manager_email = $manager['email'];
    
    echo "<p>Manager Email: <strong>" . $manager_email . "</strong></p>";
    echo "<p>Attempting to send test email...</p>";
    
    $test_subject = "TEST - Manager Email Notification";
    $test_body = "
    <html>
    <body style='font-family: Arial, sans-serif;'>
        <h2>Test Email for Manager Notification</h2>
        <p>Hello <strong>" . htmlspecialchars($manager_name) . "</strong>,</p>
        <p>This is a test email to verify manager notifications are working.</p>
        <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>User Department:</strong> " . htmlspecialchars($user_department) . "</p>
        <p><strong>Manager Department:</strong> " . htmlspecialchars($manager['department']) . "</p>
    </body>
    </html>
    ";
    
    $sent = $emailHelper->sendEmail($manager_email, $test_subject, $test_body);
    
    if ($sent) {
        echo "<p style='color: green; font-weight: bold;'>✓✓✓ SUCCESS! Email sent to manager.</p>";
        echo "<p>Check the inbox for: " . $manager_email . "</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗✗✗ FAILED! Email NOT sent to manager.</p>";
        echo "<p>Check your error logs for details.</p>";
    }
} else {
    echo "<h3>4. Cannot Test Email Send</h3>";
    echo "<p style='color: red;'>No manager found for department: '" . $user_department . "'</p>";
    
    // Suggest fixes
    echo "<h4>Possible Issues:</h4>";
    echo "<ol>";
    echo "<li>Department name mismatch (check for extra spaces or different capitalization)</li>";
    echo "<li>No manager assigned to the '" . $user_department . "' department</li>";
    echo "<li>Manager's is_active = 0 or is_deleted = 1</li>";
    echo "</ol>";
    
    echo "<h4>Recommended Fix:</h4>";
    echo "<p>If you see a manager in the table above that SHOULD match:</p>";
    echo "<ul>";
    echo "<li>Compare the department names carefully (look at the lengths)</li>";
    echo "<li>Check for hidden spaces or special characters</li>";
    echo "<li>You may need to update the user's department to exactly match the manager's department</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<h3>5. Department Comparison Details</h3>";
echo "<p>Looking for exact matches between user department and manager departments...</p>";

foreach ($all_managers as $mgr) {
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
    echo "<strong>Manager: " . $mgr['first_name'] . " " . $mgr['last_name'] . "</strong><br>";
    echo "User Dept: '" . $user_department . "' (len=" . strlen($user_department) . ")<br>";
    echo "Manager Dept: '" . $mgr['department'] . "' (len=" . strlen($mgr['department']) . ")<br>";
    echo "Exact Match: " . ($user_department === $mgr['department'] ? "✓ YES" : "✗ NO") . "<br>";
    echo "Case-insensitive Match: " . (strtolower($user_department) === strtolower($mgr['department']) ? "✓ YES" : "✗ NO") . "<br>";
    
    if ($user_department !== $mgr['department']) {
        echo "<span style='color: red;'>Character-by-character comparison:</span><br>";
        echo "User: ";
        for ($i = 0; $i < strlen($user_department); $i++) {
            echo "[" . $user_department[$i] . ":" . ord($user_department[$i]) . "] ";
        }
        echo "<br>Manager: ";
        for ($i = 0; $i < strlen($mgr['department']); $i++) {
            echo "[" . $mgr['department'][$i] . ":" . ord($mgr['department'][$i]) . "] ";
        }
        echo "<br>";
    }
    echo "</div>";
}
?>