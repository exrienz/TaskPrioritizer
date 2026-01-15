# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
