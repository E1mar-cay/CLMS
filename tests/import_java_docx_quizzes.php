<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';

/**
 * Imports Quiz 1 and Quiz 2 MCQs from a DOCX into the Java course modules.
 *
 * Usage:
 *   php tests/import_java_docx_quizzes.php "C:\path\to\Java_Quiz_MCQ_ENBES.docx"
 *
 * Notes:
 * - Quiz 1 -> first module in Java course
 * - Quiz 2 -> second module in Java course
 * - Final exam in this CLMS uses all course questions, so these become part of
 *   the final exam automatically.
 * - Duplicate question text in the same module is skipped.
 */

$docxPath = $argv[1] ?? '';
if ($docxPath === '') {
    fwrite(STDERR, "Usage: php tests/import_java_docx_quizzes.php \"C:\\path\\to\\Java_Quiz_MCQ_ENBES.docx\"\n");
    exit(1);
}
if (!is_file($docxPath)) {
    fwrite(STDERR, "DOCX not found: {$docxPath}\n");
    exit(1);
}

function parseDocxParagraphs(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open DOCX file.');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('DOCX document.xml is missing or empty.');
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        throw new RuntimeException('Could not parse DOCX XML.');
    }

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $paragraphs = [];
    foreach ($xp->query('//w:p') as $pNode) {
        $parts = [];
        foreach ($xp->query('.//w:t', $pNode) as $tNode) {
            $parts[] = (string) $tNode->textContent;
        }
        $line = trim(implode('', $parts));
        if ($line !== '') {
            $paragraphs[] = preg_replace('/\s+/u', ' ', $line) ?? $line;
        }
    }

    return $paragraphs;
}

/**
 * Returns:
 * [
 *   'quiz1' => [ ['question_text' => '', 'options' => ['A'=>'...'], 'correct' => 'B'], ... ],
 *   'quiz2' => [ ... ],
 * ]
 */
function parseQuestions(array $lines): array
{
    $sections = [
        'quiz1' => [],
        'quiz2' => [],
    ];

    $currentSection = null;
    $current = null;

    $commitCurrent = static function () use (&$sections, &$currentSection, &$current): void {
        if ($currentSection === null || $current === null) {
            $current = null;
            return;
        }
        $hasAllOptions = isset($current['options']['A'], $current['options']['B'], $current['options']['C'], $current['options']['D']);
        $correct = $current['correct'] ?? null;
        if ($hasAllOptions && in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            $sections[$currentSection][] = $current;
        }
        $current = null;
    };

    foreach ($lines as $line) {
        $lineNorm = trim($line);

        if (preg_match('/^Quiz\s*1\b/i', $lineNorm)) {
            $commitCurrent();
            $currentSection = 'quiz1';
            continue;
        }
        if (preg_match('/^Quiz\s*2\b/i', $lineNorm)) {
            $commitCurrent();
            $currentSection = 'quiz2';
            continue;
        }

        if ($currentSection === null) {
            continue;
        }

        if (preg_match('/^\d+\.\s*(.+)$/', $lineNorm, $m)) {
            $commitCurrent();
            $current = [
                'question_text' => trim($m[1]),
                'options' => [],
                'correct' => null,
            ];
            continue;
        }

        if ($current === null) {
            continue;
        }

        if (preg_match('/^([A-D])\.\s*(.+)$/', $lineNorm, $m)) {
            $current['options'][$m[1]] = trim($m[2]);
            continue;
        }

        if (preg_match('/Answer:\s*([A-D])/i', $lineNorm, $m)) {
            $current['correct'] = strtoupper($m[1]);
            continue;
        }
    }

    $commitCurrent();
    return $sections;
}

