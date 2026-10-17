<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Livewire 4 aliases these names on $wire (e.g. wire:submit="commit" calls $wire.$commit, not our method).
// A page action with one of them silently does nothing in a real browser while Livewire::test()->call() still passes.
class LivewireReservedNamesTest extends TestCase
{
    private const RESERVED = ['on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch', 'dirty', 'effect', 'commit', 'errors', 'island', 'upload',
        'entangle', 'dispatch', 'intercept', 'interceptAction', 'interceptMessage', 'interceptRequest', 'dispatchTo', 'dispatchSelf', 'dispatchEl', 'dispatchRef',
        'removeUpload', 'cancelUpload', 'uploadMultiple'];

    public function test_no_livewire_page_declares_a_public_method_with_a_reserved_wire_name(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php' || ! preg_match('#/(Filament|Livewire)/#', $file->getPathname())) continue;
            preg_match_all('/public function (\w+)\s*\(/', file_get_contents($file->getPathname()), $m);
            foreach (array_intersect($m[1], self::RESERVED) as $name) $offenders[] = str_replace($root.'/', '', $file->getPathname()).'::'.$name;
        }
        $this->assertSame([], $offenders, 'Rename these Livewire actions; the names are reserved on $wire.');
    }
}
