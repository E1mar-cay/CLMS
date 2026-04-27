<?php

declare(strict_types=1);

/**
 * CLMS certificate download endpoint.
 *
 * Renders the blank A4-landscape certificate template and overlays the
 * student's name, course title, date issued, and verification hash on
 * top of it, then streams the result to the browser as a download.
 *
 * IMPORTANT: this file must not emit ANY bytes (HTML, whitespace, BOM)
 * before FPDF's Output() call, otherwise the PDF download breaks.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/vendor/fpdf/fpdf.php';

// --- Task 2: Secure the endpoint ----------------------------------------
//
// Must be logged in AND the hash must belong to the current user. A
// missing login or a hash that maps to another student is treated as a
// 403 Forbidden — no information leak about whether the hash exists.

clms_require_roles(['student']);

$hash = isset($_GET['hash']) ? (string) $_GET['hash'] : '';

// Hash shape sanity check before hitting the DB — every cert_hash we
// generate is 64 hex chars (see includes/exam_grading.php).
if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
    http_response_code(403);
    die('403 Forbidden');
}

$certificateStmt = $pdo->prepare(
    'SELECT cert.certificate_hash,
            cert.issued_at,
            u.first_name,
            u.last_name,
            u.email,
            c.title AS course_title
     FROM certificates cert
     INNER JOIN users u   ON u.id = cert.user_id
     INNER JOIN courses c ON c.id = cert.course_id
     WHERE cert.certificate_hash = :hash
       AND cert.user_id = :user_id
     LIMIT 1'
);
$certificateStmt->execute([
    'hash' => $hash,
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
]);
$certificate = $certificateStmt->fetch();

if (!$certificate) {
    http_response_code(403);
    die('403 Forbidden');
}

// --- Prepare the injected strings --------------------------------------

$firstName = trim((string) $certificate['first_name']);
$lastName = trim((string) $certificate['last_name']);
$studentFullName = trim($firstName . ' ' . $lastName);
if ($studentFullName === '') {
    $studentFullName = (string) $certificate['email'];
}

$courseTitle = (string) $certificate['course_title'];

$issuedAtRaw = (string) $certificate['issued_at'];
$issuedAtTs = strtotime($issuedAtRaw);
$dateIssued = date('F j, Y', $issuedAtTs !== false ? $issuedAtTs : time());

$certificateHash = (string) $certificate['certificate_hash'];

// FPDF's core fonts are Latin-1 only — strip anything outside that so
// unicode accents in a name don't render as question marks.
$toLatin1 = static function (string $s): string {
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    return $converted === false ? $s : $converted;
};

$studentFullNameOut = $toLatin1($studentFullName);
$courseTitleOut = $toLatin1($courseTitle);
$dateIssuedOut = $toLatin1($dateIssued);

// Flush anything that might have been buffered by auth/session code so
// FPDF's headers go out cleanly.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// --- Task 3: Initialize FPDF (Landscape, A4, mm) -----------------------
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false, 0);
$pdf->SetMargins(0, 0, 0);
$pdf->AddPage();

// --- Task 4: Background template ---------------------------------------
// 297 x 210 mm = full A4 landscape bleed.
$pdf->Image(
    dirname(__DIR__) . '/public/assets/images/blank_cert_template.jpg',
    0,
    0,
    297,
    210
);

// --- Task 5: Coordinate-mapped text injection --------------------------

// Student's Full Name — Arial Bold 36pt, Navy Blue #0f204b, centered ~Y=95.
$pdf->SetTextColor(15, 32, 75); // #0f204b
$pdf->SetFont('Arial', 'B', 36);
$pdf->SetXY(0, 110);
$pdf->Cell(297, 14, $studentFullNameOut, 0, 0, 'C');

// Course Title — Arial Bold 24pt, Navy Blue, centered ~Y=130.
$pdf->SetFont('Arial', 'B', 24);
$pdf->SetXY(0, 140);
$pdf->Cell(297, 12, $courseTitleOut, 0, 0, 'C');

// Date Issued — Arial Regular 14pt, Black, lower-middle-left.
// The template already carries the "Date Issued:" label, so this just
// drops the value right next to it.
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 14);
$pdf->SetXY(192, 169);
$pdf->Cell(70, 8, $dateIssuedOut, 0, 0, 'L');

// Certificate Hash — Courier Regular 10pt, Gray, lower-middle-right.
// Positioned directly after the "Verification Hash:" label on the
// template.
$pdf->SetTextColor(120, 120, 120);
$pdf->SetFont('Courier', '', 10);
$pdf->SetXY(175, 178);
$pdf->Cell(90, 6, $certificateHash, 0, 0, 'L');

// --- Task 6: Force download --------------------------------------------
// Filename pattern per spec: CLMS_Certificate_<FirstName>.pdf
$safeFirstName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $firstName !== '' ? $firstName : 'Student');
if ($safeFirstName === null || $safeFirstName === '') {
    $safeFirstName = 'Student';
}
$downloadName = 'CLMS_Certificate_' . $safeFirstName . '.pdf';

$pdf->Output('D', $downloadName);
exit;
