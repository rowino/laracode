<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->testPath = sys_get_temp_dir().'/laracode-statusline-'.uniqid();
    mkdir($this->testPath, 0755, true);
    $this->scriptPath = dirname(__DIR__, 2).'/stubs/scripts/statusline.php';
});

afterEach(function () {
    if (is_dir($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

it('outputs model and context percentage without lock file', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus', 'id' => 'claude-opus-4-5-20251101'],
        'context_window' => ['used_percentage' => 25],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)
        ->toContain('[Opus]')
        ->toContain('25%')
        ->not->toContain('#');
});

it('formats opus model name correctly', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus', 'id' => 'claude-opus-4-5-20251101'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('[Opus]');
});

it('formats sonnet model name correctly', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Sonnet', 'id' => 'claude-3-5-sonnet-20241022'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('[Sonnet]');
});

it('formats haiku model name correctly', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Haiku', 'id' => 'claude-3-haiku-20240307'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('[Haiku]');
});

it('handles unknown model names', function () {
    $input = json_encode([
        'model' => ['display_name' => 'gpt-4-turbo', 'id' => 'gpt-4-turbo'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('[Gpt]');
});

it('uses model id when display_name is missing', function () {
    $input = json_encode([
        'model' => ['id' => 'claude-opus-4-1'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('[Opus]');
});

it('calculates context percentage from used_percentage', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 50],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('50%');
});

it('handles legacy format with context_used and context_window', function () {
    $input = json_encode([
        'model' => 'claude-opus',
        'context_used' => 100000,
        'context_window' => 200000,
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('50%');
});

it('shows green color for low context usage', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 10],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    // 10% usage should be green (ANSI code 32)
    expect($output)->toContain("\033[32m");
});

it('shows yellow color for medium context usage', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 70],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    // 70% usage should be yellow (ANSI code 33)
    expect($output)->toContain("\033[33m70%");
});

it('shows red color for high context usage', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 90],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    // 90% usage should be red (ANSI code 31)
    expect($output)->toContain("\033[31m");
});

it('displays task info when lock file exists', function () {
    // Create .laracode/specs/feature directory with lock file
    $specsDir = $this->testPath.'/.laracode/specs/my-feature';
    mkdir($specsDir, 0755, true);

    $lockData = [
        'pid' => 12345,
        'started' => date('c'),
        'tasksPath' => $specsDir.'/tasks.json',
        'currentTask' => [
            'id' => 3,
            'title' => 'Create Eloquent model',
        ],
    ];
    file_put_contents($specsDir.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 42],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)
        ->toContain('#3: Create Eloquent model')
        ->toContain('[Opus]')
        ->toContain('42%');
});

it('truncates long task titles', function () {
    $specsDir = $this->testPath.'/.laracode/specs/my-feature';
    mkdir($specsDir, 0755, true);

    $lockData = [
        'pid' => 12345,
        'started' => date('c'),
        'tasksPath' => $specsDir.'/tasks.json',
        'currentTask' => [
            'id' => 5,
            'title' => 'This is a very long task title that should be truncated because it exceeds forty characters',
        ],
    ];
    file_put_contents($specsDir.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)
        ->toContain('#5: This is a very long task title that s...')
        ->not->toContain('exceeds forty characters');
});

it('handles missing currentTask in lock file gracefully', function () {
    $specsDir = $this->testPath.'/.laracode/specs/my-feature';
    mkdir($specsDir, 0755, true);

    $lockData = [
        'pid' => 12345,
        'started' => date('c'),
    ];
    file_put_contents($specsDir.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)
        ->toContain('[Opus]')
        ->not->toContain('#');
});

it('handles empty stdin gracefully', function () {
    $output = runStatusline($this->scriptPath, $this->testPath, '');

    expect($output)
        ->toContain('[Unknown]')
        ->toContain('0%');
});

it('handles invalid JSON gracefully', function () {
    $output = runStatusline($this->scriptPath, $this->testPath, 'not valid json');

    expect($output)
        ->toContain('[Unknown]')
        ->toContain('0%');
});

it('handles zero context percentage gracefully', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('0%');
});

it('finds lock file in multiple spec directories', function () {
    // Create multiple spec directories, only one with lock file
    $specsDir1 = $this->testPath.'/.laracode/specs/feature-one';
    $specsDir2 = $this->testPath.'/.laracode/specs/feature-two';
    mkdir($specsDir1, 0755, true);
    mkdir($specsDir2, 0755, true);

    $lockData = [
        'pid' => 99999,
        'started' => date('c'),
        'tasksPath' => $specsDir2.'/tasks.json',
        'currentTask' => [
            'id' => 7,
            'title' => 'Active task in feature-two',
        ],
    ];
    file_put_contents($specsDir2.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('#7: Active task in feature-two');
});

it('uses cyan color for task info', function () {
    $specsDir = $this->testPath.'/.laracode/specs/my-feature';
    mkdir($specsDir, 0755, true);

    $lockData = [
        'pid' => 12345,
        'started' => date('c'),
        'currentTask' => [
            'id' => 1,
            'title' => 'Test task',
        ],
    ];
    file_put_contents($specsDir.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    // Task info should be cyan (ANSI code 36)
    expect($output)->toContain("\033[36m#1: Test task");
});

it('uses yellow color for model name', function () {
    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    // Model should be yellow (ANSI code 33)
    expect($output)->toContain("\033[33m[Opus]");
});

it('handles task with missing title gracefully', function () {
    $specsDir = $this->testPath.'/.laracode/specs/my-feature';
    mkdir($specsDir, 0755, true);

    $lockData = [
        'pid' => 12345,
        'started' => date('c'),
        'currentTask' => [
            'id' => 2,
        ],
    ];
    file_put_contents($specsDir.'/index.lock', json_encode($lockData));

    $input = json_encode([
        'model' => ['display_name' => 'Opus'],
        'context_window' => ['used_percentage' => 0],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)->toContain('#2: Untitled');
});

it('handles full Claude Code status format', function () {
    $input = json_encode([
        'hook_event_name' => 'Status',
        'session_id' => 'abc123',
        'model' => [
            'id' => 'claude-opus-4-1',
            'display_name' => 'Opus',
        ],
        'context_window' => [
            'total_input_tokens' => 15234,
            'total_output_tokens' => 4521,
            'context_window_size' => 200000,
            'used_percentage' => 42.5,
            'remaining_percentage' => 57.5,
        ],
        'cost' => [
            'total_cost_usd' => 0.01234,
        ],
    ]);

    $output = runStatusline($this->scriptPath, $this->testPath, $input);

    expect($output)
        ->toContain('[Opus]')
        ->toContain('43%'); // 42.5 rounded
});

it('does not freeze with no stdin', function () {
    // This test verifies the script doesn't block when run without piped input
    $startTime = microtime(true);
    $output = runStatusline($this->scriptPath, $this->testPath, '');
    $elapsed = microtime(true) - $startTime;

    // Should complete in under 1 second (non-blocking)
    expect($elapsed)->toBeLessThan(1.0)
        ->and($output)->toContain('[Unknown]');
});

function runStatusline(string $scriptPath, string $cwd, string $input): string
{
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        ['php', $scriptPath],
        $descriptorspec,
        $pipes,
        $cwd
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start process');
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output ?: '';
}
