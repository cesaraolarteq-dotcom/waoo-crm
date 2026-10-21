<?php
// Real Blade/script with synthetic data only. No grant, DB or provider read/write.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env'] = 'testing';
config(['session.driver' => 'array', 'app.url' => 'https://crm.example.test']);
$lang = ($argv[1] ?? 'es') === 'en' ? 'en' : 'es';
$request = \Illuminate\Http\Request::create('https://crm.example.test/dev/portal/details?lang='.$lang);
$app->instance('request', $request);
$response = (new \App\Http\Controllers\Portal\PortalPreviewController)($request, 'details');
$vm = $response->getOriginalContent()->getData()['vm'];
$vm['preview'] = false;
$vm['details']['action'] = 'https://crm.example.test/portal/api/details';
$vm['lang_url'] = ['es' => 'https://crm.example.test/portal?lang=es', 'en' => 'https://crm.example.test/portal?lang=en'];
$vm['details']['expected_event_version'] = 7;
$vm['details']['expected_revision'] = 3;
$vm['details']['fixed_address'] = true;
$vm['details']['fixed_postal_code'] = true;
$vm['prefill']['event_address'] = '123 Example Lane';
$vm['prefill']['event_postal_code'] = '77001';
if (($argv[2] ?? '') === 'retry') {
    $vm['step'] = 'details_sent';
    $vm['details']['prepare_action'] = 'https://crm.example.test/portal/api/details/contract';
}
echo view('portal.show', ['vm' => $vm])->render();
