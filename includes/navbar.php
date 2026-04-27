<?php

declare(strict_types=1);

/**
 * Shared layout navbar used by admin/instructor/student dashboards.
 *
 * Expected in scope:
 *   - $clmsWebBase : string (provided by includes/sneat-paths.php)
 *   - $navbarSearchPlaceholder : optional string
 *   - $_SESSION : user context
 *
 * The search input is client-side: it filters any element in the page
 * marked with `data-search-item` using its `data-search-text` attribute
 * (falls back to the element's innerText). Set data-search-item on each
 * card/row/list-item you want searchable.
 */

$clmsWebBase = $clmsWebBase ?? '';
$navbarSearchPlaceholder = $navbarSearchPlaceholder ?? 'Search this page...';

$sessionRole = (string) ($_SESSION['role'] ?? 'student');
$sessionEmail = (string) ($_SESSION['email'] ?? '');
$sessionFirstName = trim((string) ($_SESSION['first_name'] ?? ''));
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

/* Notification bell is student-only for now. Admins and instructors
   author announcements (admin/announcements.php), so they don't also
   get notified about them. Wrapped in isset($pdo) so the navbar stays
   robust on pages that haven't required database.php yet. */
$navbarNotifications = [];
$navbarNotificationsUnread = 0;
if ($sessionRole === 'student' && $sessionUserId > 0 && isset($pdo) && $pdo instanceof PDO) {
    require_once __DIR__ . '/notifications.php';
    $navbarNotifications = clms_notifications_list($pdo, $sessionUserId, 10);
    $navbarNotificationsUnread = clms_notifications_unread_count($pdo, $sessionUserId);
}

$displayName = $sessionFirstName !== '' ? $sessionFirstName : ($sessionEmail !== '' ? explode('@', $sessionEmail)[0] : 'User');

$initial = mb_strtoupper(mb_substr($displayName, 0, 1) ?: 'U');

$roleLabelMap = [
    'admin' => 'Administrator',
    'instructor' => 'Instructor',
    'student' => 'Student',
];
$roleLabel = $roleLabelMap[$sessionRole] ?? ucfirst($sessionRole);

$profileHrefMap = [
    'admin' => '/admin/profile.php',
    'instructor' => '/instructor/profile.php',
    'student' => '/student/profile.php',
];
$profileHref = $clmsWebBase . ($profileHrefMap[$sessionRole] ?? '/student/profile.php');

?>
          <nav
            class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme clms-navbar"
            id="layout-navbar">
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0">
              <a class="nav-item nav-link px-0" href="javascript:void(0)" aria-label="Toggle sidebar">
                <i class="icon-base bx bx-menu icon-md"></i>
              </a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
              <div class="clms-navbar-search flex-grow-1 me-3" role="search">
                <label class="clms-navbar-search-wrapper mb-0" for="clmsNavbarSearch">
                  <i class="bx bx-search clms-navbar-search-icon"></i>
                  <input
                    id="clmsNavbarSearch"
                    type="search"
                    class="form-control clms-navbar-search-input"
                    placeholder="<?php echo htmlspecialchars($navbarSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="off"
                    aria-label="Search" />
                  <kbd class="clms-navbar-search-kbd d-none d-md-inline-flex">/</kbd>
                </label>
                <div class="clms-navbar-search-empty text-muted small mt-1 d-none" id="clmsNavbarSearchEmpty">
                  <i class="bx bx-info-circle me-1"></i>No matches for "<span data-search-term></span>" on this page.
                </div>
              </div>

              <ul class="navbar-nav flex-row align-items-center ms-auto gap-2">
