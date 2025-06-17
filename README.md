#### JWT Task Prioritizer

A lightweight Task Prioritizer built with PHP and SQLite, designed to help you manage tasks dynamically. This system calculates task scores based on priority, effort, mandays, and due dates, and sorts tasks by their scores in real-time.

Demo: https://todo.code-x.my/

#### Features

- **User Authentication with JWT**: Register with a username and password and receive a JWT token on login.
- **Dynamic Task Scoring**:
  - Scores are calculated dynamically based on priority, effort, mandays, and due dates.
  - Tasks are automatically sorted by their scores in descending order. ****
- **Task Management**:
  - Add tasks with details such as priority, effort, mandays, and due date.
  - Delete tasks easily.
- **SQLite Database**: Lightweight and easy-to-use database for storing users and tasks.
- **Docker Support**: Run the application in a Docker container for easy deployment.

#### How Task Scores Are Calculated

Task priority uses a weighted formula that factors in criticality, effort, due date urgency and estimated mandays. Priority and effort values are first mapped to numerical scores (criticality `1-5`, effort `1-4`). The calculation uses the following weights:

- **Criticality weight**: 50
- **Effort weight**: 20
- **Mandays weight**: 15
- **Urgency max**: 80
- **Overdue boost**: 100

Urgency uses a reciprocal formula where tasks become more urgent as the due date approaches, and overdue tasks receive a fixed boost:

```
if days_left < 0:
    urgency = OVERDUE_BOOST
else:
    urgency = URGENCY_MAX / (1 + days_left)

score = (criticality * CRITICALITY_WEIGHT)
      + (EFFORT_WEIGHT / effort)
      + (MANDAYS_WEIGHT / mandays)
      + urgency
```

The score is rounded to two decimal places.

#### Installation

##### Prerequisites

- **Docker**: Ensure Docker is installed on your system. [Get Docker](https://www.docker.com/get-started)
- **PHP**: If running locally, ensure PHP is installed with the SQLite3 extension enabled.

##### Using Docker

1. Clone the Repository:

   ```bash
   git clone https://github.com/exrienz/TaskPrioritizer.git
   cd TaskPrioritizer
   ```

2. Build the Docker Image:

   ```bash
   docker build -t task-prioritizer .
   ```

3. Run the Docker Container:

   ```bash
   docker run -d -p 8080:80 --restart=always --name task-prioritizer-container task-prioritizer
   ```

4. Access the Application:
   Open your browser and navigate to `http://localhost:8080`.

##### Running Locally (Without Docker)

1. Clone the Repository:

   ```bash
   git clone https://github.com/exrienz/TaskPrioritizer.git
   cd TaskPrioritizer
   ```

2. Set Up the Database:
   - Ensure the SQLite3 extension is enabled in your PHP installation.
   - The application will automatically create the required database (`task_management.db`) and tables (`users` and `tasks`) when you run it for the first time.

3. Run the Application:
   - Place the project files in your web server's root directory (e.g., `htdocs` for XAMPP or `www` for WAMP).
   - Start your web server and navigate to `http://localhost/TaskPrioritizer/index.php`.

#### How to Use

1. **Register an Account**:
   - Provide a username and password on the registration form.

2. **Log In**:
   - Sign in with your credentials to receive a JWT token stored in a cookie.

3. **Manage Tasks**:
   - Add tasks by filling out the task form with details such as task name, priority, effort, mandays, and due date. ****
   - View all tasks in a table, sorted by their scores in descending order. ****
   - Delete tasks using the "Delete" button.

4. **Dynamic Task Scoring**:
   - Task scores are recalculated dynamically based on the current date and displayed in the task list. ****

#### Project Structure

```
TaskPrioritizer/
├── index.php          # Main application file
├── Dockerfile         # Docker configuration file
├── task_management.db # SQLite database (auto-created)
├── README.md          # Project documentation
└── assets/            # (Optional) Add CSS/JS files here if needed
```

#### Technologies Used

- Backend: PHP
- Database: SQLite
- Frontend: HTML, Bootstrap (CSS Framework)
- Containerization: Docker

#### Future Enhancements

- Add user registration and authentication.
- Implement task editing functionality.
- Add filters and search functionality for tasks.
- Export tasks to CSV or Excel.

#### License

This project is licensed under the MIT License. You are free to use, modify, and distribute this project as per the license terms.
