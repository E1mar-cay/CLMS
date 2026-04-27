<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';

/**
 * Seed script for fast local backend/database testing.
 * Run: php tests/db_seed.php
 */

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS table_count
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute(['table_name' => $tableName]);

    return (int) ($stmt->fetch()['table_count'] ?? 0) > 0;
}

function randomString(int $length): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $output = '';
    for ($i = 0; $i < $length; $i++) {
        $output .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $output;
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_instructors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            instructor_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_course_instructor (course_id, instructor_user_id),
            CONSTRAINT fk_course_instructors_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            CONSTRAINT fk_course_instructors_user FOREIGN KEY (instructor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $tablesToTruncate = [
        'student_responses',
        'exam_attempts',
        'answers',
        'questions',
        'user_progress',
        'modules',
        'certificates',
        'course_instructors',
        'courses',
    ];

    $protectedEmails = ['elmarcayaba39@gmail.com'];
    $protectedRoles = ['admin', 'instructor'];

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tablesToTruncate as $table) {
        if (tableExists($pdo, $table)) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
    }

    $preservedUserCount = 0;
    if (tableExists($pdo, 'users')) {
        $emailPlaceholders = [];
        $deleteParams = [];
        foreach ($protectedEmails as $index => $email) {
            $placeholder = ':protected_email_' . $index;
            $emailPlaceholders[] = $placeholder;
            $deleteParams[ltrim($placeholder, ':')] = $email;
        }
        $rolePlaceholders = [];
        foreach ($protectedRoles as $index => $role) {
            $placeholder = ':protected_role_' . $index;
            $rolePlaceholders[] = $placeholder;
            $deleteParams[ltrim($placeholder, ':')] = $role;
        }

        $deleteSql = 'DELETE FROM users WHERE email NOT IN (' . implode(', ', $emailPlaceholders) . ')'
            . ' AND role NOT IN (' . implode(', ', $rolePlaceholders) . ')';
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute($deleteParams);

        $countSql = 'SELECT COUNT(*) AS preserved_total FROM users WHERE email IN (' . implode(', ', $emailPlaceholders) . ')'
            . ' OR role IN (' . implode(', ', $rolePlaceholders) . ')';
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($deleteParams);
        $preservedUserCount = (int) ($countStmt->fetch()['preserved_total'] ?? 0);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $courseSeed = [
        ['Crime Scene Investigation Fundamentals', 'Core principles for evidence handling and scene documentation.', 75.00],
        ['Forensic Interview and Interrogation', 'Best practices for witness interview strategy and legal boundaries.', 78.00],
        ['Criminal Law and Case Analysis', 'Applied legal interpretation for criminology casework.', 80.00],
    ];

    $courseInsertStmt = $pdo->prepare(
        'INSERT INTO courses (title, description, passing_score_percentage, is_published)
         VALUES (:title, :description, :passing_score_percentage, 1)'
    );
    $courseIds = [];
    foreach ($courseSeed as $course) {
        $courseInsertStmt->execute([
            'title' => $course[0],
            'description' => $course[1],
            'passing_score_percentage' => number_format((float) $course[2], 2, '.', ''),
        ]);
        $courseIds[] = (int) $pdo->lastInsertId();
    }

    $moduleInsertStmt = $pdo->prepare(
        'INSERT INTO modules (course_id, title, video_url, duration_minutes, sequence_order)
         VALUES (:course_id, :title, :video_url, :duration_minutes, :sequence_order)'
    );

    $moduleCounter = 1;
    $moduleIdsByCourse = [];
    foreach ($courseIds as $index => $courseId) {
        $moduleCountForCourse = $index < 2 ? 3 : 4; // total 10 modules
        for ($sequence = 1; $sequence <= $moduleCountForCourse; $sequence++) {
            $moduleInsertStmt->execute([
                'course_id' => $courseId,
                'title' => "Module {$moduleCounter}: Criminology Topic {$sequence}",
                'video_url' => "https://example.com/videos/crim-course-{$courseId}-module-{$sequence}.mp4",
                'duration_minutes' => random_int(15, 55),
                'sequence_order' => $sequence,
            ]);
            $moduleIdsByCourse[$courseId][] = (int) $pdo->lastInsertId();
            $moduleCounter++;
        }
    }

    $staffSeed = [
        [
            'role' => 'admin',
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'email' => 'admin@clms.local',
            'password' => 'Admin@123',
        ],
        [
            'role' => 'instructor',
            'first_name' => 'Default',
            'last_name' => 'Instructor',
            'email' => 'instructor@clms.local',
            'password' => 'Instructor@123',
        ],
    ];

    $upsertStaffStmt = $pdo->prepare(
        'INSERT INTO users (role, first_name, last_name, email, password_hash)
         VALUES (:role, :first_name, :last_name, :email, :password_hash)
         ON DUPLICATE KEY UPDATE
            role = VALUES(role),
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            password_hash = VALUES(password_hash)'
    );

    $instructorUserId = null;
    foreach ($staffSeed as $staffUser) {
        $upsertStaffStmt->execute([
            'role' => $staffUser['role'],
            'first_name' => $staffUser['first_name'],
            'last_name' => $staffUser['last_name'],
            'email' => $staffUser['email'],
            'password_hash' => password_hash($staffUser['password'], PASSWORD_DEFAULT),
        ]);

        if ($staffUser['role'] === 'instructor') {
            $lookupStaffStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $lookupStaffStmt->execute(['email' => $staffUser['email']]);
            $staffRow = $lookupStaffStmt->fetch();
            if ($staffRow) {
                $instructorUserId = (int) $staffRow['id'];
            }
        }
    }

    if ($instructorUserId !== null && tableExists($pdo, 'course_instructors')) {
        $assignInstructorStmt = $pdo->prepare(
            'INSERT IGNORE INTO course_instructors (course_id, instructor_user_id)
             VALUES (:course_id, :instructor_user_id)'
        );
        foreach ($courseIds as $courseId) {
            $assignInstructorStmt->execute([
                'course_id' => $courseId,
                'instructor_user_id' => $instructorUserId,
            ]);
        }
    }

    $studentInsertStmt = $pdo->prepare(
        "INSERT INTO users (role, first_name, last_name, email, password_hash)
         VALUES ('student', :first_name, :last_name, :email, :password_hash)"
    );

    $defaultPasswordHash = password_hash('Student@123', PASSWORD_DEFAULT);
    $studentIds = [];
    for ($i = 1; $i <= 50; $i++) {
        $firstName = 'Student' . $i;
        $lastName = 'User' . random_int(100, 999);
        $email = strtolower($firstName . '.' . $lastName . '@clms.local');

        $studentInsertStmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $defaultPasswordHash,
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $progressInsertStmt = $pdo->prepare(
        'INSERT INTO user_progress (user_id, module_id, is_completed, last_watched_second, completed_at)
         VALUES (:user_id, :module_id, :is_completed, :last_watched_second, :completed_at)'
    );
    foreach ($studentIds as $studentId) {
        foreach ($moduleIdsByCourse as $moduleIds) {
            foreach ($moduleIds as $moduleId) {
                $isCompleted = random_int(0, 100) <= 55 ? 1 : 0;
                $progressInsertStmt->execute([
                    'user_id' => $studentId,
                    'module_id' => $moduleId,
                    'is_completed' => $isCompleted,
                    'last_watched_second' => $isCompleted ? random_int(600, 3600) : random_int(0, 900),
                    'completed_at' => $isCompleted ? date('Y-m-d H:i:s') : null,
                ]);
            }
        }
    }

    $attemptInsertStmt = $pdo->prepare(
        'INSERT INTO exam_attempts
            (user_id, course_id, status, total_score, is_passed, attempted_at, completed_at)
         VALUES
            (:user_id, :course_id, :status, :total_score, :is_passed, :attempted_at, :completed_at)'
    );

    $statuses = ['in_progress', 'pending_manual_grade', 'completed'];
    for ($i = 0; $i < 100; $i++) {
        $courseId = $courseIds[array_rand($courseIds)];
        $studentId = $studentIds[array_rand($studentIds)];
        $status = $statuses[array_rand($statuses)];
        $score = random_int(35, 100);
        $attemptedAt = date('Y-m-d H:i:s', time() - random_int(3600, 86400 * 15));
        $completedAt = $status === 'in_progress'
            ? null
            : date('Y-m-d H:i:s', strtotime($attemptedAt) + random_int(600, 7200));

        $attemptInsertStmt->execute([
            'user_id' => $studentId,
            'course_id' => $courseId,
            'status' => $status,
            'total_score' => number_format((float) $score, 2, '.', ''),
            'is_passed' => $score >= 75 ? 1 : 0,
            'attempted_at' => $attemptedAt,
            'completed_at' => $completedAt,
        ]);
    }

    echo "Seed complete.\n";
    echo "Courses: 3\n";
    echo "Modules: 10\n";
    echo "Students: 50 (newly seeded)\n";
    echo "Preserved users (admin / instructor / protected emails): {$preservedUserCount}\n";
    echo "Exam Attempts: 100\n";
    echo "\nDefault credentials:\n";
    echo "  Admin       -> admin@clms.local       / Admin@123\n";
    echo "  Instructor  -> instructor@clms.local  / Instructor@123\n";
    echo "  Student     -> <seeded email>         / Student@123\n";
} catch (Throwable $e) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "Seed failed: {$e->getMessage()}\n");
    exit(1);
}
