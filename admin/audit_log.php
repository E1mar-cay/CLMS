<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

$pageTitle = 'Audit trail | Criminology LMS';
$activeAdminPage = 'audit_log';

clms_audit_ensure_schema($pdo);

$perPage = 50;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page === false || $page === null || $page < 1 ? 1 : $page;
$offset = ($page - 1) * $perPage;

$totalRows = 0;
try {
    $countStmt = $pdo->query('SELECT COUNT(*) AS c FROM audit_log');
    $totalRows = (int) ($countStmt->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $totalRows = 0;
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$auditRows = [];
try {
    $listStmt = $pdo->prepare(
        'SELECT id, created_at, action, target_type, target_id, actor_user_id, actor_role, ip_address, user_agent, details_json
         FROM audit_log
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset'
    );
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $auditRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $auditRows = [];
}

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Audit trail</h4>
                  <small class="text-muted">
                    Security-relevant events: sign-ins, backups, course/module deletions, finalized exams, MFA and settings changes.
                  </small>
                </div>
                <a href="<?php echo htmlspecialchars($clmsWebBase . '/admin/settings.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="bx bx-cog me-1"></i>System settings
                </a>
              </div>

              <div class="card">
                <h5 class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <span>Activity log</span>
                  <small class="text-muted fw-normal">
                    <?php echo (int) $totalRows; ?> record<?php echo $totalRows === 1 ? '' : 's'; ?> · page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
                  </small>
                </h5>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">When</th>
                          <th scope="col">Action</th>
                          <th scope="col">Target</th>
                          <th scope="col">Actor</th>
                          <th scope="col">IP</th>
                          <th scope="col">User agent</th>
                          <th scope="col">Details</th>
                        </tr>
                      </thead>
                      <tbody>
<?php if ($auditRows === []) : ?>
                        <tr><td colspan="7" class="text-muted px-3 py-4">No audit entries yet.</td></tr>
<?php else : ?>
<?php foreach ($auditRows as $ar) : ?>
                        <tr>
                          <td class="text-nowrap small"><?php echo htmlspecialchars((string) ($ar['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                          <td><code class="small"><?php echo htmlspecialchars((string) ($ar['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td class="small">
                            <?php
                            $tt = trim((string) ($ar['target_type'] ?? ''));
                            $tid = $ar['target_id'] ?? null;
                            if ($tt === '' && ($tid === null || $tid === '')) {
                                echo '—';
                            } else {
                                echo htmlspecialchars($tt . ($tid !== null && $tid !== '' ? ' #' . $tid : ''), ENT_QUOTES, 'UTF-8');
                            }
?>
                          </td>
                          <td class="small">
                            <?php
                            $arole = trim((string) ($ar['actor_role'] ?? ''));
                            $auid = $ar['actor_user_id'] ?? null;
                            if ($arole === '' && ($auid === null || $auid === '')) {
                                echo '—';
                            } else {
                                echo htmlspecialchars($arole . ($auid !== null && $auid !== '' ? ' #' . $auid : ''), ENT_QUOTES, 'UTF-8');
                            }
?>
                          </td>
                          <td class="small text-nowrap"><?php echo htmlspecialchars((string) ($ar['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="small" style="max-width: 160px;">
                            <span class="text-break text-muted"><?php
                                $ua = (string) ($ar['user_agent'] ?? '');
                            echo htmlspecialchars(mb_strlen($ua) > 80 ? mb_substr($ua, 0, 80) . '…' : $ua, ENT_QUOTES, 'UTF-8');
?></span>
                          </td>
                          <td class="small" style="max-width: 240px;">
                            <span class="text-break"><?php
                                $dj = (string) ($ar['details_json'] ?? '');
                            echo htmlspecialchars(mb_strlen($dj) > 220 ? mb_substr($dj, 0, 220) . '…' : $dj, ENT_QUOTES, 'UTF-8');
?></span>
                          </td>
                        </tr>
<?php endforeach; ?>
<?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
<?php if ($totalPages > 1) : ?>
                <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <div class="small text-muted">Showing <?php echo (int) ($offset + 1); ?>–<?php echo (int) min($offset + $perPage, $totalRows); ?> of <?php echo (int) $totalRows; ?></div>
                  <nav aria-label="Audit log pagination">
                    <ul class="pagination pagination-sm mb-0">
<?php if ($page > 1) : ?>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo htmlspecialchars($clmsWebBase . '/admin/audit_log.php?page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                      </li>
<?php endif; ?>
<?php if ($page < $totalPages) : ?>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo htmlspecialchars($clmsWebBase . '/admin/audit_log.php?page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                      </li>
<?php endif; ?>
                    </ul>
                  </nav>
                </div>
<?php endif; ?>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
