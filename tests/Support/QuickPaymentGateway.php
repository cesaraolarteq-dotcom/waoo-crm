<?php
namespace Tests\Support;
final class QuickPaymentGateway implements \App\Services\Finance\QuickStripeGateway
{
    public array $sessions=[];public int $creates=0;public $onCreate=null;
    public function verifyMethods(array $methods):array{return ['account'=>'acct_waoosandbox','methods'=>$methods];}
    public function createCheckout(array $parameters,string $key):array
    {
        $this->creates++;$sid='cs_test_'.str_replace('-','',$parameters['client_reference_id']);
        $this->sessions[$sid]??=['id'=>$sid,'livemode'=>false,'_verified_account'=>'acct_waoosandbox','client_reference_id'=>$parameters['client_reference_id'],
            'metadata'=>array_reverse($parameters['metadata'],true),'currency'=>'usd','amount_total'=>array_sum(array_map(fn($l)=>$l['price_data']['unit_amount']*$l['quantity'],$parameters['line_items'])),
            'url'=>'https://checkout.stripe.com/c/pay/'.$sid,'status'=>'open','payment_status'=>'unpaid','payment_method_types'=>$parameters['payment_method_types']];
        if($this->onCreate)($this->onCreate)($sid);return $this->sessions[$sid];
    }
    public function retrieveCheckout(string $id):array{return $this->sessions[$id];}
    public bool $failExpire=false;
    public function expireCheckout(string $id):void{if($this->failExpire)throw new \RuntimeException('Stripe timeout');$this->sessions[$id]['status']='expired';}
    public function pay(string $id):void
    {
        $s=&$this->sessions[$id];$pi='pi_'.substr(hash('sha256',$id),0,24);$s['status']='complete';$s['payment_status']='paid';
        $s['payment_intent']=['id'=>$pi,'livemode'=>false,'status'=>'succeeded','currency'=>'usd','amount_received'=>$s['amount_total'],'metadata'=>$s['metadata'],
            'latest_charge'=>['payment_method_details'=>['type'=>'card'],'id'=>'ch_'.substr(hash('sha256',$id),0,20),'livemode'=>false,'captured'=>true,'paid'=>true,'amount'=>$s['amount_total'],'currency'=>'usd','payment_intent'=>$pi,'created'=>now()->timestamp]];
    }
}
