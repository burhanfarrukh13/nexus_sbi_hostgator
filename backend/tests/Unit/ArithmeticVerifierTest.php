<?php

namespace Tests\Unit;

use App\Services\ArithmeticVerifier;
use PHPUnit\Framework\TestCase;

class ArithmeticVerifierTest extends TestCase
{
    public function test_it_checks_nested_arithmetic_and_reports_wrong_answer_key_values(): void
    {
        $verifier = new ArithmeticVerifier();

        self::assertSame([], $verifier->verifyAnswerKey('2 * (3 + 4) = 14'));
        self::assertSame(
            ['"2 * (3 + 4)" evaluates to 14, but the key states 15.'],
            $verifier->verifyAnswerKey('2 * (3 + 4) = 15')
        );
    }

    public function test_it_verifies_answer_arithmetic_and_markdown_table_totals(): void
    {
        $verifier = new ArithmeticVerifier();
        $facts = $verifier->verifiedFacts(
            "Working: 2 * (3 + 4) = 14\n| Total | 5 | 7 | 12 |"
        );

        self::assertContains('2 * (3 + 4) = 14', $facts);
        self::assertContains('Table total verified: 12.', $facts);
        self::assertSame(
            ['Table total check: listed values sum to 12, not the reported 11.'],
            $verifier->verifyTables('| Total | 5 | 7 | 11 |')
        );
    }

    public function test_it_does_not_evaluate_unsupported_or_division_by_zero_expressions(): void
    {
        $verifier = new ArithmeticVerifier();

        self::assertSame([], $verifier->verifyAnswerKey('2 ** 3 = 8'));
        self::assertSame([], $verifier->verifyAnswerKey('8 / 0 = 0'));
    }
}
