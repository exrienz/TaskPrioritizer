<?php
// Ensure session settings and start are called before any output
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$db = new SQLite3('/var/www/db/task_management.db');

$db->exec("CREATE TABLE IF NOT EXISTS tokens (token TEXT PRIMARY KEY);");
$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL,
    task_name TEXT NOT NULL,
    priority TEXT NOT NULL,
    effort TEXT NOT NULL,
    mandays INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    progress BOOLEAN NOT NULL DEFAULT 0,
    FOREIGN KEY (token) REFERENCES tokens(token)
);");

// Token Generation
if (isset($_POST['generate_token'])) {
    $token = bin2hex(random_bytes(16));
    $_SESSION['token'] = $token;
    $stmt = $db->prepare("INSERT INTO tokens (token) VALUES (?)");
    $stmt->bindValue(1, $token, SQLITE3_TEXT);
    $stmt->execute();
    echo "<p>Your unique token: <strong>$token</strong></p>";
}

// Login Handling
if (isset($_POST['login'])) {
    $token = $_POST['token'];
    $stmt = $db->prepare("SELECT token FROM tokens WHERE token = ?");
    $stmt->bindValue(1, $token, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        $_SESSION['loggedin'] = true;
        $_SESSION['token'] = $token;
    } else {
        echo "<p>Invalid Token!</p>";
    }
}

// Task Handling
if (isset($_POST['create_task']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("INSERT INTO tasks (token, task_name, priority, effort, mandays, due_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $_SESSION['token']);
    $stmt->bindValue(2, $_POST['task_name']);
    $stmt->bindValue(3, $_POST['priority']);
    $stmt->bindValue(4, $_POST['effort']);
    $stmt->bindValue(5, $_POST['mandays']);
    $stmt->bindValue(6, $_POST['due_date']);
    $stmt->execute();
}

// Mark Task as In Progress
if (isset($_POST['mark_progress']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("UPDATE tasks SET progress = ? WHERE id = ? AND token = ?");
    $stmt->bindValue(1, 1); // Mark as in progress
    $stmt->bindValue(2, $_POST['task_id']);
    $stmt->bindValue(3, $_SESSION['token']);
    $stmt->execute();
}

// Task Deletion
if (isset($_POST['delete_task']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND token = ?");
    $stmt->bindValue(1, $_POST['task_id']);
    $stmt->bindValue(2, $_SESSION['token']);
    $stmt->execute();
}

// Logout
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php"); // Refresh page to log out
    exit;
}

function calculateTaskScore($task) {
    $criticalityMap = ['Optional' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Critical' => 5];
    $effortMap = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];

    $criticality = $criticalityMap[$task['priority']] ?? 1;
    $effort = $effortMap[$task['effort']] ?? 2;
    $mandays = max(1, (int)$task['mandays']);
    $daysLeft = max(0, ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400));

    $priorityScore = ($criticality * (1 / $effort) * 50) + ($mandays * -5) + (40 / ($daysLeft + 1));

    return round($priorityScore, 2);
}

$tasks = [];
if ($_SESSION['loggedin'] ?? false) {
    $stmt = $db->prepare("SELECT * FROM tasks WHERE token = ?");
    $stmt->bindValue(1, $_SESSION['token']);
    $results = $stmt->execute();
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) $tasks[] = $row;
    usort($tasks, fn($a, $b) => calculateTaskScore($b) <=> calculateTaskScore($a));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet">
    <title>Task Management System</title>
    <style>
        body {
            padding-top: 20px;
        }
        .task-table td {
            vertical-align: middle;
        }
    </style>
</head>
<body class="container">
<?php if (!($_SESSION['loggedin'] ?? false)): ?>
    <h2>Generate Token</h2>
    <form method="POST">
        <button type="submit" name="generate_token" class="btn btn-primary btn-block">Generate Token</button>
    </form>
    <hr>
    <h2>Login</h2>
    <form method="POST">
        <div class="form-group">
            <label for="token">Enter Your Token:</label>
            <input type="text" class="form-control" name="token" id="token" required>
        </div>
        <button type="submit" name="login" class="btn btn-primary btn-block">Login</button>
    </form>
<?php else: ?>
    <h2>Member Area</h2>
    <form method="POST">
        <button type="submit" name="logout" class="btn btn-danger btn-block">Logout</button>
    </form>
    <hr>
    <h3>Create Task</h3>
    <form method="POST">
        <div class="form-group">
            <label for="task_name">Task Name:</label>
            <input type="text" name="task_name" class="form-control" required>
        </div>
        <div class="form-group">
            <label for="priority">Priority:</label>
            <select name="priority" class="form-control" required>
                <option>Critical</option>
                <option>High</option>
                <option>Medium</option>
                <option>Low</option>
                <option>Optional</option>
            </select>
        </div>
        <div class="form-group">
            <label for="effort">Effort:</label>
            <select name="effort" class="form-control" required>
                <option>Very High</option>
                <option>High</option>
                <option>Medium</option>
                <option>Low</option>
            </select>
        </div>
        <div class="form-group">
            <label for="mandays">Mandays:</label>
            <input type="number" name="mandays" class="form-control" required>
        </div>
        <div class="form-group">
            <label for="due_date">Due Date:</label>
            <input type="date" name="due_date" class="form-control" required>
        </div>
        <button type="submit" name="create_task" class="btn btn-success btn-block">Create Task</button>
    </form>
    <hr>
    <h3>All Tasks</h3>
    <table class="table table-bordered task-table">
        <thead>
            <tr>
                <th>Task Name</th>
                <th>Priority</th>
                <th>Effort</th>
                <th>Mandays</th>
                <th>Due Date</th>
                <th>Score</th>
                <th>In Progress</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= htmlspecialchars($task['task_name']) ?></td>
                    <td><?= htmlspecialchars($task['priority']) ?></td>
                    <td><?= htmlspecialchars($task['effort']) ?></td>
                    <td><?= htmlspecialchars($task['mandays']) ?></td>
                    <td><?= htmlspecialchars($task['due_date']) ?></td>
                    <td><?= calculateTaskScore($task) ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <input type="checkbox" name="mark_progress" <?= $task['progress'] ? 'checked' : '' ?> onchange="this.form.submit()"> Mark as In Progress
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            <button type="submit" name="delete_task" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>