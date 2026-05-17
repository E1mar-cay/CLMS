<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

if (!isset($clmsWebBase)) {
    require dirname(__DIR__) . '/includes/sneat-paths.php';
}

clms_require_roles(['admin']);

$pageTitle = 'Pending Enrollments | Criminology LMS';
$activeAdminPage = 'pending_enrollments';
$errorMessage = '';
$successMessage = '';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

/* Check for flash messages from process_enrollment.php */
if (isset($_SESSION['enrollment_success_message'])) {
    $successMessage = (string) $_SESSION['enrollment_success_message'];
    unset($_SESSION['enrollment_success_message']);
}
if (isset($_SESSION['enrollment_error_message'])) {
    $errorMessage = (string) $_SESSION['enrollment_error_message'];
    unset($_SESSION['enrollment_error_message']);
}

/* Ensure the enrollments table exists with review_track column */
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            review_track VARCHAR(50) NOT NULL DEFAULT 'Regular',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_track (user_id, review_track),
            CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_enrollments_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    error_log('enrollments table creation failed: ' . $e->getMessage());
}

/* Ensure users table has required columns */
foreach ([
    'university_name' => 'VARCHAR(255) NULL',
    'review_track' => "ENUM('Regular', 'Enhancement') NULL DEFAULT 'Regular'",
] as $columnName => $columnSpec) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE '" . $columnName . "'")->fetch();
        if (!$check) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $columnName . ' ' . $columnSpec);
        }
    } catch (Throwable $e) {
        error_log('users.' . $columnName . ' migration failed: ' . $e->getMessage());
    }
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$page = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$perPage = 15;
$offset = ($page - 1) * $perPage;

/* Build WHERE clause for pending users */
$whereSql = "WHERE u.role = 'student' AND u.account_approval_status = 'pending'";
$params = [];

if ($searchQuery !== '') {
    $normQ = function_exists('mb_strtolower')
        ? mb_strtolower($searchQuery, 'UTF-8')
        : strtolower($searchQuery);
    $likeLower = '%' . $normQ . '%';
    $whereSql .= ' AND (
        LOWER(u.first_name) LIKE :search_first
        OR LOWER(u.last_name) LIKE :search_last
        OR LOWER(u.email) LIKE :search_email
        OR LOWER(CONCAT(u.first_name, " ", u.last_name)) LIKE :search_full
        OR LOWER(u.university_name) LIKE :search_university
    )';
    $params['search_first'] = $likeLower;
    $params['search_last'] = $likeLower;
    $params['search_email'] = $likeLower;
    $params['search_full'] = $likeLower;
    $params['search_university'] = $likeLower;
}

