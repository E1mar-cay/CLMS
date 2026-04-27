<?php

declare(strict_types=1);

/**
 * Command-line grading logic verification (no HTTP/POST required).
 * Run: php tests/test_grading_logic.php
 */

function gradeAllOrNothing(string $questionType, array $userAnswers, array $databaseAnswers): bool
{
    if ($questionType === 'multiple_select') {
        $user = array_values(array_unique(array_map('intval', $userAnswers)));
        $db = array_values(array_unique(array_map('intval', $databaseAnswers)));
        sort($user);
        sort($db);

        return $user === $db;
    }

    if ($questionType === 'sequencing') {
        $normalizedUser = [];
        foreach ($userAnswers as $answerId => $position) {
            $normalizedUser[(int) $answerId] = (int) $position;
        }
        $normalizedDb = [];
        foreach ($databaseAnswers as $answerId => $position) {
            $normalizedDb[(int) $answerId] = (int) $position;
        }
        ksort($normalizedUser);
        ksort($normalizedDb);

        return $normalizedUser === $normalizedDb;
    }

    return false;
}

// Three mock scenarios for direct CLI verification.
$mockCases = [
    [
        'label' => 'Multiple Select - exact match (should be true)',
        'question_type' => 'multiple_select',
        'user_answers' => [11, 13, 15],
        'database_answers' => [15, 13, 11],
    ],
    [
        'label' => 'Multiple Select - partial selection (should be false)',
        'question_type' => 'multiple_select',
        'user_answers' => [11, 13],
        'database_answers' => [11, 13, 15],
    ],
    [
        'label' => 'Sequencing - strict order map (should be true)',
        'question_type' => 'sequencing',
        'user_answers' => [
            201 => 1,
            202 => 2,
            203 => 3,
        ],
        'database_answers' => [
            201 => 1,
            202 => 2,
            203 => 3,
        ],
    ],
];

foreach ($mockCases as $case) {
    $result = gradeAllOrNothing(
        $case['question_type'],
        $case['user_answers'],
        $case['database_answers']
    );

    echo "==== {$case['label']} ====\n";
    echo "Question Type: {$case['question_type']}\n";
    echo "User Answers:\n";
    var_dump($case['user_answers']);
    echo "Database Answers:\n";
    var_dump($case['database_answers']);
    echo "All-or-Nothing Result:\n";
    var_dump($result);
    echo "\n";
}
