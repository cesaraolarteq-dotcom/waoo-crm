<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Blade only compiles "@if"/"@endif"… when the "@" is not glued to a word character: "importar@if" or "ejemplo@endif"
// render as raw text (or break the template). Both happened in QA; this keeps them out of every view.
class BladeGluedDirectivesTest extends TestCase
{
    public function test_no_blade_directive_is_glued_to_a_preceding_word(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) continue;
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/[\p{L}\p{N}_]@(if|elseif|else|endif|unless|endunless|foreach|endforeach|forelse|empty|endforelse|isset|endisset)\b/u', $line)) {
                    $bad[] = str_replace($root.'/', '', $file->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'Put a space (or another non-word character) before these Blade directives.');
    }
}
