<?php

namespace Tests\Unit;

use App\Services\QuestionImportParser;
use PHPUnit\Framework\TestCase;

class QuestionImportParserTest extends TestCase
{
    public function test_it_joins_wrapped_criteria_and_split_mark_allocations(): void
    {
        $result = (new QuestionImportParser())->parse([
            "Question 1: Evaluate the expression.\n".
            "Answer 1: 14\n".
            "Marking 1:\nShow the calculation and explain why it is\ncontinued. |\n2",
        ], false);

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['groups'][0]['errors']);
        self::assertSame(
            [[
                'criterion' => 'Show the calculation and explain why it is continued.',
                'marks' => 2.0,
            ]],
            $result['groups'][0]['markingScheme']
        );
    }

    public function test_it_preserves_question_part_labels_in_marking_criteria(): void
    {
        $result = (new QuestionImportParser())->parse([
            "Question 1: Evaluate the expression.\n".
            "Answer 1: 14\n".
            "Marking 1: (a)\nCorrect calculation | 2\n".
            "Marking 1: (b)\nClear explanation | 1",
        ], true);

        self::assertSame([], $result['errors']);
        self::assertSame(
            [
                ['criterion' => 'Part (a): Correct calculation', 'marks' => 2.0],
                ['criterion' => 'Part (b): Clear explanation', 'marks' => 1.0],
            ],
            $result['groups'][0]['markingScheme']
        );
    }
}
