{{-- REPORT-A internal Monday email (design draft). Table layout + inline styles; big numbers, one button, Spanish. --}}
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $r['subject'] }}</title></head>
<body style="margin:0;padding:0;background:#F3F4FA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#141A33">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4FA;padding:24px 12px"><tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden">
    <tr><td style="background:#0E1B52;padding:20px 28px"><img src="{{ $logo }}" width="96" alt="Waoo Party" style="display:block;border:0"></td></tr>
    <tr><td style="padding:24px 28px 4px 28px"><p style="margin:0;font-size:13px;color:#59618A;text-transform:uppercase;letter-spacing:.06em">Reporte del lunes</p>
      <p style="margin:4px 0 0 0;font-size:20px;font-weight:700">{{ $r['period'] }}</p></td></tr>
    <tr><td style="padding:12px 20px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        @foreach (array_chunk($r['tiles'], 2) as $pair)
          <tr>@foreach ($pair as $t)
            <td width="50%" style="padding:8px"><div style="background:#F3F4FA;border-radius:12px;padding:14px 16px">
              <div style="font-size:13px;color:#59618A">{{ $t['label'] }}</div>
              <div style="font-size:26px;font-weight:800;margin-top:2px">{{ $t['value'] }}</div>
              <div style="font-size:12px;color:#59618A">{{ $t['note'] }}</div></div></td>
          @endforeach</tr>
        @endforeach
      </table>
    </td></tr>
    <tr><td style="padding:4px 28px 8px 28px;font-size:15px;line-height:1.55">
      @if ($r['packages'])<p style="margin:0 0 10px 0"><b>Lo que se vendió:</b> {{ $r['packages'] }}</p>@endif
      <p style="margin:0 0 10px 0">{{ $r['weekend'] }}</p>
      @foreach ($r['notes'] as $n)<p style="margin:0 0 10px 0;color:#8F5B00">• {{ $n }}</p>@endforeach
    </td></tr>
    <tr><td style="padding:4px 28px 24px 28px"><a href="{{ $url }}" style="display:inline-block;background:#FF017C;color:#FFFFFF;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:12px">Ver en el CRM</a></td></tr>
    <tr><td style="background:#F3F4FA;padding:16px 28px;font-size:12px;line-height:1.5;color:#59618A">Reporte interno de Waoo Party para sales@waooparty.com. El dinero cuenta por la fecha en que llegó; saldos y staff por la fecha de cada fiesta. No lo reenvíes a clientes.</td></tr>
  </table>
</td></tr></table>
</body></html>