<?php if ($sessionRole === 'student') : ?>
                <li class="nav-item dropdown-notifications navbar-dropdown dropdown me-1">
                  <a
                    class="nav-link dropdown-toggle hide-arrow clms-bell-btn"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    aria-label="Notifications">
                    <i class="bx bx-bell icon-md"></i>
                    <span
                      class="clms-bell-badge<?php echo $navbarNotificationsUnread === 0 ? ' d-none' : ''; ?>"
                      id="clmsBellBadge"
                      aria-label="<?php echo (int) $navbarNotificationsUnread; ?> unread notifications">
                      <?php echo $navbarNotificationsUnread > 99 ? '99+' : (int) $navbarNotificationsUnread; ?>
                    </span>
                  </a>
                  <div class="dropdown-menu dropdown-menu-end clms-bell-menu p-0">
                    <div class="dropdown-header d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                      <h6 class="mb-0 fw-semibold">Notifications</h6>
                      <button
                        type="button"
                        class="btn btn-link btn-sm p-0 text-decoration-none clms-bell-mark-all<?php echo $navbarNotificationsUnread === 0 ? ' d-none' : ''; ?>"
                        id="clmsBellMarkAll">
                        Mark all as read
                      </button>
                    </div>
                    <ul class="list-unstyled mb-0 clms-bell-list" id="clmsBellList" role="list">
<?php if ($navbarNotifications === []) : ?>
                      <li class="clms-bell-empty">
                        <div class="text-center text-muted py-4 px-3">
                          <i class="bx bx-bell-off d-block mb-2" style="font-size:1.75rem;"></i>
                          <small>You're all caught up.</small>
                        </div>
                      </li>
<?php else : ?>
<?php foreach ($navbarNotifications as $note) : ?>
                      <li
                        class="clms-bell-item<?php echo $note['is_read'] ? '' : ' is-unread'; ?>"
                        data-announcement-id="<?php echo (int) $note['id']; ?>">
                        <div class="d-flex align-items-start gap-2 px-3 py-2">
                          <span class="clms-bell-dot" aria-hidden="true"></span>
                          <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-baseline gap-2">
                              <span class="fw-semibold text-body text-truncate">
                                <?php echo htmlspecialchars((string) $note['title'], ENT_QUOTES, 'UTF-8'); ?>
                              </span>
                              <small class="text-muted flex-shrink-0">
                                <?php echo htmlspecialchars(clms_notifications_format_time_ago((string) $note['created_at']), ENT_QUOTES, 'UTF-8'); ?>
                              </small>
                            </div>
                            <p class="mb-0 small text-muted clms-bell-body">
                              <?php echo nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                          </div>
                        </div>
                      </li>
<?php endforeach; ?>
<?php endif; ?>
                    </ul>
                    <div class="dropdown-footer border-top px-3 py-2 text-center">
                      <a
                        href="<?php echo htmlspecialchars($clmsWebBase . '/student/dashboard.php#announcements', ENT_QUOTES, 'UTF-8'); ?>"
                        class="text-decoration-none small fw-semibold">
                        View all announcements
                      </a>
                    </div>
                  </div>
                </li>
