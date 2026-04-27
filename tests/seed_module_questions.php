<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';

/**
 * Seeds 5 single-choice questions (4 options each) per module for testing the
 * module-quiz + final-exam flow. Safe to run multiple times: modules that
 * already have questions attached are skipped.
 *
 *   php tests/seed_module_questions.php
 */

try {
    $check = $pdo->query("SHOW COLUMNS FROM questions LIKE 'module_id'")->fetch();
    if (!$check) {
        $pdo->exec('ALTER TABLE questions ADD COLUMN module_id INT NULL AFTER course_id');
        $pdo->exec('ALTER TABLE questions ADD INDEX idx_questions_module (module_id)');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not verify module_id column: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Pool of criminology-flavoured single-choice question templates.
 * Each entry: [question_text, [ [answer_text, is_correct], ... ]].
 */
$questionPool = [
    [
        'Which principle states that a perpetrator brings something to the scene and leaves with something from it?',
        [
            ['Locard\'s Exchange Principle', true],
            ['Hume\'s Principle of Uniformity', false],
            ['Bentham\'s Utility Principle', false],
            ['Beccaria\'s Rational Choice', false],
        ],
    ],
    [
        'What is the first priority of a responding officer at a crime scene?',
        [
            ['Preserve life and ensure safety', true],
            ['Collect physical evidence', false],
            ['Interview the suspect', false],
            ['Photograph the scene', false],
        ],
    ],
    [
        'Which document legally authorizes law enforcement to search a private dwelling?',
        [
            ['Search Warrant', true],
            ['Arrest Warrant', false],
            ['Subpoena Duces Tecum', false],
            ['Writ of Habeas Corpus', false],
        ],
    ],
    [
        'Chain of custody refers to the:',
        [
            ['Documented handling history of evidence from collection to court', true],
            ['Order of officers responding to an incident', false],
            ['Hierarchy of command inside a police station', false],
            ['Sequence of victims in a multi-casualty incident', false],
        ],
    ],
    [
        'Which is NOT one of the classic elements of a crime?',
        [
            ['Attire of the offender', true],
            ['Actus reus', false],
            ['Mens rea', false],
            ['Concurrence of act and intent', false],
        ],
    ],
    [
        'What type of evidence directly establishes a fact without inference?',
        [
            ['Direct evidence', true],
            ['Circumstantial evidence', false],
            ['Hearsay evidence', false],
            ['Demonstrative evidence', false],
        ],
    ],
    [
        'The Miranda warning must be given when a person is:',
        [
            ['In custody and about to be interrogated', true],
            ['Merely a witness to a crime', false],
            ['Being booked into a county jail', false],
            ['Being processed for a traffic ticket', false],
        ],
    ],
    [
        'Which interview technique emphasizes rapport-building and open-ended questions?',
        [
            ['Cognitive Interview', true],
            ['Reid Technique', false],
            ['Hostile Interrogation', false],
            ['Polygraph Examination', false],
        ],
    ],
    [
        'In photographing a crime scene, the correct sequence is:',
        [
            ['Overall, mid-range, close-up', true],
            ['Close-up, mid-range, overall', false],
            ['Aerial, panoramic, macro', false],
            ['Evidence only, then scene', false],
        ],
    ],
    [
        'What is the best collection method for trace fibers on a carpet?',
        [
            ['Taping with adhesive lifters', true],
            ['Vacuuming into an open bag', false],
            ['Wiping with a damp cloth', false],
            ['Brushing into a pile', false],
        ],
    ],
    [
        'Rigor mortis typically reaches its peak:',
        [
            ['12 hours after death', true],
            ['30 minutes after death', false],
            ['3 days after death', false],
            ['Immediately after death', false],
        ],
    ],
    [
        'Which pattern indicates medium-velocity blood spatter?',
        [
            ['Spatter sized 1-4 mm, typical of beatings or stabbings', true],
            ['Fine mist under 1 mm, typical of gunshots', false],
            ['Large drops over 4 mm, typical of gravity drips', false],
            ['Arterial arcs showing cardiac pulse', false],
        ],
    ],
    [
        'The presumption of innocence in criminal law means:',
        [
            ['The accused is considered innocent until proven guilty beyond reasonable doubt', true],
            ['The accused must prove their innocence', false],
            ['The judge decides guilt before trial', false],
            ['The prosecution is presumed correct', false],
        ],
    ],
    [
        'A suspect\'s right against self-incrimination comes from which constitutional clause?',
        [
            ['Fifth Amendment / Right against self-incrimination', true],
            ['First Amendment / Free speech', false],
            ['Fourth Amendment / Search and seizure', false],
            ['Eighth Amendment / Cruel and unusual punishment', false],
        ],
    ],
    [
        'Which fingerprint pattern is most common in the general population?',
        [
            ['Loop', true],
            ['Whorl', false],
            ['Arch', false],
            ['Composite', false],
        ],
    ],
    [
        'A modus operandi describes:',
        [
            ['The offender\'s method of committing crimes', true],
            ['The legal sentencing framework', false],
            ['The list of suspects in a case', false],
            ['The forensic lab workflow', false],
        ],
    ],
    [
        'When securing a crime scene perimeter, investigators should:',
        [
            ['Establish an outer, inner, and core zone with controlled access', true],
            ['Allow media inside to document events', false],
            ['Let witnesses roam freely for recall accuracy', false],
            ['Remove all evidence quickly before the public arrives', false],
        ],
    ],
    [
        'Which of the following is a primary goal of a criminal investigation?',
        [
            ['Determine whether a crime occurred and identify the offender', true],
            ['Generate publicity for the department', false],
            ['Provide training exercises for new recruits', false],
            ['Assign blame to the first available suspect', false],
        ],
    ],
    [
        'A suspect waives Miranda rights. What must be true?',
        [
            ['The waiver is made knowingly, voluntarily, and intelligently', true],
            ['The waiver must be written in Latin', false],
            ['The waiver automatically expires after 24 hours', false],
            ['The waiver requires two witnesses from the media', false],
        ],
    ],
    [
        'DNA samples from biological evidence are most reliably collected using:',
        [
            ['Sterile swabs and proper packaging', true],
            ['Reused cotton balls', false],
            ['Household paper towels', false],
            ['Aluminum foil wrappers', false],
        ],
    ],
];

$moduleStmt = $pdo->query(
    'SELECT m.id AS module_id, m.course_id, m.title AS module_title, c.title AS course_title
     FROM modules m
     INNER JOIN courses c ON c.id = m.course_id
     ORDER BY m.course_id ASC, m.sequence_order ASC, m.id ASC'
);
$modules = $moduleStmt->fetchAll();

if ($modules === []) {
    echo "No modules found. Run tests/db_seed.php first.\n";
    exit(0);
}

$existingStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM questions WHERE module_id = :module_id');

$insertQuestionStmt = $pdo->prepare(
    'INSERT INTO questions (course_id, module_id, question_text, question_type, points)
     VALUES (:course_id, :module_id, :question_text, :question_type, :points)'
);
$insertAnswerStmt = $pdo->prepare(
    'INSERT INTO answers (question_id, answer_text, is_correct, sequence_position)
     VALUES (:question_id, :answer_text, :is_correct, :sequence_position)'
);

$questionsPerModule = 5;
$totalInsertedQuestions = 0;
$modulesSeeded = 0;
$modulesSkipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($modules as $module) {
        $moduleId = (int) $module['module_id'];
        $courseId = (int) $module['course_id'];

        $existingStmt->execute(['module_id' => $moduleId]);
        $existing = (int) ($existingStmt->fetch()['total'] ?? 0);
        if ($existing > 0) {
            $modulesSkipped++;
            echo "- Skipped (already has {$existing} questions): [{$module['course_title']}] {$module['module_title']}\n";
            continue;
        }

        $poolKeys = array_keys($questionPool);
        shuffle($poolKeys);
        $selectedKeys = array_slice($poolKeys, 0, $questionsPerModule);

        foreach ($selectedKeys as $poolKey) {
            [$questionText, $answerDefs] = $questionPool[$poolKey];

            $insertQuestionStmt->execute([
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'question_text' => $questionText,
                'question_type' => 'single_choice',
                'points' => '1.00',
            ]);
            $questionId = (int) $pdo->lastInsertId();
            $totalInsertedQuestions++;

            $shuffledAnswers = $answerDefs;
            shuffle($shuffledAnswers);
            foreach ($shuffledAnswers as $position => $answer) {
                $insertAnswerStmt->execute([
                    'question_id' => $questionId,
                    'answer_text' => (string) $answer[0],
                    'is_correct' => $answer[1] ? 1 : 0,
                    'sequence_position' => $position + 1,
                ]);
            }
        }

        $modulesSeeded++;
        echo "+ Seeded {$questionsPerModule} questions: [{$module['course_title']}] {$module['module_title']}\n";
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seeding failed: {$e->getMessage()}\n");
    exit(1);
}

echo "\nDone.\n";
echo "Modules seeded:  {$modulesSeeded}\n";
echo "Modules skipped: {$modulesSkipped}\n";
echo "Questions added: {$totalInsertedQuestions}\n";
