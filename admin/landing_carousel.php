<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/landing-carousel-schema.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin']);

$pageTitle = 'Landing carousel | Criminology LMS';
$activeAdminPage = 'landing_carousel';
$errorMessage = '';
$successMessage = '';

clms_ensure_landing_carousel_schema($pdo);

$openNewModal = isset($_GET['new']) && (string) $_GET['new'] === '1';
$editId = (int) filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
if ($openNewModal) {
    $editId = 0;
}
$formTitle = '';
$formSubtitle = '';
$formButtonLabel = '';
$formButtonUrl = '';
$formImageUrl = '';
$formIsActive = 1;
$formAction = 'create';
$formSlideId = 0;

$clmsCarouselUploadDir = dirname(__DIR__) . '/public/uploads/landing-carousel/';
$clmsCarouselUploadRel = '/public/uploads/landing-carousel/';

/**
 * Stores an uploaded slide image and returns its site-relative URL.
 * Returns '' when no file was uploaded; throws RuntimeException on errors.
 */
$clmsHandleSlideImageUpload = static function (array $file) use ($clmsCarouselUploadDir, $clmsCarouselUploadRel): string {
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (code ' . $err . ').');
    }
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Image upload was rejected.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be under 5MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('That file does not look like an image.');
    }
    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default => null,
    };
    if ($ext === null) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WebP are allowed.');
    }
    if (!is_dir($clmsCarouselUploadDir) && !mkdir($clmsCarouselUploadDir, 0755, true) && !is_dir($clmsCarouselUploadDir)) {
        throw new RuntimeException('Could not create upload folder.');
    }
    $name = 'slide_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $clmsCarouselUploadDir . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return $clmsCarouselUploadRel . $name;
};