<?php endif; ?>
                <li class="nav-item d-none d-lg-flex align-items-center">
                  <span class="badge bg-label-primary text-uppercase fw-semibold" style="letter-spacing:.5px;">
                    <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </li>
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a
                    class="nav-link dropdown-toggle hide-arrow p-0"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <div class="d-flex align-items-center gap-2">
                      <div class="clms-avatar">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                      <div class="d-none d-md-flex flex-column lh-sm text-start">
                        <span class="fw-semibold text-body"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <small class="text-muted text-truncate" style="max-width: 180px;">
                          <?php echo htmlspecialchars($sessionEmail, ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                      </div>
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <div class="dropdown-item-text">
                        <div class="d-flex align-items-center gap-2">
                          <div class="clms-avatar clms-avatar-lg">
                            <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                          <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <small class="text-muted d-block"><?php echo htmlspecialchars($sessionEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                            <span class="badge bg-label-primary mt-1"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                          </div>
                        </div>
                      </div>
                    </li>
                    <li><hr class="dropdown-divider" /></li>
                    <li>
                      <a class="dropdown-item" href="<?php echo htmlspecialchars($profileHref, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx bx-user-circle me-2"></i>My Profile
                      </a>
                    </li>
                  </ul>
                </li>
              </ul>
            </div>
          </nav>

          <style>
            .clms-navbar { padding-top: .5rem; padding-bottom: .5rem; }
            .clms-navbar-search-wrapper {
              position: relative;
              display: flex;
              align-items: center;
              max-width: 420px;
              width: 100%;
            }
            /* Icon sits ABOVE the input so the focused (white) input background
               doesn't paint over it. Previously the icon vanished on focus. */
            .clms-navbar-search-icon {
              position: absolute;
              left: .9rem;
              top: 50%;
              transform: translateY(-50%);
              color: #8592a3;
              font-size: 1.1rem;
              pointer-events: none;
              z-index: 2;
              transition: color .2s;
            }
            /* `!important` + compound selector beats Sneat's
               `.form-control { padding: …shorthand }` which otherwise resets
               our asymmetric padding and causes the icon and placeholder to
               visually stack on top of each other. */
            .clms-navbar-search .clms-navbar-search-input.form-control {
              padding: .45rem 2.5rem !important;
              border-radius: 999px;
              background-color: rgba(15, 32, 75, 0.05);
              border: 1px solid transparent;
              transition: background-color .2s, border-color .2s, box-shadow .2s;
              height: 40px;
              position: relative;
              z-index: 1;
            }
            .clms-navbar-search .clms-navbar-search-input.form-control:focus {
              background-color: #fff;
              border-color: var(--clms-navy, #0f204b);
              box-shadow: 0 0 0 .15rem rgba(15, 32, 75, 0.12);
            }
            .clms-navbar-search-input:focus + .clms-navbar-search-kbd,
            .clms-navbar-search-wrapper:focus-within .clms-navbar-search-icon {
              color: var(--clms-navy, #0f204b);
            }
            /* Hide the native search input clear "x" (we have our own UX) */
            .clms-navbar-search-input::-webkit-search-cancel-button { -webkit-appearance: none; }
            .clms-navbar-search-kbd {
              position: absolute;
              right: .65rem;
              top: 50%;
              transform: translateY(-50%);
              background-color: rgba(15, 32, 75, 0.08);
              color: #697a8d;
              border-radius: 6px;
              padding: 2px 8px;
              font-size: 12px;
              font-weight: 600;
              border: 1px solid rgba(15, 32, 75, 0.12);
              z-index: 2;
              pointer-events: none;
            }
            .clms-avatar {
              width: 38px;
              height: 38px;
              border-radius: 50%;
              display: inline-flex;
              align-items: center;
              justify-content: center;
              font-weight: 600;
              color: #fff;
              background: linear-gradient(135deg, #0f204b 0%, #1f3a7a 100%);
              box-shadow: 0 2px 6px rgba(15, 32, 75, 0.35);
              font-size: 1rem;
              user-select: none;
            }
            .clms-avatar-lg { width: 46px; height: 46px; font-size: 1.15rem; }
            [data-search-hidden="true"] { display: none !important; }

            /* --- Notification bell ------------------------------------ */
            .clms-bell-btn {
              position: relative;
              padding: .35rem .5rem;
              color: #566a7f;
            }
            .clms-bell-btn:hover { color: var(--clms-navy, #0f204b); }
            .clms-bell-btn .icon-md { font-size: 1.45rem; line-height: 1; }
            .clms-bell-badge {
              position: absolute;
              top: 2px;
              right: 2px;
              min-width: 18px;
              height: 18px;
              padding: 0 5px;
              border-radius: 999px;
              background: #dc3545;
              color: #fff;
              font-size: 10px;
              font-weight: 700;
              line-height: 18px;
              text-align: center;
              box-shadow: 0 0 0 2px #fff;
              pointer-events: none;
            }
            .clms-bell-menu {
              width: 360px;
              max-width: calc(100vw - 1rem);
              border-radius: .65rem;
              box-shadow: 0 .75rem 2rem rgba(15, 32, 75, .15);
              border: 1px solid rgba(15, 32, 75, .08);
              overflow: hidden;
            }
            .clms-bell-list {
              max-height: 380px;
              overflow-y: auto;
            }
            .clms-bell-item {
              position: relative;
              cursor: pointer;
              transition: background-color .15s;
              border-bottom: 1px solid rgba(15, 32, 75, .05);
            }
            .clms-bell-item:last-child { border-bottom: 0; }
            .clms-bell-item:hover { background-color: rgba(15, 32, 75, .035); }
            .clms-bell-dot {
              display: inline-block;
              width: 8px;
              height: 8px;
              border-radius: 999px;
              background: transparent;
              margin-top: .45rem;
              flex-shrink: 0;
            }
            .clms-bell-item.is-unread {
              background-color: rgba(15, 32, 75, .03);
            }
            .clms-bell-item.is-unread .clms-bell-dot {
              background: var(--clms-navy, #0f204b);
              box-shadow: 0 0 0 3px rgba(15, 32, 75, .12);
            }
            .clms-bell-body {
              display: -webkit-box;
              -webkit-line-clamp: 2;
              -webkit-box-orient: vertical;
              overflow: hidden;
              white-space: normal;
            }
            .clms-bell-mark-all {
              color: var(--clms-navy, #0f204b);
              font-weight: 600;
            }
            .dropdown-footer a { color: var(--clms-navy, #0f204b); }
          </style>

          <script>
            (() => {
              const input = document.getElementById('clmsNavbarSearch');
              const emptyEl = document.getElementById('clmsNavbarSearchEmpty');
              if (!input) return;

              document.addEventListener('keydown', (event) => {
                if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
                const tag = (event.target && event.target.tagName) || '';
                if (/^(INPUT|TEXTAREA|SELECT)$/i.test(tag) || event.target.isContentEditable) return;
                event.preventDefault();
                input.focus();
                input.select();
              });

              const getSearchText = (item) => {
                const attr = item.getAttribute('data-search-text');
                if (attr !== null) return attr.toLowerCase();
                return (item.innerText || item.textContent || '').toLowerCase();
              };

              const apply = () => {
                const query = input.value.trim().toLowerCase();
                const items = document.querySelectorAll('[data-search-item]');

                if (items.length === 0) {
                  if (emptyEl) emptyEl.classList.add('d-none');
                  return;
                }

                let visibleCount = 0;
                items.forEach((item) => {
                  if (query === '') {
                    item.setAttribute('data-search-hidden', 'false');
                    visibleCount++;
                    return;
                  }
                  const matches = getSearchText(item).includes(query);
                  item.setAttribute('data-search-hidden', matches ? 'false' : 'true');
                  if (matches) visibleCount++;
                });

                if (emptyEl) {
                  const termEl = emptyEl.querySelector('[data-search-term]');
                  if (termEl) termEl.textContent = input.value.trim();
                  if (query !== '' && visibleCount === 0) {
                    emptyEl.classList.remove('d-none');
                  } else {
                    emptyEl.classList.add('d-none');
                  }
                }
              };

              /* Hide the "/" keyboard hint as soon as the user starts typing or
                 focuses the field, so it never overlaps the typed query. */
              const kbd = document.querySelector('.clms-navbar-search-kbd');
              const toggleKbd = () => {
                if (!kbd) return;
                const hasValue = input.value.length > 0;
                const isFocused = document.activeElement === input;
                kbd.style.visibility = (hasValue || isFocused) ? 'hidden' : '';
              };

              input.addEventListener('input', () => { apply(); toggleKbd(); });
              input.addEventListener('search', () => { apply(); toggleKbd(); });
              input.addEventListener('focus', toggleKbd);
              input.addEventListener('blur', toggleKbd);
              toggleKbd();
            })();
          </script>

<?php if ($sessionRole === 'student') : ?>
          <script>
            /* Notification bell — live updates without a page reload.
               - Polls every 60s for new unread count (cheap: just returns
                 the list+count JSON from student/notifications.php).
               - On dropdown open, marks all as read and clears the badge.
               - Single-item click also marks that one as read. */
            (() => {
              const endpoint   = <?php echo json_encode($clmsWebBase . '/student/notifications.php', JSON_UNESCAPED_SLASHES); ?>;
              const csrfToken  = <?php echo json_encode(clms_csrf_token()); ?>;
              const badge      = document.getElementById('clmsBellBadge');
              const markAllBtn = document.getElementById('clmsBellMarkAll');
              const list       = document.getElementById('clmsBellList');
              const bellEl     = document.querySelector('.dropdown-notifications');
              if (!badge || !list || !bellEl) return;

              const setUnread = (count) => {
                const n = Math.max(0, parseInt(count, 10) || 0);
                if (n === 0) {
                  badge.classList.add('d-none');
                  if (markAllBtn) markAllBtn.classList.add('d-none');
                } else {
                  badge.textContent = n > 99 ? '99+' : String(n);
                  badge.classList.remove('d-none');
                  if (markAllBtn) markAllBtn.classList.remove('d-none');
                }
              };

              const escapeHtml = (s) => String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

              const renderItems = (items) => {
                if (!items || items.length === 0) {
                  list.innerHTML = `
                    <li class="clms-bell-empty">
                      <div class="text-center text-muted py-4 px-3">
                        <i class="bx bx-bell-off d-block mb-2" style="font-size:1.75rem;"></i>
                        <small>You're all caught up.</small>
                      </div>
                    </li>`;
                  return;
                }
                list.innerHTML = items.map((n) => `
                  <li class="clms-bell-item${n.is_read ? '' : ' is-unread'}" data-announcement-id="${n.id}">
                    <div class="d-flex align-items-start gap-2 px-3 py-2">
                      <span class="clms-bell-dot" aria-hidden="true"></span>
                      <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                          <span class="fw-semibold text-body text-truncate">${escapeHtml(n.title)}</span>
                          <small class="text-muted flex-shrink-0">${escapeHtml(n.time_ago || '')}</small>
                        </div>
                        <p class="mb-0 small text-muted clms-bell-body">${escapeHtml(n.body).replace(/\n/g, '<br>')}</p>
                      </div>
                    </div>
                  </li>
                `).join('');
              };

              const refresh = async () => {
                try {
                  const res = await fetch(`${endpoint}?action=list`, { credentials: 'same-origin' });
                  if (!res.ok) return;
                  const data = await res.json();
                  if (!data || !data.ok) return;
                  setUnread(data.unread);
                  renderItems(data.items);
                } catch (e) { /* network hiccup: the next poll will retry */ }
              };

              const markAll = async () => {
                const body = new URLSearchParams();
                body.set('action', 'mark_all');
                body.set('csrf_token', csrfToken);
                try {
                  const res = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                  });
                  if (!res.ok) return;
                  const data = await res.json();
                  setUnread(data.unread || 0);
                  /* Visually flip all unread rows to read without a re-fetch. */
                  list.querySelectorAll('.clms-bell-item.is-unread').forEach((el) => el.classList.remove('is-unread'));
                } catch (e) { /* silent */ }
              };

              const markOne = async (announcementId) => {
                const body = new URLSearchParams();
                body.set('action', 'mark_read');
                body.set('announcement_id', String(announcementId));
                body.set('csrf_token', csrfToken);
                try {
                  const res = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                  });
                  if (!res.ok) return;
                  const data = await res.json();
                  setUnread(data.unread || 0);
                } catch (e) { /* silent */ }
              };

              /* Mark-all button. */
              if (markAllBtn) {
                markAllBtn.addEventListener('click', (event) => {
                  event.stopPropagation();
                  markAll();
                });
              }

              /* Click on an individual item: flip visual state and mark
                 that one read. Uses delegation so items added later by
                 polling still work. */
              list.addEventListener('click', (event) => {
                const item = event.target.closest('.clms-bell-item');
                if (!item) return;
                const id = parseInt(item.getAttribute('data-announcement-id'), 10);
                if (!id) return;
                if (item.classList.contains('is-unread')) {
                  item.classList.remove('is-unread');
                  markOne(id);
                }
              });

              /* When dropdown opens, refresh the list so a notification
                 that arrived seconds ago is visible. */
              bellEl.addEventListener('shown.bs.dropdown', refresh);

              /* Background polling: every 60s update the badge so new
                 announcements surface even if the user never opens the
                 dropdown. Pauses while the tab is hidden. */
              const POLL_MS = 60000;
              let pollId = null;
              const startPoll = () => {
                stopPoll();
                pollId = setInterval(refresh, POLL_MS);
              };
              const stopPoll = () => {
                if (pollId) { clearInterval(pollId); pollId = null; }
              };
              document.addEventListener('visibilitychange', () => {
                if (document.hidden) stopPoll();
                else { refresh(); startPoll(); }
              });
              startPoll();
            })();
          </script>
<?php endif; ?>
