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
    $is_https_request = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    if (!$is_localhost && !$is_https_request) {
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
            description TEXT NULL,
            assignee VARCHAR(255) NULL,
            priority ENUM('Optional', 'Low', 'Medium', 'High', 'Critical') NOT NULL DEFAULT 'Medium',
            effort ENUM('Low', 'Medium', 'High', 'Very High') NOT NULL DEFAULT 'Medium',
            mandays INT NOT NULL DEFAULT 1,
            due_date DATE NOT NULL,
            in_progress BOOLEAN DEFAULT FALSE,
            status ENUM('backlog', 'todo', 'in_progress', 'done') NOT NULL DEFAULT 'todo',
            sort_order INT NOT NULL DEFAULT 0,
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
    try { $pdo->exec("CREATE INDEX idx_tasks_status_order ON tasks(user_id, status, sort_order)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_email ON users(email)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_username ON users(username)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_oauth ON users(oauth_provider, oauth_id)"); } catch (PDOException $e) { /* Index might already exist */ }

    // Migrate existing tasks table for Kanban support
    try {
        $statusColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'status'")->fetchAll();
        if (empty($statusColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN status ENUM('backlog', 'todo', 'in_progress', 'done') NOT NULL DEFAULT 'todo' AFTER in_progress");
            $pdo->exec("UPDATE tasks SET status = CASE WHEN in_progress = 1 THEN 'in_progress' ELSE 'todo' END");
        }
    } catch (PDOException $e) { /* Migration might have already been applied */ }

    try {
        $sortColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'sort_order'")->fetchAll();
        if (empty($sortColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status");
            $pdo->exec("SET @rownum = 0");
            $pdo->exec("UPDATE tasks SET sort_order = (@rownum := @rownum + 1) ORDER BY created_at, id");
        }
    } catch (PDOException $e) { /* Migration might have already been applied */ }

    try {
        $descriptionColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'description'")->fetchAll();
        if (empty($descriptionColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN description TEXT NULL AFTER task_name");
        }
    } catch (PDOException $e) { /* Migration might have already been applied */ }

    try {
        $assigneeColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'assignee'")->fetchAll();
        if (empty($assigneeColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN assignee VARCHAR(255) NULL AFTER description");
        }
    } catch (PDOException $e) { /* Migration might have already been applied */ }
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
        $nextOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM tasks WHERE user_id = ? AND status = 'todo'");
        $nextOrderStmt->execute([$_SESSION['user_id']]);
        $nextOrder = (int) $nextOrderStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, task_name, description, assignee, priority, effort, mandays, due_date, in_progress, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'todo', ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $_POST['task_name'],
            trim($_POST['description'] ?? ''),
            trim($_POST['assignee'] ?? ''),
            $_POST['priority'],
            $_POST['effort'],
            $_POST['mandays'],
            $_POST['due_date'],
            $nextOrder
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

if (isset($_POST['delete_task_ajax']) && $_SESSION['loggedin'] === true) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    if ($taskId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid task id']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$taskId, $_SESSION['user_id']]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Failed to delete task']);
        exit;
    }
}

if (isset($_POST['mark_progress']) && $_SESSION['loggedin'] === true) {
    try {
        $nextOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM tasks WHERE user_id = ? AND status = 'in_progress'");
        $nextOrderStmt->execute([$_SESSION['user_id']]);
        $nextOrder = (int) $nextOrderStmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE tasks SET in_progress = 1, status = 'in_progress', sort_order = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$nextOrder, $_POST['task_id'], $_SESSION['user_id']]);
        $success_message = "Task moved to In Progress!";
    } catch (PDOException $e) {
        $error_message = "Failed to update task. Please try again.";
    }
}

