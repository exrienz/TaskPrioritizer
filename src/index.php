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
    
    $score = ($priority * 30) + $urgency - $effortPenalty;
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
