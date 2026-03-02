<?php

declare(strict_types=1);

use App\Services\CliffNotesParser;

describe('CliffNotesParser', function () {
    describe('extractTaskNotes', function () {
        it('extracts a single task note', function () {
            $content = <<<'MD'
            ---
            ## Task #1: Create something
            - Did the thing
            - Also did another thing
            MD;

            $parser = new CliffNotesParser;
            $notes = $parser->extractTaskNotes($content);

            expect($notes)
                ->toHaveCount(1)
                ->toHaveKey(1)
                ->and($notes[1])->toContain('## Task #1: Create something')
                ->toContain('- Did the thing')
                ->toContain('- Also did another thing');
        });

        it('extracts multiple task notes keyed by id', function () {
            $content = <<<'MD'
            ---
            ## Task #1: First task
            - Note A

            ---
            ## Task #3: Third task
            - Note B
            - Note C

            ---
            ## Task #5: Fifth task
            - Note D
            MD;

            $parser = new CliffNotesParser;
            $notes = $parser->extractTaskNotes($content);

            expect($notes)
                ->toHaveCount(3)
                ->toHaveKeys([1, 3, 5])
                ->and($notes[1])->toContain('First task')
                ->and($notes[3])->toContain('Note B')
                ->and($notes[5])->toContain('Note D');
        });

        it('returns empty array for empty content', function () {
            $parser = new CliffNotesParser;

            expect($parser->extractTaskNotes(''))->toBe([])
                ->and($parser->extractTaskNotes('   '))->toBe([]);
        });

        it('returns empty array for content with no task markers', function () {
            $content = "Some random text\nwithout any task headings\n";

            $parser = new CliffNotesParser;

            expect($parser->extractTaskNotes($content))->toBe([]);
        });

        it('preserves full section content including sub-bullets', function () {
            $content = <<<'MD'
            ---
            ## Task #7: Complex task
            - Main point
              - Sub point 1
              - Sub point 2
            - Another point
            MD;

            $parser = new CliffNotesParser;
            $notes = $parser->extractTaskNotes($content);

            expect($notes[7])
                ->toContain('Main point')
                ->toContain('Sub point 1')
                ->toContain('Another point');
        });
    });

    describe('formatTaskNote', function () {
        it('produces correct output format', function () {
            $parser = new CliffNotesParser;
            $result = $parser->formatTaskNote(42, 'Do the thing', "- Did it\n- Done");

            expect($result)->toBe("---\n## Task #42: Do the thing\n- Did it\n- Done");
        });

        it('handles empty notes', function () {
            $parser = new CliffNotesParser;
            $result = $parser->formatTaskNote(1, 'Empty', '');

            expect($result)->toBe("---\n## Task #1: Empty\n");
        });
    });
});
