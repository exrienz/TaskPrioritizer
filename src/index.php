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
            parent_task_id INT NULL,
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
            is_blocking BOOLEAN NOT NULL DEFAULT 0,
            influence_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            priority_overridden_by_subtask BOOLEAN NOT NULL DEFAULT 0,
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

    try {
        $parentColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'parent_task_id'")->fetchAll();
        if (empty($parentColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN parent_task_id INT NULL AFTER user_id");
        }
    } catch (PDOException $e) { error_log("Schema migration failed (parent_task_id): " . $e->getMessage()); }

    try {
        $blockingColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'is_blocking'")->fetchAll();
        if (empty($blockingColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN is_blocking BOOLEAN NOT NULL DEFAULT 0 AFTER sort_order");
        }
    } catch (PDOException $e) { error_log("Schema migration failed (is_blocking): " . $e->getMessage()); }

    try {
        $weightColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'influence_weight'")->fetchAll();
        if (empty($weightColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN influence_weight DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER is_blocking");
        }
    } catch (PDOException $e) { error_log("Schema migration failed (influence_weight): " . $e->getMessage()); }

    try {
        $overrideColumn = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'priority_overridden_by_subtask'")->fetchAll();
        if (empty($overrideColumn)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN priority_overridden_by_subtask BOOLEAN NOT NULL DEFAULT 0 AFTER influence_weight");
        }
    } catch (PDOException $e) { error_log("Schema migration failed (priority_overridden_by_subtask): " . $e->getMessage()); }

    try { $pdo->exec("CREATE INDEX idx_tasks_parent ON tasks(parent_task_id)"); } catch (PDOException $e) { /* Index might already exist */ }

    $requiredTaskColumns = [
        'parent_task_id',
        'is_blocking',
        'influence_weight',
        'priority_overridden_by_subtask'
    ];
    $missingColumns = [];
    foreach ($requiredTaskColumns as $columnName) {
        $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = ?");
        $columnStmt->execute([$columnName]);
        if ((int) $columnStmt->fetchColumn() === 0) {
            $missingColumns[] = $columnName;
        }
    }

    if (!empty($missingColumns)) {
        throw new Exception('Database schema boot check failed. Missing tasks columns: ' . implode(', ', $missingColumns));
    }
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

function normalizePriorityFromScore($score) {
    if ($score >= 5) {
        return 'Critical';
    }
    if ($score >= 4) {
        return 'High';
    }
    if ($score >= 3) {
        return 'Medium';
    }
    if ($score >= 2) {
        return 'Low';
    }
    return 'Optional';
}

function priorityValueForRollup($priority): int {
    $priorityMap = ['Optional' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Critical' => 5];
    return $priorityMap[$priority] ?? 3;
}

function isDebugEnvironment(): bool {
    $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production'));
    return in_array($appEnv, ['local', 'dev', 'development', 'staging', 'test'], true);
}

function makeSafeErrorMessage(string $publicMessage, Throwable $error): string {
    if (isDebugEnvironment()) {
        return $publicMessage . ' Debug: ' . $error->getMessage();
    }

    $ref = substr(bin2hex(random_bytes(8)), 0, 8);
    error_log('TaskPrioritizer error [' . $ref . ']: ' . $error->getMessage());
    return $publicMessage . ' (ref: ' . $ref . ')';
}

function recalculateParentTask(PDO $pdo, int $userId, int $parentTaskId): void {
    $childrenStmt = $pdo->prepare("SELECT id, priority, status, is_blocking, influence_weight, task_name FROM tasks WHERE user_id = ? AND parent_task_id = ?");
    $childrenStmt->execute([$userId, $parentTaskId]);
    $children = $childrenStmt->fetchAll();

    if (!$children) {
        $resetStmt = $pdo->prepare("UPDATE tasks SET priority_overridden_by_subtask = 0 WHERE id = ? AND user_id = ?");
        $resetStmt->execute([$parentTaskId, $userId]);
        return;
    }

    $maxWeightedPriority = 0.0;
    $openChildren = 0;
    $inProgressChildren = 0;
    $hasBlockingOpenChild = false;

    foreach ($children as $child) {
        $childStatus = $child['status'] ?? 'todo';
        $isOpen = $childStatus !== 'done';
        if ($isOpen) {
            $openChildren++;
            if ($childStatus === 'in_progress') {
                $inProgressChildren++;
            }
            if (!empty($child['is_blocking'])) {
                $hasBlockingOpenChild = true;
            }
        }

        $weight = max(0.1, (float) ($child['influence_weight'] ?? 1));
        $weightedPriority = priorityValueForRollup($child['priority']) * $weight;
        if ($weightedPriority > $maxWeightedPriority) {
            $maxWeightedPriority = $weightedPriority;
        }
    }

    $derivedPriority = normalizePriorityFromScore($maxWeightedPriority);
    if ($hasBlockingOpenChild) {
        $derivedPriority = 'Critical';
    }

    $statusStmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ? AND user_id = ?");
    $statusStmt->execute([$parentTaskId, $userId]);
    $currentStatus = $statusStmt->fetchColumn() ?: 'todo';

    $derivedStatus = $currentStatus;
    if ($openChildren === 0) {
        $derivedStatus = 'done';
    } elseif ($inProgressChildren > 0) {
        $derivedStatus = 'in_progress';
    } elseif ($currentStatus === 'done') {
        $derivedStatus = 'todo';
    }

    $updateStmt = $pdo->prepare("UPDATE tasks SET priority = ?, status = ?, in_progress = ?, priority_overridden_by_subtask = 1 WHERE id = ? AND user_id = ?");
    $updateStmt->execute([
        $derivedPriority,
        $derivedStatus,
        $derivedStatus === 'in_progress' ? 1 : 0,
        $parentTaskId,
        $userId
    ]);
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
        $pdo->beginTransaction();

        $parentTaskId = (int) ($_POST['parent_task_id'] ?? 0);
        $parentTaskId = $parentTaskId > 0 ? $parentTaskId : null;
        $isBlocking = isset($_POST['is_blocking']) ? 1 : 0;
        $influenceWeight = max(0.1, min(3.0, (float) ($_POST['influence_weight'] ?? 1)));

        if ($parentTaskId !== null) {
            $parentCheckStmt = $pdo->prepare("SELECT id, due_date FROM tasks WHERE id = ? AND user_id = ?");
            $parentCheckStmt->execute([$parentTaskId, $_SESSION['user_id']]);
            $parentTask = $parentCheckStmt->fetch();
            if (!$parentTask) {
                throw new Exception('Selected parent task was not found.');
            }
            if (!empty($_POST['due_date']) && strtotime($_POST['due_date']) > strtotime($parentTask['due_date'])) {
                throw new Exception('Invalid due date: sub-task end date cannot be later than the parent task end date.');
            }
        }

        $nextOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM tasks WHERE user_id = ? AND status = 'todo'");
        $nextOrderStmt->execute([$_SESSION['user_id']]);
        $nextOrder = (int) $nextOrderStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO tasks (user_id, parent_task_id, task_name, description, assignee, priority, effort, mandays, due_date, in_progress, status, sort_order, is_blocking, influence_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'todo', ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $parentTaskId,
            $_POST['task_name'],
            trim($_POST['description'] ?? ''),
            null,
            $_POST['priority'],
            $_POST['effort'],
            $_POST['mandays'],
            $_POST['due_date'],
            $nextOrder,
            $isBlocking,
            $influenceWeight
        ]);

        if ($parentTaskId !== null) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], $parentTaskId);
        }

        $pdo->commit();

        $success_message = "Task created successfully!";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = str_starts_with($e->getMessage(), 'Invalid due date:')
            ? $e->getMessage()
            : makeSafeErrorMessage("Failed to create task. Please try again.", $e);
    }
}

if (isset($_POST['delete_task']) && $_SESSION['loggedin'] === true) {
    try {
        $pdo->beginTransaction();

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $parentLookupStmt = $pdo->prepare("SELECT parent_task_id FROM tasks WHERE id = ? AND user_id = ?");
        $parentLookupStmt->execute([$taskId, $_SESSION['user_id']]);
        $parentTaskId = $parentLookupStmt->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$taskId, $_SESSION['user_id']]);

        if ($parentTaskId) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $parentTaskId);
        }

        $pdo->commit();

        $success_message = "Task deleted successfully!";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = makeSafeErrorMessage("Failed to delete task. Please try again.", $e);
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
        $pdo->beginTransaction();

        $parentLookupStmt = $pdo->prepare("SELECT parent_task_id FROM tasks WHERE id = ? AND user_id = ?");
        $parentLookupStmt->execute([$taskId, $_SESSION['user_id']]);
        $parentTaskId = $parentLookupStmt->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$taskId, $_SESSION['user_id']]);

        if ($parentTaskId) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $parentTaskId);
        }

        $pdo->commit();

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => makeSafeErrorMessage('Failed to delete task', $e)]);
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

        $taskStmt = $pdo->prepare("SELECT id, status, sort_order, parent_task_id FROM tasks WHERE id = ? AND user_id = ? FOR UPDATE");
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

        if (!empty($task['parent_task_id'])) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $task['parent_task_id']);
        }

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

