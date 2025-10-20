<?php
// logout.php
session_start();

include("../auth/config/database.php");

// Optional: Log logout activity in database
/*
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO user_activity (user_id, activity_type, activity_time) VALUES (?, 'logout', NOW())");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // Handle error silently for logout
        error_log("Logout logging error: " . $e->getMessage());
    }
}
*/

// Clear all session data
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear remember me cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Destroy the session
session_destroy();

// Redirect to login page with success message
header("Location: login.php?message=logged_out");
exit();
?>