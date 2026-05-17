# Two-Track Enrollment System - Administrator Interface
## Implementation Summary

Created on: May 17, 2026
Files Created: 2
Total Lines of Code: ~500

---

## Files Created

### 1. `admin/pending_enrollments.php` (Main Interface Page)
**Location:** `c:\xampp\htdocs\CLMS\admin\pending_enrollments.php`
**Size:** ~380 lines

**Features:**
- Admin-only access (role-based authentication)
- Lists all pending user registrations with status = 'pending'
- Displays DataTable with columns:
  - Row number
  - Student Name (first_name + last_name)
  - Email
  - University Name
  - Requested Track (Regular or Enhancement with color-coded badges)
  - Registration Date (formatted as "Mon DD, YYYY HH:MM")
  - Action buttons (Approve/Reject)
- Search functionality (searches name, email, university)
- Pagination (15 items per page)
- Responsive design matching Sneat template
- Uses Bootstrap 5 styling
- Flash message display for success/error feedback

**Database Migrations:**
The page automatically ensures these tables/columns exist:
```sql
-- Enrollments table
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    review_track VARCHAR(50) NOT NULL DEFAULT 'Regular',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_track (user_id, review_track),
    CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_enrollments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users table modifications
ALTER TABLE users ADD COLUMN university_name VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN review_track ENUM('Regular', 'Enhancement') NULL DEFAULT 'Regular';
```

---

### 2. `admin/process_enrollment.php` (Backend Handler)
**Location:** `c:\xampp\htdocs\CLMS\admin\process_enrollment.php`
**Size:** ~130 lines

**Features:**
- POST-only endpoint (secure form submission)
- CSRF token validation
- Two action modes:
  - **Approve**: Updates user status and creates enrollment record (transactional)
  - **Reject**: Sets user status to 'rejected'
- PDO transaction support for data integrity
- Error handling with try/catch blocks
- Audit logging to PHP error log
- Flash messaging via session variables
- Redirects back to pending_enrollments.php with status message

**Approval Flow (with Transaction):**
```php
1. BEGIN TRANSACTION
2. UPDATE users SET account_approval_status = 'approved', account_approved_at = NOW()
3. INSERT INTO enrollments (user_id, review_track) VALUES (...)
   (ON DUPLICATE KEY UPDATE to handle existing records)
4. COMMIT TRANSACTION
```

**Rejection Flow:**
```php
UPDATE users SET account_approval_status = 'rejected'
```

---

## Usage Instructions

### Accessing the Interface
1. Log in as an Admin user
2. Navigate to: `/admin/pending_enrollments.php`
3. You'll see a paginated list of pending enrollments

### Approving an Enrollment
1. Review the student information (Name, Email, University, Requested Track)
2. Click the green **"Approve"** button
3. The system will:
   - Update their account status to 'active'
   - Create an enrollment record with their requested track
   - Display a success message
   - Return to the pending list

### Rejecting an Enrollment
1. Review the student information
2. Click the red **"Reject"** button
3. The system will:
   - Set their account status to 'rejected'
   - Display a success message
   - Return to the pending list

### Searching Pending Enrollments
1. Type in the search box to filter by:
   - Student name (first or last)
   - Email address
   - University name
2. Results update automatically (500ms debounce)
3. Click **"Clear"** button to reset search

### Pagination
- Shows 15 enrollments per page
- Navigation buttons: First, Previous, [page numbers], Next, Last
- Pagination state preserves search query

---

## Database Schema Details

### users table (Modified)
```
- id: INT (existing)
- first_name: VARCHAR (existing)
- last_name: VARCHAR (existing)
- email: VARCHAR (existing)
- account_approval_status: VARCHAR (existing) - Values: 'pending', 'approved', 'rejected'
- account_approved_at: DATETIME (existing)
- university_name: VARCHAR(255) NULL (NEW)
- review_track: ENUM('Regular', 'Enhancement') NULL (NEW)
- created_at: TIMESTAMP (existing)
```

