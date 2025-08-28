# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a User-Based Task Prioritizer built with PHP and MySQL. It's a single-file application (`src/index.php`) that manages tasks with dynamic scoring based on priority, effort, mandays, and due dates. The application features proper user registration and authentication with secure password hashing.

## Architecture

- **Single-file application**: All logic is contained in `src/index.php`
- **Database**: MySQL database with two main tables:
  - `users`: Stores user accounts with secure password hashing
  - `tasks`: Stores task data with columns for priority, effort, mandays, due_date, and in_progress status
- **Authentication**: User registration and login system with bcrypt password hashing
- **Frontend**: Embedded HTML with Bootstrap CSS framework and tabbed login/registration interface
- **Session management**: PHP sessions with security configurations
- **Environment Variables**: Database configuration stored securely in `.env` file

## Key Components

### Task Scoring Algorithm (`calculateTaskScore` function)
Tasks are scored using a weighted formula:
- Criticality weight: 50 (Priority: Optional=1, Low=2, Medium=3, High=4, Critical=5)
- Effort weight: 20 (Low=1, Medium=2, High=3, Very High=4) - inverse relationship
- Mandays weight: 15 (inverse relationship)
- Urgency: Up to 80 points for approaching deadlines, 100 for overdue tasks

Tasks are automatically sorted by score in descending order.

### Database Schema
```sql
-- users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- tasks table  
CREATE TABLE tasks (
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
);
```

## Development Commands

### Docker Development (Recommended)
```bash
# Start the application with MySQL
docker-compose up -d

# Stop the application
docker-compose down

# Rebuild after changes
docker-compose up --build

# Access the application
open http://localhost:8080

# View logs
docker-compose logs app
docker-compose logs mysql
```

### Environment Configuration
- Copy and customize the `.env` file with your database credentials
- Never commit the `.env` file to version control
- The application reads database configuration from environment variables

## File Structure
```
TaskPrioritizer/
├── src/index.php          # Main application file (all logic here)
├── docker-compose.yml     # Docker orchestration with MySQL
├── dockerfile             # Docker configuration for PHP app
├── init.sql               # MySQL database initialization
├── .env                   # Environment variables (not in git)
├── .gitignore             # Git ignore rules
├── README.md              # Project documentation
└── CLAUDE.md              # This file
```

## Security Features
- Session security settings (secure, httponly, strict mode)
- Prepared SQL statements with PDO to prevent injection
- Bcrypt password hashing for user authentication
- Environment variables for database configuration
- Database credentials never hardcoded in source code
- User input sanitization with `htmlspecialchars()`

## Core Functionality
- User registration with secure password hashing
- User login and session management
- Task CRUD operations (Create, Read, Update, Delete)
- Dynamic task scoring and automatic sorting
- Task status management (in progress marking)
- Basic task editing (name only)
- User isolation (users can only see their own tasks)

## Notes for Development
- Database schema is initialized via `init.sql` on first MySQL container startup
- All user input is sanitized with `htmlspecialchars()`
- Session management is handled at the top of the file
- Tasks are re-scored and re-sorted on each page load
- Each user can only access their own tasks (isolated by `user_id`)
- Password strength requirements: minimum 6 characters
- Bootstrap JavaScript is included for tabbed login/registration interface