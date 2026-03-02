<?php

use Illuminate\Support\Facades\View;

describe('custom-flow blade template', function (): void {
    it('renders basic prompt without feedback', function (): void {
        $output = View::make('prompts.custom-flow', [
            'description' => 'Install composer dependencies',
            'previousFlow' => null,
            'feedback' => null,
        ])->render();

        expect($output)
            ->toContain('Install composer dependencies')
            ->toContain('BRANCH_NAME: The git branch name')
            ->toContain('WORKTREE_PATH: Full path')
            ->toContain('OPTIONS FORMAT')
            ->toContain('label/value format')
            ->not->toContain('PREVIOUS GENERATED FLOW')
            ->not->toContain('USER FEEDBACK');
    });

    it('renders prompt with feedback section when both previousFlow and feedback provided', function (): void {
        $previousFlow = [
            'id' => 'composer-install',
            'name' => 'Composer Install',
            'steps' => [
                ['id' => 'install', 'command' => 'composer install'],
            ],
        ];

        $output = View::make('prompts.custom-flow', [
            'description' => 'Install composer dependencies',
            'previousFlow' => $previousFlow,
            'feedback' => 'Add a step to clear cache after install',
        ])->render();

        expect($output)
            ->toContain('Install composer dependencies')
            ->toContain('PREVIOUS GENERATED FLOW')
            ->toContain('composer-install')
            ->toContain('Composer Install')
            ->toContain('USER FEEDBACK')
            ->toContain('Add a step to clear cache after install')
            ->toContain('modify the flow based on the user\'s feedback');
    });

    it('does not render feedback section when only previousFlow is provided', function (): void {
        $previousFlow = [
            'id' => 'test-flow',
            'name' => 'Test Flow',
            'steps' => [],
        ];

        $output = View::make('prompts.custom-flow', [
            'description' => 'Test description',
            'previousFlow' => $previousFlow,
            'feedback' => null,
        ])->render();

        expect($output)
            ->toContain('Test description')
            ->not->toContain('PREVIOUS GENERATED FLOW')
            ->not->toContain('USER FEEDBACK');
    });

    it('does not render feedback section when only feedback is provided', function (): void {
        $output = View::make('prompts.custom-flow', [
            'description' => 'Test description',
            'previousFlow' => null,
            'feedback' => 'Some feedback',
        ])->render();

        expect($output)
            ->toContain('Test description')
            ->not->toContain('PREVIOUS GENERATED FLOW')
            ->not->toContain('USER FEEDBACK');
    });

    it('contains all required schema documentation', function (): void {
        $output = View::make('prompts.custom-flow', [
            'description' => 'Test',
            'previousFlow' => null,
            'feedback' => null,
        ])->render();

        expect($output)
            ->toContain('AVAILABLE VARIABLES')
            ->toContain('AVAILABLE FILTERS')
            ->toContain('AVAILABLE PROMPT TYPES')
            ->toContain('OPTIONS FORMAT')
            ->toContain('CONDITIONS')
            ->toContain('IMPORTANT');
    });

    it('outputs valid template variable syntax', function (): void {
        $output = View::make('prompts.custom-flow', [
            'description' => 'Test',
            'previousFlow' => null,
            'feedback' => null,
        ])->render();

        expect($output)
            ->toContain('{{VARIABLE}}')
            ->toContain('{{db_name}}')
            ->toContain('{{VARIABLE|filter}}');
    });
});
