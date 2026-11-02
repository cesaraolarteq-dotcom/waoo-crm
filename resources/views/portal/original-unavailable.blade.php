@php($vm=['lang'=>$lang,'test_mode'=>true,'lang_url'=>['es'=>url('/portal?lang=es'),'en'=>url('/portal?lang=en')]])
@extends('portal.layout')
@section('content')
<section class="p-card"><h1 class="p-h1">{{ $lang==='es'?'Acuerdo no disponible':'Agreement unavailable' }}</h1>
<p>{{ $lang==='es'?'Este enlace ya no permite consultar el acuerdo. Contáctanos para recibir un enlace actualizado.':'This link no longer provides access to the agreement. Please contact us for an updated link.' }}</p></section>
@endsection
