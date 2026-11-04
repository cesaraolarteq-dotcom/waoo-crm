<?php
// Real Blade and form script, synthetic preview only. No database or provider calls.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['session.driver' => 'array', 'app.url' => 'https://crm.example.test', 'waoo.sandbox_company' => true]);
$lang = ($argv[1] ?? 'es') === 'en' ? 'en' : 'es';
$request = \Illuminate\Http\Request::create('https://crm.example.test/dev/portal/details?lang='.$lang);
$app->instance('request', $request);
$vm = (new \App\Http\Controllers\Portal\PortalPreviewController)($request, 'details')->getOriginalContent()->getData()['vm'];
$vm['preview'] = false;
$vm['actions']['details'] = 'https://crm.example.test/portal/api/details';
$vm['details']['expected_event_version'] = 1; $vm['details']['expected_revision'] = 0;
$vm['event']['celebrant'] = ($argv[2] ?? 'birthday') === 'birthday';
echo view('portal.show', ['vm' => $vm])->render();