if (isset($_POST['set_task_status']) && $_SESSION['loggedin'] === true) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $allowedStatuses = ['backlog', 'todo', 'in_progress', 'done'];

    if ($taskId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid status request']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $taskStmt = $pdo->prepare("SELECT id, parent_task_id FROM tasks WHERE id = ? AND user_id = ? FOR UPDATE");
        $taskStmt->execute([$taskId, $_SESSION['user_id']]);
        $task = $taskStmt->fetch();

        if (!$task) {
            $pdo->rollBack();
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Task not found']);
            exit;
        }

        $statusStmt = $pdo->prepare("UPDATE tasks SET status = ?, in_progress = ? WHERE id = ? AND user_id = ?");
        $statusStmt->execute([
            $newStatus,
            $newStatus === 'in_progress' ? 1 : 0,
            $taskId,
            $_SESSION['user_id']
        ]);

        if (!empty($task['parent_task_id'])) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $task['parent_task_id']);
        }

        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => makeSafeErrorMessage('Failed to update task status', $e)]);
        exit;
    }
}

if (isset($_POST['update_task_details']) && $_SESSION['loggedin'] === true) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $title = trim($_POST['task_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $effort = $_POST['effort'] ?? 'Medium';
    $mandays = max(1, (int) ($_POST['mandays'] ?? 1));
    $dueDate = $_POST['due_date'] ?? '';
    $status = $_POST['status'] ?? 'todo';
    $parentTaskId = (int) ($_POST['parent_task_id'] ?? 0);
    $parentTaskId = $parentTaskId > 0 ? $parentTaskId : null;
    $isBlocking = isset($_POST['is_blocking']) ? 1 : 0;
    $influenceWeight = max(0.1, min(3.0, (float) ($_POST['influence_weight'] ?? 1)));

    $allowedStatuses = ['backlog', 'todo', 'in_progress', 'done'];
    $allowedPriorities = ['Optional', 'Low', 'Medium', 'High', 'Critical'];
    $allowedEfforts = ['Low', 'Medium', 'High', 'Very High'];

    if ($taskId <= 0 || $title === '' || !in_array($status, $allowedStatuses, true) || !in_array($priority, $allowedPriorities, true) || !in_array($effort, $allowedEfforts, true) || $dueDate === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid task payload']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($parentTaskId !== null && $parentTaskId === $taskId) {
            throw new Exception('A task cannot be its own parent.');
        }

        $prevParentStmt = $pdo->prepare("SELECT parent_task_id FROM tasks WHERE id = ? AND user_id = ?");
        $prevParentStmt->execute([$taskId, $_SESSION['user_id']]);
        $previousParentTaskId = $prevParentStmt->fetchColumn();

        if ($parentTaskId !== null) {
            $visited = [];
            $cursorParentId = $parentTaskId;
            $parentChainStmt = $pdo->prepare("SELECT parent_task_id FROM tasks WHERE id = ? AND user_id = ?");
            while ($cursorParentId !== null) {
                if ($cursorParentId === $taskId) {
                    throw new Exception('This parent selection would create a cycle.');
                }
                if (isset($visited[$cursorParentId])) {
                    break;
                }
                $visited[$cursorParentId] = true;
                $parentChainStmt->execute([$cursorParentId, $_SESSION['user_id']]);
                $nextParent = $parentChainStmt->fetchColumn();
                $cursorParentId = $nextParent ? (int) $nextParent : null;
            }

            $parentDueStmt = $pdo->prepare("SELECT due_date FROM tasks WHERE id = ? AND user_id = ?");
            $parentDueStmt->execute([$parentTaskId, $_SESSION['user_id']]);
            $parentDueDate = $parentDueStmt->fetchColumn();
            if (!$parentDueDate) {
                throw new Exception('Selected parent task was not found.');
            }
            if (strtotime($dueDate) > strtotime($parentDueDate)) {
                throw new Exception('Invalid due date: sub-task end date cannot be later than the parent task end date.');
            }
        }

        $stmt = $pdo->prepare("UPDATE tasks SET parent_task_id = ?, task_name = ?, description = ?, assignee = ?, priority = ?, effort = ?, mandays = ?, due_date = ?, status = ?, in_progress = ?, is_blocking = ?, influence_weight = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([
            $parentTaskId,
            $title,
            $description !== '' ? $description : null,
            null,
            $priority,
            $effort,
            $mandays,
            $dueDate,
            $status,
            $status === 'in_progress' ? 1 : 0,
            $isBlocking,
            $influenceWeight,
            $taskId,
            $_SESSION['user_id']
        ]);

        if ($previousParentTaskId) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $previousParentTaskId);
        }
        if ($parentTaskId) {
            recalculateParentTask($pdo, (int) $_SESSION['user_id'], (int) $parentTaskId);
        }

        $pdo->commit();

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        $message = str_starts_with($e->getMessage(), 'Invalid due date:')
            ? $e->getMessage()
            : makeSafeErrorMessage('Failed to update task', $e);
        echo json_encode(['ok' => false, 'message' => $message]);
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

