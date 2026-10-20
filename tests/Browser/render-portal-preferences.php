<?php
// Synthetic preview VM rendered with the real client form/script. No DB reads, grants or provider calls.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['session.driver'=>'array', 'app.url'=>'https://crm.example.test']);
$request = \Illuminate\Http\Request::create('https://crm.example.test/dev/portal/details?lang='.($argv[1] === 'en' ? 'en' : 'es'));
$app->instance('request', $request);
$response = (new \App\Http\Controllers\Portal\PortalPreviewController)($request, 'details');
$vm = $response->getOriginalContent()->getData()['vm'];
$vm['preview'] = false;
$vm['actions']['details'] = 'https://crm.example.test/portal/api/details';
$vm['details']['expected_event_version'] = 1;
$vm['details']['expected_revision'] = 0;
echo view('portal.show', ['vm'=>$vm])->render();
