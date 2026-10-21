<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$app['env']='testing';
config(['session.driver'=>'array','app.url'=>'https://crm.example.test']);$lang=($argv[1]??'es')==='en'?'en':'es';
$r=\Illuminate\Http\Request::create('https://crm.example.test/dev/portal/details?lang='.$lang);$app->instance('request',$r);
$vm=(new \App\Http\Controllers\Portal\PortalPreviewController)($r,'details')->getOriginalContent()->getData()['vm'];$vm['preview']=false;$vm['step']='quick_pay';$vm['steps']=['details','pay','contract'];
$terms=\App\Services\Booking\QuickStripeTerms::freeze(30001,new DateTimeImmutable('2026-10-21T17:00:00Z'));
$vm['quick_payment']=['expected_booking_version'=>1,'expected_payment_sha256'=>str_repeat('a',64),'state'=>'awaiting_payment','total_cents'=>30001,'deposit_cents'=>15001,'can_pay'=>true,'can_sign'=>false,'zelle_pending'=>false,
 'choices'=>[$terms->snapshot(),\App\Services\Booking\QuickStripeTerms::freeze(30001,new DateTimeImmutable('2026-10-21T17:00:00Z'),100)->snapshot()]];
echo view('portal.show',['vm'=>$vm])->render();
