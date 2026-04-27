<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['student']);

$pageTitle = 'My Profile | Criminology LMS';
$activeStudentPage = 'profile';

require_once dirname(__DIR__) . '/includes/profile-logic.php';

require_once __DIR__ . '/includes/layout-top.php';

require_once dirname(__DIR__) . '/includes/profile-view.php';

require_once __DIR__ . '/includes/layout-bottom.php';
