# Criminology Learning Management System (CLMS)

CLMS is a PHP + MySQL web application for criminology review centers with role-based dashboards for students, instructors, and administrators.

## Features

- Public landing page with dynamic featured courses
- Student workflow:
  - Browse published courses
  - Enroll and track module progress
  - Take final exams
  - Download generated certificates
- Instructor workflow:
  - Manage course questions/content
  - Monitor assigned course performance
  - Review student activity and outcomes
- Admin workflow:
  - Manage users, students, courses, and announcements
  - View reports and analytics dashboards
- Persistent sidebar behavior and shared dashboard UI system
- CSRF protection, session-based authentication, and role guards

## Tech Stack

- PHP 8+
- MySQL / MariaDB (PDO)
- Bootstrap 5 + Boxicons
- Sneat-based dashboard shell with custom CLMS theming

## Project Structure

- `index.php` - Public landing page
- `login.php`, `register.php`, `logout.php` - Authentication
- `admin/` - Admin dashboards and management pages
- `instructor/` - Instructor dashboards and content management
- `student/` - Student dashboard, modules, exams, certificates
- `includes/` - Shared auth, layout, navbar/footer, helpers
- `public/assets/css/` - Custom shared styles (`custom.css`, `dashboard-clean.css`, `auth-public.css`, `student-dashboard.css`)
- `database.php` - Shared PDO connection

## Local Setup (XAMPP)

1. Clone/copy the project into your web root:

```bash
c:\xampp\htdocs\CLMS
```

2. Create the database (default name: `clms_db`).
3. Import your schema/data (if you have an SQL dump).
4. Configure DB credentials using environment variables (optional) or defaults in `database.php`:
   - `CLMS_DB_HOST` (default `127.0.0.1`)
   - `CLMS_DB_NAME` (default `clms_db`)
   - `CLMS_DB_USER` (default `root`)
   - `CLMS_DB_PASS` (default empty)
5. Start Apache and MySQL in XAMPP.
6. Open:

```text
http://localhost/CLMS/
```

## Default Routing

- Visitors: `index.php` (public landing page)
- Logged-in users are redirected by role:
  - Admin -> `admin/dashboard.php`
  - Instructor -> `instructor/dashboard.php`
  - Student -> `student/dashboard.php`

## Development Notes

- Shared app shell styles: `public/assets/css/custom.css`
- Shared dashboard style system: `public/assets/css/dashboard-clean.css`
- Shared auth page styles: `public/assets/css/auth-public.css`
- Student dashboard-specific styles: `public/assets/css/student-dashboard.css`

## License

This project currently uses the repository `LICENSE` file.