try {
    $courseStmt = $pdo->prepare(
        'SELECT id, title
         FROM courses
         WHERE LOWER(title) LIKE :needle
         ORDER BY id ASC
         LIMIT 1'
    );
    $courseStmt->execute(['needle' => '%java%']);
    $course = $courseStmt->fetch();
    if (!$course) {
        throw new RuntimeException('No course found with title containing "Java".');
    }
    $courseId = (int) $course['id'];

    $modulesStmt = $pdo->prepare(
        'SELECT id, title, sequence_order
         FROM modules
         WHERE course_id = :course_id
         ORDER BY sequence_order ASC, id ASC'
    );
    $modulesStmt->execute(['course_id' => $courseId]);
    $modules = $modulesStmt->fetchAll();
    if ($modules === []) {
        throw new RuntimeException('Java course has no modules. Add at least one module first.');
    }

    $moduleForQuiz1 = (int) $modules[0]['id'];
    // If the course has only one module, import both quizzes into it.
    $moduleForQuiz2 = (int) ($modules[1]['id'] ?? $modules[0]['id']);

    $lines = parseDocxParagraphs($docxPath);
    $parsed = parseQuestions($lines);
    $quiz1 = $parsed['quiz1'];
    $quiz2 = $parsed['quiz2'];

    if ($quiz1 === [] && $quiz2 === []) {
        throw new RuntimeException('No Quiz 1/Quiz 2 questions parsed from DOCX.');
    }

    $existsStmt = $pdo->prepare(
        'SELECT id
         FROM questions
         WHERE course_id = :course_id
           AND module_id = :module_id
           AND question_text = :question_text
         LIMIT 1'
    );
    $insertQStmt = $pdo->prepare(
        'INSERT INTO questions (course_id, module_id, question_text, question_type, points)
         VALUES (:course_id, :module_id, :question_text, :question_type, :points)'
    );
    $insertAStmt = $pdo->prepare(
        'INSERT INTO answers (question_id, answer_text, is_correct, sequence_position)
         VALUES (:question_id, :answer_text, :is_correct, :sequence_position)'
    );

    $inserted = 0;
    $skipped = 0;

    $insertSet = static function (array $items, int $targetModuleId) use (
        $pdo,
        $courseId,
        $existsStmt,
        $insertQStmt,
        $insertAStmt,
        &$inserted,
        &$skipped
    ): void {
        foreach ($items as $item) {
            $qText = (string) $item['question_text'];
            $existsStmt->execute([
                'course_id' => $courseId,
                'module_id' => $targetModuleId,
                'question_text' => $qText,
            ]);
            if ($existsStmt->fetch()) {
                $skipped++;
                continue;
            }

            $insertQStmt->execute([
                'course_id' => $courseId,
                'module_id' => $targetModuleId,
                'question_text' => $qText,
                'question_type' => 'single_choice',
                'points' => '1.00',
            ]);
            $questionId = (int) $pdo->lastInsertId();

            $correctKey = (string) $item['correct'];
            $sequence = 1;
            foreach (['A', 'B', 'C', 'D'] as $optKey) {
                $insertAStmt->execute([
                    'question_id' => $questionId,
                    'answer_text' => (string) ($item['options'][$optKey] ?? ''),
                    'is_correct' => $optKey === $correctKey ? 1 : 0,
                    'sequence_position' => $sequence,
                ]);
                $sequence++;
            }
            $inserted++;
        }
    };

    $pdo->beginTransaction();
    $insertSet($quiz1, $moduleForQuiz1);
    $insertSet($quiz2, $moduleForQuiz2);
    $pdo->commit();

    echo "Imported into course: {$course['title']} (ID {$courseId})\n";
    echo "Quiz 1 module: {$modules[0]['title']} (ID {$moduleForQuiz1})\n";
    if (isset($modules[1])) {
        echo "Quiz 2 module: {$modules[1]['title']} (ID {$moduleForQuiz2})\n";
    } else {
        echo "Quiz 2 module: {$modules[0]['title']} (ID {$moduleForQuiz2}) [fallback: single module]\n";
    }
    echo "Parsed Quiz 1 questions: " . count($quiz1) . "\n";
    echo "Parsed Quiz 2 questions: " . count($quiz2) . "\n";
    echo "Inserted new questions: {$inserted}\n";
    echo "Skipped duplicates: {$skipped}\n";
    echo "Final exam note: CLMS final exam reads all questions in the course.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}

