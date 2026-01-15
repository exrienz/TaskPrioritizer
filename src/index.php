<?php
// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Ensure session settings and start are called before any output
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration from environment variables
$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'mysql';
$db_port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'task_prioritizer';
$db_user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'taskuser';
$db_pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? 'secure_password_123';

// Google OAuth configuration from environment variables
$google_oauth_enabled = filter_var($_ENV['GOOGLE_OAUTH_ENABLED'] ?? getenv('GOOGLE_OAUTH_ENABLED') ?? 'false', FILTER_VALIDATE_BOOLEAN);
$google_client_id = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?? '';
$google_client_secret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?? '';
$google_redirect_uri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?? '';

// Validate OAuth configuration if enabled
$oauth_config_error = null;
if ($google_oauth_enabled) {
    if (empty($google_client_id) || empty($google_client_secret) || empty($google_redirect_uri)) {
        $oauth_config_error = "Google OAuth is enabled but configuration is incomplete. Please check GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URI in your environment variables.";
        error_log("OAuth Configuration Error: " . $oauth_config_error);
        $google_oauth_enabled = false; // Disable OAuth to fail safely
    }

    // Enforce HTTPS in production (except for localhost development)
    $is_localhost = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                     strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
    if (!$is_localhost && empty($_SERVER['HTTPS'])) {
        $oauth_config_error = "Google OAuth requires HTTPS in production. Please ensure your application is served over HTTPS.";
        error_log("OAuth Security Error: " . $oauth_config_error);
    }
}

// Function to connect to MySQL server (without specifying database)
function connectToMySQLServer($host, $port, $user, $pass, $maxRetries = 30) {
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            return $pdo;
        } catch (PDOException $e) {
            if ($i === $maxRetries - 1) {
                throw $e;
            }
            sleep(2); // Wait 2 seconds before retrying
        }
    }
}