/* Count total pending enrollments */
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_rows
     FROM users u
     {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['total_rows'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* Fetch pending enrollments */
$enrollmentStmt = $pdo->prepare(
    "SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        COALESCE(u.university_name, 'N/A') AS university_name,
        COALESCE(u.review_track, 'Regular') AS review_track,
        u.created_at AS registration_date
     FROM users u
     {$whereSql}
     ORDER BY u.created_at DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $enrollmentStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$enrollmentStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$enrollmentStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

try {
    $enrollmentStmt->execute();
    $enrollments = $enrollmentStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Failed to fetch pending enrollments: ' . $e->getMessage());
    $enrollments = [];
    $errorMessage = 'Failed to load pending enrollments.';
}

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-1">Pending Enrollments</h4>
        <small class="text-muted">Review and approve/reject pending user registrations for the Two-Track enrollment system.</small>
    </div>
</div>

<?php if ($errorMessage !== '') : ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage !== '') : ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card" id="clms-enrollments-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <h5 class="mb-0">Enrollment Requests</h5>
        <form id="clms-enrollments-search-form" class="clms-enrollments-toolbar-form d-flex flex-wrap justify-content-end gap-2" method="get">
            <div class="clms-enrollments-search-field position-relative">
                <i class="bx bx-search clms-enrollments-search-icon"></i>
                <input
                    type="search"
                    class="form-control form-control-sm clms-enrollments-search-input"
                    id="clms-enrollments-search-input"
                    name="q"
                    placeholder="Search by name, email, or university..."
                    value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="off" />
                <span id="clms-enrollments-search-spinner" class="d-none">
                    <span class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></span>
                </span>
            </div>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm <?php echo $searchQuery === '' ? 'd-none' : ''; ?>"
                id="clms-enrollments-search-clear">
                Clear
            </button>
            <noscript>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </noscript>
        </form>
    </div>

    <style>
        .clms-enrollments-search-icon {
            position: absolute;
            left: .75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #8592a3;
            font-size: 1rem;
            pointer-events: none;
            z-index: 2;
        }

        .clms-enrollments-search-field .clms-enrollments-search-input.form-control {
            padding: .35rem 2.25rem !important;
            position: relative;
            z-index: 1;
        }

        .clms-enrollments-search-input::-webkit-search-cancel-button {
            -webkit-appearance: none;
        }

        .clms-enrollments-search-spinner {
            position: absolute;
            right: .6rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .clms-enrollments-toolbar-form {
            justify-content: flex-end;
        }

        @media (max-width: 575.98px) {
            #clms-enrollments-card>.card-header {
                flex-direction: column;
                align-items: stretch;
            }

            .clms-enrollments-toolbar-form {
                flex-direction: column;
                align-items: stretch;
                width: 100%;
                justify-content: flex-start;
            }

            .clms-enrollments-search-field {
                width: 100%;
                min-width: 0;
            }
        }
    </style>

    <div class="card-body" id="clms-enrollments-partial" aria-live="polite">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <small class="text-muted">
                Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>&ndash;<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> pending enrollment(s)<?php if ($searchQuery !== '') : ?>
                    for &quot;<strong><?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?></strong>&quot;
                <?php endif; ?>
            </small>
        </div>

        <?php if ($enrollments === []) : ?>
            <p class="mb-0 text-muted">
                <?php echo $searchQuery !== '' ? 'No pending enrollments match your search.' : 'No pending enrollments at this time.'; ?>
            </p>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>University Name</th>
                            <th>Requested Track</th>
                            <th>Registration Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $index => $enrollment) : ?>
                            <?php
                                $rowNumber = $offset + $index + 1;
                                $fullName = trim((string) $enrollment['first_name'] . ' ' . (string) $enrollment['last_name']);
                                $enrollmentId = (int) $enrollment['id'];
                                $email = (string) $enrollment['email'];
                                $universityName = (string) $enrollment['university_name'];
                                $reviewTrack = (string) $enrollment['review_track'];
                                $registrationDate = (string) $enrollment['registration_date'];
                                $registrationDateFormatted = $registrationDate !== '' 
                                    ? date('M d, Y H:i', strtotime($registrationDate))
                                    : 'N/A';
                            ?>
                            <tr data-enrollment-id="<?php echo $enrollmentId; ?>">
                                <td><?php echo $rowNumber; ?></td>
                                <td><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($universityName, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="badge <?php echo $reviewTrack === 'Enhancement' ? 'bg-label-warning' : 'bg-label-info'; ?>">
                                        <?php echo htmlspecialchars($reviewTrack, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td><small><?php echo htmlspecialchars($registrationDateFormatted, ENT_QUOTES, 'UTF-8'); ?></small></td>
                                <td class="text-end">
                                    <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/process_enrollment.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                        <input type="hidden" name="user_id" value="<?php echo $enrollmentId; ?>" />
                                        <input type="hidden" name="action" value="approve" />
                                        <button type="submit" class="btn btn-success btn-sm me-2" title="Approve this enrollment">
                                            <i class="bx bx-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="post" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/process_enrollment.php', ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                        <input type="hidden" name="user_id" value="<?php echo $enrollmentId; ?>" />
                                        <input type="hidden" name="action" value="reject" />
                                        <button type="submit" class="btn btn-danger btn-sm" title="Reject this enrollment">
                                            <i class="bx bx-x"></i> Reject
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1) : ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1) : ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1<?php echo $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''; ?>">First</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''; ?>">Previous</a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $isActive = $i === $page ? ' active' : '';
                            $queryString = '?page=' . $i . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '');
                            echo '<li class="page-item' . $isActive . '"><a class="page-link" href="' . htmlspecialchars($queryString, ENT_QUOTES, 'UTF-8') . '">' . $i . '</a></li>';
                        }
                        if ($endPage < $totalPages) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <?php if ($page < $totalPages) : ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''; ?>">Next</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : ''; ?>">Last</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    (() => {
        const form = document.getElementById('clms-enrollments-search-form');
        const input = document.getElementById('clms-enrollments-search-input');
        const spinner = document.getElementById('clms-enrollments-search-spinner');
        const clearBtn = document.getElementById('clms-enrollments-search-clear');

        if (!form || !input) return;

        /* Search input handler - auto-submit with debounce */
        let searchTimeout;
        input.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            spinner.classList.remove('d-none');
            searchTimeout = setTimeout(() => {
                form.submit();
            }, 500);
        });

        /* Clear button */
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                window.location.href = window.location.pathname;
            });
        }

        /* Show spinner on manual submit */
        form.addEventListener('submit', () => {
            spinner.classList.remove('d-none');
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>
