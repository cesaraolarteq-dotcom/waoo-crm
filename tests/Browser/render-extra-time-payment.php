<?php
// Real portal Blade and JavaScript with synthetic payment data. No database, money or provider calls.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['env']='testing';
config(['session.driver'=>'array','app.url'=>'https://crm.example.test']);
$lang=($argv[1]??'es')==='en'?'en':'es';$full=($argv[2]??'full')==='full';
$request=\Illuminate\Http\Request::create('https://crm.example.test/dev/portal/pay?lang='.$lang);
$app->instance('request',$request);
$vm=(new \App\Http\Controllers\Portal\PortalPreviewController)($request,'pay')->getOriginalContent()->getData()['vm'];
$vm['preview']=false;$vm['reserved']=false;$vm['first_name']='María';$vm['step']='quick_pay';$vm['steps']=['details','pay','contract'];
$vm['event']=[...$vm['event'],'package'=>$lang==='es'?'Música y Juegos · 2 h':'Music & Games · 2 h',
    'when'=>$lang==='es'?'jue. 5 nov. 2026, 3:00 p. m.':'Thu, Nov 5, 2026 at 3:00 pm',
    'total'=>'$525.00','travel'=>'$0.00','paid'=>'$0.00','balance'=>'$525.00',
    'due'=>$lang==='es'?'mié. 4 nov., 3:00 p. m.':'Wed, Nov 4, 3:00 pm'];
$choices=array_map(fn($p)=>\App\Services\Booking\QuickStripeTerms::freeze(52500,new DateTimeImmutable('2026-11-04T16:00:00Z'),$p)->snapshot(),$full?[100]:[50,100]);
$vm['quick_payment']=['deposit_cents'=>$choices[0]['principal_cents'],'total_cents'=>52500,'choices'=>$choices,'can_pay'=>true,
    'active_checkout'=>null,'expected_booking_version'=>1,'expected_payment_sha256'=>str_repeat('a',64),'attempt_generation'=>0];
echo view('portal.show',['vm'=>$vm])->render();
