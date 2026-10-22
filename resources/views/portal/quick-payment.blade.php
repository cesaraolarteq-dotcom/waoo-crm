@php($q=$vm['quick_payment'])
@php($es=$vm['lang']==='es')
<section class="p-card" id="quick-payment">
<h2>{{ $es?'Cuánto pagar ahora':'Amount due now' }}: ${{ number_format($q['deposit_cents']/100,2) }}</h2>
<p>{{ $es?'Total de la fiesta':'Party total' }}: <strong>${{ number_format($q['total_cents']/100,2) }}</strong></p>
@if(!empty($q['active_checkout']) && request()->has('quick_booking_return') && !request()->has('cancelled'))
<p role="status" id="quick-confirming">{{ \App\Support\ApprovedClientTexts::text('portal_stripe_confirming', $vm['lang']) }}</p>
@elseif(!empty($q['active_checkout']))
<p role="status">{{ $es?'Tu intento de Stripe sigue pendiente. Puedes retomarlo o cerrarlo antes de cambiar a Zelle.':'Your Stripe attempt is pending. Resume it or close it before switching to Zelle.' }}</p>
@if(!empty($q['active_checkout']['checkout_url']))
<a class="p-btn" href="{{ $q['active_checkout']['checkout_url'] }}">{{ $es?'Retomar Stripe':'Resume Stripe' }}</a>
<form id="quick-close" method="POST" action="{{ url('/portal/api/quick-payment/checkout/close') }}">@csrf<button class="p-btn p-btn-quiet" type="submit">{{ $es?'Cerrar Stripe y elegir otro medio':'Close Stripe and choose another method' }}</button></form>
@elseif(!empty($q['active_checkout']['replay']))
<form id="quick-resume" method="POST" action="{{ url('/portal/api/quick-payment/checkout') }}">@csrf<button class="p-btn" type="submit">{{ $es?'Retomar el mismo intento':'Resume the same attempt' }}</button></form>
@endif
@elseif($q['can_pay'])
<h3>Zelle</h3><p>{{ $es?'Envía a':'Send to' }}: <b>Waoo Party LLC · 346-216-8739</b></p>
<p>{{ \App\Support\ApprovedClientTexts::text('portal_zelle_memo', $vm['lang']) }}: <b><code>{{ $vm['zelle_memo'] }}</code></b> <small>({{ \App\Support\ApprovedClientTexts::text('portal_zelle_memo_help', $vm['lang']) }})</small></p>
<p>{{ $es?'Sin cargo adicional. El dueño verifica el dinero recibido en el banco.':'No additional fee. The owner verifies the money received in the bank.' }}</p>
<form id="quick-zelle" class="p-form" method="POST" action="{{ url('/portal/api/quick-payment/zelle') }}">@csrf
<label class="p-field">{{ $es?'Monto enviado (USD)':'Amount sent (USD)' }}<input name="amount" inputmode="decimal" required value="{{ number_format($q['deposit_cents']/100,2,'.','') }}"></label>
<label class="p-field">{{ $es?'Referencia (opcional)':'Reference (optional)' }}<input name="reference" maxlength="255"></label>
<button class="p-btn" type="submit">{{ $es?'Pagué por Zelle':'I paid by Zelle' }}</button>
</form>
<h3>Stripe · {{ $es?'Tarjeta / Klarna':'Card / Klarna' }}</h3>
<form id="quick-stripe" class="p-form" method="POST" action="{{ url('/portal/api/quick-payment/checkout') }}">@csrf
@foreach($q['choices'] as $choice)
<label class="p-check"><input type="radio" name="percent" value="{{ $choice['deposit_percent'] }}" @checked($choice['deposit_percent']===50)>
{{ $choice['deposit_percent'] }} % · ${{ number_format($choice['principal_cents']/100,2) }} + ${{ number_format($choice['customer_fee_cents']/100,2) }} (3 %) = <b>${{ number_format($choice['payable_cents']/100,2) }}</b></label>
@endforeach
<button class="p-btn" type="submit">{{ $es?'Continuar a Stripe':'Continue to Stripe' }}</button></form>
@else
<p role="status">{{ $es?'Esta reserva necesita revisión del dueño antes de continuar. No hagas otro pago.':'This booking needs owner review before continuing. Please do not make another payment.' }}</p>
@endif
<p id="quick-payment-error" class="p-test" role="alert" hidden></p>
<a class="p-btn p-btn-quiet" href="{{ $vm['lang_url'][$vm['lang']] }}">{{ $es?'Actualizar estado':'Refresh status' }}</a>
</section>
@if(!($vm['preview']??false))
<script>
(function(){
 var returned=new URL(location.href).searchParams.has('quick_booking_return');
 var hasActiveCheckout=@js(!empty($q['active_checkout']));
 if(returned || hasActiveCheckout){
  var polls=0,stopped=false;
  var pendingText=@js($es?'Tu pago sigue en confirmación. Toca «Actualizar estado» en un minuto; no pagues de nuevo.':'Your payment is still being confirmed. Tap “Refresh status” in a minute; do not pay again.');
  function pendingNotice(){var c=document.getElementById('quick-confirming')||document.getElementById('quick-payment-error');if(c){c.textContent=pendingText;c.hidden=false;}}
  async function poll(){
   if(stopped)return;
   if(++polls>60){pendingNotice();return;}
   var controller=new AbortController(),timeout=setTimeout(function(){controller.abort();},8000);
   try{
    var r=await fetch('/portal/api/quick-payment',{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
    if(r.ok){
     var next=await r.json();
     if(next.can_sign===true){stopped=true;var u=new URL(location.href);u.searchParams.delete('quick_booking_return');u.searchParams.delete('cancelled');location.assign(u.href);return;}
     // Refresh an ended attempt so the server can show its current state; never infer payment from a return URL.
     if(next.can_sign===false && !next.active_checkout){stopped=true;var u=new URL(location.href);u.searchParams.delete('quick_booking_return');u.searchParams.delete('cancelled');location.assign(u.href);return;}
    }
   }catch(e){/* A temporary network failure must not permanently stop confirmation checks. */}
   finally{clearTimeout(timeout);}
   if(!stopped)setTimeout(poll,3000);
  }
  setTimeout(poll,3000);
 }

 var pin=@js(collect($q)->only(['expected_booking_version','expected_payment_sha256'])->all());
 ['quick-zelle','quick-stripe','quick-close','quick-resume'].forEach(function(id){var f=document.getElementById(id);if(!f)return;f.addEventListener('submit',async function(ev){
 ev.preventDefault();var err=document.getElementById('quick-payment-error'),b=f.querySelector('button');err.hidden=true;
 var body=Object.assign({},pin),fd=new FormData(f);
 if(id==='quick-zelle'){var raw=String(fd.get('amount')).trim();if(!/^\d{1,7}(\.\d{1,2})?$/.test(raw)||Number(raw)<=0){err.textContent=@js($es?'Revisa el monto.':'Check the amount.');err.hidden=false;return;}body.amount_cents=Math.round(Number(raw)*100);body.reference=String(fd.get('reference')||'').trim()||null;}
 else if(id==='quick-close') body.request_id=@js($q['active_checkout']['request_id']??null);
 else if(id==='quick-resume') body=@js($q['active_checkout']['replay']['payload']??null);
 else body.deposit_percent=Number(fd.get('percent'));
 var store='waoo-quick-payment-'+id+'-'+pin.expected_payment_sha256+'-'+pin.expected_booking_version+'-'+@js($q['attempt_generation']??0),serialized=JSON.stringify(body),attempt;
 try{attempt=JSON.parse(sessionStorage.getItem(store)||'null');if(attempt&&attempt.payload!==serialized)throw new Error(@js($es?'Hay un intento pendiente con otros datos. Actualiza el estado antes de cambiarlo.':'A pending attempt has different details. Refresh status before changing it.'));if(!attempt){attempt={key:id==='quick-resume'?@js($q['active_checkout']['replay']['idempotency_key']??null):crypto.randomUUID(),payload:serialized};sessionStorage.setItem(store,JSON.stringify(attempt));}}
 catch(e){err.textContent=e.message;err.hidden=false;return;}
 b.disabled=true;
 try{var r=await fetch(f.action,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Idempotency-Key':attempt.key},body:attempt.payload}),j=await r.json();if(!r.ok)throw new Error(j.message||'Error');
 if(j.checkout_url){var u=new URL(j.checkout_url);if(u.protocol!=='https:'||u.hostname!=='checkout.stripe.com')throw new Error('Invalid Stripe URL');location.assign(u.href);}else{sessionStorage.removeItem(store);location.reload();}}
 catch(e){err.textContent=e.message;err.hidden=false;b.disabled=false;}
 });});
})();
</script>
@endif