$clmsDeleteSlideImageIfLocal = static function (?string $imageUrl) use ($clmsCarouselUploadRel): void {
    $u = trim((string) $imageUrl);
    if ($u === '' || !str_starts_with($u, $clmsCarouselUploadRel)) {
        return;
    }
    $base = basename($u);
    if ($base === '' || str_contains($base, '..')) {
        return;
    }
    $abs = dirname(__DIR__) . $clmsCarouselUploadRel . $base;
    if (is_file($abs)) {
        @unlink($abs);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!clms_csrf_validate($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'create' || $action === 'update') {
                $titleInput = trim((string) ($_POST['title'] ?? ''));
                $subtitleInput = trim((string) ($_POST['subtitle'] ?? ''));
                $buttonLabel = trim((string) ($_POST['button_label'] ?? ''));
                $buttonUrl = trim((string) ($_POST['button_url'] ?? ''));
                $isActiveInput = isset($_POST['is_active']) ? 1 : 0;
                $removeImage = isset($_POST['remove_image']) && (string) $_POST['remove_image'] === '1';

                $formTitle = $titleInput;
                $formSubtitle = $subtitleInput;
                $formButtonLabel = $buttonLabel;
                $formButtonUrl = $buttonUrl;
                $formIsActive = $isActiveInput;
                $formAction = $action;
                $formSlideId = (int) filter_input(INPUT_POST, 'slide_id', FILTER_VALIDATE_INT);

                if (mb_strlen($titleInput) > 255) {
                    throw new RuntimeException('Headline is too long (255 characters max).');
                }
                if (mb_strlen($subtitleInput) > 8000) {
                    throw new RuntimeException('Description is too long.');
                }
                if (mb_strlen($buttonLabel) > 120) {
                    throw new RuntimeException('Button label is too long.');
                }
                if (mb_strlen($buttonUrl) > 500) {
                    throw new RuntimeException('Button URL is too long.');
                }

                /*
                 * `image_file` (single) is used in edit mode; `image_file[]`
                 * (multi) is used in create mode so admins can upload many
                 * photos at once and get one slide per photo. We normalise
                 * both shapes into a list of single-file arrays.
                 */
                $perFile = [];
                if (isset($_FILES['image_file']) && is_array($_FILES['image_file'])) {
                    $f = $_FILES['image_file'];
                    if (is_array($f['name'] ?? null)) {
                        $count = count($f['name']);
                        for ($i = 0; $i < $count; $i++) {
                            if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            $perFile[] = [
                                'name' => (string) ($f['name'][$i] ?? ''),
                                'type' => (string) ($f['type'][$i] ?? ''),
                                'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''),
                                'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                                'size' => (int) ($f['size'][$i] ?? 0),
                            ];
                        }
                    } elseif ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $perFile[] = $f;
                    }
                }

                if ($action === 'create') {
                    $uploadedUrls = [];
                    foreach ($perFile as $oneFile) {
                        $u = $clmsHandleSlideImageUpload($oneFile);
                        if ($u !== '') {
                            $uploadedUrls[] = $u;
                        }
                    }

                    $maxStmt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) AS m FROM landing_carousel_slides');
                    $nextOrder = (int) (($maxStmt ? $maxStmt->fetch()['m'] : 0) ?? 0) + 1;
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO landing_carousel_slides
                            (title, subtitle, button_label, button_url, image_url, sort_order, is_active)
                         VALUES
                            (:title, :subtitle, :button_label, :button_url, :image_url, :sort_order, :is_active)'
                    );

                    $imageBatch = $uploadedUrls === [] ? [null] : $uploadedUrls;
                    foreach ($imageBatch as $imgUrl) {
                        $insertStmt->execute([
                            'title' => $titleInput,
                            'subtitle' => $subtitleInput,
                            'button_label' => $buttonLabel,
                            'button_url' => $buttonUrl,
                            'image_url' => $imgUrl,
                            'sort_order' => $nextOrder,
                            'is_active' => $isActiveInput,
                        ]);
                        $nextOrder++;
                    }

                    $createdCount = count($imageBatch);
                    $successMessage = $createdCount > 1
                        ? "Created {$createdCount} carousel slides."
                        : 'Carousel slide created.';
                    $formTitle = $formSubtitle = $formButtonLabel = $formButtonUrl = $formImageUrl = '';
                    $formIsActive = 1;
                    $formAction = 'create';
                    $formSlideId = 0;
                } else {
                    $slideId = (int) filter_input(INPUT_POST, 'slide_id', FILTER_VALIDATE_INT);
                    if ($slideId <= 0) {
                        throw new RuntimeException('Invalid slide id.');
                    }

                    $existingStmt = $pdo->prepare('SELECT image_url FROM landing_carousel_slides WHERE id = :id LIMIT 1');
                    $existingStmt->execute(['id' => $slideId]);
                    $existingRow = $existingStmt->fetch();
                    $existingImageUrl = $existingRow ? (string) ($existingRow['image_url'] ?? '') : '';

                    /* Edit mode keeps a single photo per slide — if multiple
                       files were submitted we only keep the first one. */
                    $editUploadedUrl = '';
                    if ($perFile !== []) {
                        $editUploadedUrl = $clmsHandleSlideImageUpload($perFile[0]);
                    }

                    if ($editUploadedUrl !== '') {
                        $finalImageUrl = $editUploadedUrl;
                        $clmsDeleteSlideImageIfLocal($existingImageUrl);
                    } elseif ($removeImage) {
                        $finalImageUrl = null;
                        $clmsDeleteSlideImageIfLocal($existingImageUrl);
                    } else {
                        $finalImageUrl = $existingImageUrl !== '' ? $existingImageUrl : null;
                    }

                    $updateStmt = $pdo->prepare(
                        'UPDATE landing_carousel_slides
                            SET title = :title,
                                subtitle = :subtitle,
                                button_label = :button_label,
                                button_url = :button_url,
                                image_url = :image_url,
                                is_active = :is_active
                          WHERE id = :id'
                    );
                    $updateStmt->execute([
                        'title' => $titleInput,
                        'subtitle' => $subtitleInput,
                        'button_label' => $buttonLabel,
                        'button_url' => $buttonUrl,
                        'image_url' => $finalImageUrl,
                        'is_active' => $isActiveInput,
                        'id' => $slideId,
                    ]);
                    $successMessage = 'Carousel slide updated.';
                    clms_redirect('admin/landing_carousel.php?flash=updated');
                }
            } elseif ($action === 'delete') {
                $slideId = (int) filter_input(INPUT_POST, 'slide_id', FILTER_VALIDATE_INT);
                if ($slideId <= 0) {
                    throw new RuntimeException('Invalid slide id.');
                }
                $imgStmt = $pdo->prepare('SELECT image_url FROM landing_carousel_slides WHERE id = :id LIMIT 1');
                $imgStmt->execute(['id' => $slideId]);
                $imgRow = $imgStmt->fetch();
                $del = $pdo->prepare('DELETE FROM landing_carousel_slides WHERE id = :id');
                $del->execute(['id' => $slideId]);
                if ($imgRow) {
                    $clmsDeleteSlideImageIfLocal((string) ($imgRow['image_url'] ?? ''));
                }
                $successMessage = 'Slide deleted.';
            } elseif ($action === 'toggle') {
                $slideId = (int) filter_input(INPUT_POST, 'slide_id', FILTER_VALIDATE_INT);
                if ($slideId <= 0) {
                    throw new RuntimeException('Invalid slide id.');
                }
                $t = $pdo->prepare(
                    'UPDATE landing_carousel_slides SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id'
                );
                $t->execute(['id' => $slideId]);
                $successMessage = 'Slide visibility updated.';
            } elseif ($action === 'move') {
                $slideId = (int) filter_input(INPUT_POST, 'slide_id', FILTER_VALIDATE_INT);
                $dir = strtolower(trim((string) ($_POST['direction'] ?? '')));
                if ($slideId <= 0 || !in_array($dir, ['up', 'down'], true)) {
                    throw new RuntimeException('Invalid reorder request.');
                }
                $cur = $pdo->prepare('SELECT id, sort_order FROM landing_carousel_slides WHERE id = :id LIMIT 1');
                $cur->execute(['id' => $slideId]);
                $row = $cur->fetch();
                if (!$row) {
                    throw new RuntimeException('Slide not found.');
                }
                $so = (int) $row['sort_order'];
                if ($dir === 'up') {
                    $neighbor = $pdo->prepare(
                        'SELECT id, sort_order FROM landing_carousel_slides
                         WHERE sort_order < :so OR (sort_order = :so2 AND id < :id)
                         ORDER BY sort_order DESC, id DESC LIMIT 1'
                    );
                    $neighbor->execute(['so' => $so, 'so2' => $so, 'id' => $slideId]);
                } else {
                    $neighbor = $pdo->prepare(
                        'SELECT id, sort_order FROM landing_carousel_slides
                         WHERE sort_order > :so OR (sort_order = :so2 AND id > :id)
                         ORDER BY sort_order ASC, id ASC LIMIT 1'
                    );
                    $neighbor->execute(['so' => $so, 'so2' => $so, 'id' => $slideId]);
                }
                $nb = $neighbor->fetch();
                if (!$nb) {
                    throw new RuntimeException('Cannot move further in that direction.');
                }
                $pdo->beginTransaction();
                try {
                    $tmp = 2000000000 + (int) $row['id'];
                    $u1 = $pdo->prepare('UPDATE landing_carousel_slides SET sort_order = :t WHERE id = :id');
                    $u1->execute(['t' => $tmp, 'id' => (int) $row['id']]);
                    $u2 = $pdo->prepare('UPDATE landing_carousel_slides SET sort_order = :so WHERE id = :id');
                    $u2->execute(['so' => $so, 'id' => (int) $nb['id']]);
                    $u3 = $pdo->prepare('UPDATE landing_carousel_slides SET sort_order = :so WHERE id = :id');
                    $u3->execute(['so' => (int) $nb['sort_order'], 'id' => (int) $row['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $successMessage = 'Slide order updated.';
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $errorMessage = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Operation failed. Please try again.';
            if (!($e instanceof RuntimeException)) {
                error_log('landing_carousel: ' . $e->getMessage());
            }
        }
    }
}

$flashCode = (string) ($_GET['flash'] ?? '');
if ($successMessage === '' && $flashCode !== '') {
    $flashMap = [
        'updated' => 'Carousel slide updated.',
    ];
    if (isset($flashMap[$flashCode])) {
        $successMessage = $flashMap[$flashCode];
    }
}

if ($editId > 0) {
    $es = $pdo->prepare(
        'SELECT id, title, subtitle, button_label, button_url, image_url, is_active
         FROM landing_carousel_slides WHERE id = :id LIMIT 1'
    );
    $es->execute(['id' => $editId]);
    $er = $es->fetch();
    if ($er) {
        $formTitle = (string) $er['title'];
        $formSubtitle = (string) $er['subtitle'];
        $formButtonLabel = (string) ($er['button_label'] ?? '');
        $formButtonUrl = (string) ($er['button_url'] ?? '');
        $formImageUrl = (string) ($er['image_url'] ?? '');
        $formIsActive = (int) $er['is_active'];
        $formAction = 'update';
        $formSlideId = (int) $er['id'];
    } else {
        $editId = 0;
    }
}

$listStmt = $pdo->query(
    'SELECT id, title, subtitle, button_label, image_url, is_active, sort_order, updated_at
     FROM landing_carousel_slides
     ORDER BY sort_order ASC, id ASC'
);
$slides = $listStmt ? $listStmt->fetchAll() : [];

$resolveSlideThumb = static function (string $raw) use ($clmsWebBase): string {
    $p = trim($raw);
    if ($p === '') {
        return '';
    }
    if (preg_match('/^(https?:)?\/\//i', $p) === 1 || str_starts_with($p, 'data:')) {
        return $p;
    }
    if (str_starts_with($p, '/')) {
        return rtrim((string) $clmsWebBase, '/') . $p;
    }

    return rtrim((string) $clmsWebBase, '/') . '/' . ltrim($p, '/');
};

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">Landing page carousel</h4>
                  <small class="text-muted">Hero slides on the public home page (<code>index.php</code>). If no active slides exist, the default static hero is shown.</small>
                </div>
                <a
                  class="btn btn-primary btn-sm"
                  href="<?php echo htmlspecialchars($clmsWebBase . '/admin/landing_carousel.php?new=1', ENT_QUOTES, 'UTF-8'); ?>">
                  <i class="bx bx-plus me-1"></i>New slide
                </a>
              </div>

<?php if ($successMessage !== '') : ?>
              <div class="alert alert-success alert-dismissible" role="alert">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
<?php endif; ?>
<?php if ($errorMessage !== '') : ?>
              <div class="alert alert-danger alert-dismissible" role="alert">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
<?php endif; ?>

              <div class="card mb-4">
                <h5 class="card-header">All slides</h5>
                <div class="card-body">
<?php if ($slides === []) : ?>
                  <p class="mb-0 text-muted">No slides yet. Add at least one <strong>active</strong> slide to replace the default hero on the landing page.</p>
<?php else : ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead>
                        <tr>
                          <th style="width:4rem">Order</th>
                          <th style="width:5rem">Photo</th>
                          <th>Title</th>
                          <th style="width:6rem">Active</th>
                          <th style="width:14rem">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($slides as $s) : ?>
                        <?php $thumbUrl = $resolveSlideThumb((string) ($s['image_url'] ?? '')); ?>
                        <tr>
                          <td class="text-muted small"><?php echo (int) $s['sort_order']; ?></td>
                          <td>
<?php if ($thumbUrl !== '') : ?>
                            <img src="<?php echo htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="height:36px;width:60px;object-fit:cover;border-radius:4px;border:1px solid rgba(0,0,0,0.08);" />
<?php else : ?>
                            <span class="text-muted small">—</span>
<?php endif; ?>
                          </td>
                          <td>
                            <?php $sTitle = trim((string) ($s['title'] ?? '')); ?>
                            <?php $sSub = trim((string) ($s['subtitle'] ?? '')); ?>
                            <div class="fw-semibold<?php echo $sTitle === '' ? ' text-muted fst-italic' : ''; ?>">
                              <?php echo $sTitle !== '' ? htmlspecialchars($sTitle, ENT_QUOTES, 'UTF-8') : '(photo-only slide)'; ?>
                            </div>
                            <?php if ($sSub !== '') : ?>
                              <div class="small text-muted text-truncate" style="max-width:420px"><?php echo htmlspecialchars(mb_strimwidth($sSub, 0, 120, '…'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                          </td>
                          <td>
<?php if ((int) $s['is_active'] === 1) : ?>
                            <span class="badge bg-label-success">On</span>
<?php else : ?>
                            <span class="badge bg-label-secondary">Off</span>
<?php endif; ?>
                          </td>
                          <td>
                            <div class="btn-group btn-group-sm" role="group">
                              <a class="btn btn-outline-primary" href="<?php echo htmlspecialchars($clmsWebBase . '/admin/landing_carousel.php?edit=' . (int) $s['id'], ENT_QUOTES, 'UTF-8'); ?>">Edit</a>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="move" />
                                <input type="hidden" name="slide_id" value="<?php echo (int) $s['id']; ?>" />
                                <input type="hidden" name="direction" value="up" />
                                <button type="submit" class="btn btn-outline-secondary" title="Move up">↑</button>
                              </form>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="move" />
                                <input type="hidden" name="slide_id" value="<?php echo (int) $s['id']; ?>" />
                                <input type="hidden" name="direction" value="down" />
                                <button type="submit" class="btn btn-outline-secondary" title="Move down">↓</button>
                              </form>
                              <form method="post" class="d-inline" onsubmit="return confirm('Toggle visibility for this slide?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="toggle" />
                                <input type="hidden" name="slide_id" value="<?php echo (int) $s['id']; ?>" />
                                <button type="submit" class="btn btn-outline-warning">Toggle</button>
                              </form>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this slide permanently?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="action" value="delete" />
                                <input type="hidden" name="slide_id" value="<?php echo (int) $s['id']; ?>" />
                                <button type="submit" class="btn btn-outline-danger">Delete</button>
                              </form>
                            </div>
                          </td>
                        </tr>
<?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
<?php endif; ?>
                </div>
              </div>

              <div class="modal fade" id="carouselSlideFormModal" tabindex="-1" aria-labelledby="carouselSlideFormModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($clmsWebBase . '/admin/landing_carousel.php', ENT_QUOTES, 'UTF-8'); ?>">
                      <div class="modal-header">
                        <h5 class="modal-title" id="carouselSlideFormModalLabel"><?php echo $formAction === 'update' ? 'Edit slide' : 'New slide'; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(clms_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="slide_id" value="<?php echo (int) $formSlideId; ?>" />

                        <div class="mb-3">
                          <label class="form-label" for="carousel_title">Headline <span class="text-muted">(optional)</span></label>
                          <input class="form-control" type="text" name="title" id="carousel_title" maxlength="255" value="<?php echo htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8'); ?>" />
                          <small class="text-muted">Leave blank for photo-only slides (e.g. passer showcases).</small>
                        </div>

                        <div class="mb-3">
                          <label class="form-label" for="carousel_subtitle">Description <span class="text-muted">(optional)</span></label>
                          <textarea class="form-control" name="subtitle" id="carousel_subtitle" rows="3"><?php echo htmlspecialchars($formSubtitle, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="mb-3">
                          <label class="form-label" for="carousel_image_file">Background photo <span class="text-muted">(optional)</span></label>
<?php if ($formImageUrl !== '') : ?>
                          <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded">
                            <img src="<?php echo htmlspecialchars($resolveSlideThumb($formImageUrl), ENT_QUOTES, 'UTF-8'); ?>" alt="Current slide photo" style="height:64px;width:auto;border-radius:4px;object-fit:cover;" />
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="remove_image" id="carousel_remove_image" value="1" />
                              <label class="form-check-label small" for="carousel_remove_image">Remove current photo</label>
                            </div>
                          </div>
<?php endif; ?>
<?php if ($formAction === 'create') : ?>
                          <input class="form-control" type="file" name="image_file[]" id="carousel_image_file" accept="image/jpeg,image/png,image/gif,image/webp" multiple />
                          <small class="text-muted">JPG, PNG, GIF, or WebP — up to 5MB each. <strong>Tip:</strong> pick several photos to create one slide per photo.</small>
<?php else : ?>
                          <input class="form-control" type="file" name="image_file" id="carousel_image_file" accept="image/jpeg,image/png,image/gif,image/webp" />
                          <small class="text-muted">JPG, PNG, GIF, or WebP — up to 5MB.<?php echo $formImageUrl !== '' ? ' Pick a new file to replace the current photo.' : ''; ?></small>
<?php endif; ?>
                        </div>

                        <div class="row g-3 mb-3">
                          <div class="col-md-6">
                            <label class="form-label" for="carousel_btn_label">Button label <span class="text-muted">(optional)</span></label>
                            <input class="form-control" type="text" name="button_label" id="carousel_btn_label" maxlength="120" placeholder="e.g. Start your CLMS account" value="<?php echo htmlspecialchars($formButtonLabel, ENT_QUOTES, 'UTF-8'); ?>" />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="carousel_btn_url">Button link <span class="text-muted">(optional)</span></label>
                            <input class="form-control" type="text" name="button_url" id="carousel_btn_url" maxlength="500" placeholder="register.php" value="<?php echo htmlspecialchars($formButtonUrl, ENT_QUOTES, 'UTF-8'); ?>" />
                          </div>
                        </div>

                        <div class="form-check form-switch">
                          <input class="form-check-input" type="checkbox" name="is_active" id="carousel_is_active" value="1"<?php echo $formIsActive ? ' checked' : ''; ?> />
                          <label class="form-check-label" for="carousel_is_active">Active (visible on home page)</label>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><?php echo $formAction === 'update' ? 'Save changes' : 'Create slide'; ?></button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <script>
                /*
                 * Bootstrap is loaded at the bottom of the page (footer).
                 * Inline scripts here run *before* that, so `window.bootstrap`
                 * is undefined if we run immediately — the modal never opens
                 * for ?new=1, ?edit=…, or validation errors. Wait for DOM +
                 * poll briefly until Bootstrap is available.
                 */
                (function () {
                  var shouldOpen = <?php echo json_encode($openNewModal || $editId > 0 || ($errorMessage !== '' && $_SERVER['REQUEST_METHOD'] === 'POST')); ?>;
                  if (!shouldOpen) return;

                  var attempts = 0;
                  var maxAttempts = 80;

                  function tryShow() {
                    var modalEl = document.getElementById('carouselSlideFormModal');
                    if (!modalEl) return;
                    if (window.bootstrap && bootstrap.Modal) {
                      bootstrap.Modal.getOrCreateInstance(modalEl).show();
                      return;
                    }
                    attempts += 1;
                    if (attempts < maxAttempts) {
                      setTimeout(tryShow, 25);
                    }
                  }

                  if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', tryShow);
                  } else {
                    tryShow();
                  }
                })();
              </script>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