if (isset($_POST['move_task']) && $_SESSION['loggedin'] === true) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $newOrder = max(1, (int) ($_POST['new_order'] ?? 1));
    $allowedStatuses = ['backlog', 'todo', 'in_progress', 'done'];

    if ($taskId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid move request']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $taskStmt = $pdo->prepare("SELECT id, status, sort_order FROM tasks WHERE id = ? AND user_id = ? FOR UPDATE");
        $taskStmt->execute([$taskId, $_SESSION['user_id']]);
        $task = $taskStmt->fetch();

        if (!$task) {
            $pdo->rollBack();
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Task not found']);
            exit;
        }

        $oldStatus = $task['status'];
        $oldOrder = (int) $task['sort_order'];

        if ($oldStatus !== $newStatus) {
            $closeGap = $pdo->prepare("UPDATE tasks SET sort_order = sort_order - 1 WHERE user_id = ? AND status = ? AND sort_order > ?");
            $closeGap->execute([$_SESSION['user_id'], $oldStatus, $oldOrder]);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = ? AND id != ?");
        $countStmt->execute([$_SESSION['user_id'], $newStatus, $taskId]);
        $maxPosition = ((int) $countStmt->fetchColumn()) + 1;
        $newOrder = min($newOrder, $maxPosition);

        $openGap = $pdo->prepare("UPDATE tasks SET sort_order = sort_order + 1 WHERE user_id = ? AND status = ? AND id != ? AND sort_order >= ?");
        $openGap->execute([$_SESSION['user_id'], $newStatus, $taskId, $newOrder]);

        $inProgress = $newStatus === 'in_progress' ? 1 : 0;
        $moveStmt = $pdo->prepare("UPDATE tasks SET status = ?, in_progress = ?, sort_order = ? WHERE id = ? AND user_id = ?");
        $moveStmt->execute([$newStatus, $inProgress, $newOrder, $taskId, $_SESSION['user_id']]);

        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Failed to move task']);
        exit;
    }
}

