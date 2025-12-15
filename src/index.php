<?php
// Ensure session settings and start are called before any output
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration from environment variables
$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'mysql';
$db_port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'task_prioritizer';
$db_user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'taskuser';
$db_pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? 'secure_password_123';

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
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    
    // Create indexes for better performance (with proper error handling)
    try { $pdo->exec("CREATE INDEX idx_tasks_user_id ON tasks(user_id)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_tasks_due_date ON tasks(due_date)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_tasks_priority ON tasks(priority)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_email ON users(email)"); } catch (PDOException $e) { /* Index might already exist */ }
    try { $pdo->exec("CREATE INDEX idx_users_username ON users(username)"); } catch (PDOException $e) { /* Index might already exist */ }
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

// Skip processing if there's a database error
if (isset($db_error)) {
    // Will be handled in the HTML section
} else {

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

// Updated Edit Task Logic to allow full modification
if (isset($_POST['edit_task']) && $_SESSION['loggedin'] === true) {
    try {
        $stmt = $pdo->prepare("UPDATE tasks SET task_name = ?, priority = ?, effort = ?, mandays = ?, due_date = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([
            $_POST['task_name'],
            $_POST['priority'],
            $_POST['effort'],
            $_POST['mandays'],
            $_POST['due_date'],
            $_POST['task_id'],
            $_SESSION['user_id']
        ]);
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
    <title>Task Prioritizer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #34495e;
            --accent: #3498db;
            --bg-color: #f4f6f9;
        }
        body { background-color: var(--bg-color); font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #333; }
        .navbar { background-color: var(--primary); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar-brand { font-weight: 600; font-size: 1.25rem; }
        .btn-primary { background-color: var(--accent); border-color: var(--accent); }
        .btn-primary:hover { background-color: #2980b9; border-color: #2980b9; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); transition: transform 0.2s, box-shadow 0.2s; background: #fff; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .task-card .card-header { background: transparent; border-bottom: 1px solid #eee; font-weight: 600; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; }
        .task-title { margin: 0; font-size: 1.1rem; color: var(--primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70%; }
        .badge-mode-urgent { background-color: #e74c3c; }
        .badge-mode-strategic { background-color: #2980b9; }
        .auth-container { max-width: 450px; margin: 4rem auto; }
        .stat-card { text-align: center; padding: 1.5rem; }
        .stat-number { font-size: 2rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.9rem; text-transform: uppercase; color: #7f8c8d; letter-spacing: 1px; }
        .fab-add { position: fixed; bottom: 2rem; right: 2rem; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 10px rgba(0,0,0,0.2); z-index: 1000; }
        footer { margin-top: 3rem; color: #7f8c8d; font-size: 0.9rem; border-top: 1px solid #e0e0e0; padding-top: 1.5rem; }
        .action-btn { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s; }
        .action-btn:hover { background: #f0f0f0; }
        .due-date-text { font-size: 0.85rem; }
        .progress-indicator { height: 4px; border-radius: 2px; background: #e0e0e0; margin-top: 0.5rem; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--accent); }
    </style>
</head>
<body>

<?php if (isset($db_error)): ?>
    <div class="container py-5">
        <div class="alert alert-danger shadow-sm">
            <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> Database Error</h4>
            <p><?= htmlspecialchars($db_error) ?></p>
            <button class="btn btn-outline-danger" onclick="location.reload()">Retry Connection</button>
        </div>
    </div>
<?php else: ?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="fas fa-tasks me-2"></i>Task Prioritizer</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <?php if ($_SESSION['loggedin'] ?? false): ?>
                    <li class="nav-item me-3 text-light">
                        <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                    </li>
                    <li class="nav-item">
                        <form method="POST" class="d-inline">
                            <button name="logout" class="btn btn-sm btn-outline-light">Logout</button>
                        </form>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">
    <!-- Messages -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!($_SESSION['loggedin'] ?? false)): ?>
    <!-- Auth Section -->
    <div class="auth-container card shadow">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
            <ul class="nav nav-pills nav-fill" id="authTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button">Login</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button">Register</button>
                </li>
            </ul>
        </div>
        <div class="card-body p-4">
            <div class="tab-content" id="authTabContent">
                <!-- Login Form -->
                <div class="tab-pane fade show active" id="login" role="tabpanel">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small">USERNAME OR EMAIL</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small">PASSWORD</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary w-100 py-2">Sign In</button>
                    </form>
                </div>
                <!-- Register Form -->
                <div class="tab-pane fade" id="register" role="tabpanel">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small">USERNAME</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small">EMAIL</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted small">PASSWORD</label>
                                <input type="password" class="form-control" name="password" required minlength="6">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted small">CONFIRM</label>
                                <input type="password" class="form-control" name="confirm_password" required minlength="6">
                            </div>
                        </div>
                        <button type="submit" name="register" class="btn btn-success w-100 py-2">Create Account</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Dashboard -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card stat-card h-100">
                <div class="stat-number text-primary"><?= count($tasks) ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
        </div>
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card stat-card h-100">
                <div class="stat-number text-danger">
                    <?= count(array_filter($tasks, fn($t) => getTaskMode(ceil((strtotime($t['due_date']) - strtotime(date('Y-m-d'))) / 86400)) === 'URGENT')) ?>
                </div>
                <div class="stat-label">Urgent</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="stat-number text-success">
                    <?= count(array_filter($tasks, fn($t) => !empty($t['in_progress']))) ?>
                </div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 text-secondary">Your Tasks</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTaskModal">
            <i class="fas fa-plus me-1"></i> New Task
        </button>
    </div>

    <div class="row g-4">
        <?php if (empty($tasks)): ?>
            <div class="col-12 text-center py-5">
                <img src="https://via.placeholder.com/150/e0e0e0/ffffff?text=No+Tasks" class="mb-3 rounded-circle" alt="No Tasks">
                <h5 class="text-muted">You're all caught up!</h5>
                <p class="text-muted small">Click "New Task" to get started.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($tasks as $task): ?>
            <?php
                $daysLeft = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);
                $taskMode = getTaskMode($daysLeft);
                $taskScore = calculateTaskScore($task);

                if ($daysLeft < 0) {
                    $daysText = abs($daysLeft) . 'd overdue';
                    $daysColor = 'text-danger';
                } elseif ($daysLeft == 0) {
                    $daysText = 'Today';
                    $daysColor = 'text-danger';
                } else {
                    $daysText = $daysLeft . 'd left';
                    $daysColor = $daysLeft <= 3 ? 'text-warning' : 'text-success';
                }
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card task-card h-100">
                    <div class="card-header">
                        <h5 class="task-title" title="<?= htmlspecialchars($task['task_name']) ?>">
                            <?= htmlspecialchars($task['task_name']) ?>
                        </h5>
                        <div class="dropdown">
                            <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item edit-task-btn" href="#"
                                       data-id="<?= $task['id'] ?>"
                                       data-name="<?= htmlspecialchars($task['task_name']) ?>"
                                       data-priority="<?= $task['priority'] ?>"
                                       data-effort="<?= $task['effort'] ?>"
                                       data-mandays="<?= $task['mandays'] ?>"
                                       data-due="<?= $task['due_date'] ?>">
                                    <i class="fas fa-edit me-2 text-primary"></i> Edit</a>
                                </li>
                                <li>
                                    <form method="POST" class="d-inline w-100">
                                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                        <button type="submit" name="delete_task" class="dropdown-item text-danger">
                                            <i class="fas fa-trash-alt me-2"></i> Delete
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="badge rounded-pill <?= $taskMode === 'URGENT' ? 'badge-mode-urgent' : 'badge-mode-strategic' ?>">
                                <?= $taskMode ?>
                            </span>
                            <span class="fw-bold text-muted small">Score: <?= $taskScore ?></span>
                        </div>
                        
                        <div class="row g-2 small text-muted mb-3">
                            <div class="col-6">
                                <i class="fas fa-flag me-1 text-warning"></i> <?= $task['priority'] ?>
                            </div>
                            <div class="col-6">
                                <i class="fas fa-dumbbell me-1 text-info"></i> <?= $task['effort'] ?>
                            </div>
                            <div class="col-6">
                                <i class="fas fa-user-clock me-1 text-secondary"></i> <?= $task['mandays'] ?> days
                            </div>
                            <div class="col-6 <?= $daysColor ?> fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i> <?= $daysText ?>
                            </div>
                        </div>

                        <?php if ($task['in_progress']): ?>
                            <div class="alert alert-soft-success py-1 px-2 mb-0 small text-center bg-light text-success border">
                                <i class="fas fa-spinner fa-spin me-1"></i> In Progress
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <input type="hidden" name="mark_progress" value="1">
                                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Start Task</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Create Task Modal -->
    <div class="modal fade" id="createTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Task Name</label>
                            <input type="text" name="task_name" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-select" required>
                                    <option value="Critical">Critical</option>
                                    <option value="High">High</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="Low">Low</option>
                                    <option value="Optional">Optional</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Effort</label>
                                <select name="effort" class="form-select" required>
                                    <option value="Very High">Very High</option>
                                    <option value="High">High</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="Low">Low</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mandays</label>
                                <input type="number" name="mandays" class="form-control" value="1" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_task" class="btn btn-primary">Create Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="task_id" id="edit_task_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Task Name</label>
                            <input type="text" name="task_name" id="edit_task_name" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" id="edit_priority" class="form-select" required>
                                    <option value="Critical">Critical</option>
                                    <option value="High">High</option>
                                    <option value="Medium">Medium</option>
                                    <option value="Low">Low</option>
                                    <option value="Optional">Optional</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Effort</label>
                                <select name="effort" id="edit_effort" class="form-select" required>
                                    <option value="Very High">Very High</option>
                                    <option value="High">High</option>
                                    <option value="Medium">Medium</option>
                                    <option value="Low">Low</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mandays</label>
                                <input type="number" name="mandays" id="edit_mandays" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" id="edit_due_date" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_task" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <footer class="text-center">
        <p>Vibe coded by Exrienz with <span class="text-danger">&#10084;</span></p>
    </footer>
</div>

<?php endif; // End of db_error check ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const editButtons = document.querySelectorAll('.edit-task-btn');
        const editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
        const editForm = document.getElementById('editTaskModal').querySelector('form');

        editButtons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('edit_task_id').value = this.dataset.id;
                document.getElementById('edit_task_name').value = this.dataset.name;
                document.getElementById('edit_priority').value = this.dataset.priority;
                document.getElementById('edit_effort').value = this.dataset.effort;
                document.getElementById('edit_mandays').value = this.dataset.mandays;
                document.getElementById('edit_due_date').value = this.dataset.due;

                editModal.show();
            });
        });
    });
</script>
</body>
</html>