// Function to initialize database and tables
function initializeDatabase($pdo, $db_name) {
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db_name}`");
    
    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            oauth_provider VARCHAR(50) NULL,
            oauth_id VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_oauth (oauth_provider, oauth_id)
        ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    
    // Create tasks table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            task_name VARCHAR(500) NOT NULL,
            priority ENUM('Optional', 'Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Medium',
            effort ENUM('Low', 'Medium', 'High', 'Very High') NOT NULL DEFAULT 'Medium',
            mandays INT NOT NULL DEFAULT 1,
            due_date DATE NOT NULL,
            in_progress BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    
    // Migrate existing users table to add OAuth columns (if they don't exist)
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'oauth_provider'")->fetchAll();
        if (empty($columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) NULL AFTER password_hash");
            $pdo->exec("ALTER TABLE users ADD COLUMN oauth_id VARCHAR(255) NULL AFTER oauth_provider");
            $pdo->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE users ADD UNIQUE KEY unique_oauth (oauth_provider, oauth_id)");
        }
    } catch (PDOException $e) { /* Migration might have already been applied */ }

    // Create indexes for better performance (with proper error handling)
    try { $pdo->exec("CREATE INDEX idx_tasks_user_id ON tasks(user_id)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_tasks_due_date ON tasks(due_date)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_tasks_priority ON tasks(priority)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_email ON users(email)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_username ON users(username)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_oauth ON users(oauth_provider, oauth_id)"); } catch (PDOException $e) { /* Index might already exist */ }
}

// Function to check if database is properly initialized
function isDatabaseInitialized($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users', 'tasks')");
        return $stmt->fetchColumn() >= 2;
    } catch (PDOException $e) {
        return false;
    }
}

try {
    // First, connect to MySQL server without specifying database
    $pdo = connectToMySQLServer($db_host, $db_port, $db_user, $db_pass);
    
    // Initialize database and tables
    initializeDatabase($pdo, $db_name);
    
    // Now connect to the specific database
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // Double-check that database is properly initialized
    if (!isDatabaseInitialized($pdo)) {
        throw new Exception("Database initialization failed");
    }
    
} catch (Exception $e) {
    // Show a user-friendly error page
    $db_error = $e->getMessage();
}

// Initialize Google OAuth Client
$googleClient = null;
if ($google_oauth_enabled && !isset($oauth_config_error)) {
    try {
        $googleClient = new Google_Client();
        $googleClient->setClientId($google_client_id);
        $googleClient->setClientSecret($google_client_secret);
        $googleClient->setRedirectUri($google_redirect_uri);
        $googleClient->addScope('email');
        $googleClient->addScope('profile');
        $googleClient->setAccessType('online');
    } catch (Exception $e) {
        error_log("Google Client Initialization Error: " . $e->getMessage());
        $oauth_config_error = "Failed to initialize Google OAuth client. Please check your configuration.";
        $google_oauth_enabled = false;
    }
}

// Function to generate CSRF token
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to validate CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Skip processing if there's a database error
if (isset($db_error)) {
    // Will be handled in the HTML section
} else {

// Handle Google OAuth Callback
if (isset($_GET['code']) && isset($_GET['state']) && $google_oauth_enabled && $googleClient) {
    // Validate CSRF state parameter
    if (!validateCsrfToken($_GET['state'])) {
        $error_message = "Invalid OAuth state parameter. Please try logging in again.";
        error_log("OAuth CSRF Validation Failed");
    } else {
        try {
            // Exchange authorization code for access token
            $token = $googleClient->fetchAccessTokenWithAuthCode($_GET['code']);

            if (isset($token['error'])) {
                throw new Exception($token['error_description'] ?? $token['error']);
            }

            $googleClient->setAccessToken($token);

            // Get user profile information
            $google_oauth = new Google_Service_Oauth2($googleClient);
            $google_user_info = $google_oauth->userinfo->get();

            $google_id = $google_user_info->id;
            $email = $google_user_info->email;
            $name = $google_user_info->name;
            $verified_email = $google_user_info->verifiedEmail;

            // Only proceed with verified emails
            if (!$verified_email) {
                throw new Exception("Email not verified by Google");
            }

            // Check if user exists with this Google ID
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE oauth_provider = 'google' AND oauth_id = ?");
            $stmt->execute([$google_id]);
            $user = $stmt->fetch();

            if ($user) {
                // User exists, log them in
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                unset($_SESSION['oauth_state']); // Clean up
                header("Location: index.php");
                exit;
            } else {
                // Check if user exists with this email (for linking accounts)
                $stmt = $pdo->prepare("SELECT id, username, email, oauth_provider FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $existing_user = $stmt->fetch();

                if ($existing_user && empty($existing_user['oauth_provider'])) {
                    // Link Google account to existing manual account
                    $stmt = $pdo->prepare("UPDATE users SET oauth_provider = 'google', oauth_id = ? WHERE id = ?");
                    $stmt->execute([$google_id, $existing_user['id']]);

                    $_SESSION['loggedin'] = true;
                    $_SESSION['user_id'] = $existing_user['id'];
                    $_SESSION['username'] = $existing_user['username'];
                    unset($_SESSION['oauth_state']);
                    $success_message = "Your Google account has been linked successfully!";
                    header("Location: index.php");
                    exit;
                } elseif ($existing_user) {
                    // Email exists but linked to different OAuth provider
                    throw new Exception("An account with this email already exists with a different login method.");
                } else {
                    // Create new user
                    $username = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', strtolower($name)));
                    $base_username = $username;
                    $counter = 1;

                    // Ensure username is unique
                    while (true) {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                        $stmt->execute([$username]);
                        if (!$stmt->fetch()) {
                            break;
                        }
                        $username = $base_username . $counter;
                        $counter++;
                    }

                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, oauth_provider, oauth_id) VALUES (?, ?, NULL, 'google', ?)");
                    $stmt->execute([$username, $email, $google_id]);
                    $new_user_id = $pdo->lastInsertId();

                    $_SESSION['loggedin'] = true;
                    $_SESSION['user_id'] = $new_user_id;
                    $_SESSION['username'] = $username;
                    unset($_SESSION['oauth_state']);
                    $success_message = "Account created successfully with Google! Welcome!";
                    header("Location: index.php");
                    exit;
                }
            }
        } catch (Exception $e) {
            $error_message = "Google login failed: " . htmlspecialchars($e->getMessage());
            error_log("OAuth Error: " . $e->getMessage());
        }
    }
}

// Handle Google OAuth Error Callback
if (isset($_GET['error']) && $google_oauth_enabled) {
    $error_description = $_GET['error_description'] ?? 'Unknown error';
    if ($_GET['error'] === 'access_denied') {
        $error_message = "Google login was cancelled. Please try again if you'd like to sign in with Google.";
    } else {
        $error_message = "Google login error: " . htmlspecialchars($error_description);
    }
    error_log("OAuth Error: " . $_GET['error'] . " - " . $error_description);
}

// User Registration
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($username) || empty($email) || empty($password)) {
        $error_message = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } else {
        try {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error_message = "Username or email already exists.";
            } else {
                // Create new user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $password_hash]);
                $success_message = "Registration successful! You can now log in.";
            }
        } catch (PDOException $e) {
            $error_message = "Registration failed. Please try again.";
        }
    }
}

// User Login
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
            } else {
                $error_message = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            $error_message = "Login failed. Please try again.";
        }
    }
}

if (isset($_POST['create_task']) && $_SESSION['loggedin'] === true) {
    try {
        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, task_name, priority, effort, mandays, due_date, in_progress) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_POST['task_name'],
            $_POST['priority'],
            $_POST['effort'],
            $_POST['mandays'],
            $_POST['due_date']
        ]);
        $success_message = "Task created successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to create task. Please try again.";
    }
}

if (isset($_POST['delete_task']) && $_SESSION['loggedin'] === true) {
    try {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$_POST['task_id'], $_SESSION['user_id']]);
        $success_message = "Task deleted successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to delete task. Please try again.";
    }
}

if (isset($_POST['mark_progress']) && $_SESSION['loggedin'] === true) {
    try {
        $stmt = $pdo->prepare("UPDATE tasks SET in_progress = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$_POST['task_id'], $_SESSION['user_id']]);
        $success_message = "Task marked as in progress!";
    } catch (PDOException $e) {
        $error_message = "Failed to update task. Please try again.";
    }
}

if (isset($_POST['edit_task']) && $_SESSION['loggedin'] === true) {
    try {
        $stmt = $pdo->prepare("UPDATE tasks SET task_name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$_POST['new_task_name'], $_POST['task_id'], $_SESSION['user_id']]);
        $success_message = "Task updated successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to update task. Please try again.";
    }
}

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

function getPriorityScore($priority) {
    $priorityMap = ['Optional' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Critical' => 5];
    return $priorityMap[$priority] ?? 3;
}

function getEffortScore($effort) {
    $effortMap = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];
    return $effortMap[$effort] ?? 2;
}

function calculateUrgentScore($task, $daysLeft) {
    $priority = getPriorityScore($task['priority']);
    $effort = getEffortScore($task['effort']);
    $mandays = max(1, (int) $task['mandays']);
    
    // Urgent mode: Favor quick wins with reasonable effort consideration
    $effortPenalty = ($effort * 8) + min($mandays * 2, 20); // Capped mandays penalty
    
    // Strong urgency boost for imminent deadlines
    if ($daysLeft < 0) {
        $urgency = 200 + (abs($daysLeft) * 30); // Higher base + escalating penalty
    } elseif ($daysLeft == 0) {
        $urgency = 180; // Due today
    } elseif ($daysLeft <= 1) {
        $urgency = 150; // Due tomorrow  
    } else {
        $urgency = 120 / (1 + $daysLeft); // Strong urgency curve
    }
    
    $score = ($priority * 50) + $urgency - $effortPenalty;
    return max(0, round($score, 2));
}

function calculateStrategicScore($task, $daysLeft) {
    $priority = getPriorityScore($task['priority']);
    $effort = getEffortScore($task['effort']);
    $mandays = max(1, (int) $task['mandays']);
    
    // Strategic mode: Balance priority with reasonable effort consideration
    $urgency = 40 / (1 + $daysLeft * 0.05); // More urgency influence, gentler curve
    
    // Moderate penalties with strong high-priority forgiveness
    $effortWeight = ($priority >= 4) ? 8 : 15; // Significant forgiveness for Critical/High
    $mandaysWeight = ($priority >= 4) ? 1.5 : 3; // Much less penalty for high priority
    
    $effortPenalty = ($effort * $effortWeight) + ($mandays * $mandaysWeight);
    
    // Higher base score to ensure strategic tasks still rank meaningfully
    $score = ($priority * 50) + $urgency - $effortPenalty;
    return max(0, round($score, 2));
}

function getTaskMode($daysLeft) {
    return ($daysLeft <= 3 || $daysLeft < 0) ? 'URGENT' : 'STRATEGIC';
}

function calculateTaskScore($task) {
    $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    
    // Adaptive strategy based on time constraints
    if ($daysLeft <= 3 || $daysLeft < 0) {
        // URGENT MODE: Favor quick wins and immediate completion
        return calculateUrgentScore($task, $daysLeft);
    } else {
        // STRATEGIC MODE: Balance high-priority work with efficiency
        return calculateStrategicScore($task, $daysLeft);
    }
}

$tasks = [];
if ($_SESSION['loggedin'] ?? false) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $tasks = $stmt->fetchAll();
        usort($tasks, fn($a, $b) => calculateTaskScore($b) <=> calculateTaskScore($a));
    } catch (PDOException $e) {
        $error_message = "Failed to load tasks. Please try again.";
    }
}

} // End of database error check
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Task Management System</title>
    <style>
        body { background-color: #f8f9fa; }
        .card { margin-bottom: 1rem; }
        .progress-badge { background-color: #ffc107; color: #000; font-size: 0.8em; padding: 0.2em 0.6em; border-radius: 5px; }
        .auth-container { max-width: 400px; margin: 0 auto; }
        .nav-tabs .nav-link { cursor: pointer; }
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="text-center mb-4">Task Management System</h1>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success" role="alert">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($oauth_config_error)): ?>
        <div class="alert alert-warning" role="alert">
            <?= htmlspecialchars($oauth_config_error) ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($db_error)): ?>
        <div class="alert alert-danger" role="alert">
            <h4>Database Connection Error</h4>
            <p>The application is unable to connect to the database. This usually happens when:</p>
            <ul>
                <li>The MySQL container is still starting up (please wait a few moments and refresh)</li>
                <li>The database credentials in the .env file are incorrect</li>
                <li>The MySQL service is not running</li>
            </ul>
            <p><strong>Technical details:</strong> <?= htmlspecialchars($db_error) ?></p>
            <button class="btn btn-primary" onclick="location.reload()">Retry Connection</button>
        </div>
        <?php return; // Stop processing the rest of the page ?>
    <?php endif; ?>
<?php if (!($_SESSION['loggedin'] ?? false)): ?>
<div class="auth-container">
    <ul class="nav nav-tabs" id="authTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button" role="tab">Login</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab">Register</button>
        </li>
    </ul>
    
    <div class="tab-content" id="authTabContent">
        <div class="tab-pane fade show active" id="login" role="tabpanel">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Login</h5>

                    <?php if ($google_oauth_enabled && $googleClient && !isset($oauth_config_error)): ?>
                        <?php
                            // Generate CSRF state token
                            $state = generateCsrfToken();
                            $_SESSION['oauth_state'] = $state;
                            $googleClient->setState($state);
                            $authUrl = $googleClient->createAuthUrl();
                        ?>
                        <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-outline-danger w-100 mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-google" viewBox="0 0 16 16" style="margin-right: 8px;">
                                <path d="M15.545 6.558a9.42 9.42 0 0 1 .139 1.626c0 2.434-.87 4.492-2.384 5.885h.002C11.978 15.292 10.158 16 8 16A8 8 0 1 1 8 0a7.689 7.689 0 0 1 5.352 2.082l-2.284 2.284A4.347 4.347 0 0 0 8 3.166c-2.087 0-3.86 1.408-4.492 3.304a4.792 4.792 0 0 0 0 3.063h.003c.635 1.893 2.405 3.301 4.492 3.301 1.078 0 2.004-.276 2.722-.764h-.003a3.702 3.702 0 0 0 1.599-2.431H8v-3.08h7.545z"/>
                            </svg>
                            Continue with Google
                        </a>
                        <div class="text-center mb-3">
                            <small class="text-muted">- OR -</small>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="login_username" class="form-label">Username or Email:</label>
                            <input type="text" class="form-control" name="username" id="login_username" required>
                        </div>
                        <div class="mb-3">
                            <label for="login_password" class="form-label">Password:</label>
                            <input type="password" class="form-control" name="password" id="login_password" required>
                        </div>
                        <button type="submit" name="login" class="btn btn-success w-100">Login</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="tab-pane fade" id="register" role="tabpanel">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Register</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="register_username" class="form-label">Username:</label>
                            <input type="text" class="form-control" name="username" id="register_username" required>
                        </div>
                        <div class="mb-3">
                            <label for="register_email" class="form-label">Email:</label>
                            <input type="email" class="form-control" name="email" id="register_email" required>
                        </div>
                        <div class="mb-3">
                            <label for="register_password" class="form-label">Password:</label>
                            <input type="password" class="form-control" name="password" id="register_password" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password:</label>
                            <input type="password" class="form-control" name="confirm_password" id="confirm_password" required minlength="6">
                        </div>
                        <button type="submit" name="register" class="btn btn-primary w-100">Register</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h2 class="mb-0">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h2>
        <form method="POST" class="ms-2">
            <button name="logout" class="btn btn-danger">Logout</button>
        </form>
    </div>
</div>
<div class="row mb-3">
    <div class="col-12">
        <h3>Create Task</h3>
    </div>
</div>
<div class="row justify-content-center mb-4">
    <div class="col-12 col-md-10 col-lg-8 col-xl-6">
        <form method="POST" class="p-3 border rounded bg-white">
            <input type="text" name="task_name" class="form-control mb-2" placeholder="Task Name" required>
            <select name="priority" class="form-select mb-2">
                <option>Choose Priority</option><option>Critical</option><option>High</option><option>Medium</option><option>Low</option><option>Optional</option>
            </select>
            <select name="effort" class="form-select mb-2">
                <option>Choose Effort</option><option>Very High</option><option>High</option><option>Medium</option><option>Low</option>
            </select>
            <input type="number" name="mandays" class="form-control mb-2" placeholder="Mandays" required>
            <input type="date" name="due_date" class="form-control mb-2" required>
            <button type="submit" name="create_task" class="btn btn-primary w-100">Create Task</button>
        </form>
    </div>
</div>
<h3>Your Tasks</h3>
<div class="row g-3">
<?php foreach ($tasks as $task): ?>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title mb-1"><?= htmlspecialchars($task['task_name']) ?>
                    <?php if (!empty($task['in_progress'])): ?><span class="progress-badge ms-2">In Progress</span><?php endif; ?>
                </h5>
                <p class="card-text mb-1">
                    <strong>Priority:</strong> <?= htmlspecialchars($task['priority']) ?><br>
                    <strong>Effort:</strong> <?= htmlspecialchars($task['effort']) ?><br>
                    <strong>Mandays:</strong> <?= htmlspecialchars($task['mandays']) ?><br>
                    <strong>Due:</strong> <?= htmlspecialchars($task['due_date']) ?><br>
                    <?php
                        $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
                        if ($daysLeft < 0) {
                            $daysLeftText = abs($daysLeft) . ' day(s) overdue';
                            $daysLeftClass = 'text-danger fw-bold';
                        } elseif ($daysLeft <= 3) {
                            $daysLeftText = $daysLeft . ' day(s) left';
                            $daysLeftClass = 'text-warning fw-bold';
                        } else {
                            $daysLeftText = $daysLeft . ' day(s) left';
                            $daysLeftClass = 'text-success';
                        }
                        
                        $taskMode = getTaskMode($daysLeft);
                        $taskScore = calculateTaskScore($task);
                        $modeClass = ($taskMode == 'URGENT') ? 'badge bg-danger' : 'badge bg-primary';
                    ?>
                    <strong>Time Left:</strong> <span class="<?= $daysLeftClass ?>"><?= $daysLeftText ?></span><br>
                    <strong>Mode:</strong> <span class="<?= $modeClass ?>"><?= $taskMode ?></span><br>
                    <strong>Score:</strong> <span class="fw-bold text-primary"><?= $taskScore ?></span>
                </p>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <button type="submit" name="delete_task" class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
                <?php if (empty($task['in_progress'])): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <input type="hidden" name="mark_progress" value="1">
                    <input type="checkbox" onchange="this.form.submit()">
                    Mark as In Progress
                </form>
                <?php endif; ?>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <div class="input-group">
                        <input type="text" name="new_task_name" class="form-control" value="<?= htmlspecialchars($task['task_name']) ?>" required>
                        <button type="submit" name="edit_task" class="btn btn-outline-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<footer class="text-center mt-4">
    <a href="/">Back to Home</a><br>
    Vibe coded by Exrienz with <span style="color:red">&#10084;</span>
</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
