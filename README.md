#### User-Based Task Prioritizer

A lightweight Task Prioritizer built with PHP and MySQL, designed to help you manage tasks dynamically. This system calculates task scores based on priority, effort, mandays, and due dates, and sorts tasks by their scores in real-time.

Demo: https://todo.code-x.my/

#### Features

- **User Authentication**:
  - Manual registration and login with secure password hashing
  - **Google OAuth 2.0 Login**: Sign in with your Google account (optional, configurable)
- **Dynamic Task Scoring**:
  - Scores are calculated dynamically based on priority, effort, mandays, and due dates.
  - Tasks are automatically sorted by their scores in descending order
  - Adaptive scoring modes: URGENT for approaching deadlines, STRATEGIC for long-term planning
- **Task Management**:
  - Add tasks with details such as priority, effort, mandays, and due date
  - Edit task names
  - Mark tasks as "In Progress"
  - Delete tasks easily
  - User isolation: users can only see their own tasks
- **MySQL Database**: Robust database with user management and task storage
- **Docker Support**: Run the application in a Docker container for easy deployment
- **Security**: Prepared statements, bcrypt password hashing, secure session management, CSRF protection

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
- **PHP**: If running locally, ensure PHP 7.4+ is installed with PDO MySQL extension
- **Composer**: Required for installing dependencies. [Get Composer](https://getcomposer.org/)

##### Using Docker

1. Clone the Repository:

   ```bash
   git clone https://github.com/exrienz/TaskPrioritizer.git
   cd TaskPrioritizer
   ```

2. Configure Environment Variables:

   ```bash
   cp .env.example .env
   # Edit .env with your database and OAuth settings (optional)
   ```

3. Start the Application:

   ```bash
   docker-compose up -d
   ```

4. Access the Application:
   Open your browser and navigate to `http://localhost:8080`

5. Stop the Application:

   ```bash
   docker-compose down
   ```

##### Running Locally (Without Docker)

1. Clone the Repository:

   ```bash
   git clone https://github.com/exrienz/TaskPrioritizer.git
   cd TaskPrioritizer
   ```

2. Install Dependencies:

   ```bash
   composer install
   ```

3. Set Up the Database:
   - Install MySQL server locally
   - Create a database and configure credentials in `.env` file
   - The application will automatically create required tables on first run

4. Configure Environment:

   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

5. Run the Application:
   - Place the project files in your web server's root directory (e.g., `htdocs` for XAMPP or `www` for WAMP)
   - Start your web server and navigate to `http://localhost/TaskPrioritizer/src/index.php`

#### Google OAuth Setup (Optional)

Google OAuth login is optional and can be enabled via configuration. To set up Google OAuth:

1. **Create Google Cloud Project**:
   - Go to [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project or select an existing one
   - Enable the Google+ API

2. **Configure OAuth Consent Screen**:
   - Navigate to "APIs & Services" > "OAuth consent screen"
   - Configure your app information
   - Add scopes: `email` and `profile`

3. **Create OAuth 2.0 Credentials**:
   - Navigate to "APIs & Services" > "Credentials"
   - Click "Create Credentials" > "OAuth 2.0 Client ID"
   - Select "Web application"
   - Add authorized redirect URIs:
     - For local development: `http://localhost:8080/index.php`
     - For production: `https://yourdomain.com/index.php`
   - Save and copy your Client ID and Client Secret

4. **Configure Environment Variables**:

   Edit your `.env` file:
   ```
   GOOGLE_OAUTH_ENABLED=true
   GOOGLE_CLIENT_ID=your_client_id_here
   GOOGLE_CLIENT_SECRET=your_client_secret_here
   GOOGLE_REDIRECT_URI=http://localhost:8080/index.php
   ```

5. **Security Requirements**:
   - OAuth must use HTTPS in production (localhost is exempt for development)
   - Redirect URIs must match exactly what's configured in Google Cloud Console
   - CSRF protection is automatically handled via state parameter validation

6. **Deployment Notes**:
   - Never commit `.env` file to version control
   - Update `GOOGLE_REDIRECT_URI` for your production domain
   - Manual login remains available even when OAuth is enabled
   - OAuth can be disabled anytime by setting `GOOGLE_OAUTH_ENABLED=false`

#### How to Use

1. **Register an Account**:
   - Click the "Register" tab on the login page
   - Enter your username, email, and password
   - Click "Register" to create your account

2. **Log In**:
   - **Option 1 - Manual Login**: Enter your username/email and password
   - **Option 2 - Google OAuth** (if enabled): Click "Continue with Google" button
   - First-time Google users will have an account automatically created
   - Existing users can link their Google account to their manual account

3. **Manage Tasks**:
   - Add tasks by filling out the task form with details such as task name, priority, effort, mandays, and due date
   - View all tasks sorted by their scores in descending order
   - Edit task names inline
   - Mark tasks as "In Progress"
   - Delete tasks using the "Delete" button

4. **Dynamic Task Scoring**:
   - Task scores are recalculated dynamically based on the current date
   - Tasks display their mode (URGENT or STRATEGIC) and current score
   - Overdue and approaching deadline tasks are highlighted

#### Project Structure

```
TaskPrioritizer/
├── src/
│   └── index.php          # Main application file
├── docker-compose.yml     # Docker orchestration
├── dockerfile             # Docker configuration
├── composer.json          # PHP dependencies
├── .env.example           # Environment variables template
├── .env                   # Environment variables (not in git)
├── init.sql               # MySQL initialization script
├── README.md              # Project documentation
├── CLAUDE.md              # AI assistant guidance
└── CHANGELOG.md           # Version history
```

#### Technologies Used

- **Backend**: PHP 8.1
- **Database**: MySQL 8.0
- **Authentication**: Bcrypt password hashing, Google OAuth 2.0
- **Frontend**: HTML, Bootstrap 5 (CSS Framework)
- **Containerization**: Docker + Docker Compose
- **Dependencies**: Google API PHP Client (via Composer)

#### Future Enhancements

- Add full task editing (priority, effort, mandays, due date)
- Add filters and search functionality for tasks
- Export tasks to CSV or Excel
- Additional OAuth providers (GitHub, Microsoft, etc.)
- Task categories and tags
- Task collaboration and sharing

#### License

This project is licensed under the MIT License. You are free to use, modify, and distribute this project as per the license terms.
