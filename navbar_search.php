<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/database.php';

clms_require_roles(['admin', 'instructor', 'student']);

header('Content-Type: application/json; charset=UTF-8');

$queryRaw = isset($_GET['q']) ? (string) $_GET['q'] : '';
$query = trim($queryRaw);
if ($query === '') {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_SLASHES);
    exit;
}

$queryLike = '%' . $query . '%';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? 'student');

$results = [];

/**
 * @param array<string,mixed> $item
 */
$pushResult = static function (array $item) use (&$results): void {
    $key = strtolower(((string) ($item['url'] ?? '')) . '|' . ((string) ($item['title'] ?? '')));
    if ($key === '') {
        return;
    }
    if (!isset($results[$key])) {
        $results[$key] = $item;
    }
};

if ($role === 'admin') {
    try {
        $userStmt = $pdo->prepare(
            "SELECT id, first_name, last_name, email, role
             FROM users
             WHERE (first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)
             ORDER BY first_name ASC, last_name ASC
             LIMIT 5"
        );
        $userStmt->execute(['q' => $queryLike]);
        foreach ($userStmt->fetchAll() as $row) {
            $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($name === '') {
                $name = (string) $row['email'];
            }
            $pushResult([
                'title' => $name,
                'subtitle' => 'User: ' . ucfirst((string) $row['role']) . ' - ' . (string) $row['email'],
                'url' => $clmsWebBase . '/admin/users.php',
                'icon' => 'bx-user',
            ]);
        }
    } catch (Throwable $e) {
        error_log('navbar_search admin users failed: ' . $e->getMessage());
    }
}

if ($role === 'admin' || $role === 'instructor') {
    try {
        if ($role === 'admin') {
            $courseStmt = $pdo->prepare(
                "SELECT c.id, c.title, c.description
                 FROM courses c
                 WHERE c.title LIKE :q OR c.description LIKE :q
                 ORDER BY c.title ASC
                 LIMIT 6"
            );
            $courseStmt->execute(['q' => $queryLike]);
        } else {
            $courseStmt = $pdo->prepare(
                "SELECT c.id, c.title, c.description
                 FROM courses c
                 LEFT JOIN course_instructors ci
                   ON ci.course_id = c.id
                  AND ci.instructor_user_id = :instructor_id
                 WHERE (c.title LIKE :q OR c.description LIKE :q)
                   AND (
                     ci.instructor_user_id IS NOT NULL
                     OR NOT EXISTS (SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id)
                   )
                 ORDER BY c.title ASC
                 LIMIT 6"
            );
            $courseStmt->execute([
                'q' => $queryLike,
                'instructor_id' => $userId,
            ]);
        }

        foreach ($courseStmt->fetchAll() as $row) {
            $pushResult([
                'title' => (string) $row['title'],
                'subtitle' => 'Course',
                'url' => $role === 'admin'
                    ? $clmsWebBase . '/admin/courses.php'
                    : $clmsWebBase . '/instructor/add_question.php?course_id=' . (int) $row['id'],
                'icon' => 'bx-book',
            ]);
        }
    } catch (Throwable $e) {
        error_log('navbar_search courses failed: ' . $e->getMessage());
    }
}

if ($role === 'student') {
    try {
        $moduleStmt = $pdo->prepare(
            "SELECT DISTINCT
                m.id AS module_id,
                m.title AS module_title,
                c.title AS course_title
             FROM modules m
             INNER JOIN courses c ON c.id = m.course_id
             INNER JOIN user_enrollments ue ON ue.course_id = c.id
             WHERE ue.user_id = :user_id
               AND (m.title LIKE :q OR c.title LIKE :q)
             ORDER BY c.title ASC, m.id ASC
             LIMIT 8"
        );
        $moduleStmt->execute([
            'user_id' => $userId,
            'q' => $queryLike,
        ]);

        foreach ($moduleStmt->fetchAll() as $row) {
            $pushResult([
                'title' => (string) $row['module_title'],
                'subtitle' => 'Module - ' . (string) $row['course_title'],
                'url' => $clmsWebBase . '/student/view_module.php?module_id=' . (int) $row['module_id'],
                'icon' => 'bx-play-circle',
            ]);
        }
    } catch (Throwable $e) {
        error_log('navbar_search student modules failed: ' . $e->getMessage());
    }
}

if ($role === 'instructor') {
    try {
        $studentStmt = $pdo->prepare(
            "SELECT DISTINCT
                u.id,
                u.first_name,
                u.last_name,
                u.email
             FROM users u
             INNER JOIN exam_attempts ea ON ea.user_id = u.id
             INNER JOIN courses c ON c.id = ea.course_id
             LEFT JOIN course_instructors ci
               ON ci.course_id = c.id
              AND ci.instructor_user_id = :instructor_id
             WHERE u.role = 'student'
               AND (
                 ci.instructor_user_id IS NOT NULL
                 OR NOT EXISTS (SELECT 1 FROM course_instructors ci2 WHERE ci2.course_id = c.id)
               )
               AND (u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q)
             ORDER BY u.first_name ASC, u.last_name ASC
             LIMIT 5"
        );
        $studentStmt->execute([
            'instructor_id' => $userId,
            'q' => $queryLike,
        ]);

        foreach ($studentStmt->fetchAll() as $row) {
            $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($fullName === '') {
                $fullName = (string) $row['email'];
            }
            $pushResult([
                'title' => $fullName,
                'subtitle' => 'Student - ' . (string) $row['email'],
                'url' => $clmsWebBase . '/instructor/student_activity.php',
                'icon' => 'bx-user',
            ]);
        }
    } catch (Throwable $e) {
        error_log('navbar_search instructor students failed: ' . $e->getMessage());
    }
}

$quickLinks = [
    'admin' => [
        ['Dashboard', '/admin/dashboard.php', 'bx-home'],
        ['Students', '/admin/students.php', 'bx-user'],
        ['Users', '/admin/users.php', 'bx-group'],
        ['Courses', '/admin/courses.php', 'bx-book'],
        ['Announcements', '/admin/announcements.php', 'bx-bell'],
        ['Student Activity', '/admin/student_activity.php', 'bx-pulse'],
        ['Reports', '/admin/reports.php', 'bx-file'],
        ['Course Rankings', '/admin/rankings.php', 'bx-trophy'],
        ['Data Analytics', '/admin/data_analytics.php', 'bx-bar-chart-alt-2'],
        ['User Guide', '/admin/users_guide.php', 'bx-help-circle'],
        ['Settings', '/admin/settings.php', 'bx-cog'],
    ],
    'instructor' => [
        ['Dashboard', '/instructor/dashboard.php', 'bx-home'],
        ['Manage Content', '/instructor/add_question.php', 'bx-folder-plus'],
        ['Course Rankings', '/instructor/rankings.php', 'bx-trophy'],
        ['Student Activity', '/instructor/student_activity.php', 'bx-pulse'],
    ],
    'student' => [
        ['Dashboard', '/student/dashboard.php', 'bx-home'],
        ['View Modules', '/student/view_module.php', 'bx-play-circle'],
        ['Take Final Exam', '/student/take_exam.php', 'bx-edit'],
    ],
];

foreach (($quickLinks[$role] ?? []) as $entry) {
    [$title, $path, $icon] = $entry;
    if (stripos($title, $query) !== false) {
        $pushResult([
            'title' => $title,
            'subtitle' => 'Quick Link',
            'url' => $clmsWebBase . $path,
            'icon' => $icon,
        ]);
    }
}

$items = array_slice(array_values($results), 0, 10);

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
exit;

