<?php

// Render only synthetic access pages; no database, provider, credentials or customer data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['session.driver' => 'array', 'app.url' => 'https://crm.example.test']);
$lang = ($argv[1] ?? 'es') === 'en' ? 'en' : 'es';
if (($argv[2] ?? '') === 'tip') {
    echo view('portal.tip-access', ['vm' => ['lang' => $lang, 'test_mode' => true,
        'lang_url' => ['es' => '/portal/tip?lang=es', 'en' => '/portal/tip?lang=en']]])->render();
} else {
    echo view('portal.landing', ['lang' => $lang])->render();
}
