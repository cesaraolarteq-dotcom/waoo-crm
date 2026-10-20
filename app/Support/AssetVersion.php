<?php

namespace App\Support;

/**
 * Static CSS/JS URLs with the file's modification time as ?v=, so browsers (and Hostinger's 7-day cache) fetch the new
 * file right after a deploy instead of showing an old stylesheet (e.g. the login eye rendering as a grey box).
 */
final class AssetVersion
{
    public static function url(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
