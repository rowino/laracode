<?php

declare(strict_types=1);

use App\Services\GitHelper;

describe('GitHelper', function () {
    describe('defaultBranch', function () {
        it('returns main when main branch exists among branches', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--format=%(refname:short)'], '/some/path')
                ->andReturn("feature/auth\nmain\ndevelop");

            expect($helper->defaultBranch('/some/path'))->toBe('main');
        });

        it('returns master when master exists but not main', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--format=%(refname:short)'], '/some/path')
                ->andReturn("feature/auth\nmaster\ndevelop");

            expect($helper->defaultBranch('/some/path'))->toBe('master');
        });

        it('returns develop when only develop exists', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--format=%(refname:short)'], '/some/path')
                ->andReturn("feature/auth\ndevelop\nfeature/login");

            expect($helper->defaultBranch('/some/path'))->toBe('develop');
        });

        it('returns first branch as fallback when no known branch found', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--format=%(refname:short)'], '/some/path')
                ->andReturn("feature/auth\nfeature/login");

            expect($helper->defaultBranch('/some/path'))->toBe('feature/auth');
        });

        it('returns main when git returns empty output', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--format=%(refname:short)'], '/some/path')
                ->andReturn('');

            expect($helper->defaultBranch('/some/path'))->toBe('main');
        });
    });

    describe('currentBranch', function () {
        it('returns the current branch name', function () {
            $helper = Mockery::mock(GitHelper::class)
                ->makePartial()
                ->shouldAllowMockingProtectedMethods();

            $helper->shouldReceive('runGitCommand')
                ->with(['git', 'branch', '--show-current'], '/some/path')
                ->andReturn('feature/worktree');

            expect($helper->currentBranch('/some/path'))->toBe('feature/worktree');
        });
    });
});
