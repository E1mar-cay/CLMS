<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/sneat-paths.php';

/**
 * Printable questionnaire view for admin/reports.php (expects scope-checked course).
 *
 * @var PDO $pdo
 * @var array{id:int|string,title:string} $courseRow
 */

$courseId = (int) $courseRow['id'];

$questionsStmt = $pdo->prepare(
    'SELECT q.id, q.module_id, q.question_text, q.question_type, q.points,
            m.title AS module_title, m.sequence_order AS module_seq
     FROM questions q
     LEFT JOIN modules m ON m.id = q.module_id
     WHERE q.course_id = :course_id
     ORDER BY
       CASE WHEN q.module_id IS NULL THEN 1 ELSE 0 END,
       COALESCE(m.sequence_order, 999999),
       m.id,
       q.id'
);
$questionsStmt->execute(['course_id' => $courseId]);
$questionRows = $questionsStmt->fetchAll();

$sections = [];
foreach ($questionRows as $qr) {
    $mid = $qr['module_id'] !== null ? (string) (int) $qr['module_id'] : '_final';
    if (!isset($sections[$mid])) {
        $sections[$mid] = [
            'label' => $qr['module_id'] !== null
                ? trim((string) ($qr['module_title'] ?? 'Module'))
                : 'Final Exam (course-level)',
            'questions' => [],
        ];
    }
    $sections[$mid]['questions'][] = $qr;
}

$answersByQuestion = [];
if ($questionRows !== []) {
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $questionRows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $ansStmt = $pdo->prepare(
        "SELECT question_id, answer_text, is_correct, sequence_position
         FROM answers
         WHERE question_id IN ($placeholders)
         ORDER BY id ASC"
    );
    $ansStmt->execute($ids);
    while ($ar = $ansStmt->fetch()) {
        $qid = (int) $ar['question_id'];
        if (!isset($answersByQuestion[$qid])) {
            $answersByQuestion[$qid] = [];
        }
        $answersByQuestion[$qid][] = $ar;
    }
}

$pageTitleEsc = htmlspecialchars((string) $courseRow['title'], ENT_QUOTES, 'UTF-8');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Questionnaire — <?php echo $pageTitleEsc; ?></title>
  <style>
    :root { font-family: system-ui, Segoe UI, Roboto, sans-serif; color: #1a1a1a; }
    body { margin: 0; padding: 1.25rem 1.5rem 2rem; background: #fff; }
    h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
    .meta { color: #555; font-size: 0.9rem; margin-bottom: 1.25rem; }
    h2 { font-size: 1.05rem; margin: 1.25rem 0 0.65rem; padding-bottom: 0.25rem; border-bottom: 2px solid #333; }
    .q { margin: 1rem 0 1.25rem; page-break-inside: avoid; }
    .q-num { font-weight: 700; margin-right: 0.35rem; }
    .q-type { font-size: 0.78rem; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
    .points { font-size: 0.85rem; color: #444; }
    ul.opts { margin: 0.35rem 0 0 1.25rem; padding: 0; }
    ul.opts li { margin: 0.2rem 0; }
    .corr { font-weight: 600; }
    .seq { margin: 0.25rem 0 0 1rem; padding-left: 0; list-style: decimal; }
    .toolbar { margin-bottom: 1rem; }
    .toolbar button {
      font: inherit; padding: 0.45rem 0.85rem; cursor: pointer;
      background: #333; color: #fff; border: 0; border-radius: 6px;
    }
    @media print {
      .toolbar { display: none; }
      body { padding: 0; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="window.print()">Print</button>
  </div>
  <h1><?php echo $pageTitleEsc; ?></h1>
  <p class="meta">Questionnaire export — <?php echo htmlspecialchars(date('F j, Y'), ENT_QUOTES, 'UTF-8'); ?></p>

<?php if ($sections === []) : ?>
  <p>No questions have been authored for this course yet.</p>
<?php else : ?>
<?php
    $n = 0;
    foreach ($sections as $sec) :
?>
  <h2><?php echo htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
<?php foreach ($sec['questions'] as $q) :
        $n++;
        $qid = (int) $q['id'];
        $type = (string) ($q['question_type'] ?? '');
        $label = function_exists('clms_question_type_label') ? clms_question_type_label($type) : $type;
        $pts = number_format((float) ($q['points'] ?? 0), 2);
        $answers = $answersByQuestion[$qid] ?? [];
?>
  <div class="q">
    <div>
      <span class="q-num"><?php echo $n; ?>.</span>
      <span class="q-type"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="points">(<?php echo htmlspecialchars($pts, ENT_QUOTES, 'UTF-8'); ?> pts)</span>
    </div>
    <p style="margin: 0.35rem 0 0;"><?php echo nl2br(htmlspecialchars((string) ($q['question_text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
<?php if ($type === 'multiple_select' || $type === 'single_choice') : ?>
    <ul class="opts">
<?php foreach ($answers as $ans) : ?>
      <li class="<?php echo (int) ($ans['is_correct'] ?? 0) === 1 ? 'corr' : ''; ?>">
        <?php echo htmlspecialchars((string) ($ans['answer_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        <?php if ((int) ($ans['is_correct'] ?? 0) === 1) : ?><span class="corr"> (correct)</span><?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php elseif ($type === 'true_false') : ?>
    <ul class="opts">
<?php foreach ($answers as $ans) : ?>
      <li class="<?php echo (int) ($ans['is_correct'] ?? 0) === 1 ? 'corr' : ''; ?>">
        <?php echo htmlspecialchars((string) ($ans['answer_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        <?php if ((int) ($ans['is_correct'] ?? 0) === 1) : ?> — <span class="corr">correct</span><?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php elseif ($type === 'fill_blank') : ?>
    <p class="opts" style="margin-left:1rem;"><strong>Accepted answers:</strong>
      <?php
        $acc = [];
        foreach ($answers as $ans) {
            if ((int) ($ans['is_correct'] ?? 0) === 1) {
                $acc[] = (string) ($ans['answer_text'] ?? '');
            }
        }
        echo htmlspecialchars(implode('; ', $acc), ENT_QUOTES, 'UTF-8');
      ?>
    </p>
<?php elseif ($type === 'sequencing') : ?>
    <ol class="seq">
<?php
        $seqRows = $answers;
        usort($seqRows, static function (array $a, array $b): int {
            $pa = (int) ($a['sequence_position'] ?? 0);
            $pb = (int) ($b['sequence_position'] ?? 0);
            return $pa <=> $pb;
        });
        foreach ($seqRows as $ans) :
?>
      <li><?php echo htmlspecialchars((string) ($ans['answer_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></li>
<?php endforeach; ?>
    </ol>
<?php else : ?>
    <ul class="opts">
<?php foreach ($answers as $ans) : ?>
      <li><?php echo htmlspecialchars((string) ($ans['answer_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
