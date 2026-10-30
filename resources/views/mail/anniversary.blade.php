{{-- ANNIV-01 "One year later" (César 2026-10-29, design option B, English only). Email-safe: tables, inline styles, hosted JPGs
     from waooparty.com, web fonts only as a progressive enhancement. All values escaped. $kind = main | reminder. --}}
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark light"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background:#07091F;font-family:'Bricolage Grotesque',Arial,Helvetica,sans-serif;color:#F4F1FF">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#07091F"><tr><td align="center" style="padding:0 8px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">
  <tr><td align="center" style="padding:30px 20px 0"><img src="{{ $site }}/waoo-logo.png" width="120" alt="Waoo Party" style="display:block;border:0;width:120px;height:auto"></td></tr>
  <tr><td align="center" style="padding:22px 20px 0;font-size:12px;font-weight:700;letter-spacing:4px;text-transform:uppercase;color:#F9C930">{{ $kind === 'reminder' ? 'Last day tomorrow' : 'One year later' }}</td></tr>
  @if ($kind === 'main')
  <tr><td align="center" style="padding:4px 20px 0">
    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
      <td style="font-family:'Bodoni Moda',Didot,Georgia,serif;font-style:italic;font-weight:900;font-size:150px;line-height:1;color:#F9C930;padding-right:12px">1</td>
      <td style="font-size:34px;line-height:1.05;font-weight:800;color:#F4F1FF">year of<br><span style="color:#FF017C">magic</span></td>
    </tr></table>
  </td></tr>
  @endif
  <tr><td align="center" style="padding:22px 24px 0;font-size:32px;line-height:1.15;font-weight:800;color:#FFFFFF">
    @if ($kind === 'main')We’d love to <span style="font-family:'Bodoni Moda',Georgia,serif;font-style:italic;font-weight:700;color:#15D1C4">celebrate</span> together again!
    @else Your code expires <span style="font-family:'Bodoni Moda',Georgia,serif;font-style:italic;font-weight:700;color:#15D1C4">tomorrow</span>
    @endif
  </td></tr>
  @if ($kind === 'main')
  <tr><td align="center" style="padding:28px 10px 0">
    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
      @foreach ($parade as $i => $p)
        <td align="center" style="padding:0 3px;vertical-align:{{ $i === 2 ? 'middle' : 'bottom' }}"><img src="{{ $p['src'] }}" alt="{{ $p['alt'] }}" width="{{ $i === 2 ? 118 : 88 }}" height="{{ $i === 2 ? 118 : 88 }}" style="display:block;border:4px solid {{ $p['ring'] }};border-radius:50%;object-fit:cover;width:{{ $i === 2 ? 118 : 88 }}px;height:{{ $i === 2 ? 118 : 88 }}px"></td>
      @endforeach
    </tr></table>
  </td></tr>
  <tr><td align="center" style="padding:18px 20px 0;font-family:'Bodoni Moda',Georgia,serif;font-style:italic;font-size:19px;color:#FFF6E6">Your favorite characters are ready for round two</td></tr>
  @endif
  <tr><td style="padding:28px 32px 0;font-size:17px;line-height:1.6;color:#E4E1F7">
    <p style="margin:0 0 14px">Hi <strong style="color:#FFFFFF">{{ $firstName }}</strong>,</p>
    @if ($kind === 'main')
      <p style="margin:0 0 14px">Almost a year ago we were part of your celebration, and it was a joy to share it with you.</p>
      <p style="margin:0">That’s why we have this gift <span style="font-family:Georgia,serif;font-style:italic;color:#F9C930">for you</span>:</p>
    @else
      <p style="margin:0">Just a friendly reminder: your 10% gift for your next celebration ends tomorrow.</p>
    @endif
  </td></tr>
  <tr><td style="padding:24px 20px 0">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF6E6;border-radius:20px;overflow:hidden">
      <tr><td style="background:#FF017C;padding:22px 24px">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
          <td style="font-size:72px;line-height:.9;font-weight:800;color:#FFFFFF;padding-right:16px">10%</td>
          <td style="font-size:16px;line-height:1.3;font-weight:700;color:#FFFFFF">off the packages and add-ons of your next party</td>
        </tr></table>
      </td></tr>
      <tr><td style="border-top:3px dashed #D9D3EA;font-size:0;line-height:0">&nbsp;</td></tr>
      <tr><td align="center" style="padding:20px 20px 22px;color:#07091F">
        <div style="font-size:11px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:#6B6890">Your exclusive code</div>
        <div style="margin:10px auto 0;display:inline-block;padding:12px 22px;border:2px dashed #122F94;border-radius:12px;font-family:'Courier New',Courier,monospace;font-size:30px;font-weight:700;letter-spacing:2px;color:#122F94">{{ $code }}</div>
        <div style="margin-top:14px;font-size:14px;color:#3B3960">Valid until <strong>{{ $expires }}</strong> (Houston time)</div>
      </td></tr>
    </table>
  </td></tr>
  <tr><td align="center" style="padding:14px 20px 0"><span style="display:inline-block;padding:8px 16px;border:1px solid #6E5A1E;border-radius:999px;background:#151530;font-size:13px;font-weight:700;color:#FFF6E6">Regular prices only · can’t be combined with any other promotion</span></td></tr>
  <tr><td align="center" style="padding:26px 20px 0"><a href="{{ $bookUrl }}" style="display:inline-block;padding:17px 40px;border-radius:999px;background:#F9C930;color:#07091F;font-size:18px;font-weight:800;text-decoration:none">Check my date</a></td></tr>
  <tr><td align="center" style="padding:16px 20px 0;font-size:14px;color:#BDBADB">Rather message us? Call or text <a href="tel:+13468518830" style="color:#15D1C4;font-weight:700;text-decoration:none">(346) 851-8830</a></td></tr>
  @if ($kind === 'main')
  <tr><td style="padding:34px 20px 0">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B0E2B;border:1px solid #262A57;border-radius:20px">
      <tr><td style="padding:22px 22px 6px;font-size:19px;font-weight:800;color:#FFFFFF">It’s that easy</td></tr>
      @foreach ([['#FF017C','#FFFFFF','Pick the date and time.','We show the open times right away.'],['#15D1C4','#07091F','Enter your code '.$code,'when you book.'],['#F9C930','#07091F','Reserve with a 50% deposit','before your code expires. The party itself can be later.']] as $n => [$bg, $fg, $b, $t])
        <tr><td style="padding:8px 22px {{ $n === 2 ? 22 : 8 }}px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>
          <td width="34" valign="top"><div style="width:30px;height:30px;line-height:30px;border-radius:50%;background:{{ $bg }};color:{{ $fg }};font-weight:800;text-align:center">{{ $n + 1 }}</div></td>
          <td style="padding-left:12px;font-size:15px;line-height:1.5;color:#E4E1F7"><strong style="color:#FFFFFF">{{ $b }}</strong> {{ $t }}</td>
        </tr></table></td></tr>
      @endforeach
    </table>
  </td></tr>
  <tr><td style="padding:22px 32px 0;font-size:15px;line-height:1.55;color:#BDBADB"><strong style="color:#FFF6E6">P.S.</strong> Saturdays fill up first. If you already have a date in mind, reserve it early.</td></tr>
  @endif
  <tr><td align="center" style="padding:30px 20px 0;font-family:Georgia,serif;font-style:italic;font-size:24px;color:#FFF6E6">See you at the next party!</td></tr>
  <tr><td align="center" style="padding:8px 20px 0;font-size:14px;color:#BDBADB">With love, the Waoo Party team</td></tr>
  <tr><td style="padding:30px 0 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B0E2B;border-top:1px solid #262A57">
    <tr><td align="center" style="padding:22px 28px 30px;font-size:12px;line-height:1.6;color:#9A97BF">
      <p style="margin:0 0 10px">One code per client, single use. 10% off the regular price of packages and add-ons; the travel fee is charged in full. Valid only on a new booking with the deposit received by {{ $expires }} (Houston time). Cannot be combined with other promotions or discounts.</p>
      <p style="margin:0 0 6px">{{ $address }}</p>
      <p style="margin:0">You’re receiving this because you celebrated a party with us. <a href="{{ $unsubscribeUrl }}" style="color:#BDBADB">Unsubscribe from promotions</a></p>
    </td></tr>
  </table></td></tr>
</table>
</td></tr></table>
</body></html>