### enrollments table (New)
```
- id: INT AUTO_INCREMENT PRIMARY KEY
- user_id: INT NOT NULL (FK → users.id)
- review_track: VARCHAR(50) NOT NULL DEFAULT 'Regular'
- created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- Unique Index: (user_id, review_track)
- Foreign Key: user_id → users(id) ON DELETE CASCADE
```

---

## Security Features

✅ **CSRF Protection**
- All forms include CSRF tokens
- Tokens validated server-side in process_enrollment.php

✅ **Role-Based Access Control**
- Both pages require 'admin' role
- User is redirected to login if unauthorized

✅ **SQL Injection Prevention**
- All queries use prepared statements with parameter binding
- No direct string concatenation in SQL

✅ **Transaction Safety**
- Approve action uses database transactions
- Ensures both user update AND enrollment creation succeed or both fail

✅ **Input Validation**
- User ID validated as integer
- Action validated against whitelist ['approve', 'reject']
- Review track validated against enum values

✅ **HTML Escaping**
- All output escaped with htmlspecialchars()
- Prevents XSS attacks

---

## Error Handling

All errors are gracefully handled:

| Error Type | Behavior |
|-----------|----------|
| Invalid request method | Throws RuntimeException, redirects with error |
| Invalid CSRF token | Throws RuntimeException, redirects with error |
| Invalid user ID | Throws RuntimeException, redirects with error |
| Invalid action | Throws RuntimeException, redirects with error |
| User not found or not pending | Throws RuntimeException, redirects with error |
| Database transaction failure | Rollback occurs, redirects with error |
| Success | Flash message set, redirects to pending list |

Errors are logged to PHP error log (error_log) for audit purposes.

---

## Code Quality

✅ **PHP 7.0+ Strict Mode**
- `declare(strict_types=1)` on all files

✅ **Prepared Statements**
- All database queries use PDO prepared statements
- Parameters bound safely using bindValue() and execute()

✅ **Responsive Design**
- Mobile-optimized (tables become responsive at 576px breakpoint)
- Touch-friendly button sizes
- Readable font sizes

✅ **Performance Optimizations**
- Pagination limits results (15 per page)
- Database indexes on foreign keys and frequently searched columns
- Search debounce (500ms) prevents excessive queries

---

## Testing Checklist

- [ ] Navigate to /admin/pending_enrollments.php as admin
- [ ] Verify pending enrollments are listed
- [ ] Test search functionality (by name, email, university)
- [ ] Test pagination (navigate between pages)
- [ ] Click "Approve" - verify success message and enrollment created
- [ ] Verify approved user can now log in
- [ ] Click "Reject" on another pending user
- [ ] Verify rejection message shows and user account is blocked
- [ ] Test CSRF protection (submit form with invalid token)
- [ ] Test role access (try accessing as student/instructor)
- [ ] Verify database changes:
  - Users table has university_name and review_track columns
  - Enrollments table created with proper constraints
  - Approved user appears in enrollments table

---

## Future Enhancement Possibilities

1. **Bulk Operations**: Approve/Reject multiple enrollments at once
2. **Filter by Track**: Filter pending list by Regular vs Enhancement track
3. **Comments/Notes**: Allow admins to add notes before approving/rejecting
4. **Notification Emails**: Send email to student when approved/rejected
5. **Audit Trail**: Track who approved/rejected and when in audit_log table
6. **Batch Import**: Import multiple pending enrollments from CSV
7. **Analytics Dashboard**: Show approval rate, pending time statistics
8. **Auto-approval Rules**: Create rules for auto-approval based on criteria

---

## Support Notes

- Both files follow existing CLMS codebase patterns
- Compatible with existing Sneat template
- Uses existing authentication system (clms_require_roles)
- Uses existing database connection ($pdo)
- Follows existing error handling patterns
- Integrates with existing pagination and search patterns
