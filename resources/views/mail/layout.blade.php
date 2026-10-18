{{-- Waoo Party transactional email (MAIL-01). Table layout + inline styles for email clients; one button (thank-you may add a second, TIP-01); plain and light.
     All values escaped. Logo from the CRM's own domain (same as the link) to avoid mixed-domain spam signals. --}}
<!doctype html>
<html lang="{{ $lang }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $mail['subject'] }}</title></head>
<body style="margin:0;padding:0;background:#F3F4FA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#141A33">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4FA;padding:24px 12px"><tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden">
    <tr><td style="background:#0E1B52;padding:20px 28px"><img src="{{ $logo }}" width="96" alt="Waoo Party" style="display:block;border:0"></td></tr>
    <tr><td style="padding:28px 28px 8px 28px;font-size:16px;line-height:1.55">
      @foreach ($mail['paragraphs'] as $p)
        @if ($p === '__totals__')
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px 0;border-top:1px solid #DCE1EE">
            @foreach ($totals as $row)
              <tr><td style="padding:8px 0;border-bottom:1px solid #DCE1EE;{{ $row['strong'] ?? false ? 'font-weight:700;font-size:18px' : '' }}">{{ $row['label'] }}</td>
                  <td align="right" style="padding:8px 0;border-bottom:1px solid #DCE1EE;white-space:nowrap;{{ $row['strong'] ?? false ? 'font-weight:700;font-size:18px' : '' }}{{ $row['discount'] ?? false ? 'color:#11734A' : '' }}">{{ $row['amount'] }}</td></tr>
            @endforeach
          </table>
        @else
          <p style="margin:0 0 14px 0">{{ $p }}</p>
        @endif
      @endforeach
    </td></tr>
    @if ($mail['button'] && $mail['button']['url'])
      <tr><td style="padding:4px 28px 24px 28px"><a href="{{ $mail['button']['url'] }}" style="display:inline-block;background:#FF017C;color:#FFFFFF;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:12px">{{ $mail['button']['label'] }}</a>@if (! empty($mail['button2']['url']))&nbsp;
        <a href="{{ $mail['button2']['url'] }}" style="display:inline-block;background:#FFFFFF;color:#1F2A6B;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:12px;border:2px solid #1F2A6B;margin-top:8px">{{ $mail['button2']['label'] }}</a>@endif</td></tr>
    @endif
    <tr><td style="padding:0 28px 26px 28px;font-size:16px;line-height:1.55"><p style="margin:0">@if ($mail['closing']){{ $mail['closing'] }}<br>@endif— Waoo Party</p></td></tr>
    <tr><td style="background:#F3F4FA;padding:16px 28px;font-size:12px;line-height:1.5;color:#59618A">Waoo Party LLC · Katy / Houston, TX · (346) 851-8830<br>{{ $lang === 'es' ? 'Recibes este correo por tu reserva con Waoo Party.' : 'You are receiving this email about your booking with Waoo Party.' }}</td></tr>
  </table>
</td></tr></table>
</body></html>
