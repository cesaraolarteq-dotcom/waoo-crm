@extends('portal.layout')
@section('content')
<section class="p-card"><h1>{{ $vm['lang']==='es'?'Propina para el equipo':'Tip for the team' }}</h1><p id="tip-access-message">{{ $vm['lang']==='es'?'Abriendo el enlace…':'Opening your link…' }}</p></section>
<script>
(function(){
  var token=location.hash.slice(1), target=document.getElementById('tip-access-message');
  history.replaceState(null,'',location.pathname+location.search);
  function unavailable(){target.textContent=@js($vm['lang']==='es'?'El enlace no está disponible. Vuelve a abrir el mensaje original o contacta a Waoo Party.':'This link is unavailable. Reopen the original message or contact Waoo Party.');}
  if(!/^[A-Za-z0-9_-]{43}$/.test(token)){unavailable();return;}
  fetch('/portal/tip/session',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({token:token})})
    .then(function(r){token='';if(!r.ok)throw new Error('unavailable');location.replace('/portal/tip?lang='+@js($vm['lang']));}).catch(function(){token='';unavailable();});
})();
</script>
@endsection