if (isset($_POST['update_task_details']) && $_SESSION['loggedin'] === true) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $title = trim($_POST['task_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignee = trim($_POST['assignee'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $dueDate = $_POST['due_date'] ?? '';
    $status = $_POST['status'] ?? 'todo';

    $allowedStatuses = ['backlog', 'todo', 'in_progress', 'done'];
    $allowedPriorities = ['Optional', 'Low', 'Medium', 'High', 'Critical'];

    if ($taskId <= 0 || $title === '' || !in_array($status, $allowedStatuses, true) || !in_array($priority, $allowedPriorities, true) || $dueDate === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid task payload']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE tasks SET task_name = ?, description = ?, assignee = ?, priority = ?, due_date = ?, status = ?, in_progress = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([
            $title,
            $description !== '' ? $description : null,
            $assignee !== '' ? $assignee : null,
            $priority,
            $dueDate,
            $status,
            $status === 'in_progress' ? 1 : 0,
            $taskId,
            $_SESSION['user_id']
        ]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Failed to update task']);
        exit;
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
        .kanban-board { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 1rem; }
        .kanban-column { background: #ffffff; border: 1px solid #e8ecf4; border-radius: 0.85rem; min-height: 280px; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04); }
        .kanban-column-header { padding: 0.85rem 1rem; border-bottom: 1px solid #edf1f7; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .kanban-column-body { padding: 0.8rem; min-height: 220px; }
        .kanban-task { border: 1px solid #e6ebf3; border-radius: 0.8rem; background: #fff; padding: 0.85rem; margin-bottom: 0.75rem; cursor: grab; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06); transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease; }
        .kanban-task:hover { transform: translateY(-1px); border-color: #cfd9ea; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.09); }
        .kanban-task.dragging { opacity: 0.5; }
        .kanban-column.is-over { outline: 2px dashed #0d6efd; outline-offset: 2px; }
        .task-title { font-size: 0.98rem; font-weight: 700; margin-bottom: 0.35rem; color: #10213d; }
        .task-meta { font-size: 0.8rem; color: #5d6b82; margin-bottom: 0.2rem; }
        .due-meta { font-weight: 600; }
        .due-countdown { display: inline-block; margin-left: 0.2rem; font-weight: 700; }
        .due-safe { color: #1f7a3f; }
        .due-soon { color: #9a6200; }
        .due-today { color: #0a4c8f; }
        .due-overdue { color: #a31818; }
        .task-desc { font-size: 0.86rem; color: #2f3d52; margin: 0.4rem 0 0.45rem; line-height: 1.35; white-space: pre-line; }
        .task-actions { display: flex; gap: 0.4rem; }
        .task-signals { display: flex; align-items: center; justify-content: space-between; gap: 0.45rem; margin: 0.2rem 0 0.55rem; flex-wrap: wrap; }
        .mode-badge { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em; border-radius: 999px; padding: 0.18rem 0.55rem; border: 1px solid transparent; }
        .mode-urgent { background: #fff1ef; border-color: #f1b2aa; color: #8b1d13; }
        .mode-strategic { background: #edf5ff; border-color: #b7d3ff; color: #11408a; }
        .score-pill { display: inline-flex; align-items: center; gap: 0.28rem; font-size: 0.76rem; font-weight: 800; border-radius: 999px; padding: 0.2rem 0.55rem; border: 1px solid transparent; }
        .score-low { background: #f5f7fb; border-color: #d8e0ee; color: #334765; }
        .score-medium { background: #f4f8ff; border-color: #bfd4ff; color: #1f4d9a; }
        .score-high { background: #fff6ea; border-color: #f3c58a; color: #8a4a09; }
        .score-critical { background: #fff0ee; border-color: #f3b4ad; color: #8b1f16; }
        .status-backlog { background: #eef2f6; border-left: 3px solid #95a1b3; }
        .status-todo { background: #f5f9ff; border-left: 3px solid #6596ff; }
        .status-in_progress { background: #fff9f0; border-left: 3px solid #f2a548; }
        .status-done { background: #f2fcf4; border-left: 3px solid #4caf70; }
        .kanban-task .btn { --bs-btn-padding-y: .2rem; --bs-btn-padding-x: .5rem; --bs-btn-font-size: .75rem; }
        .ranked-card { border: 1px solid #dbe4f1; border-radius: 0.9rem; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05); }
        .ranked-card.is-expanded { border-color: #b9c8df; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.09); }
        .ranked-summary { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.6rem; }
        .ranked-signals { display: flex; gap: 0.45rem; flex-wrap: wrap; }
        .ranked-details { max-height: 0; overflow: hidden; opacity: 0; transform: translateY(-4px); transition: max-height 220ms ease, opacity 180ms ease, transform 180ms ease, margin-top 180ms ease; margin-top: 0; }
        .ranked-details.is-open { max-height: 900px; opacity: 1; transform: translateY(0); margin-top: 0.7rem; }
        .ranked-toggle { min-width: 88px; }
        .ranked-toggle { display: none; }
        .ranked-section-head { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem; }
        .ranked-section-body { max-height: 2200px; overflow: hidden; opacity: 1; transition: max-height 260ms ease, opacity 180ms ease; }
        .ranked-section-body.is-collapsed { max-height: 0; opacity: 0; }
        @media (min-width: 768px) {
            .kanban-board { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1200px) {
            .kanban-board { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
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
            <textarea name="description" class="form-control mb-2" placeholder="Description (optional)" rows="2"></textarea>
            <input type="text" name="assignee" class="form-control mb-2" placeholder="Assignee (optional)">
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
<?php
    $kanbanStatuses = ['backlog', 'todo', 'in_progress', 'done'];
    $kanbanLabels = ['backlog' => 'Backlog', 'todo' => 'To Do', 'in_progress' => 'In Progress', 'done' => 'Done'];
    $tasksByStatus = ['backlog' => [], 'todo' => [], 'in_progress' => [], 'done' => []];
    foreach ($tasks as $task) {
        $status = $task['status'] ?? (empty($task['in_progress']) ? 'todo' : 'in_progress');
        if (!isset($tasksByStatus[$status])) {
            $status = 'todo';
        }
        $task['score'] = calculateTaskScore($task);
        $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $task['mode'] = getTaskMode($daysLeft);
        $task['mode_weight'] = $task['mode'] === 'URGENT' ? 2 : 1;
        $tasksByStatus[$status][] = $task;
    }
    foreach ($kanbanStatuses as $status) {
        usort($tasksByStatus[$status], fn($a, $b) => ($b['mode_weight'] <=> $a['mode_weight']) ?: ($b['score'] <=> $a['score']));
    }
?>

<h4 class="mt-4 mb-3">Kanban Board</h4>
<div class="kanban-board mb-4" id="kanbanBoard">
    <?php foreach ($kanbanStatuses as $status): ?>
        <div class="kanban-column" data-status="<?= $status ?>">
            <div class="kanban-column-header">
                <span><?= $kanbanLabels[$status] ?></span>
                <span class="badge bg-secondary"><?= count($tasksByStatus[$status]) ?></span>
            </div>
            <div class="kanban-column-body" data-status="<?= $status ?>">
                <?php foreach ($tasksByStatus[$status] as $task): ?>
                    <?php
                        $scoreClass = 'score-low';
                        if ($task['score'] >= 220) {
                            $scoreClass = 'score-critical';
                        } elseif ($task['score'] >= 170) {
                            $scoreClass = 'score-high';
                        } elseif ($task['score'] >= 110) {
                            $scoreClass = 'score-medium';
                        }
                        $modeClass = $task['mode'] === 'URGENT' ? 'mode-urgent' : 'mode-strategic';
                    ?>
                    <div class="kanban-task status-<?= htmlspecialchars($status) ?>" draggable="true" data-task-id="<?= $task['id'] ?>" data-task-name="<?= htmlspecialchars($task['task_name']) ?>" data-description="<?= htmlspecialchars($task['description'] ?? '') ?>" data-assignee="<?= htmlspecialchars($task['assignee'] ?? '') ?>" data-priority="<?= htmlspecialchars($task['priority']) ?>" data-due-date="<?= htmlspecialchars($task['due_date']) ?>" data-status="<?= htmlspecialchars($status) ?>" data-mode="<?= htmlspecialchars($task['mode']) ?>" data-score="<?= htmlspecialchars((string) $task['score']) ?>" data-effort="<?= htmlspecialchars($task['effort']) ?>" data-mandays="<?= htmlspecialchars((string) $task['mandays']) ?>">
                        <div class="task-title"><?= htmlspecialchars($task['task_name']) ?></div>
                        <div class="task-signals">
                            <span class="mode-badge <?= $modeClass ?>">Mode: <?= htmlspecialchars($task['mode']) ?></span>
                            <span class="score-pill <?= $scoreClass ?>" aria-label="Task score <?= htmlspecialchars((string) $task['score']) ?>">Score: <?= $task['score'] ?></span>
                        </div>
                        <?php if (!empty($task['description'])): ?>
                            <div class="task-desc"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                        <?php endif; ?>
                        <div class="task-meta task-priority">Priority: <?= htmlspecialchars($task['priority']) ?></div>
                        <?php if (!empty($task['due_date'])): ?>
                            <div class="task-meta due-meta">Due: <span class="due-date-value"><?= htmlspecialchars($task['due_date']) ?></span><span class="due-countdown"></span></div>
                        <?php endif; ?>
                        <div class="task-meta task-assignee">Assignee: <?= htmlspecialchars($task['assignee'] ?? 'Unassigned') ?></div>
                        <div class="task-actions">
                            <button type="button" class="btn btn-outline-primary btn-sm edit-task-btn">Edit</button>
                            <button type="button" class="btn btn-outline-danger btn-sm delete-task-btn">Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="ranked-section-head">
    <h4 class="mb-0">Ranked Task Cards</h4>
    <button type="button" id="rankedSectionToggle" class="btn btn-sm btn-outline-secondary" aria-expanded="false" aria-controls="rankedSectionBody">Expand Section</button>
</div>
<div id="rankedSectionBody" class="ranked-section-body is-collapsed" data-ranked-section-body>
<div class="row g-3">
<?php foreach ($tasks as $task): ?>
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
        $detailsId = 'ranked-details-' . (int) $task['id'];
    ?>
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 ranked-card" data-ranked-card-id="<?= (int) $task['id'] ?>">
            <div class="card-body">
                <div class="ranked-summary">
                    <div>
                        <h5 class="card-title mb-1"><?= htmlspecialchars($task['task_name']) ?></h5>
                        <div class="ranked-signals mb-1">
                            <span class="<?= $modeClass ?>">Mode: <?= $taskMode ?></span>
                            <span class="fw-bold text-primary">Score: <?= $taskScore ?></span>
                            <?php if (!empty($task['in_progress'])): ?><span class="progress-badge">In Progress</span><?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary ranked-toggle" data-ranked-toggle data-target="<?= $detailsId ?>" aria-expanded="false" aria-controls="<?= $detailsId ?>">Expand</button>
                </div>

                <div id="<?= $detailsId ?>" class="ranked-details" data-ranked-details>
                    <p class="card-text mb-2">
                        <strong>Description:</strong> <?= htmlspecialchars($task['description'] ?? 'No description') ?><br>
                        <strong>Priority:</strong> <?= htmlspecialchars($task['priority']) ?><br>
                        <strong>Effort:</strong> <?= htmlspecialchars($task['effort']) ?><br>
                        <strong>Mandays:</strong> <?= htmlspecialchars($task['mandays']) ?><br>
                        <strong>Due:</strong> <?= htmlspecialchars($task['due_date']) ?> (<?= $daysLeftText ?>)<br>
                        <strong>Time Left:</strong> <span class="<?= $daysLeftClass ?>"><?= $daysLeftText ?></span>
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
    </div>
<?php endforeach; ?>
</div>
</div>

<div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editTaskForm">
                <div class="modal-body">
                    <input type="hidden" name="task_id" id="edit_task_id">
                    <div class="mb-2">
                        <label class="form-label" for="edit_task_name">Title</label>
                        <input class="form-control" type="text" name="task_name" id="edit_task_name" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="edit_description">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="edit_assignee">Assignee</label>
                        <input class="form-control" type="text" name="assignee" id="edit_assignee">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="edit_priority">Priority</label>
                            <select class="form-select" name="priority" id="edit_priority" required>
                                <option>Critical</option><option>High</option><option>Medium</option><option>Low</option><option>Optional</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="edit_due_date">Due Date</label>
                            <input class="form-control" type="date" name="due_date" id="edit_due_date" required>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label" for="edit_status">Status</label>
                        <select class="form-select" name="status" id="edit_status" required>
                            <option value="backlog">Backlog</option>
                            <option value="todo">To Do</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Done</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<footer class="text-center mt-4">
    <a href="/">Back to Home</a><br>
    Vibe coded by Exrienz with <span style="color:red">&#10084;</span>
</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const board = document.getElementById('kanbanBoard');
    if (!board) {
        return;
    }

    const editModalEl = document.getElementById('editTaskModal');
    const editForm = document.getElementById('editTaskForm');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;
    let draggingTask = null;

    const getInsertionTarget = (container, y) => {
        const cards = [...container.querySelectorAll('.kanban-task:not(.dragging)')];
        let closest = null;
        let closestOffset = Number.NEGATIVE_INFINITY;
        cards.forEach((card) => {
            const box = card.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closest = card;
            }
        });
        return closest;
    };

    const sendMove = async (taskId, newStatus, newOrder) => {
        const payload = new URLSearchParams();
        payload.append('move_task', '1');
        payload.append('task_id', String(taskId));
        payload.append('new_status', newStatus);
        payload.append('new_order', String(newOrder));

        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });

        if (!response.ok) {
            throw new Error('Move failed');
        }
        return response.json();
    };

    const updateColumnCounts = () => {
        board.querySelectorAll('.kanban-column').forEach((column) => {
            const count = column.querySelectorAll('.kanban-task').length;
            const badge = column.querySelector('.badge');
            if (badge) {
                badge.textContent = String(count);
            }
        });
    };

    const getModeByDueDate = (dueDateValue) => {
        const dueDate = new Date(`${dueDateValue}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const daysLeft = Math.ceil((dueDate - today) / 86400000);
        return daysLeft <= 3 ? 'URGENT' : 'STRATEGIC';
    };

    const priorityScore = (priority) => ({ Optional: 1, Low: 2, Medium: 3, High: 4, Critical: 5 }[priority] || 3);
    const effortScore = (effort) => ({ Low: 1, Medium: 2, High: 3, 'Very High': 4 }[effort] || 2);

    const calculateScore = (taskData) => {
        const dueDate = new Date(`${taskData.due_date}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const daysLeft = Math.ceil((dueDate - today) / 86400000);
        const p = priorityScore(taskData.priority);
        const e = effortScore(taskData.effort || 'Medium');
        const mandays = Math.max(1, Number.parseInt(taskData.mandays || '1', 10));

        if (daysLeft <= 3) {
            const effortPenalty = (e * 8) + Math.min(mandays * 2, 20);
            let urgency = 0;
            if (daysLeft < 0) urgency = 200 + (Math.abs(daysLeft) * 30);
            else if (daysLeft === 0) urgency = 180;
            else if (daysLeft <= 1) urgency = 150;
            else urgency = 120 / (1 + daysLeft);
            return Math.max(0, Math.round((p * 50 + urgency - effortPenalty) * 100) / 100);
        }

        const urgency = 40 / (1 + daysLeft * 0.05);
        const effortWeight = p >= 4 ? 8 : 15;
        const mandaysWeight = p >= 4 ? 1.5 : 3;
        const effortPenalty = (e * effortWeight) + (mandays * mandaysWeight);
        return Math.max(0, Math.round((p * 50 + urgency - effortPenalty) * 100) / 100);
    };

    const scoreClass = (score) => {
        if (score >= 220) return 'score-critical';
        if (score >= 170) return 'score-high';
        if (score >= 110) return 'score-medium';
        return 'score-low';
    };

    const renderDueCountdown = (taskCard) => {
        const dueDateValue = taskCard.dataset.dueDate || '';
        let dueMeta = taskCard.querySelector('.due-meta');

        if (!dueDateValue) {
            if (dueMeta) {
                dueMeta.remove();
            }
            return;
        }

        if (!dueMeta) {
            dueMeta = document.createElement('div');
            dueMeta.className = 'task-meta due-meta';
            const priorityMeta = taskCard.querySelector('.task-meta');
            if (priorityMeta) {
                priorityMeta.insertAdjacentElement('afterend', dueMeta);
            } else {
                taskCard.appendChild(dueMeta);
            }
        }

        const dueDate = new Date(`${dueDateValue}T00:00:00`);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const daysLeft = Math.ceil((dueDate - today) / 86400000);

        let countdownText = '';
        let countdownClass = 'due-safe';
        if (daysLeft < 0) {
            countdownText = `(Overdue by ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'})`;
            countdownClass = 'due-overdue';
        } else if (daysLeft === 0) {
            countdownText = '(Due today)';
            countdownClass = 'due-today';
        } else if (daysLeft <= 3) {
            countdownText = `(${daysLeft} day${daysLeft === 1 ? '' : 's'} left before breach)`;
            countdownClass = 'due-soon';
        } else {
            countdownText = `(${daysLeft} day${daysLeft === 1 ? '' : 's'} left before breach)`;
            countdownClass = 'due-safe';
        }

        dueMeta.textContent = '';
        dueMeta.append('Due: ');
        const dueDateSpan = document.createElement('span');
        dueDateSpan.className = 'due-date-value';
        dueDateSpan.textContent = dueDateValue;
        const dueCountdownSpan = document.createElement('span');
        dueCountdownSpan.className = `due-countdown ${countdownClass}`;
        dueCountdownSpan.textContent = countdownText;
        dueMeta.append(dueDateSpan, dueCountdownSpan);
    };

    const applyTaskDataToCard = (taskCard, data) => {
        taskCard.dataset.taskName = data.task_name;
        taskCard.dataset.description = data.description;
        taskCard.dataset.assignee = data.assignee;
        taskCard.dataset.priority = data.priority;
        taskCard.dataset.dueDate = data.due_date;
        taskCard.dataset.status = data.status;
        taskCard.dataset.mode = data.mode;
        taskCard.dataset.score = String(data.score);

        taskCard.classList.remove('status-backlog', 'status-todo', 'status-in_progress', 'status-done');
        taskCard.classList.add(`status-${data.status}`);

        const title = taskCard.querySelector('.task-title');
        const desc = taskCard.querySelector('.task-desc');
        const modeBadge = taskCard.querySelector('.mode-badge');
        const scorePill = taskCard.querySelector('.score-pill');
        if (title) {
            title.textContent = data.task_name;
        }
        if (desc) {
            if (data.description.trim() === '') {
                desc.remove();
            } else {
                desc.textContent = data.description;
            }
        } else if (data.description.trim() !== '') {
            const newDesc = document.createElement('div');
            newDesc.className = 'task-desc';
            newDesc.textContent = data.description;
            const titleNode = taskCard.querySelector('.task-title');
            if (titleNode) {
                titleNode.insertAdjacentElement('afterend', newDesc);
            }
        }
        if (modeBadge) {
            modeBadge.textContent = `Mode: ${data.mode}`;
            modeBadge.classList.remove('mode-urgent', 'mode-strategic');
            modeBadge.classList.add(data.mode === 'URGENT' ? 'mode-urgent' : 'mode-strategic');
        }
        if (scorePill) {
            const scoreValue = Number.parseFloat(data.score || '0');
            scorePill.textContent = `Score: ${scoreValue}`;
            scorePill.classList.remove('score-low', 'score-medium', 'score-high', 'score-critical');
            scorePill.classList.add(scoreClass(scoreValue));
            scorePill.setAttribute('aria-label', `Task score ${scoreValue}`);
        }
        const priorityMeta = taskCard.querySelector('.task-priority');
        if (priorityMeta) {
            priorityMeta.textContent = `Priority: ${data.priority}`;
        }
        renderDueCountdown(taskCard);
        const assigneeMeta = taskCard.querySelector('.task-assignee');
        if (assigneeMeta) assigneeMeta.textContent = `Assignee: ${data.assignee.trim() === '' ? 'Unassigned' : data.assignee}`;
    };

    const openEditModal = (taskCard) => {
        if (!editModal || !editForm) {
            return;
        }
        editForm.querySelector('#edit_task_id').value = taskCard.dataset.taskId;
        editForm.querySelector('#edit_task_name').value = taskCard.dataset.taskName || '';
        editForm.querySelector('#edit_description').value = taskCard.dataset.description || '';
        editForm.querySelector('#edit_assignee').value = taskCard.dataset.assignee || '';
        editForm.querySelector('#edit_priority').value = taskCard.dataset.priority || 'Medium';
        editForm.querySelector('#edit_due_date').value = taskCard.dataset.dueDate || '';
        editForm.querySelector('#edit_status').value = taskCard.dataset.status || 'todo';
        editForm.dataset.taskId = taskCard.dataset.taskId;
        editModal.show();
    };

    const saveTaskDetails = async (formData) => {
        formData.append('update_task_details', '1');
        const response = await fetch('index.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        if (!response.ok) {
            throw new Error('Save failed');
        }
        return response.json();
    };

    const deleteTask = async (taskId) => {
        const payload = new URLSearchParams();
        payload.append('delete_task_ajax', '1');
        payload.append('task_id', String(taskId));
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        if (!response.ok) {
            throw new Error('Delete failed');
        }
        return response.json();
    };

    const sortColumnCards = (columnBody) => {
        const cards = [...columnBody.querySelectorAll('.kanban-task')];
        cards.sort((a, b) => {
            const modeA = a.dataset.mode === 'URGENT' ? 2 : 1;
            const modeB = b.dataset.mode === 'URGENT' ? 2 : 1;
            if (modeA !== modeB) {
                return modeB - modeA;
            }
            const scoreA = Number.parseFloat(a.dataset.score || '0');
            const scoreB = Number.parseFloat(b.dataset.score || '0');
            return scoreB - scoreA;
        });
        cards.forEach((card) => columnBody.appendChild(card));
    };

    board.querySelectorAll('.kanban-task').forEach((task) => {
        task.addEventListener('dragstart', () => {
            draggingTask = task;
            task.classList.add('dragging');
        });

        task.addEventListener('dragend', () => {
            task.classList.remove('dragging');
            draggingTask = null;
            board.querySelectorAll('.kanban-column').forEach((col) => col.classList.remove('is-over'));
        });

        const editButton = task.querySelector('.edit-task-btn');
        if (editButton) {
            editButton.addEventListener('click', () => openEditModal(task));
        }

        const deleteButton = task.querySelector('.delete-task-btn');
        if (deleteButton) {
            deleteButton.addEventListener('click', async () => {
                const confirmed = window.confirm('Delete this task? This action cannot be undone.');
                if (!confirmed) {
                    return;
                }
                try {
                    await deleteTask(task.dataset.taskId);
                    const parent = task.parentElement;
                    task.remove();
                    if (parent) {
                        sortColumnCards(parent);
                    }
                    updateColumnCounts();
                } catch (error) {
                    alert('Failed to delete task. Please try again.');
                }
            });
        }
    });

    board.querySelectorAll('.kanban-column-body').forEach((columnBody) => {
        columnBody.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (!draggingTask) {
                return;
            }
            const target = getInsertionTarget(columnBody, event.clientY);
            if (!target) {
                columnBody.appendChild(draggingTask);
            } else {
                columnBody.insertBefore(draggingTask, target);
            }
            columnBody.closest('.kanban-column').classList.add('is-over');
        });

        columnBody.addEventListener('dragleave', () => {
            columnBody.closest('.kanban-column').classList.remove('is-over');
        });

        columnBody.addEventListener('drop', async () => {
            if (!draggingTask) {
                return;
            }
            const cards = [...columnBody.querySelectorAll('.kanban-task')];
            const newOrder = cards.findIndex((el) => el.dataset.taskId === draggingTask.dataset.taskId) + 1;
            const newStatus = columnBody.dataset.status;
            const taskId = draggingTask.dataset.taskId;

            try {
                await sendMove(taskId, newStatus, newOrder);
                if (draggingTask) {
                    draggingTask.dataset.status = newStatus;
                    draggingTask.classList.remove('status-backlog', 'status-todo', 'status-in_progress', 'status-done');
                    draggingTask.classList.add(`status-${newStatus}`);
                    sortColumnCards(columnBody);
                }
                updateColumnCounts();
            } catch (error) {
                window.location.reload();
            }
        });
    });

    board.querySelectorAll('.kanban-task').forEach((taskCard) => {
        renderDueCountdown(taskCard);
    });

    document.querySelectorAll('[data-ranked-details]').forEach((details) => {
        details.classList.add('is-open');
    });
    document.querySelectorAll('[data-ranked-card-id]').forEach((card) => {
        card.classList.add('is-expanded');
    });

    const rankedSectionToggle = document.getElementById('rankedSectionToggle');
    const rankedSectionBody = document.getElementById('rankedSectionBody');
    const rankedSectionKey = 'rankedTaskSectionCollapsed';
    const readSectionCollapsed = () => window.sessionStorage.getItem(rankedSectionKey) !== 'false';
    const setSectionCollapsed = (collapsed) => {
        if (!rankedSectionBody || !rankedSectionToggle) {
            return;
        }
        rankedSectionBody.classList.toggle('is-collapsed', collapsed);
        rankedSectionToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        rankedSectionToggle.textContent = collapsed ? 'Expand Section' : 'Collapse Section';
        window.sessionStorage.setItem(rankedSectionKey, collapsed ? 'true' : 'false');
    };

    if (rankedSectionToggle && rankedSectionBody) {
        setSectionCollapsed(readSectionCollapsed());
        rankedSectionToggle.addEventListener('click', () => {
            const isCollapsed = rankedSectionBody.classList.contains('is-collapsed');
            setSectionCollapsed(!isCollapsed ? true : false);
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(editForm);
            const taskId = editForm.dataset.taskId;
            const taskCard = board.querySelector(`.kanban-task[data-task-id="${taskId}"]`);
            if (!taskCard) {
                return;
            }

            const updatedData = {
                task_name: String(formData.get('task_name') || ''),
                description: String(formData.get('description') || ''),
                assignee: String(formData.get('assignee') || ''),
                priority: String(formData.get('priority') || 'Medium'),
                due_date: String(formData.get('due_date') || ''),
                status: String(formData.get('status') || 'todo'),
                effort: taskCard.dataset.effort || 'Medium',
                mandays: taskCard.dataset.mandays || '1'
            };

            updatedData.mode = getModeByDueDate(updatedData.due_date);
            updatedData.score = calculateScore(updatedData);

            try {
                await saveTaskDetails(formData);
                applyTaskDataToCard(taskCard, updatedData);
                const targetColumn = board.querySelector(`.kanban-column-body[data-status="${updatedData.status}"]`);
                if (targetColumn && taskCard.parentElement !== targetColumn) {
                    targetColumn.appendChild(taskCard);
                }
                sortColumnCards(taskCard.parentElement);
                updateColumnCounts();
                editModal.hide();
            } catch (error) {
                alert('Failed to save task changes. Please try again.');
            }
        });
    }
})();
</script>
</body>
</html>
