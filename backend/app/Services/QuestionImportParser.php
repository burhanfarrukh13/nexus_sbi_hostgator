<?php

namespace App\Services;

class QuestionImportParser
{
    private const QUESTION = '/^\s*(?:question|q\.?)\s*(?:(?:no\.?|number)\s*)?#?\s*(\d+[a-z]*)\s*(?:\(\s*(easy|medium|moderate|hard|difficult)\s*\))?(?!\s*\()\s*[:.)-]?\s*(.*)$/i';
    private const NUMBERED = '/^\s*(answer|ans|solution|marking(?:\s+scheme)?|scheme)(?:\s+(?:for|to)\s+)?\s*(?:question\s*)?#?\s*(\d+[a-z]*)\s*[:.)-]?\s*(.*)$/i';
    private const CANDIDATE = '/^\s*(?:question|q\.?|answer|ans|solution|marking(?:\s+scheme)?|scheme)\s*(?:#|\d|for\b|to\b|no\.?|number\b)/i';
    private const ALLOC = '/\s*(?:\|\s*|\t+)(\d+(?:\.\d+)?)\s*$/';

    public function parse(array $documents, bool $allowGroupedDocuments): array
    {
        $lines = [];
        $sources = [];
        foreach ($documents as $sourceIndex => $document) {
            $page = null;
            foreach (preg_split('/\r\n?|\n/', $document) as $line) {
                if (preg_match('/^\s*--\s*(\d+)\s+of\s+\d+\s*--\s*$/', $line, $marker)) {
                    $page = (int) $marker[1];
                    continue;
                }
                $lines[] = $line;
                $sources[] = ['sourceIndex' => $sourceIndex, 'pageNumber' => $page];
            }
            $lines[] = '';
            $sources[] = ['sourceIndex' => $sourceIndex, 'pageNumber' => null];
        }

        $sections = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $question = preg_match(self::QUESTION, $line, $q) ? $q : null;
            $numbered = preg_match(self::NUMBERED, $line, $n) ? $n : null;
            if ($question === null && $numbered === null) {
                if (preg_match(self::CANDIDATE, $line)) {
                    $errors[] = 'Malformed section heading on line '.($index + 1).'.';
                }
                continue;
            }
            $identifier = $question !== null ? $question[1] : $numbered[2];
            if (!preg_match('/^(\d+)([a-z]*)$/i', $identifier, $parts) || (int) $parts[1] < 1) {
                $errors[] = 'Section on line '.($index + 1).' must use a positive question number.';
                continue;
            }
            $kind = $question !== null
                ? 'question'
                : (preg_match('/^(?:marking|scheme)/i', $numbered[1]) ? 'marking' : 'answer');
            $difficulty = '';
            if ($question !== null && !empty($question[2])) {
                $difficulty = match (strtolower($question[2])) {
                    'easy' => 'EASY',
                    'medium', 'moderate' => 'MODERATE',
                    default => 'DIFFICULT',
                };
            }
            $sections[] = [
                'kind' => $kind,
                'identifier' => (int) $parts[1].strtolower($parts[2]),
                'difficulty' => $difficulty,
                'inline' => $question !== null ? $question[3] : $numbered[3],
                'sourceIndex' => $sources[$index]['sourceIndex'],
                'pageNumber' => $sources[$index]['pageNumber'],
                'start' => $index + 1,
                'end' => count($lines),
            ];
        }

        foreach ($sections as $index => &$section) {
            if (isset($sections[$index + 1])) {
                $section['end'] = $sections[$index + 1]['start'] - 1;
            }
        }
        unset($section);
        if ($sections === []) {
            return ['groups' => [], 'errors' => ['No numbered question sections were found.']];
        }

        $byId = [];
        foreach ($sections as $section) {
            $byId[$section['identifier']][$section['kind']][] = $section;
        }
        $identifiers = array_keys($byId);
        usort($identifiers, static function (string $left, string $right): int {
            preg_match('/^(\d+)([a-z]*)$/', $left, $a);
            preg_match('/^(\d+)([a-z]*)$/', $right, $b);
            return ((int) $a[1] <=> (int) $b[1]) ?: strcmp($a[2], $b[2]);
        });

        if (!$allowGroupedDocuments) {
            $order = ['question' => 0, 'answer' => 1, 'marking' => 2];
            $previous = -1;
            $numberMap = array_flip($identifiers);
            foreach ($sections as $section) {
                $current = $numberMap[$section['identifier']] * 3 + $order[$section['kind']];
                if ($current < $previous) {
                    $errors[] = 'Sections are out of order near question '.$section['identifier'].'; use Question, Answer, then Marking for each group.';
                    break;
                }
                $previous = $current;
            }
        }

