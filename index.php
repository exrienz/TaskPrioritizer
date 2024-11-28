<?php

ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Ensure session_start is called before any output
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet">
    <title>Task Management System</title>
</head>
<body class="container">
<?php
// Database connection
$db = new SQLite3('/var/www/db/task_management.db');

// Create tokens and tasks table if they don't exist
$db->exec("CREATE TABLE IF NOT EXISTS tokens (
    token TEXT PRIMARY KEY
);");

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
if (isset($_POST['create_task']) && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $task_name = $_POST['task_name'];
    $priority = $_POST['priority'];
    $effort = $_POST['effort'];
    $mandays = $_POST['mandays'];
    $due_date = $_POST['due_date'];
    
    // Insert task into database
    $stmt = $db->prepare("INSERT INTO tasks (token, task_name, priority, effort, mandays, due_date) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $_SESSION['token'], SQLITE3_TEXT);
    $stmt->bindValue(2, $task_name, SQLITE3_TEXT);
    $stmt->bindValue(3, $priority, SQLITE3_TEXT);
    $stmt->bindValue(4, $effort, SQLITE3_TEXT);
    $stmt->bindValue(5, $mandays, SQLITE3_INTEGER);
    $stmt->bindValue(6, $due_date, SQLITE3_TEXT);
    $stmt->execute();
}

// Task Deletion
if (isset($_POST['delete_task']) && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $task_id = $_POST['task_id'];
    
    // Delete task from database
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND token = ?");
    $stmt->bindValue(1, $task_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $_SESSION['token'], SQLITE3_TEXT);
    $stmt->execute();
}

// Retrieve tasks for the logged-in user
$tasks = [];
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $stmt = $db->prepare("SELECT * FROM tasks WHERE token = ?");
    $stmt->bindValue(1, $_SESSION['token'], SQLITE3_TEXT);
    $results = $stmt->execute();
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $tasks[] = $row;
    }
}

// Calculate Task Score
function calculateTaskScore($task) {
    $priorityScore = ['Critical' => 3, 'High' => 2, 'Medium' => 1, 'Low' => 0][$task['priority']];
    $daysRemaining = (strtotime($task['due_date']) - strtotime(date('Y-m-d'))) / 86400;
    $urgencyScore = max(0, 10 - $daysRemaining);
    $effortScore = ['Low' => 2, 'Medium' => 1, 'High' => 0][$task['effort']];
    $mandaysScore = max(0, 10 - $task['mandays']);
    return $priorityScore + $urgencyScore + $effortScore + $mandaysScore;
}

// Sort tasks by score in descending order
usort($tasks, function ($a, $b) {
    return calculateTaskScore($b) <=> calculateTaskScore($a);
});
?>

<?php if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true): ?>

<h2>Generate Token</h2>
<form method="POST">
    <button type="submit" name="generate_token" class="btn btn-primary">Generate Token</button>
</form>
<br>
<br>
<hr/>
<br>
<br>
<h2>Login</h2>
<form method="POST">
    <div class="form-group">
        <label for="token">Enter Your Token:</label>
        <input type="text" class="form-control" name="token" id="token" required>
    </div>
    <button type="submit" name="login" class="btn btn-primary">Login</button>
</form>

<?php else: ?>

<h2>Member Area</h2>
<form method="POST">
    <div class="form-group">
        <label for="task_name">Task Name:</label>
        <input type="text" class="form-control" name="task_name" id="task_name" required>
    </div>
    <div class="form-group">
        <label for="priority">Priority:</label>
        <select name="priority" class="form-control" id="priority">
            <option value="Critical">Critical</option>
            <option value="High">High</option>
            <option value="Medium">Medium</option>
            <option value="Low">Low</option>
        </select>
    </div>
    <div class="form-group">
        <label for="effort">Effort:</label>
        <select name="effort" class="form-control" id="effort">
            <option value="High">High</option>
            <option value="Medium">Medium</option>
            <option value="Low">Low</option>
        </select>
    </div>
    <div class="form-group">
        <label for="mandays">Mandays:</label>
        <input type="number" class="form-control" name="mandays" id="mandays" required>
    </div>
    <div class="form-group">
        <label for="due_date">Due Date:</label>
        <input type="date" class="form-control" name="due_date" id="due_date" required>
    </div>
    <button type="submit" name="create_task" class="btn btn-primary">Create Task</button>
</form>
<br>
<br>
<hr/>
<br>
<br>
<h3>All Tasks</h3>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Task Name</th>
            <th>Priority</th>
            <th>Effort</th>
            <th>Mandays</th>
            <th>Due Date</th>
            <th>Task Score</th>
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
                    <form method="POST" style="display:inline-block;">
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
