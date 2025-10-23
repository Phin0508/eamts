<?php
// test_chat.php - Place this in your public folder to test the chat system
session_start();

// Force login for testing (replace with your actual user_id)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Change this to test with different users
    $_SESSION['first_name'] = 'Test';
    $_SESSION['last_name'] = 'User';
}

include("../auth/config/database.php");

echo "<h1>Chat System Debug Test</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .success{color:green;} .error{color:red;} .section{margin:20px 0;padding:10px;border:1px solid #ccc;}</style>";

// Test 1: Database Connection
echo "<div class='section'>";
echo "<h2>1. Database Connection</h2>";
try {
    $stmt = $pdo->query("SELECT VERSION()");
    echo "<p class='success'>✓ Database connected successfully</p>";
    echo "<p>MySQL Version: " . $stmt->fetchColumn() . "</p>";
} catch (PDOException $e) {
    echo "<p class='error'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 2: Check if tables exist
echo "<div class='section'>";
echo "<h2>2. Database Tables</h2>";
$tables = ['chat_users', 'chat_conversations', 'chat_participants', 'chat_messages', 'chat_message_reads', 'chat_typing'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "<p class='success'>✓ Table '$table' exists (Rows: $count)</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ Table '$table' missing or error: " . $e->getMessage() . "</p>";
    }
}
echo "</div>";

// Test 3: Check users table
echo "<div class='section'>";
echo "<h2>3. Users Table</h2>";
try {
    $stmt = $pdo->query("SELECT user_id, first_name, last_name, email, is_active FROM users LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) > 0) {
        echo "<p class='success'>✓ Found " . count($users) . " users</p>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Active</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['user_id']}</td>";
            echo "<td>{$user['first_name']} {$user['last_name']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>✗ No users found in database</p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>✗ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 4: Test API Files Exist
echo "<div class='section'>";
echo "<h2>4. API Files Check</h2>";
$api_files = [
    'chat_get_users.php',
    'chat_get_conversation.php',
    'chat_get_messages.php',
    'chat_send_message.php',
    'chat_update_status.php'
];
foreach ($api_files as $file) {
    $path = "../api/$file";
    if (file_exists($path)) {
        echo "<p class='success'>✓ File exists: $file</p>";
    } else {
        echo "<p class='error'>✗ File missing: $file (Expected at: $path)</p>";
    }
}
echo "</div>";

// Test 5: Test API Endpoints
echo "<div class='section'>";
echo "<h2>5. API Endpoints Test</h2>";

// Test get users endpoint
try {
    $ch = curl_init();
    $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/../api/chat_get_users.php';
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            echo "<p class='success'>✓ chat_get_users.php working (Found " . count($data) . " users)</p>";
        } else {
            echo "<p class='error'>✗ chat_get_users.php returned invalid JSON: " . htmlspecialchars($response) . "</p>";
        }
    } else {
        echo "<p class='error'>✗ chat_get_users.php returned HTTP $http_code</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Error testing API: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 6: Insert test status
echo "<div class='section'>";
echo "<h2>6. Test Status Update</h2>";
try {
    $stmt = $pdo->prepare("INSERT INTO chat_users (user_id, status, last_activity) 
                          VALUES (?, 'online', NOW()) 
                          ON DUPLICATE KEY UPDATE status = 'online', last_activity = NOW()");
    $stmt->execute([$_SESSION['user_id']]);
    echo "<p class='success'>✓ Status update successful</p>";
} catch (PDOException $e) {
    echo "<p class='error'>✗ Status update failed: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 7: Session Check
echo "<div class='section'>";
echo "<h2>7. Session Information</h2>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>User ID: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'Not set') . "</p>";
echo "<p>Name: " . (isset($_SESSION['first_name']) ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] : 'Not set') . "</p>";
echo "</div>";

// Test 8: JavaScript Console Test
echo "<div class='section'>";
echo "<h2>8. JavaScript Test</h2>";
echo "<button onclick='testJavaScript()'>Test JavaScript API Calls</button>";
echo "<div id='jsResults'></div>";
echo "</div>";

?>

<script>
async function testJavaScript() {
    const resultsDiv = document.getElementById('jsResults');
    resultsDiv.innerHTML = '<p>Testing...</p>';
    let results = '';
    
    // Test 1: Load users
    try {
        const response = await fetch('../api/chat_get_users.php');
        const data = await response.json();
        results += '<p style="color:green">✓ JavaScript can load users: ' + data.length + ' users found</p>';
        console.log('Users:', data);
    } catch (error) {
        results += '<p style="color:red">✗ JavaScript error loading users: ' + error.message + '</p>';
        console.error('Error:', error);
    }
    
    // Test 2: Update status
    try {
        const formData = new FormData();
        formData.append('status', 'online');
        const response = await fetch('../api/chat_update_status.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        results += '<p style="color:green">✓ JavaScript can update status</p>';
        console.log('Status update:', data);
    } catch (error) {
        results += '<p style="color:red">✗ JavaScript error updating status: ' + error.message + '</p>';
        console.error('Error:', error);
    }
    
    resultsDiv.innerHTML = results;
}
</script>

<hr>
<h2>Quick Fixes:</h2>
<ul>
    <li><strong>If API files are missing:</strong> Make sure all 5 files are in the /api/ folder</li>
    <li><strong>If tables are missing:</strong> Run the SQL schema again</li>
    <li><strong>If database connection fails:</strong> Check ../auth/config/database.php path</li>
    <li><strong>If JavaScript errors:</strong> Check browser console (F12) for errors</li>
    <li><strong>If 404 errors:</strong> Check the file paths match your structure</li>
</ul>

<p><a href="chat.php">Go to Chat Page</a></p>