        $groups = [];
        foreach ($identifiers as $groupIndex => $identifier) {
            $fields = $byId[$identifier];
            $groupNumber = $groupIndex + 1;
            $groupErrors = [];
            $get = static function (string $kind) use (&$groupErrors, $fields, $groupNumber, $allowGroupedDocuments): array {
                $matches = $fields[$kind] ?? [];
                if ($matches === []) {
                    $groupErrors[] = "Missing {$kind} section for question {$groupNumber}.";
                } elseif (count($matches) > 1 && !$allowGroupedDocuments) {
                    $groupErrors[] = "Duplicate {$kind} sections for question {$groupNumber}.";
                }
                return $allowGroupedDocuments ? $matches : array_slice($matches, 0, 1);
            };
            $questionSections = $get('question');
            $answerSections = $get('answer');
            $markingSections = $get('marking');
            $questionText = $this->combinedContent($questionSections, $lines, $sources);
            $markingText = $this->combinedContent($markingSections, $lines, $sources);
            $solutionParts = [];
            foreach ($answerSections as $section) {
                $content = $this->sectionContent($section, $lines, $sources);
                if ($content !== '') {
                    $solutionParts[] = $content;
                }
            }
            $interleavedSections = [];
            foreach ($markingSections as $section) {
                $interleaved = $this->interleavedAnswer($section, $lines, $sources);
                if ($interleaved !== '') {
                    $solutionParts[] = $interleaved;
                    $interleavedSections[] = $section;
                }
            }
            $solution = implode("\n\n", array_values(array_unique($solutionParts)));
            if ($questionSections !== [] && trim($questionText) === '') {
                $groupErrors[] = "Question {$groupNumber} text is empty.";
            }
            if ($answerSections !== [] && trim($solution) === '') {
                $groupErrors[] = "Answer {$groupNumber} text is empty.";
            }
            if ($markingSections !== [] && trim($markingText) === '') {
                $groupErrors[] = "Marking criteria for question {$groupNumber} are empty.";
            }

            $scheme = [];
            foreach ($markingSections as $section) {
                $content = $this->sectionContent($section, $lines, $sources, true);
                $part = preg_match('/^\s*\(([a-z])\)/i', $section['inline'], $partMatch) ? strtolower($partMatch[1]) : null;
                foreach ($this->criteria($content, $groupNumber, $groupErrors) as $criterion) {
                    if ($part !== null) {
                        $criterion['criterion'] = "Part ({$part}): ".$criterion['criterion'];
                    }
                    $scheme[] = $criterion;
                }
            }
            $groups[] = [
                'groupNumber' => $groupNumber,
                'questionText' => $questionText,
                'questionSourceLocations' => $this->sourceLocations($questionSections, $lines, $sources),
                'difficulty' => $this->firstDifficulty($questionSections),
                'solution' => $solution,
                'solutionSourceLocations' => $this->sourceLocations(
                    array_merge($answerSections, $interleavedSections),
                    $lines,
                    $sources
                ),
                'markingScheme' => $scheme,
                'errors' => $groupErrors,
            ];
        }