function orderTasksWithSubtasks(array $tasks): array {
    $byId = [];
    $childrenByParent = [];
    foreach ($tasks as $task) {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0) {
            continue;
        }
        $byId[$taskId] = $task;
        $parentId = (int) ($task['parent_task_id'] ?? 0);
        if ($parentId > 0) {
            $childrenByParent[$parentId][] = $task;
        }
    }

    $roots = [];
    $orphans = [];
    foreach ($tasks as $task) {
        $taskId = (int) ($task['id'] ?? 0);
        $parentId = (int) ($task['parent_task_id'] ?? 0);
        if ($parentId > 0 && !isset($byId[$parentId])) {
            $orphans[] = $task;
        } elseif ($parentId <= 0) {
            $roots[] = $task;
        }
    }

    $ordered = [];
    foreach ($roots as $root) {
        $ordered[] = $root;
        $rootId = (int) $root['id'];
        if (!empty($childrenByParent[$rootId])) {
            foreach ($childrenByParent[$rootId] as $child) {
                $ordered[] = $child;
            }
        }
    }

    foreach ($orphans as $orphan) {
        $ordered[] = $orphan;
    }

    return $ordered;
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
        :root {
            --page-bg: #f7f8f9;
            --surface: #ffffff;
            --surface-muted: #f1f2f4;
            --ink: #172b4d;
            --muted: #626f86;
            --line: #dfe1e6;
            --accent: #0c66e4;
            --accent-strong: #0052cc;
            --accent-soft: #e9f2ff;
            --amber: #a54800;
            --red: #ae2e24;
            --green: #216e4e;
            --shadow: 0 8px 20px rgba(9, 30, 66, 0.12);
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { max-width: 100%; overflow-x: hidden; }
        body {
            background: var(--page-bg);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .app-shell { width: 100%; max-width: 1680px; margin: 0 auto; padding: 0 18px 20px; overflow-x: hidden; }
        .app-titlebar { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin: 0 0 20px; padding: 12px 4px; background: var(--surface); border-bottom: 1px solid var(--line); box-shadow: 0 1px 2px rgba(9, 30, 66, 0.08); }
        .brand-lockup { display: flex; align-items: center; gap: 13px; min-width: 0; }
        .brand-mark { width: 34px; height: 34px; border-radius: 6px; display: grid; place-items: center; background: var(--accent); color: #fff; font-weight: 800; box-shadow: inset 0 -1px 0 rgba(0,0,0,0.14); }
        .brand-copy h1, .brand-copy h2 { font-size: clamp(1.25rem, 2vw, 1.75rem); line-height: 1.12; margin: 0; letter-spacing: 0; }
        .brand-copy p { color: var(--muted); margin: 0.2rem 0 0; font-size: 0.92rem; }
        .top-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 3px; box-shadow: 0 1px 2px rgba(9, 30, 66, 0.08); }
        .panel-pad { padding: 16px; }
        .section-kicker { color: var(--muted); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.25rem; }
        .section-title { font-size: 1.08rem; font-weight: 800; margin: 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; min-width: 0; }
        .metric-card { padding: 12px 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 3px; box-shadow: 0 1px 2px rgba(9, 30, 66, 0.08); }
        .metric-card span { display: block; color: var(--muted); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
        .metric-card strong { display: block; margin-top: 2px; font-size: 1.55rem; line-height: 1; }
        .workspace-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: start; min-width: 0; }
        .task-composer { position: static; }
        .task-composer form { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px; align-items: end; min-width: 0; }
        .task-composer form > * { min-width: 0; }
        .task-composer .composer-title { grid-column: 1 / -1; }
        .task-composer .composer-name { grid-column: span 4; }
        .task-composer .composer-date { grid-column: span 2; }
        .task-composer .composer-short { grid-column: span 2; }
        .task-composer details { grid-column: 1 / -1; }
        .task-composer .composer-submit { grid-column: 1 / -1; }
        .form-label { color: #405047; font-size: 0.78rem; font-weight: 800; }
        .form-control, .form-select { border-color: #c1c7d0; border-radius: 3px; min-height: 40px; background-color: #fafbfc; }
        textarea.form-control { min-height: 88px; }
        .form-control:focus, .form-select:focus { border-color: rgba(23, 107, 91, 0.55); box-shadow: 0 0 0 0.2rem rgba(23, 107, 91, 0.12); }
        .btn { border-radius: 3px; font-weight: 750; }
        .btn-primary, .btn-success { --bs-btn-bg: var(--accent); --bs-btn-border-color: var(--accent); --bs-btn-hover-bg: var(--accent-strong); --bs-btn-hover-border-color: var(--accent-strong); }
        .btn-danger { --bs-btn-bg: #a83b2f; --bs-btn-border-color: #a83b2f; --bs-btn-hover-bg: #842b22; --bs-btn-hover-border-color: #842b22; }
        .btn-outline-primary { --bs-btn-color: var(--accent); --bs-btn-border-color: rgba(23, 107, 91, 0.42); --bs-btn-hover-bg: var(--accent); --bs-btn-hover-border-color: var(--accent); }
        .btn-outline-secondary { --bs-btn-color: #405047; --bs-btn-border-color: #c8d2c7; --bs-btn-hover-bg: #eef3ec; --bs-btn-hover-color: #1d2a22; --bs-btn-hover-border-color: #b8c5b6; }
        .auth-container { max-width: 440px; margin: 28px auto 0; }
        .auth-container .card, .modal-content { border: 1px solid var(--line); border-radius: 3px; box-shadow: var(--shadow); }
        .nav-tabs { border-bottom-color: var(--line); }
        .nav-tabs .nav-link { cursor: pointer; color: var(--muted); font-weight: 800; border-radius: 8px 8px 0 0; }
        .nav-tabs .nav-link.active { color: var(--accent); }
        .board-toolbar { display: flex; align-items: end; justify-content: space-between; gap: 14px; margin-bottom: 12px; flex-wrap: wrap; min-width: 0; }
        .board-toolbar .search-wrap { width: min(100%, 430px); min-width: 0; }
        .kanban-board { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 10px; min-width: 0; }
        .kanban-column { background: #f1f2f4; border: 1px solid #dcdfe4; border-radius: 3px; min-height: 360px; box-shadow: none; overflow: hidden; min-width: 0; }
        .kanban-column-header { padding: 10px 12px; border-bottom: 1px solid #dcdfe4; font-weight: 850; display: flex; justify-content: space-between; align-items: center; background: #f7f8f9; color: #44546f; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .kanban-column-header .badge { background: #dfe1e6 !important; color: #172b4d; border: 1px solid #c1c7d0; }
        .kanban-column-body { padding: 12px; min-height: 280px; }
        .kanban-task { position: relative; border: 1px solid transparent; border-radius: 3px; background: var(--surface); padding: 12px; margin-bottom: 8px; cursor: grab; box-shadow: 0 1px 2px rgba(9, 30, 66, 0.22); transition: background-color 120ms ease, box-shadow 120ms ease, border-color 120ms ease; }
        .kanban-task:hover { transform: none; border-color: #85b8ff; box-shadow: 0 3px 6px rgba(9, 30, 66, 0.18); }
        .kanban-task.dragging { opacity: 0.5; }
        .kanban-column.is-over { outline: 2px solid rgba(23, 107, 91, 0.38); outline-offset: 2px; }
        .task-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 0.5rem; }
        .task-title-wrap { min-width: 0; }
        .task-title { font-size: 0.94rem; font-weight: 750; margin-bottom: 0.32rem; color: var(--ink); overflow-wrap: anywhere; }
        .task-status-chip { display: inline-flex; align-items: center; border-radius: 3px; border: 0; background: #f1f2f4; color: #44546f; font-size: 0.65rem; font-weight: 850; padding: 0.18rem 0.42rem; white-space: nowrap; text-transform: uppercase; }
        .task-status-chip.status-done-chip { color: #216e4e; background: #dcfff1; }
        .task-meta { font-size: 0.8rem; color: var(--muted); margin-bottom: 0.24rem; }
        .task-meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.25rem 0.6rem; margin-top: 0.45rem; }
        .task-meta-grid .task-meta { margin-bottom: 0; }
        .due-meta { font-weight: 750; }
        .due-countdown { display: inline-block; margin-left: 0.25rem; font-weight: 850; }
        .due-meta .due-date-value { font-weight: 850; }
        .due-safe { color: var(--green); }
        .due-overdue { color: var(--red); }
        .task-desc { font-size: 0.84rem; color: #35443b; margin: 0.5rem 0 0.6rem; line-height: 1.45; white-space: pre-line; overflow-wrap: anywhere; background: #f7f8f9; border: 1px solid #dfe1e6; border-left: 3px solid #8590a2; border-radius: 3px; padding: 0.58rem 0.68rem; }
        .task-actions { display: flex; gap: 0.38rem; flex-wrap: wrap; justify-content: flex-end; margin-top: 0.7rem; padding-top: 0.65rem; border-top: 1px solid #f1f2f4; }
        .task-actions .btn { min-height: 2.1rem; }
        .subtask-status-btn.is-complete { --bs-btn-color: #1f6b45; --bs-btn-border-color: #9cccaa; --bs-btn-hover-bg: #1f6b45; --bs-btn-hover-border-color: #1f6b45; }
        .kanban-task.is-complete .task-title { color: #637068; text-decoration: line-through; text-decoration-thickness: 2px; text-decoration-color: #aab8aa; }
        .kanban-task.is-complete { background: #fbfdf9; }
        .subtask-card { margin-left: 0.85rem; border-left: 3px solid #579dff; background: #ffffff; }
        .subtask-chip { font-size: 0.65rem; font-weight: 850; color: #0c66e4; background: #e9f2ff; border: 0; border-radius: 3px; padding: 0.12rem 0.42rem; display: inline-block; margin-bottom: 0.4rem; text-transform: uppercase; }
        .task-signals { display: flex; align-items: center; justify-content: space-between; gap: 0.45rem; margin: 0.2rem 0 0.6rem; flex-wrap: wrap; }
        .mode-badge, .score-pill { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.7rem; font-weight: 850; border-radius: 999px; padding: 0.22rem 0.58rem; border: 1px solid transparent; }
        .mode-urgent { background: #ffebe6; border-color: #ffd2cc; color: #ae2e24; }
        .mode-strategic { background: #e9f2ff; border-color: #cce0ff; color: #0c66e4; }
        .score-low { background: #f1f2f4; border-color: #dfe1e6; color: #44546f; }
        .score-medium { background: #e9f2ff; border-color: #cce0ff; color: #0c66e4; }
        .score-high { background: #fff5e8; border-color: #edc28c; color: #81500e; }
        .score-critical { background: #fff0ee; border-color: #efb1aa; color: #8b2117; }
        .status-backlog { border-left: 3px solid #8590a2; }
        .status-todo { border-left: 3px solid #0c66e4; }
        .status-in_progress { border-left: 3px solid #f5cd47; }
        .status-done { border-left: 3px solid #22a06b; }
        .kanban-task .btn { --bs-btn-padding-y: .3rem; --bs-btn-padding-x: .55rem; --bs-btn-font-size: .78rem; }
        .ranked-card { border: 1px solid var(--line); border-radius: 3px; box-shadow: 0 1px 2px rgba(9, 30, 66, 0.10); margin-bottom: 0; }
        .ranked-card.is-expanded { border-color: #b6c8b1; box-shadow: 0 14px 34px rgba(35, 47, 38, 0.11); }
        .ranked-summary { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.6rem; }
        .ranked-signals { display: flex; gap: 0.45rem; flex-wrap: wrap; }
        .ranked-details { max-height: 0; overflow: hidden; opacity: 0; transform: translateY(-4px); transition: max-height 220ms ease, opacity 180ms ease, transform 180ms ease, margin-top 180ms ease; margin-top: 0; }
        .ranked-details.is-open { max-height: 900px; opacity: 1; transform: translateY(0); margin-top: 0.7rem; }
        .ranked-toggle { min-width: 88px; display: none; }
        .ranked-section-head { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; margin: 22px 0 12px; }
        .ranked-section-body { max-height: 2200px; overflow: hidden; opacity: 1; transition: max-height 260ms ease, opacity 180ms ease; }
        .ranked-section-body.is-collapsed { max-height: 0; opacity: 0; }
        .timeline-section { margin-top: 18px; }
        .timeline-legend { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; color: var(--muted); font-size: 0.78rem; margin-top: 0.45rem; }
        .timeline-legend-item { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; }
        .timeline-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
        .timeline-swatch.todo { background: #0c66e4; }
        .timeline-swatch.progress { background: #b38600; }
        .timeline-swatch.done { background: #22a06b; }
        .timeline-swatch.subtask { background: #f4f8ff; border: 1px solid #85b8ff; }
        .timeline-shell { overflow-x: auto; overflow-y: hidden; border: 1px solid var(--line); border-radius: 3px; background: var(--surface); box-shadow: 0 1px 2px rgba(9, 30, 66, 0.08); max-width: 100%; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; scrollbar-gutter: stable; }
        .timeline-shell::-webkit-scrollbar { height: 10px; }
        .timeline-shell::-webkit-scrollbar-track { background: #f1f2f4; }
        .timeline-shell::-webkit-scrollbar-thumb { background: #c1c7d0; border-radius: 999px; border: 2px solid #f1f2f4; }
        .timeline-grid { width: max-content; min-width: 100%; }
        .timeline-row { display: grid; align-items: stretch; position: relative; }
        .timeline-cell, .timeline-label, .timeline-day { border-bottom: 1px solid #f1f2f4; min-height: 42px; }
        .timeline-label { position: sticky; left: 0; z-index: 2; background: var(--surface); padding: 10px 12px; border-right: 1px solid var(--line); font-weight: 750; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .timeline-label-main { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .timeline-description { color: var(--muted); font-size: 0.72rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .timeline-row.is-subtask .timeline-label { background: #f4f8ff; padding-left: 28px; }
        .timeline-row.is-subtask .timeline-row-bg { background-color: #f4f8ff; }
        .timeline-day { padding: 8px 2px; text-align: center; color: var(--muted); font-size: clamp(0.5rem, 0.7vw, 0.72rem); font-weight: 800; background: #f7f8f9; border-right: 1px solid #ebecf0; overflow: hidden; white-space: nowrap; text-overflow: clip; }
        .timeline-row-bg { grid-column: 2 / -1; grid-row: 1; background-image: linear-gradient(to right, transparent calc(100% - 1px), #ebecf0 calc(100% - 1px)); background-size: 32px 100%; border-bottom: 1px solid #f1f2f4; }
        .timeline-bar { grid-row: 1; align-self: center; height: 24px; border-radius: 3px; padding: 3px 8px; color: #fff; font-size: 0.72rem; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; box-shadow: 0 1px 2px rgba(9, 30, 66, 0.22); z-index: 1; }
        .timeline-bar.status-backlog { background: #8590a2; border-left: 0; }
        .timeline-bar.status-todo { background: #0c66e4; border-left: 0; }
        .timeline-bar.status-in_progress { background: #b38600; border-left: 0; }
        .timeline-bar.status-done { background: #22a06b; border-left: 0; }
        .timeline-empty { padding: 16px; color: var(--muted); }
        footer { color: var(--muted); font-size: 0.85rem; }
        footer a { color: var(--accent); font-weight: 750; text-decoration: none; }

        @media (min-width: 768px) {
            .summary-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .kanban-board { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .kanban-board { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 1099px) {
            .workspace-grid { grid-template-columns: 1fr; }
            .task-composer { position: static; }
            .task-composer form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .task-composer .composer-name,
            .task-composer .composer-date,
            .task-composer .composer-short { grid-column: span 1; }
        }
        @media (max-width: 640px) {
            .app-shell { width: min(100% - 20px, 1540px); padding-top: 16px; }
            .app-titlebar { align-items: flex-start; flex-direction: column; }
            .top-actions { justify-content: flex-start; width: 100%; }
            .top-actions form, .top-actions .btn { width: 100%; }
            .brand-mark { width: 38px; height: 38px; }
            .task-composer form { grid-template-columns: 1fr; }
            .task-composer .composer-name,
            .task-composer .composer-date,
            .task-composer .composer-short { grid-column: 1 / -1; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="app-titlebar">
        <div class="brand-lockup">
            <div class="brand-mark">TP</div>
            <div class="brand-copy">
                <h1>Task Prioritizer</h1>
                <p>Plan high-impact work, surface blockers, and keep execution moving.</p>
            </div>
        </div>
    </div>
    
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
<?php
    $totalTaskCount = count($tasks);
    $doneTaskCount = count(array_filter($tasks, fn($task) => ($task['status'] ?? '') === 'done'));
    $activeTaskCount = max(0, $totalTaskCount - $doneTaskCount);
    $criticalTaskCount = count(array_filter($tasks, fn($task) => ($task['priority'] ?? '') === 'Critical'));
    $subtaskCount = count(array_filter($tasks, fn($task) => !empty($task['parent_task_id'])));
?>
<div class="app-titlebar">
    <div class="brand-copy">
        <h2>Welcome, <?= htmlspecialchars($_SESSION['username']) ?></h2>
        <p><?= $activeTaskCount ?> active task<?= $activeTaskCount === 1 ? '' : 's' ?> across your board.</p>
    </div>
    <div class="top-actions">
        <form method="POST">
            <button name="logout" class="btn btn-danger">Logout</button>
        </form>
    </div>
</div>

<div class="summary-grid">
    <div class="metric-card"><span>Total Tasks</span><strong><?= $totalTaskCount ?></strong></div>
    <div class="metric-card"><span>Active</span><strong><?= $activeTaskCount ?></strong></div>
    <div class="metric-card"><span>Critical</span><strong><?= $criticalTaskCount ?></strong></div>
    <div class="metric-card"><span>Sub-tasks</span><strong><?= $subtaskCount ?></strong></div>
</div>

<div class="workspace-grid">
    <aside class="panel panel-pad task-composer">
        <form method="POST">
            <div class="composer-title">
                <div class="section-kicker">Create</div>
                <h3 class="section-title mb-0">New Task</h3>
            </div>
            <div class="composer-name">
                <label class="form-label mb-1" for="create_task_name">Task Name</label>
                <input id="create_task_name" type="text" name="task_name" class="form-control" placeholder="Task Name" required>
            </div>
            <div class="composer-date">
                <label class="form-label mb-1" for="create_due_date">Due Date</label>
                <input id="create_due_date" type="date" name="due_date" class="form-control" required>
            </div>
            <div class="composer-short">
                <label class="form-label mb-1" for="create_priority">Priority</label>
                <select id="create_priority" name="priority" class="form-select">
                    <option>Medium</option><option>High</option><option>Critical</option><option>Low</option><option>Optional</option>
                </select>
            </div>
            <div class="composer-short">
                <label class="form-label mb-1" for="create_effort">Effort</label>
                <select id="create_effort" name="effort" class="form-select">
                    <option>Medium</option><option>Low</option><option>High</option><option>Very High</option>
                </select>
            </div>
            <div class="composer-short">
                <label class="form-label mb-1" for="create_mandays">Mandays</label>
                <input id="create_mandays" type="number" min="1" name="mandays" class="form-control" placeholder="Mandays" value="1" required>
            </div>
            <details class="mb-3">
                <summary class="small fw-semibold mb-2">Advanced Options</summary>
                <textarea name="description" class="form-control mb-2" placeholder="Description (optional)" rows="2"></textarea>
                <select name="parent_task_id" class="form-select mb-2">
                    <option value="">No Parent (Top-level task)</option>
                    <?php foreach ($tasks as $existingTask): ?>
                        <option value="<?= (int) $existingTask['id'] ?>"><?= htmlspecialchars($existingTask['task_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <input type="number" min="0.1" max="3" step="0.1" name="influence_weight" class="form-control" value="1" placeholder="Influence Weight">
                    </div>
                    <div class="col-6 d-flex align-items-center">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_blocking" id="is_blocking">
                            <label class="form-check-label" for="is_blocking">Blocking sub-task</label>
                        </div>
                    </div>
                </div>
                <div class="alert alert-light border small mb-2" role="note">
                    Parent rollup rule: highest weighted open sub-task priority sets parent priority; any open blocking sub-task sets parent to Critical; parent becomes Done when all sub-tasks are Done.
                </div>
            </details>
            <button type="submit" name="create_task" class="btn btn-primary composer-submit">Create Task</button>
        </form>
    </aside>
    <main>
<?php
    $kanbanStatuses = ['backlog', 'todo', 'in_progress', 'done'];
    $kanbanLabels = ['backlog' => 'Backlog', 'todo' => 'To Do', 'in_progress' => 'In Progress', 'done' => 'Done'];
    $tasksByStatus = ['backlog' => [], 'todo' => [], 'in_progress' => [], 'done' => []];
    $taskStatusById = [];
    foreach ($tasks as $task) {
        $taskStatusById[(int) $task['id']] = $task['status'] ?? (empty($task['in_progress']) ? 'todo' : 'in_progress');
    }
    foreach ($tasks as $task) {
        $status = $task['status'] ?? (empty($task['in_progress']) ? 'todo' : 'in_progress');
        $parentId = (int) ($task['parent_task_id'] ?? 0);
        if ($parentId > 0 && isset($taskStatusById[$parentId])) {
            $status = $taskStatusById[$parentId];
        }
        if (!isset($tasksByStatus[$status])) {
            $status = 'todo';
        }
        $task['display_status'] = $status;
        $task['score'] = calculateTaskScore($task);
        $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $task['mode'] = getTaskMode($daysLeft);
        $task['mode_weight'] = $task['mode'] === 'URGENT' ? 2 : 1;
        $tasksByStatus[$status][] = $task;
    }
    $subtaskCountsByParent = [];
    foreach ($tasks as $task) {
        $parentId = (int) ($task['parent_task_id'] ?? 0);
        if ($parentId > 0) {
            $subtaskCountsByParent[$parentId] = ($subtaskCountsByParent[$parentId] ?? 0) + 1;
        }
    }

    foreach ($kanbanStatuses as $status) {
        usort($tasksByStatus[$status], fn($a, $b) => ($b['mode_weight'] <=> $a['mode_weight']) ?: ($b['score'] <=> $a['score']));
        $tasksByStatus[$status] = orderTasksWithSubtasks($tasksByStatus[$status]);
    }

    $timelineRows = [];
    $timelineStart = null;
    $timelineEnd = null;
    foreach ($tasks as $task) {
        if (empty($task['due_date'])) {
            continue;
        }
        try {
            $dueDate = new DateTimeImmutable($task['due_date']);
            $durationDays = max(1, (int) ($task['mandays'] ?? 1));
            $startDate = $dueDate->modify('-' . ($durationDays - 1) . ' days');
            $timelineStart = $timelineStart === null || $startDate < $timelineStart ? $startDate : $timelineStart;
            $timelineEnd = $timelineEnd === null || $dueDate > $timelineEnd ? $dueDate : $timelineEnd;
            $timelineRows[] = [
                'id' => (int) $task['id'],
                'name' => $task['task_name'],
                'status' => $task['status'] ?? (empty($task['in_progress']) ? 'todo' : 'in_progress'),
                'priority' => $task['priority'],
                'description' => $task['description'] ?? '',
                'parent_task_id' => (int) ($task['parent_task_id'] ?? 0),
                'start' => $startDate,
                'end' => $dueDate,
                'duration' => $durationDays,
                'is_subtask' => !empty($task['parent_task_id'])
            ];
        } catch (Exception $e) {
            continue;
        }
    }
    if ($timelineStart === null) {
        $timelineStart = new DateTimeImmutable(date('Y-m-d'));
        $timelineEnd = $timelineStart;
    }
    usort($timelineRows, fn($a, $b) => ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
    $timelineTotalDays = max(1, (int) $timelineStart->diff($timelineEnd)->format('%a') + 1);
    $timelineScale = 'day';
    if ($timelineTotalDays > 180) {
        $timelineScale = 'month';
    } elseif ($timelineTotalDays > 45) {
        $timelineScale = 'week';
    }

    $timelineBuckets = [];
    $bucketCursor = $timelineStart;
    while ($bucketCursor <= $timelineEnd) {
        if ($timelineScale === 'month') {
            $bucketEnd = $bucketCursor->modify('last day of this month');
            $bucketLabel = $bucketCursor->format('M Y');
        } elseif ($timelineScale === 'week') {
            $bucketEnd = $bucketCursor->modify('+6 days');
            $bucketLabel = $bucketCursor->format('M j');
        } else {
            $bucketEnd = $bucketCursor;
            $bucketLabel = $bucketCursor->format('M j');
        }
        if ($bucketEnd > $timelineEnd) {
            $bucketEnd = $timelineEnd;
        }
        $timelineBuckets[] = [
            'start' => $bucketCursor,
            'end' => $bucketEnd,
            'label' => $bucketLabel
        ];
        $bucketCursor = $bucketEnd->modify('+1 day');
    }
    $timelineBucketCount = max(1, count($timelineBuckets));

    foreach ($timelineRows as $index => $row) {
        $startBucket = null;
        $endBucket = null;
        foreach ($timelineBuckets as $bucketIndex => $bucket) {
            if ($row['end'] >= $bucket['start'] && $row['start'] <= $bucket['end']) {
                $startBucket = $startBucket ?? $bucketIndex;
                $endBucket = $bucketIndex;
            }
        }
        $timelineRows[$index]['bucket_start'] = $startBucket ?? 0;
        $timelineRows[$index]['bucket_end'] = $endBucket ?? $startBucket ?? 0;
    }

    $timelineRowsById = [];
    $timelineChildrenByParent = [];
    foreach ($timelineRows as $row) {
        $timelineRowsById[(int) $row['id']] = $row;
        if ((int) $row['parent_task_id'] > 0) {
            $timelineChildrenByParent[(int) $row['parent_task_id']][] = $row;
        }
    }
    foreach ($timelineChildrenByParent as $parentId => $children) {
        usort($children, fn($a, $b) => ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));
        $timelineChildrenByParent[$parentId] = $children;
    }

    $orderedTimelineRows = [];
    $seenTimelineRows = [];
    foreach ($timelineRows as $row) {
        $rowId = (int) $row['id'];
        $parentId = (int) $row['parent_task_id'];
        if ($parentId > 0 && isset($timelineRowsById[$parentId])) {
            continue;
        }
        if (isset($seenTimelineRows[$rowId])) {
            continue;
        }
        $orderedTimelineRows[] = $row;
        $seenTimelineRows[$rowId] = true;
        foreach ($timelineChildrenByParent[$rowId] ?? [] as $childRow) {
            $orderedTimelineRows[] = $childRow;
            $seenTimelineRows[(int) $childRow['id']] = true;
        }
    }
    foreach ($timelineRows as $row) {
        if (!isset($seenTimelineRows[(int) $row['id']])) {
            $orderedTimelineRows[] = $row;
        }
    }
    $timelineRows = $orderedTimelineRows;
?>

<div class="board-toolbar">
    <div>
        <div class="section-kicker">Board</div>
        <h3 class="section-title">Kanban Board</h3>
    </div>
    <div class="search-wrap">
        <input type="search" id="taskSearch" class="form-control" placeholder="Search tasks by name or description">
    </div>
</div>
<div class="kanban-board mb-4" id="kanbanBoard">
    <?php foreach ($kanbanStatuses as $status): ?>
        <div class="kanban-column" data-status="<?= $status ?>">
            <div class="kanban-column-header">
                <span><?= $kanbanLabels[$status] ?></span>
                <span class="badge bg-secondary"><?= count($tasksByStatus[$status]) ?></span>
            </div>
            <div class="kanban-column-body" data-status="<?= $status ?>">
                <?php if (empty($tasksByStatus[$status])): ?>
                    <div class="small text-muted p-2">No tasks here yet.</div>
                <?php endif; ?>
                <?php foreach ($tasksByStatus[$status] as $task): ?>
                    <?php
                        $scoreClass = 'score-low';
                        $isSubtask = !empty($task['parent_task_id']);
                        $actualStatus = $task['status'] ?? (empty($task['in_progress']) ? 'todo' : 'in_progress');
                        $displayStatus = $task['display_status'] ?? $status;
                        $isComplete = $actualStatus === 'done';
                        if ($task['score'] >= 220) {
                            $scoreClass = 'score-critical';
                        } elseif ($task['score'] >= 170) {
                            $scoreClass = 'score-high';
                        } elseif ($task['score'] >= 110) {
                            $scoreClass = 'score-medium';
                        }
                        $modeClass = $task['mode'] === 'URGENT' ? 'mode-urgent' : 'mode-strategic';
                    ?>
                    <div class="kanban-task status-<?= htmlspecialchars($actualStatus) ?><?= $isSubtask ? ' subtask-card' : '' ?><?= $isComplete ? ' is-complete' : '' ?>" draggable="true" data-task-id="<?= $task['id'] ?>" data-task-name="<?= htmlspecialchars($task['task_name']) ?>" data-description="<?= htmlspecialchars($task['description'] ?? '') ?>" data-priority="<?= htmlspecialchars($task['priority']) ?>" data-due-date="<?= htmlspecialchars($task['due_date']) ?>" data-status="<?= htmlspecialchars($actualStatus) ?>" data-display-status="<?= htmlspecialchars($displayStatus) ?>" data-mode="<?= htmlspecialchars($task['mode']) ?>" data-score="<?= htmlspecialchars((string) $task['score']) ?>" data-effort="<?= htmlspecialchars($task['effort']) ?>" data-mandays="<?= htmlspecialchars((string) $task['mandays']) ?>" data-parent-task-id="<?= htmlspecialchars((string) ($task['parent_task_id'] ?? '')) ?>" data-is-blocking="<?= !empty($task['is_blocking']) ? '1' : '0' ?>" data-influence-weight="<?= htmlspecialchars((string) ($task['influence_weight'] ?? '1')) ?>" data-has-subtasks="<?= !empty($subtaskCountsByParent[(int) $task['id']]) ? '1' : '0' ?>">
                        <div class="task-card-head">
                            <div class="task-title-wrap">
                                <?php if ($isSubtask): ?>
                                    <span class="subtask-chip">Sub-task</span>
                                <?php endif; ?>
                                <div class="task-title"><?= htmlspecialchars($task['task_name']) ?></div>
                            </div>
                            <span class="task-status-chip <?= $isComplete ? 'status-done-chip' : '' ?>"><?= htmlspecialchars($isComplete ? 'Done' : $kanbanLabels[$actualStatus] ?? $actualStatus) ?></span>
                        </div>
                        <?php if (!$isSubtask && !empty($subtaskCountsByParent[(int) $task['id']])): ?>
                            <div class="mb-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary subtask-toggle-btn" data-subtask-toggle aria-expanded="false">
                                    Show Sub-tasks (<?= (int) $subtaskCountsByParent[(int) $task['id']] ?>)
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="task-signals">
                            <span class="mode-badge <?= $modeClass ?>">Mode: <?= htmlspecialchars($task['mode']) ?></span>
                            <span class="score-pill <?= $scoreClass ?>" aria-label="Task score <?= htmlspecialchars((string) $task['score']) ?>">Score: <?= $task['score'] ?></span>
                        </div>
                        <?php if (!empty($task['description'])): ?>
                            <div class="task-desc"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                        <?php endif; ?>
                        <div class="task-meta-grid">
                            <div class="task-meta task-priority">Priority: <?= htmlspecialchars($task['priority']) ?></div>
                            <?php if (!empty($task['due_date'])): ?>
                                <div class="task-meta due-meta">Due: <span class="due-date-value"><?= htmlspecialchars($task['due_date']) ?></span><span class="due-countdown"></span></div>
                            <?php endif; ?>
                            <?php if (!empty($task['parent_task_id'])): ?>
                                <div class="task-meta">Parent: #<?= (int) $task['parent_task_id'] ?></div>
                                <div class="task-meta">Weight: <?= htmlspecialchars((string) ($task['influence_weight'] ?? '1')) ?><?= !empty($task['is_blocking']) ? ' / Blocking' : '' ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($task['priority_overridden_by_subtask'])): ?>
                            <div class="task-meta text-warning-emphasis mt-2">Priority influenced by open sub-tasks</div>
                        <?php endif; ?>
                        <div class="task-actions">
                            <button type="button" class="btn btn-outline-primary btn-sm edit-task-btn">Edit</button>
                            <?php if ($isSubtask): ?>
                                <button type="button" class="btn btn-outline-success btn-sm subtask-status-btn <?= $isComplete ? '' : 'is-complete' ?>" data-next-status="<?= $isComplete ? 'todo' : 'done' ?>"><?= $isComplete ? 'Reopen' : 'Complete' ?></button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-danger btn-sm delete-task-btn">Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<section class="timeline-section" id="ganttChart">
    <div class="ranked-section-head">
        <div>
            <div class="section-kicker">Timeline</div>
            <h4 class="mb-0">Project Timeline</h4>
            <div class="small text-muted">Grouped by <?= htmlspecialchars($timelineScale === 'month' ? 'month' : ($timelineScale === 'week' ? 'week' : 'day')) ?> based on project length.</div>
            <div class="timeline-legend" aria-label="Timeline legend">
                <span class="timeline-legend-item"><span class="timeline-swatch todo"></span>To Do</span>
                <span class="timeline-legend-item"><span class="timeline-swatch progress"></span>In Progress</span>
                <span class="timeline-legend-item"><span class="timeline-swatch done"></span>Done</span>
                <span class="timeline-legend-item"><span class="timeline-swatch subtask"></span>Sub-task row</span>
            </div>
        </div>
    </div>
    <div class="timeline-shell">
        <?php if (empty($timelineRows)): ?>
            <div class="timeline-empty">No scheduled tasks yet.</div>
        <?php else: ?>
            <div class="timeline-grid">
                <div class="timeline-row" style="grid-template-columns: minmax(190px, 240px) repeat(<?= $timelineBucketCount ?>, minmax(92px, 1fr));">
                    <div class="timeline-label">Task</div>
                    <?php foreach ($timelineBuckets as $bucket): ?>
                        <div class="timeline-day"><?= htmlspecialchars($bucket['label']) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($timelineRows as $row): ?>
                    <?php
                        $gridStart = ((int) $row['bucket_start']) + 2;
                        $gridEnd = ((int) $row['bucket_end']) + 3;
                    ?>
                    <div class="timeline-row <?= $row['is_subtask'] ? 'is-subtask' : '' ?>" style="grid-template-columns: minmax(190px, 240px) repeat(<?= $timelineBucketCount ?>, minmax(92px, 1fr));">
                        <div class="timeline-label" title="<?= htmlspecialchars($row['description'] ?: $row['name']) ?>">
                            <div class="timeline-label-main"><?= htmlspecialchars($row['is_subtask'] ? 'Sub-task: ' : '') ?><?= htmlspecialchars($row['name']) ?></div>
                            <?php if (!empty($row['description'])): ?>
                                <div class="timeline-description"><?= htmlspecialchars($row['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-row-bg"></div>
                        <div class="timeline-bar status-<?= htmlspecialchars($row['status']) ?>" style="grid-column: <?= $gridStart ?> / <?= $gridEnd ?>;" title="<?= htmlspecialchars($row['name']) ?>"><?= htmlspecialchars($row['name']) ?> / <?= (int) $row['duration'] ?>d</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="ranked-section-head">
    <h4 class="mb-0">Ranked Task Cards</h4>
    <button type="button" id="rankedSectionToggle" class="btn btn-sm btn-outline-secondary" aria-expanded="false" aria-controls="rankedSectionBody">Expand Section</button>
</div>
<div id="rankedSectionBody" class="ranked-section-body is-collapsed" data-ranked-section-body>
<div class="row g-3">
<?php foreach ($tasks as $task): ?>
    <?php
        $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $daysLeftText = (string) $daysLeft;
        $daysLeftClass = $daysLeft < 0 ? 'text-danger fw-bold' : 'text-success fw-bold';

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
                        <button type="submit" class="btn btn-sm btn-outline-warning">Move to In Progress</button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2 ranked-edit-btn" data-task-id="<?= (int) $task['id'] ?>">Edit Full Details</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
</div>
    </main>
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
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="edit_priority">Priority</label>
                            <select class="form-select" name="priority" id="edit_priority" required>
                                <option>Critical</option><option>High</option><option>Medium</option><option>Low</option><option>Optional</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="edit_effort">Effort</label>
                            <select class="form-select" name="effort" id="edit_effort" required>
                                <option>Low</option><option>Medium</option><option>High</option><option>Very High</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mt-0">
                        <div class="col-6">
                            <label class="form-label" for="edit_mandays">Mandays</label>
                            <input class="form-control" type="number" min="1" name="mandays" id="edit_mandays" required>
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
                    <div class="mt-2">
                        <label class="form-label" for="edit_parent_task_id">Parent Task</label>
                        <select class="form-select" name="parent_task_id" id="edit_parent_task_id">
                            <option value="">No Parent (Top-level task)</option>
                            <?php foreach ($tasks as $existingTask): ?>
                                <option value="<?= (int) $existingTask['id'] ?>"><?= htmlspecialchars($existingTask['task_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label" for="edit_influence_weight">Influence Weight</label>
                            <input class="form-control" type="number" min="0.1" max="3" step="0.1" name="influence_weight" id="edit_influence_weight" value="1">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_blocking" id="edit_is_blocking">
                                <label class="form-check-label" for="edit_is_blocking">Blocking sub-task</label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-light border small mt-2 mb-0" role="note">
                        Rollup applies automatically from sub-tasks to parent based on weight and blocking flag.
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
    const subtaskCollapseKey = 'kanbanExpandedParentTasks';
    const expandedParents = new Set((window.localStorage.getItem(subtaskCollapseKey) || '').split(',').map((id) => id.trim()).filter((id) => id !== ''));
    const statusLabels = { backlog: 'Backlog', todo: 'To Do', in_progress: 'In Progress', done: 'Done' };
    const invalidSubtaskDueDateMessage = 'Invalid due date: sub-task end date cannot be later than the parent task end date.';

    const getParentDueDate = (parentTaskId) => {
        if (!parentTaskId) {
            return '';
        }
        const parentCard = board.querySelector(`.kanban-task[data-task-id="${parentTaskId}"]`);
        return parentCard && parentCard.dataset ? (parentCard.dataset.dueDate || '') : '';
    };

    const validateSubtaskDueDate = (parentTaskId, dueDateValue) => {
        const parentDueDate = getParentDueDate(parentTaskId);
        if (!parentTaskId || !parentDueDate || !dueDateValue) {
            return true;
        }
        if (new Date(`${dueDateValue}T00:00:00`) > new Date(`${parentDueDate}T00:00:00`)) {
            alert(`${invalidSubtaskDueDateMessage} Parent ends on ${parentDueDate}.`);
            return false;
        }
        return true;
    };

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

    const sendStatusChange = async (taskId, newStatus) => {
        const payload = new URLSearchParams();
        payload.append('set_task_status', '1');
        payload.append('task_id', String(taskId));
        payload.append('new_status', newStatus);

        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });

        if (!response.ok) {
            throw new Error('Status update failed');
        }
        return response.json();
    };

    const updateColumnCounts = () => {
        board.querySelectorAll('.kanban-column').forEach((column) => {
            const count = [...column.querySelectorAll('.kanban-task')].filter((card) => card.style.display !== 'none').length;
            const badge = column.querySelector('.badge');
            if (badge) {
                badge.textContent = String(count);
            }
        });
    };

    const persistExpandedParents = () => {
        window.localStorage.setItem(subtaskCollapseKey, [...expandedParents].join(','));
    };

    const applySubtaskVisibility = () => {
        board.querySelectorAll('.kanban-column-body').forEach((columnBody) => {
            const cards = [...columnBody.querySelectorAll('.kanban-task')];
            const idsInColumn = new Set(cards.map((card) => card.dataset.taskId));

            cards.forEach((card) => {
                const parentId = (card.dataset.parentTaskId || '').trim();
                const hiddenByParent = parentId !== '' && idsInColumn.has(parentId) && !expandedParents.has(parentId);
                const hiddenBySearch = card.dataset.searchHidden === '1';
                card.style.display = (hiddenByParent || hiddenBySearch) ? 'none' : '';
            });

            cards.forEach((card) => {
                const toggleButton = card.querySelector('[data-subtask-toggle]');
                if (!toggleButton) {
                    return;
                }
                const taskId = card.dataset.taskId;
                const isExpanded = expandedParents.has(taskId);
                const childCount = cards.filter((child) => (child.dataset.parentTaskId || '').trim() === taskId).length;
                toggleButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                toggleButton.textContent = `${isExpanded ? 'Hide' : 'Show'} Sub-tasks (${childCount})`;
            });
        });
    };

    const applyTaskSearch = (query) => {
        const normalizedQuery = query.trim().toLowerCase();
        board.querySelectorAll('.kanban-task').forEach((taskCard) => {
            if (normalizedQuery === '') {
                taskCard.dataset.searchHidden = '0';
                return;
            }
            const haystack = `${taskCard.dataset.taskName || ''} ${taskCard.dataset.description || ''}`.toLowerCase();
            taskCard.dataset.searchHidden = haystack.includes(normalizedQuery) ? '0' : '1';
        });
        applySubtaskVisibility();
        updateColumnCounts();
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

        const countdownText = `(${daysLeft})`;
        const countdownClass = daysLeft < 0 ? 'due-overdue' : 'due-safe';

        dueMeta.className = `task-meta due-meta ${countdownClass}`;
        dueMeta.textContent = '';
        dueMeta.append('Due: ');
        const dueDateSpan = document.createElement('span');
        dueDateSpan.className = `due-date-value ${countdownClass}`;
        dueDateSpan.textContent = dueDateValue;
        const dueCountdownSpan = document.createElement('span');
        dueCountdownSpan.className = `due-countdown ${countdownClass}`;
        dueCountdownSpan.textContent = countdownText;
        dueMeta.append(dueDateSpan, dueCountdownSpan);
    };

    const applyTaskDataToCard = (taskCard, data) => {
        taskCard.dataset.taskName = data.task_name;
        taskCard.dataset.description = data.description;
        taskCard.dataset.priority = data.priority;
        taskCard.dataset.dueDate = data.due_date;
        taskCard.dataset.status = data.status;
        taskCard.dataset.mode = data.mode;
        taskCard.dataset.score = String(data.score);
        const isSubtask = (taskCard.dataset.parentTaskId || '').trim() !== '';

        taskCard.classList.remove('status-backlog', 'status-todo', 'status-in_progress', 'status-done');
        taskCard.classList.add(`status-${data.status}`);
        taskCard.classList.toggle('subtask-card', isSubtask);
        taskCard.classList.toggle('is-complete', data.status === 'done');

        const statusChip = taskCard.querySelector('.task-status-chip');
        if (statusChip) {
            statusChip.textContent = data.status === 'done' ? 'Done' : (statusLabels[data.status] || data.status);
            statusChip.classList.toggle('status-done-chip', data.status === 'done');
        }

        let subtaskChip = taskCard.querySelector('.subtask-chip');
        if (isSubtask && !subtaskChip) {
            subtaskChip = document.createElement('span');
            subtaskChip.className = 'subtask-chip';
            subtaskChip.textContent = 'Sub-task';
            const titleWrap = taskCard.querySelector('.task-title-wrap');
            if (titleWrap) {
                titleWrap.insertBefore(subtaskChip, titleWrap.firstChild);
            } else {
                taskCard.insertBefore(subtaskChip, taskCard.firstChild);
            }
        } else if (!isSubtask && subtaskChip) {
            subtaskChip.remove();
        }

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
        const actions = taskCard.querySelector('.task-actions');
        let subtaskStatusButton = taskCard.querySelector('.subtask-status-btn');
        if (isSubtask && !subtaskStatusButton && actions) {
            subtaskStatusButton = document.createElement('button');
            subtaskStatusButton.type = 'button';
            subtaskStatusButton.className = 'btn btn-outline-success btn-sm subtask-status-btn';
            const deleteButton = actions.querySelector('.delete-task-btn');
            actions.insertBefore(subtaskStatusButton, deleteButton || null);
        } else if (!isSubtask && subtaskStatusButton) {
            subtaskStatusButton.remove();
            subtaskStatusButton = null;
        }
        if (subtaskStatusButton) {
            subtaskStatusButton.dataset.nextStatus = data.status === 'done' ? 'todo' : 'done';
            subtaskStatusButton.textContent = data.status === 'done' ? 'Reopen' : 'Complete';
            subtaskStatusButton.classList.toggle('is-complete', data.status !== 'done');
        }
    };

    const openEditModal = (taskCard) => {
        if (!editModal || !editForm) {
            return;
        }
        editForm.querySelector('#edit_task_id').value = taskCard.dataset.taskId;
        editForm.querySelector('#edit_task_name').value = taskCard.dataset.taskName || '';
        editForm.querySelector('#edit_description').value = taskCard.dataset.description || '';
        editForm.querySelector('#edit_priority').value = taskCard.dataset.priority || 'Medium';
        editForm.querySelector('#edit_effort').value = taskCard.dataset.effort || 'Medium';
        editForm.querySelector('#edit_mandays').value = taskCard.dataset.mandays || '1';
        editForm.querySelector('#edit_due_date').value = taskCard.dataset.dueDate || '';
        editForm.querySelector('#edit_status').value = taskCard.dataset.status || 'todo';
        const parentSelect = editForm.querySelector('#edit_parent_task_id');
        if (parentSelect) {
            [...parentSelect.options].forEach((opt) => {
                opt.disabled = opt.value === taskCard.dataset.taskId;
            });
            parentSelect.value = taskCard.dataset.parentTaskId || '';
        }
        const influenceWeightInput = editForm.querySelector('#edit_influence_weight');
        if (influenceWeightInput) {
            influenceWeightInput.value = taskCard.dataset.influenceWeight || '1';
        }
        const blockingInput = editForm.querySelector('#edit_is_blocking');
        if (blockingInput) {
            blockingInput.checked = taskCard.dataset.isBlocking === '1';
        }
        editForm.dataset.taskId = taskCard.dataset.taskId;
        editModal.show();
    };

    const saveTaskDetails = async (formData) => {
        formData.append('update_task_details', '1');
        const response = await fetch('index.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'Failed to save task changes. Please try again.');
        }
        return payload;
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

        const byId = new Map();
        const childrenByParent = new Map();
        cards.forEach((card) => {
            byId.set(card.dataset.taskId, card);
            const parentId = (card.dataset.parentTaskId || '').trim();
            if (parentId !== '') {
                if (!childrenByParent.has(parentId)) {
                    childrenByParent.set(parentId, []);
                }
                childrenByParent.get(parentId).push(card);
            }
        });

        const ordered = [];
        const seen = new Set();
        cards.forEach((card) => {
            const parentId = (card.dataset.parentTaskId || '').trim();
            if (parentId !== '' && byId.has(parentId)) {
                return;
            }
            ordered.push(card);
            seen.add(card.dataset.taskId);
            const children = childrenByParent.get(card.dataset.taskId) || [];
            children.forEach((child) => {
                ordered.push(child);
                seen.add(child.dataset.taskId);
            });
        });

        cards.forEach((card) => {
            if (!seen.has(card.dataset.taskId)) {
                ordered.push(card);
            }
        });

        ordered.forEach((card) => columnBody.appendChild(card));
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
                    expandedParents.delete(task.dataset.taskId);
                    persistExpandedParents();
                    const parent = task.parentElement;
                    task.remove();
                    if (parent) {
                        sortColumnCards(parent);
                        applySubtaskVisibility();
                    }
                    updateColumnCounts();
                } catch (error) {
                    alert('Failed to delete task. Please try again.');
                }
            });
        }

    });

    const dragAndDropSupported = 'draggable' in document.createElement('div') && !('ontouchstart' in window);
    if (!dragAndDropSupported) {
        board.querySelectorAll('.kanban-task').forEach((task) => {
            task.setAttribute('draggable', 'false');
            task.style.cursor = 'default';
        });
    }

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
                    applySubtaskVisibility();
                }
                updateColumnCounts();
            } catch (error) {
                window.location.reload();
            }
        });
    });

    board.querySelectorAll('.kanban-task').forEach((taskCard) => {
        taskCard.dataset.searchHidden = '0';
        renderDueCountdown(taskCard);
    });

    board.addEventListener('click', (event) => {
        const subtaskStatusButton = event.target.closest('.subtask-status-btn');
        if (subtaskStatusButton) {
            const taskCard = subtaskStatusButton.closest('.kanban-task');
            if (!taskCard) {
                return;
            }
            const newStatus = subtaskStatusButton.dataset.nextStatus || 'done';
            sendStatusChange(taskCard.dataset.taskId, newStatus)
                .then(() => {
                    taskCard.dataset.status = newStatus;
                    taskCard.classList.remove('status-backlog', 'status-todo', 'status-in_progress', 'status-done');
                    taskCard.classList.add(`status-${newStatus}`);
                    taskCard.classList.toggle('is-complete', newStatus === 'done');
                    const statusChip = taskCard.querySelector('.task-status-chip');
                    if (statusChip) {
                        statusChip.textContent = newStatus === 'done' ? 'Done' : (statusLabels[newStatus] || newStatus);
                        statusChip.classList.toggle('status-done-chip', newStatus === 'done');
                    }
                    subtaskStatusButton.dataset.nextStatus = newStatus === 'done' ? 'todo' : 'done';
                    subtaskStatusButton.textContent = newStatus === 'done' ? 'Reopen' : 'Complete';
                    subtaskStatusButton.classList.toggle('is-complete', newStatus !== 'done');
                    sortColumnCards(taskCard.parentElement);
                    applySubtaskVisibility();
                    updateColumnCounts();
                })
                .catch(() => {
                    alert('Failed to update sub-task. Please try again.');
                });
            return;
        }

        const toggleButton = event.target.closest('[data-subtask-toggle]');
        if (!toggleButton) {
            return;
        }
        const taskCard = toggleButton.closest('.kanban-task');
        if (!taskCard) {
            return;
        }
        const taskId = taskCard.dataset.taskId;
        if (expandedParents.has(taskId)) {
            expandedParents.delete(taskId);
        } else {
            expandedParents.add(taskId);
        }
        persistExpandedParents();
        applySubtaskVisibility();
        updateColumnCounts();
    });

    applySubtaskVisibility();
    updateColumnCounts();

    const taskSearch = document.getElementById('taskSearch');
    if (taskSearch) {
        taskSearch.addEventListener('input', () => applyTaskSearch(taskSearch.value || ''));
    }

    const createTaskInput = document.getElementById('create_task_name');
    const createTaskForm = createTaskInput ? createTaskInput.closest('form') : null;
    if (createTaskForm) {
        createTaskForm.addEventListener('submit', (event) => {
            const parentTaskId = String(new FormData(createTaskForm).get('parent_task_id') || '');
            const dueDateValue = String(new FormData(createTaskForm).get('due_date') || '');
            if (!validateSubtaskDueDate(parentTaskId, dueDateValue)) {
                event.preventDefault();
            }
        });
    }

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
                priority: String(formData.get('priority') || 'Medium'),
                effort: String(formData.get('effort') || 'Medium'),
                mandays: String(formData.get('mandays') || '1'),
                due_date: String(formData.get('due_date') || ''),
                status: String(formData.get('status') || 'todo')
            };

            if (!validateSubtaskDueDate(String(formData.get('parent_task_id') || ''), updatedData.due_date)) {
                return;
            }

            updatedData.mode = getModeByDueDate(updatedData.due_date);
            updatedData.score = calculateScore(updatedData);

            try {
                await saveTaskDetails(formData);
                taskCard.dataset.parentTaskId = String(formData.get('parent_task_id') || '');
                taskCard.dataset.influenceWeight = String(formData.get('influence_weight') || '1');
                taskCard.dataset.isBlocking = formData.get('is_blocking') ? '1' : '0';
                taskCard.dataset.effort = String(formData.get('effort') || 'Medium');
                taskCard.dataset.mandays = String(formData.get('mandays') || '1');
                applyTaskDataToCard(taskCard, updatedData);
                const parentId = taskCard.dataset.parentTaskId || '';
                const parentCard = parentId !== '' ? board.querySelector(`.kanban-task[data-task-id="${parentId}"]`) : null;
                const targetColumn = parentCard && parentCard.parentElement ? parentCard.parentElement : board.querySelector(`.kanban-column-body[data-status="${updatedData.status}"]`);
                if (targetColumn && taskCard.parentElement !== targetColumn) {
                    targetColumn.appendChild(taskCard);
                }
                sortColumnCards(taskCard.parentElement);
                applySubtaskVisibility();
                updateColumnCounts();
                editModal.hide();
            } catch (error) {
                alert(error.message || 'Failed to save task changes. Please try again.');
            }
        });
    }

    document.querySelectorAll('.ranked-edit-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const taskId = button.getAttribute('data-task-id');
            const taskCard = board.querySelector(`.kanban-task[data-task-id="${taskId}"]`);
            if (taskCard) {
                openEditModal(taskCard);
            }
        });
    });
})();
</script>
</body>
</html>
