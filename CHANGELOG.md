# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Kanban Board UI**: Drag-and-drop task board with four columns (Backlog, To Do, In Progress, Done)
  - Tasks sorted by urgency mode (URGENT/STRATEGIC) and priority score within each column
  - Real-time due-date countdown badges with color-coded urgency indicators
  - Visual task cards with priority, effort, mandays, and scoring information
  - Edit and delete actions directly on Kanban cards

- **Task Description & Assignee**: Extended task model with optional description and assignee fields
  - Added `description` (TEXT) and `assignee` (VARCHAR) columns to tasks table
  - Both fields are editable via the Kanban card edit modal

- **Kanban Backend APIs**: New PHP endpoints for Kanban operations
  - `move_task` - Move task between columns with automatic sort-order management
  - `update_task_details` - Batch update task fields (title, description, assignee, priority, due date, status)
  - `delete_task_ajax` - Asynchronous task deletion with confirmation prompt
  - All operations use row-level locking for data consistency

- **Drag-and-Drop JavaScript**: Client-side Kanban interactions
  - Native HTML5 drag-and-drop with visual drop-zone indicators
  - Position-aware insertion (tasks reorder within columns)
  - Automatic column count badges update
  - Optimistic UI updates with server reconciliation

- **Sort Order Management**: Automatic sequencing of tasks within each status column
  - Tasks maintain a stable `sort_order` integer per user/status
  - Moving a task shifts sibling positions to maintain gaps
  - New tasks auto-assigned to next available order in target column

- **Ranked Task Cards Section**: Expandable/collapsible task listing with expand-for-details pattern
  - Collapsed by default; state persisted in sessionStorage
  - Task details (description, priority, effort, mandays) shown on expand

- **Edit Task Modal**: Bootstrap modal form for inline task editing
  - Updates title, description, assignee, priority, due date, and status
  - Validates inputs server-side before applying changes

### Changed

- **Port Changed**: Application port updated from `8080` to `8001` in docker-compose.yml
- **Kanban Status Migration**: Existing `in_progress` tasks automatically migrated to `in_progress` status; all others to `todo`
- **Sort Order Migration**: Existing tasks auto-assigned sequential `sort_order` values sorted by creation time
- **Description Column Migration**: Added `description` and `assignee` columns with backward-compatible migration

### Fixed

- **Drag-and-Drop Consistency**: Sort order correctly maintained when moving tasks within and across columns

---

## [1.1.0] - 2026-01-15

### Added

- **Google OAuth 2.0 Login**: Users can now authenticate using their Google account
  - "Continue with Google" button on login page
  - Automatic user account creation for first-time Google sign-ins
  - Account linking for existing users who log in with Google
  - CSRF protection via OAuth state parameter validation
  - Secure token validation and verification

- **OAuth Configuration**:
  - Feature flag `GOOGLE_OAUTH_ENABLED` to enable/disable OAuth without code changes
  - Environment variables for OAuth credentials (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`)
  - Safe failure handling with clear error messages when OAuth is misconfigured
  - HTTPS enforcement in production (with localhost exemption for development)

- **Database Schema Updates**:
  - Added `oauth_provider` column to users table (supports multiple OAuth providers)
  - Added `oauth_id` column to store provider-specific user IDs
  - Made `password_hash` column nullable for OAuth-only users
  - Added unique constraint for OAuth provider/ID combinations
  - Backward-compatible migration for existing databases

- **Security Enhancements**:
  - CSRF state validation for OAuth flows
  - Secure session cookie settings (Secure, HttpOnly, SameSite)
  - OAuth token signature and expiration verification
  - Email verification requirement for Google accounts
  - No sensitive data (tokens, secrets, PII) in production logs

- **Documentation**:
  - Comprehensive Google OAuth setup guide in README.md
  - `.env.example` template with all required OAuth variables
  - Detailed configuration instructions and security requirements
  - Deployment notes and rollback safety information

### Changed

- Updated database schema to support OAuth authentication
- Enhanced session security settings with SameSite attribute
- Improved error handling and user-friendly error messages
- Updated Dockerfile to include Composer and Google API Client library

### Fixed

- Password hash column now properly nullable for OAuth users
- Database migration logic prevents duplicate column additions

### Security

- All OAuth flows include CSRF protection
- Tokens validated for issuer, audience, expiration, and signature
- Production deployments require HTTPS for OAuth
- Secure cookie settings enforced (Secure, HttpOnly, SameSite=Lax)

### Deployment Notes

- No breaking changes to existing manual login functionality
- Database migrations are backward-compatible and safe
- OAuth can be enabled/disabled via `GOOGLE_OAUTH_ENABLED` flag
- Manual login continues to work regardless of OAuth configuration
- Rollback-safe: disabling OAuth does not affect existing user accounts

---

## [1.0.0] - 2025-01-XX

### Added

- Initial release with manual user authentication
- User registration and login with bcrypt password hashing
- Task management (create, read, update, delete)
- Dynamic task scoring based on priority, effort, mandays, and due dates
- Adaptive scoring modes (URGENT and STRATEGIC)
- MySQL database with user isolation
- Docker support with docker-compose
- Bootstrap 5 UI with responsive design
- Session management with security configurations
- User-specific task isolation