        return ['groups' => $groups, 'errors' => $errors];
    }

    private function sectionContent(array $section, array $lines, array $sources, bool $trimMarkingSubparts = false): string
    {
        $body = $this->bodyLines($section, $lines, $sources);
        if ($trimMarkingSubparts && $section['kind'] === 'marking' &&
            preg_match('/^\s*\(([a-z])\)/i', $section['inline'], $part)) {
            foreach ($body as $index => $line) {
                if (preg_match('/^\s*\(([a-z])\)(?:\s|$)/i', $line, $next) && strtolower($next[1]) > strtolower($part[1])) {
                    $body = array_slice($body, 0, $index);
                    break;
                }
            }
        }
        return implode("\n", array_filter([trim($section['inline']), trim(implode("\n", $body))], static fn (string $v): bool => $v !== ''));
    }

    private function bodyLines(array $section, array $lines, array $sources): array
    {
        $body = [];
        for ($index = $section['start']; $index < $section['end']; $index++) {
            if (($sources[$index]['sourceIndex'] ?? null) === $section['sourceIndex']) {
                $body[] = $lines[$index];
            }
        }
        return $body;
    }

    private function combinedContent(array $sections, array $lines, array $sources): string
    {
        $contents = [];
        foreach ($sections as $section) {
            $content = $this->sectionContent($section, $lines, $sources, $section['kind'] === 'marking');
            if ($content !== '') {
                $contents[] = $content;
            }
        }
        return implode("\n\n", array_values(array_unique($contents)));
    }

    private function interleavedAnswer(array $section, array $lines, array $sources): string
    {
        if (!preg_match('/^\s*\(([a-z])\)/i', $section['inline'], $part)) {
            return '';
        }
        foreach ($this->bodyLines($section, $lines, $sources) as $index => $line) {
            if (preg_match('/^\s*\(([a-z])\)(?:\s|$)/i', $line, $next) && strtolower($next[1]) > strtolower($part[1])) {
                return trim(implode("\n", array_slice($this->bodyLines($section, $lines, $sources), $index)));
            }
        }
        return '';
    }

    private function sourceLocations(array $sections, array $lines, array $sources): array
    {
        $locations = [];
        foreach ($sections as $section) {
            $source = $section['sourceIndex'];
            $locations[$source] ??= ['sourceIndex' => $source, 'pageNumbers' => []];
            if ($section['pageNumber'] !== null) {
                $locations[$source]['pageNumbers'][] = $section['pageNumber'];
            }
            for ($index = $section['start']; $index < $section['end']; $index++) {
                if (($sources[$index]['sourceIndex'] ?? null) === $source && $sources[$index]['pageNumber'] !== null) {
                    $locations[$source]['pageNumbers'][] = $sources[$index]['pageNumber'];
                }
            }
            $locations[$source]['pageNumbers'] = array_values(array_unique($locations[$source]['pageNumbers']));
        }
        return array_values($locations);
    }

    private function firstDifficulty(array $sections): string
    {
        foreach ($sections as $section) {
            if ($section['difficulty'] !== '') {
                return $section['difficulty'];
            }
        }
        return '';
    }

    private function criteria(string $text, int $groupNumber, array &$errors): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n?|\n/', $text)), static fn (string $line): bool =>
            $line !== '' &&
            !preg_match('/^\([a-z]\)$/i', $line) &&
            !preg_match('/\bpage\s+\d+\s+of\s+\d+\s*$/i', $line) &&
            !preg_match('/^--\s*\d+(?:\s+of\s+\d+)?\s*--$/i', $line)
        ));
        $lines = $this->joinWrappedCriteria($lines);
        if ($lines === []) {
            return [];
        }
        $hasAllocations = count(array_filter($lines, fn (string $line): bool => preg_match(self::ALLOC, $line) === 1)) > 0;
        if (!$hasAllocations) {
            return array_map(static fn (string $line): array => ['criterion' => $line, 'marks' => 1], $lines);
        }
        $result = [];
        foreach ($lines as $line) {
            if (!preg_match(self::ALLOC, $line, $allocation)) {
                $errors[] = "Question {$groupNumber} has a marking line with no recognized mark allocation (it was counted as 1 mark): \"".mb_substr($line, 0, 80)."\".";
                $result[] = ['criterion' => $line, 'marks' => 1];
                continue;
            }
            $criterion = trim(preg_replace(self::ALLOC, '', $line) ?? '');
            $marks = (float) $allocation[1];
            if ($marks <= 0 || $marks > 10000 || abs($marks * 100 - round($marks * 100)) > 1e-7) {
                $errors[] = "Question {$groupNumber} has an invalid mark allocation \"{$allocation[1]}\"; use a positive value with at most two decimal places.";
                $result[] = ['criterion' => $criterion !== '' ? $criterion : $line, 'marks' => 1];
            } elseif ($criterion === '') {
                $errors[] = "Question {$groupNumber} has a mark allocation with no criterion text.";
                $result[] = ['criterion' => $line, 'marks' => 1];
            } else {
                $result[] = ['criterion' => $criterion, 'marks' => $marks];
            }
        }
        return $result;
    }

    private function joinWrappedCriteria(array $lines): array
    {
        $normalized = [];
        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            if (preg_match('/(?:\||\t)\s*$/', $line) === 1 &&
                isset($lines[$index + 1]) &&
                preg_match('/^\d+(?:\.\d+)?$/', $lines[$index + 1]) === 1) {
                $line .= ' '.$lines[++$index];
            }
            $normalized[] = $line;
        }

        $joined = [];
        foreach ($normalized as $line) {
            if (preg_match(self::ALLOC, $line) === 1 &&
                $joined !== [] &&
                preg_match(self::ALLOC, $joined[count($joined) - 1]) !== 1 &&
                preg_match('/^(?:\(?[a-z]\)|\d+[.)])\s+/i', $line) !== 1) {
                $joined[count($joined) - 1] .= ' '.$line;
            } else {
                $joined[] = $line;
            }
        }

        return $joined;
    }
}
