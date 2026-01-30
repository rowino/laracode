<?php

declare(strict_types=1);

use App\Services\CommentExtractor;

beforeEach(function () {
    $this->extractor = new CommentExtractor;
    $this->tempDir = sys_get_temp_dir().'/comment_extractor_test_'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    $files = glob($this->tempDir.'/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

describe('PHP comment extraction', function () {
    it('extracts PHP single-line // comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
// @claude add validation here
$foo = 'bar';
PHP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['file'])->toBe($filePath)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude add validation here');
    });

    it('extracts PHP multi-line /* */ comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
/* @claude implement this function
   with proper error handling */
function test() {}
PHP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['file'])->toBe($filePath)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toContain('@claude implement this function');
    });

    it('extracts PHP hash # comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
# @claude refactor this block
$x = 1;
PHP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude refactor this block');
    });

    it('extracts multiple PHP comments from single file', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
// @claude first task
$a = 1;
// @claude second task
$b = 2;
/* @claude third task */
$c = 3;
PHP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(3)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[1]['line'])->toBe(4)
            ->and($comments[2]['line'])->toBe(6);
    });
});

describe('JavaScript comment extraction', function () {
    it('extracts JS single-line // comments', function () {
        $filePath = $this->tempDir.'/test.js';
        file_put_contents($filePath, <<<'JS'
const foo = 'bar';
// @claude optimize this function
function test() {}
JS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude optimize this function');
    });

    it('extracts JS multi-line /* */ comments', function () {
        $filePath = $this->tempDir.'/test.js';
        file_put_contents($filePath, <<<'JS'
/* @claude add error handling
   for edge cases */
const handler = () => {};
JS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(1)
            ->and($comments[0]['text'])->toContain('@claude add error handling');
    });

    it('extracts comments from TypeScript files', function () {
        $filePath = $this->tempDir.'/test.ts';
        file_put_contents($filePath, <<<'TS'
// @claude add type annotations
interface User {}
TS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add type annotations');
    });

    it('extracts comments from JSX files', function () {
        $filePath = $this->tempDir.'/test.jsx';
        file_put_contents($filePath, <<<'JSX'
// @claude convert to functional component
class MyComponent extends React.Component {}
JSX);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });

    it('extracts comments from TSX files', function () {
        $filePath = $this->tempDir.'/test.tsx';
        file_put_contents($filePath, <<<'TSX'
// @claude add proper typing
const Component: React.FC = () => <div />;
TSX);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('Python comment extraction', function () {
    it('extracts Python # comments', function () {
        $filePath = $this->tempDir.'/test.py';
        file_put_contents($filePath, <<<'PYTHON'
# @claude add docstring
def my_function():
    pass
PYTHON);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(1)
            ->and($comments[0]['text'])->toBe('@claude add docstring');
    });

    it('extracts Python triple-quote docstring comments', function () {
        $filePath = $this->tempDir.'/test.py';
        file_put_contents($filePath, <<<'PYTHON'
""" @claude document this module
    with usage examples """
def my_function():
    pass
PYTHON);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toContain('@claude document this module');
    });

    it('extracts Python single-quote docstring comments', function () {
        $filePath = $this->tempDir.'/test.py';
        file_put_contents($filePath, <<<'PYTHON'
''' @claude add type hints '''
def my_function():
    pass
PYTHON);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('HTML comment extraction', function () {
    it('extracts HTML <!-- --> comments', function () {
        $filePath = $this->tempDir.'/test.html';
        file_put_contents($filePath, <<<'HTML'
<div>
    <!-- @claude add accessibility attributes -->
    <button>Click me</button>
</div>
HTML);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude add accessibility attributes');
    });

    it('extracts multi-line HTML comments', function () {
        $filePath = $this->tempDir.'/test.html';
        file_put_contents($filePath, <<<'HTML'
<div>
    <!-- @claude refactor this section
         to use semantic HTML -->
    <span>Content</span>
</div>
HTML);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toContain('@claude refactor this section');
    });

    it('extracts comments from Vue files', function () {
        $filePath = $this->tempDir.'/test.vue';
        file_put_contents($filePath, <<<'VUE'
<template>
    <!-- @claude add loading state -->
    <div>{{ message }}</div>
</template>
VUE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });

    it('extracts comments from Svelte files', function () {
        $filePath = $this->tempDir.'/test.svelte';
        file_put_contents($filePath, <<<'SVELTE'
<!-- @claude add reactive statement -->
<script>
    let count = 0;
</script>
SVELTE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('Blade comment extraction', function () {
    it('extracts Blade {{-- --}} comments', function () {
        $filePath = $this->tempDir.'/test.blade.php';
        file_put_contents($filePath, <<<'BLADE'
<div>
    {{-- @claude add duration metrics here claude! --}}
    <span>Content</span>
</div>
BLADE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude add duration metrics here claude!');
    });

    it('extracts PHP comments from blade files', function () {
        $filePath = $this->tempDir.'/test.blade.php';
        file_put_contents($filePath, <<<'BLADE'
<?php
// @claude refactor this
$data = [];
?>
<div>{{ $data }}</div>
BLADE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude refactor this');
    });

    it('extracts HTML comments from blade files', function () {
        $filePath = $this->tempDir.'/test.blade.php';
        file_put_contents($filePath, <<<'BLADE'
<!-- @claude add aria labels -->
<button>Click</button>
BLADE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add aria labels');
    });

    it('extracts multiple comment styles from blade files', function () {
        $filePath = $this->tempDir.'/test.blade.php';
        file_put_contents($filePath, <<<'BLADE'
{{-- @claude blade comment --}}
<!-- @claude html comment -->
<?php // @claude php comment ?>
BLADE);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(3);
    });
});

describe('Ruby comment extraction', function () {
    it('extracts Ruby # comments', function () {
        $filePath = $this->tempDir.'/test.rb';
        file_put_contents($filePath, <<<'RUBY'
# @claude add method documentation
def my_method
end
RUBY);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add method documentation');
    });

    it('extracts Ruby =begin =end block comments', function () {
        $filePath = $this->tempDir.'/test.rb';
        file_put_contents($filePath, <<<'RUBY'
=begin @claude refactor this class
to follow SOLID principles =end
class MyClass
end
RUBY);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toContain('@claude refactor this class');
    });
});

describe('CSS comment extraction', function () {
    it('extracts CSS /* */ comments', function () {
        $filePath = $this->tempDir.'/test.css';
        file_put_contents($filePath, <<<'CSS'
/* @claude add dark mode variables */
:root {
    --primary: #333;
}
CSS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add dark mode variables');
    });

    it('extracts SCSS // comments', function () {
        $filePath = $this->tempDir.'/test.scss';
        file_put_contents($filePath, <<<'SCSS'
// @claude create mixin for this
.button {
    padding: 10px;
}
SCSS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('SQL comment extraction', function () {
    it('extracts SQL -- comments', function () {
        $filePath = $this->tempDir.'/test.sql';
        file_put_contents($filePath, <<<'SQL'
-- @claude add index for performance
SELECT * FROM users;
SQL);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add index for performance');
    });

    it('extracts SQL /* */ comments', function () {
        $filePath = $this->tempDir.'/test.sql';
        file_put_contents($filePath, <<<'SQL'
/* @claude optimize this query */
SELECT * FROM users WHERE active = 1;
SQL);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('Shell comment extraction', function () {
    it('extracts Shell # comments', function () {
        $filePath = $this->tempDir.'/test.sh';
        file_put_contents($filePath, <<<'SHELL'
#!/bin/bash
# @claude add error handling
echo "Hello"
SHELL);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude add error handling');
    });

    it('extracts comments from bash files', function () {
        $filePath = $this->tempDir.'/test.bash';
        file_put_contents($filePath, <<<'BASH'
# @claude add logging
command_here
BASH);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('YAML comment extraction', function () {
    it('extracts YAML # comments', function () {
        $filePath = $this->tempDir.'/test.yaml';
        file_put_contents($filePath, <<<'YAML'
# @claude add validation schema
config:
  key: value
YAML);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add validation schema');
    });

    it('extracts comments from yml files', function () {
        $filePath = $this->tempDir.'/test.yml';
        file_put_contents($filePath, <<<'YAML'
# @claude document this config
services:
  app:
    image: php
YAML);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('Go comment extraction', function () {
    it('extracts Go // comments', function () {
        $filePath = $this->tempDir.'/test.go';
        file_put_contents($filePath, <<<'GO'
package main
// @claude add context parameter
func handler() {}
GO);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add context parameter');
    });

    it('extracts Go /* */ comments', function () {
        $filePath = $this->tempDir.'/test.go';
        file_put_contents($filePath, <<<'GO'
package main
/* @claude add godoc comment */
func handler() {}
GO);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('Rust comment extraction', function () {
    it('extracts Rust // comments', function () {
        $filePath = $this->tempDir.'/test.rs';
        file_put_contents($filePath, <<<'RUST'
// @claude add error handling with Result
fn my_function() {}
RUST);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add error handling with Result');
    });
});

describe('Java comment extraction', function () {
    it('extracts Java // comments', function () {
        $filePath = $this->tempDir.'/test.java';
        file_put_contents($filePath, <<<'JAVA'
public class MyClass {
    // @claude add javadoc
    public void method() {}
}
JAVA);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[0]['text'])->toBe('@claude add javadoc');
    });

    it('extracts Java /* */ comments', function () {
        $filePath = $this->tempDir.'/test.java';
        file_put_contents($filePath, <<<'JAVA'
/* @claude implement interface */
public class MyClass {}
JAVA);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('C/C++ comment extraction', function () {
    it('extracts C // comments', function () {
        $filePath = $this->tempDir.'/test.c';
        file_put_contents($filePath, <<<'C'
// @claude add memory cleanup
void my_function() {}
C);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude add memory cleanup');
    });

    it('extracts C /* */ comments', function () {
        $filePath = $this->tempDir.'/test.c';
        file_put_contents($filePath, <<<'C'
/* @claude add null checks */
void my_function() {}
C);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });

    it('extracts C++ comments from .cpp files', function () {
        $filePath = $this->tempDir.'/test.cpp';
        file_put_contents($filePath, <<<'CPP'
// @claude use smart pointers
class MyClass {};
CPP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });

    it('extracts comments from header files', function () {
        $filePath = $this->tempDir.'/test.h';
        file_put_contents($filePath, <<<'H'
// @claude add header guard
void my_function();
H);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('default extension handling', function () {
    it('uses default patterns for unknown extensions', function () {
        $filePath = $this->tempDir.'/test.xyz';
        file_put_contents($filePath, <<<'TXT'
// @claude handle this unknown file
# @claude also handle hash comments
TXT);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(2);
    });
});

describe('stop word detection', function () {
    it('detects stop word in comment', function () {
        expect($this->extractor->hasStopWord('@claude fix this claude!', 'claude!'))->toBeTrue();
    });

    it('detects stop word case-insensitively', function () {
        expect($this->extractor->hasStopWord('@claude fix this CLAUDE!', 'claude!'))->toBeTrue()
            ->and($this->extractor->hasStopWord('@claude fix this Claude!', 'CLAUDE!'))->toBeTrue();
    });

    it('returns false when stop word not present', function () {
        expect($this->extractor->hasStopWord('@claude add validation', 'claude!'))->toBeFalse();
    });

    it('detects custom stop words', function () {
        expect($this->extractor->hasStopWord('@claude DONE!', 'DONE!'))->toBeTrue()
            ->and($this->extractor->hasStopWord('@claude review this ready!', 'ready!'))->toBeTrue();
    });
});

describe('line number accuracy', function () {
    it('returns correct line numbers for single-line comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n\n\n// @claude line 4\n\n// @claude line 6\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(2)
            ->and($comments[0]['line'])->toBe(4)
            ->and($comments[1]['line'])->toBe(6);
    });

    it('returns correct line numbers for multi-line comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n\n/* @claude multi\n   line\n   comment */\n\n// @claude after");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(2)
            ->and($comments[0]['line'])->toBe(3)
            ->and($comments[1]['line'])->toBe(7);
    });

    it('handles Windows-style line endings', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\r\n\r\n// @claude line 3\r\n\r\n// @claude line 5\r\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(2);
    });
});

describe('files without @claude comments', function () {
    it('returns empty array for file without comments', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n// regular comment\n\$foo = 'bar';");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toBeEmpty();
    });

    it('returns empty array for empty file', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, '');

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toBeEmpty();
    });

    it('ignores @claudeother but captures @claude', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n// @claudeother ignored\n// @claude captured\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@claude captured');
    });
});

describe('mixed comment styles', function () {
    it('extracts all comment styles from PHP file', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
// @claude single line
/* @claude multi line */
# @claude hash style
$code = 'here';
PHP);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(3);
    });

    it('handles interleaved comment and code', function () {
        $filePath = $this->tempDir.'/test.js';
        file_put_contents($filePath, <<<'JS'
const a = 1;
// @claude first
const b = 2;
/* @claude second */
const c = 3;
// @claude third
JS);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(3)
            ->and($comments[0]['line'])->toBe(2)
            ->and($comments[1]['line'])->toBe(4)
            ->and($comments[2]['line'])->toBe(6);
    });
});

describe('file size limit', function () {
    it('respects 1MB file size limit', function () {
        $filePath = $this->tempDir.'/large.php';
        $largeContent = "<?php\n// @claude this should be ignored\n".str_repeat('x', 1024 * 1024 + 100);
        file_put_contents($filePath, $largeContent);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toBeEmpty();
    });

    it('processes files under 1MB limit', function () {
        $filePath = $this->tempDir.'/medium.php';
        $content = "<?php\n// @claude this should be captured\n".str_repeat('x', 1024 * 512);
        file_put_contents($filePath, $content);

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1);
    });
});

describe('scanFiles method', function () {
    it('scans multiple files and returns combined results', function () {
        $file1 = $this->tempDir.'/test1.php';
        $file2 = $this->tempDir.'/test2.php';
        file_put_contents($file1, "<?php\n// @claude first file\n");
        file_put_contents($file2, "<?php\n// @claude second file\n");

        $result = $this->extractor->scanFiles([$file1, $file2], 'claude!');

        expect($result['comments'])->toHaveCount(2)
            ->and($result['metadata']['filesScanned'])->toBe(2)
            ->and($result['metadata']['stopWordFound'])->toBeFalse();
    });

    it('detects stop word across files', function () {
        $file1 = $this->tempDir.'/test1.php';
        $file2 = $this->tempDir.'/test2.php';
        file_put_contents($file1, "<?php\n// @claude regular comment\n");
        file_put_contents($file2, "<?php\n// @claude with stop word claude!\n");

        $result = $this->extractor->scanFiles([$file1, $file2], 'claude!');

        expect($result['metadata']['stopWordFound'])->toBeTrue()
            ->and($result['metadata']['stopWordFile'])->toBe($file2);
    });

    it('returns first file containing stop word', function () {
        $file1 = $this->tempDir.'/test1.php';
        $file2 = $this->tempDir.'/test2.php';
        file_put_contents($file1, "<?php\n// @claude has stop word claude!\n");
        file_put_contents($file2, "<?php\n// @claude also has claude!\n");

        $result = $this->extractor->scanFiles([$file1, $file2], 'claude!');

        expect($result['metadata']['stopWordFile'])->toBe($file1);
    });

    it('skips non-existent files', function () {
        $file1 = $this->tempDir.'/exists.php';
        file_put_contents($file1, "<?php\n// @claude valid\n");

        $result = $this->extractor->scanFiles([
            $file1,
            $this->tempDir.'/does_not_exist.php',
        ], 'claude!');

        expect($result['comments'])->toHaveCount(1)
            ->and($result['metadata']['filesScanned'])->toBe(2);
    });

    it('includes timestamp in metadata', function () {
        $file1 = $this->tempDir.'/test.php';
        file_put_contents($file1, "<?php\n// @claude test\n");

        $result = $this->extractor->scanFiles([$file1], 'claude!');

        expect($result['metadata']['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    });

    it('handles empty file array', function () {
        $result = $this->extractor->scanFiles([], 'claude!');

        expect($result['comments'])->toBeEmpty()
            ->and($result['metadata']['filesScanned'])->toBe(0)
            ->and($result['metadata']['stopWordFound'])->toBeFalse()
            ->and($result['metadata']['stopWordFile'])->toBeNull();
    });
});

describe('edge cases', function () {
    it('handles file with only whitespace', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "   \n\n   \t\t\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toBeEmpty();
    });

    it('handles @claude at very beginning of file', function () {
        $filePath = $this->tempDir.'/test.js';
        file_put_contents($filePath, "// @claude first line\nconst x = 1;");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(1);
    });

    it('handles @claude at very end of file', function () {
        $filePath = $this->tempDir.'/test.js';
        file_put_contents($filePath, "const x = 1;\n// @claude last line");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['line'])->toBe(2);
    });

    it('handles non-readable file gracefully', function () {
        $comments = $this->extractor->extractFromFile('/nonexistent/path/file.php');

        expect($comments)->toBeEmpty();
    });

    it('handles directory path gracefully', function () {
        $comments = $this->extractor->extractFromFile($this->tempDir);

        expect($comments)->toBeEmpty();
    });

    it('preserves comment content with special characters', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n// @claude handle \$var & <html> \"quotes\"\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toContain('$var')
            ->and($comments[0]['text'])->toContain('<html>');
    });

    it('handles multiple @claude on same line', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, "<?php\n// @claude first @claude second\n");

        $comments = $this->extractor->extractFromFile($filePath);

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toContain('first @claude second');
    });
});

describe('detectCommentStyle method', function () {
    it('returns correct patterns for PHP', function () {
        $patterns = $this->extractor->detectCommentStyle('php');

        expect($patterns)->toHaveCount(3);
    });

    it('returns correct patterns for JavaScript variants', function () {
        expect($this->extractor->detectCommentStyle('js'))->toHaveCount(2)
            ->and($this->extractor->detectCommentStyle('jsx'))->toHaveCount(2)
            ->and($this->extractor->detectCommentStyle('ts'))->toHaveCount(2)
            ->and($this->extractor->detectCommentStyle('tsx'))->toHaveCount(2);
    });

    it('returns correct patterns for Python', function () {
        $patterns = $this->extractor->detectCommentStyle('py');

        expect($patterns)->toHaveCount(3);
    });

    it('handles case-insensitive extensions', function () {
        expect($this->extractor->detectCommentStyle('PHP'))->toEqual($this->extractor->detectCommentStyle('php'))
            ->and($this->extractor->detectCommentStyle('JS'))->toEqual($this->extractor->detectCommentStyle('js'));
    });

    it('returns default patterns for unknown extensions', function () {
        $patterns = $this->extractor->detectCommentStyle('unknown');

        expect($patterns)->toHaveCount(4);
    });
});

describe('custom searchWord', function () {
    it('extracts comments with custom searchWord', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
// @todo implement this feature
// @claude ignore this one
$foo = 'bar';
PHP);

        $comments = $this->extractor->extractFromFile($filePath, '@todo');

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@todo implement this feature');
    });

    it('scanFiles uses custom searchWord', function () {
        $file1 = $this->tempDir.'/test1.php';
        file_put_contents($file1, "<?php\n// @fix this bug done!\n// @claude ignore\n");

        $result = $this->extractor->scanFiles([$file1], 'done!', '@fix');

        expect($result['comments'])->toHaveCount(1)
            ->and($result['comments'][0]['text'])->toBe('@fix this bug done!')
            ->and($result['metadata']['stopWordFound'])->toBeTrue();
    });

    it('handles searchWord with special regex characters', function () {
        $filePath = $this->tempDir.'/test.php';
        file_put_contents($filePath, <<<'PHP'
<?php
// @todo.high fix this urgently
$foo = 'bar';
PHP);

        $comments = $this->extractor->extractFromFile($filePath, '@todo.high');

        expect($comments)->toHaveCount(1)
            ->and($comments[0]['text'])->toBe('@todo.high fix this urgently');
    });

    it('extracts multiple file types with custom searchWord', function () {
        $phpFile = $this->tempDir.'/test.php';
        $jsFile = $this->tempDir.'/test.js';
        file_put_contents($phpFile, "<?php\n// @review check security\n");
        file_put_contents($jsFile, "// @review optimize performance\n");

        $phpComments = $this->extractor->extractFromFile($phpFile, '@review');
        $jsComments = $this->extractor->extractFromFile($jsFile, '@review');

        expect($phpComments)->toHaveCount(1)
            ->and($jsComments)->toHaveCount(1)
            ->and($phpComments[0]['text'])->toBe('@review check security')
            ->and($jsComments[0]['text'])->toBe('@review optimize performance');
    });
});
