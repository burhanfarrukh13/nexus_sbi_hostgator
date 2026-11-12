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

    public function test_it_matches_compact_answer_and_marking_headings_to_questions_by_number(): void
    {
        $answers = implode("\n", [
            'Answer#1Assets = Liabilities + Capital.',
            'Answer#2Rs. 50,000.',
            'Answer#3Rs. 3,000 per year.',
            'Answer#4A fixed cost remains unchanged.',
            'Answer#56.',
            'Answer#6Rs. 100.',
            'Answer#73/5 or 60%.',
            'Answer#8A business owned by one person.',
            'Answer#9Quantity demanded decreases.',
            'Answer#10Issuing currency.',
            'Marking#1Assets = Liabilities + Capital |1',
            'Marking#2Rs. 50,000 |1',
            'Marking#3Rs. 3,000 per year |1',
            'Marking#4A fixed cost remains unchanged |1',
            'Marking#56 |1',
            'Marking#6Rs. 100 |1',
            'Marking#73/5 or 60% |1',
            'Marking#8A business owned by one person |1',
            'Marking#9Quantity demanded decreases |1',
            'Marking#10Issuing currency |1',
        ]);
        $questions = implode("\n", [
            'Section A - Fundamentals of Accounting',
            'Q1. Write the basic accounting equation. (1)',
            'Q2. Calculate capital. (1)',
            'Q3. Calculate annual depreciation. (1)',
            'Section B - Quantitative Analysis',
            'Q4. What is a fixed cost? (1)',
            'Q5. Calculate the arithmetic mean. (1)',
            'Q6. Calculate simple interest. (1)',
            'Q7. What is the probability? (1)',
            'Section C - Business and Economics',
            'Q8. What is a sole proprietorship? (1)',
            'Q9. Explain the law of demand. (1)',
            'Q10. State one function of the State Bank. (1)',
            'Total 10 Marks',
        ]);

        $result = (new QuestionImportParser())->parse([$answers, $questions], true);

        self::assertSame([], $result['errors']);
        self::assertCount(10, $result['groups']);
        $expectedQuestionPrefixes = [
            'Write the basic accounting equation.',
            'Calculate capital.',
            'Calculate annual depreciation.',
            'What is a fixed cost?',
            'Calculate the arithmetic mean.',
            'Calculate simple interest.',
            'What is the probability?',
            'What is a sole proprietorship?',
            'Explain the law of demand.',
            'State one function of the State Bank.',
        ];
        foreach ($result['groups'] as $index => $group) {
            self::assertStringStartsWith($expectedQuestionPrefixes[$index], $group['questionText']);
            self::assertSame([], $group['errors'], 'Question '.($index + 1).' should have all matching sections.');
            self::assertCount(1, $group['markingScheme']);
            self::assertSame(1.0, $group['markingScheme'][0]['marks']);
            self::assertStringNotContainsString('Section ', $group['questionText']);
        }
        self::assertSame('Assets = Liabilities + Capital.', $result['groups'][0]['solution']);
        self::assertSame('6.', $result['groups'][4]['solution']);
        self::assertSame('Issuing currency.', $result['groups'][9]['solution']);
    }
}
