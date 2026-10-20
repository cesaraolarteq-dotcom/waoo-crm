<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalTokenVault;
use App\Support\{PortalCopy, ShortLink};

// /r/<code>: forwards to the client portal with the token in the fragment (never sent to a server, never in a Location header).
final class ShortLinkController extends Controller
{
    public function __invoke(string $code)
    {
        $grant = ShortLink::grant($code);
        $lang = request()->query('lang') === 'en' ? 'en' : 'es';
        if (! $grant) {
            $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
                .'<title>Waoo Party</title><body style="font-family:system-ui,sans-serif;background:#0b0d24;color:#eef0ff;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px;text-align:center">'
                .'<div><h1 style="font-size:1.4rem">'.e(PortalCopy::get($lang, 'link_expired')).'</h1><p>'.e(PortalCopy::get($lang, 'link_expired_hint')).'</p><p>'.e(PortalCopy::get($lang, 'help')).'</p></div>';

            return response($html, 404)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex');
        }
        $target = url('/portal').'#token='.PortalTokenVault::open($grant);
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">'
            .'<title>Waoo Party</title><script>location.replace('.json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP).');</script>'
            .'<noscript><a href="'.e($target).'">Abrir mi reserva / Open my booking</a></noscript>';

        return response($html)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex');
    }
}
