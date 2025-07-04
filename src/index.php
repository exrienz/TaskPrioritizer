<?php
// Ensure session settings and start are called before any output
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$db = new SQLite3('/var/www/db/task_management.db');

// Create tables if not exists
$db->exec("CREATE TABLE IF NOT EXISTS tokens (token TEXT PRIMARY KEY);");
$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL,
    task_name TEXT NOT NULL,
    priority TEXT NOT NULL,
    effort TEXT NOT NULL,
    mandays INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    FOREIGN KEY (token) REFERENCES tokens(token)
);");

// Ensure in_progress column exists
$columns = $db->query("PRAGMA table_info(tasks);");
$columnExists = false;
while ($col = $columns->fetchArray(SQLITE3_ASSOC)) {
    if ($col['name'] === 'in_progress') {
        $columnExists = true;
        break;
    }
}
if (!$columnExists) {
    $db->exec("ALTER TABLE tasks ADD COLUMN in_progress INTEGER DEFAULT 0;");
}

if (isset($_POST['generate_token'])) {
    $token = bin2hex(random_bytes(16));
    $_SESSION['token'] = $token;
    $stmt = $db->prepare("INSERT INTO tokens (token) VALUES (?)");
    $stmt->bindValue(1, $token, SQLITE3_TEXT);
    $stmt->execute();
    echo "<p>Your unique token: <strong>$token</strong></p>";
}

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

if (isset($_POST['create_task']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("INSERT INTO tasks (token, task_name, priority, effort, mandays, due_date, in_progress) VALUES (?, ?, ?, ?, ?, ?, 0)");
    $stmt->bindValue(1, $_SESSION['token']);
    $stmt->bindValue(2, $_POST['task_name']);
    $stmt->bindValue(3, $_POST['priority']);
    $stmt->bindValue(4, $_POST['effort']);
    $stmt->bindValue(5, $_POST['mandays']);
    $stmt->bindValue(6, $_POST['due_date']);
    $stmt->execute();
}

if (isset($_POST['delete_task']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND token = ?");
    $stmt->bindValue(1, $_POST['task_id']);
    $stmt->bindValue(2, $_SESSION['token']);
    $stmt->execute();
}

if (isset($_POST['mark_progress']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("UPDATE tasks SET in_progress = 1 WHERE id = ? AND token = ?");
    $stmt->bindValue(1, $_POST['task_id']);
    $stmt->bindValue(2, $_SESSION['token']);
    $stmt->execute();
}

if (isset($_POST['edit_task']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("UPDATE tasks SET task_name = ? WHERE id = ? AND token = ?");
    $stmt->bindValue(1, $_POST['new_task_name']);
    $stmt->bindValue(2, $_POST['task_id']);
    $stmt->bindValue(3, $_SESSION['token']);
    $stmt->execute();
}

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

function calculateTaskScore($task) {
    $criticalityMap = ['Optional' => 1, 'Low' => 2, 'Medium' => 3, 'High' => 4, 'Critical' => 5];
    $effortMap      = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Very High' => 4];

    $criticality = $criticalityMap[$task['priority']] ?? 1;
    $effort      = $effortMap[$task['effort']] ?? 2;
    $mandays     = max(1, (int) $task['mandays']);
    $daysLeft    = ceil((strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400);

    $CRITICALITY_WEIGHT = 50;
    $EFFORT_WEIGHT      = 20;
    $MANDAYS_WEIGHT     = 15;
    $URGENCY_MAX        = 80;  // Increased from 40
    $OVERDUE_BOOST      = 100;

    // Urgency calculation (reciprocal for non-overdue, fixed boost for overdue)
    if ($daysLeft < 0) {
        $urgencyScore = $OVERDUE_BOOST;
    } else {
        $urgencyScore = $URGENCY_MAX / (1 + $daysLeft); // DaysLeft=0 gives 80, 1 gives 40, etc.
    }

    $score  = 0;
    $score += $criticality * $CRITICALITY_WEIGHT;
    $score += ($EFFORT_WEIGHT / $effort);
    $score += ($MANDAYS_WEIGHT / $mandays);
    $score += $urgencyScore;

    return round($score, 2);
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Task Management System</title>
    <style>
        body { background-color: #f8f9fa; }
        .card { margin-bottom: 1rem; }
        .progress-badge { background-color: #ffc107; color: #000; font-size: 0.8em; padding: 0.2em 0.6em; border-radius: 5px; }
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="text-center mb-4">Task Management System</h1>
<?php if (!($_SESSION['loggedin'] ?? false)): ?>
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <h2 class="mb-4 text-center">Generate Token</h2>
        <form method="POST" class="mb-4 text-center">
            <button type="submit" name="generate_token" class="btn btn-primary">Generate Token</button>
        </form>
        <h2 class="text-center">Login</h2>
        <form method="POST">
            <label for="token" class="form-label">Enter Your Token:</label>
            <input type="text" class="form-control" name="token" id="token" required>
            <button type="submit" name="login" class="btn btn-success w-100 mt-2">Login</button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <h2 class="mb-0">Create Task</h2>
        <form method="POST" class="ms-2">
            <button name="logout" class="btn btn-danger">Logout</button>
        </form>
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
                        } else {
                            $daysLeftText = $daysLeft . ' day(s) left';
                        }
                    ?>
                    <strong>Time Left:</strong> <?= $daysLeftText ?><br>
                    <strong>Score:</strong> <?= calculateTaskScore($task) ?>
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
</body>
</html>
