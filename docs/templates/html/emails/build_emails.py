# Builds email-safe HTML for every client message (EN/ES) from one layout. Output: docs/templates/html/emails/
import html, os
OUT=os.path.dirname(os.path.abspath(__file__))
LAYOUT='''<!doctype html>
<!-- Waoo CRM email "{id}" ({lang}). Email-safe: tables + inline styles, max 600 px. Variables {{{{ }}}} are filled by MAIL-01. Generated from docs/templates/client-messages.md. -->
<html lang="{lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{subject}</title></head>
<body style="margin:0;padding:0;background:#F5F6FB;">
<div style="display:none;max-height:0;overflow:hidden;">{preheader}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F6FB;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:16px;overflow:hidden;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#141A33;">
<tr><td style="background:{band};padding:22px 28px;color:#FFFFFF;"><div style="font-size:20px;font-weight:800;letter-spacing:-0.5px;">Waoo Party</div><div style="font-size:13px;opacity:0.85;margin-top:2px;">Katy · Houston, TX</div></td></tr>
<tr><td style="padding:28px 28px 6px;"><div style="font-size:23px;font-weight:800;line-height:1.25;">{title}</div></td></tr>
<tr><td style="padding:8px 28px 4px;font-size:15px;line-height:1.65;color:#3A4166;">{body}</td></tr>
{button}
<tr><td style="padding:16px 28px 26px;font-size:15px;line-height:1.6;">{closing}<br><span style="color:#5B6385;">— Waoo Party</span></td></tr>
<tr><td style="background:#F5F6FB;padding:16px 28px;font-size:12px;line-height:1.6;color:#8A91B0;">{footer}<br>Waoo Party LLC · sales@waooparty.com · waooparty.com</td></tr>
</table></td></tr></table></body></html>
'''
BTN='<tr><td align="center" style="padding:16px 28px 4px;"><a href="{href}" style="display:inline-block;background:#FF017C;color:#FFFFFF;text-decoration:none;font-weight:800;font-size:16px;padding:14px 26px;border-radius:12px;">{label}</a></td></tr>'
BOX='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F9;border-radius:12px;margin:12px 0;"><tr><td style="padding:14px 16px;font-size:15px;line-height:1.7;color:#141A33;">{inner}</td></tr></table>'
FOOT={'es':'¿Preguntas? Responde a este correo o escríbenos por WhatsApp.','en':'Questions? Reply to this email or message us on WhatsApp.'}
CLOSE={'es':'¡Estamos emocionados de ser parte de tu celebración! 💛','en':"We're excited to be part of your celebration! 💛"}
ZELLE={'es':'Zelle a <b>Waoo Party LLC</b> · 346-216-8739 · nota <b>{{reference}}</b>','en':'Zelle to <b>Waoo Party LLC</b> · 346-216-8739 · memo <b>{{reference}}</b>'}
M=[
 ('quote','#122F94',
  {'es':('Tu cotización de Waoo Party para el {{event_date_short}} 🎉','Tu cotización está lista, {{first_name}}','¡Gracias por pensar en Waoo Party para la celebración de {{child_name}}! Aquí está tu cotización:'+BOX.format(inner='<b>{{package_name}}</b> · {{duration}} — {{package_price}}<br>Costo de transporte ({{zip}}) — {{travel_fee}}<br><b>Total: {{total}}</b><br>Depósito para reservar (50 %): <b>{{deposit}}</b>')+'Tu fecha queda asegurada cuando recibimos tu depósito.','Revisar, firmar y pagar'),
   'en':("Your Waoo Party quote for {{event_date_short}} 🎉","Your quote is ready, {{first_name}}","Thank you for thinking of Waoo Party for {{child_name}}'s celebration! Here's your quote:"+BOX.format(inner='<b>{{package_name}}</b> · {{duration}} — {{package_price}}<br>Travel fee ({{zip}}) — {{travel_fee}}<br><b>Total: {{total}}</b><br>Deposit to book (50%): <b>{{deposit}}</b>')+'Your date is secured once we receive your deposit.','Review, sign and pay')}),
 ('details-request','#122F94',
  {'es':('Gracias por tu depósito: completa tus datos 📝','¡Gracias por tu depósito, {{first_name}}!','Recibimos {{amount}} y tu <b>{{package_name}}</b> para el {{event_date_long}} quedó reservado.'+BOX.format(inner='Para terminar, llena tus datos (dirección, hora de inicio) y firma el contrato. Toma unos 2 minutos.')+'Saldo: <b>{{balance}}</b>, a más tardar el {{balance_due}}.','Completar mis datos'),
   'en':('Thank you for your deposit: complete your details 📝','Thank you for your deposit, {{first_name}}!','We received {{amount}} and your <b>{{package_name}}</b> for {{event_date_long}} is booked.'+BOX.format(inner='To finish, fill in your details (address, start time) and sign the contract. It takes about 2 minutes.')+'Balance: <b>{{balance}}</b>, due by {{balance_due}}.','Complete my details')}),
 ('zelle-pending','#15A99F',
  {'es':('Recibimos tu aviso de Zelle','¡Gracias, {{first_name}}!','Recibimos tu aviso de pago por Zelle de <b>{{amount}}</b>. Lo verificamos y te confirmamos en cuanto lo veamos reflejado (normalmente el mismo día).',None),
   'en':('We got your Zelle notice','Thank you, {{first_name}}!',"We got your Zelle notice for <b>{{amount}}</b>. We'll confirm as soon as we see it in our account (usually the same day).",None)}),
 ('booked','#122F94',
  {'es':('¡Listo! Tu fecha con Waoo Party está reservada ✅','¡Listo, {{first_name}}! Tu fecha está reservada ✅','Pago recibido: <b>{{amount}}</b> ({{method}}).'+BOX.format(inner='<b>{{package_name}}</b> · {{duration}}<br>📅 {{event_date_long}} · {{start_time}}<br>📍 {{event_address}}')+'Saldo pendiente: <b>{{balance}}</b>, a más tardar el {{balance_due}}. Puedes pagarlo por '+ZELLE['es']+' o con tarjeta desde tu enlace.','Ver mi contrato y recibo'),
   'en':("You're booked with Waoo Party ✅","You're booked, {{first_name}}! ✅",'Payment received: <b>{{amount}}</b> ({{method}}).'+BOX.format(inner='<b>{{package_name}}</b> · {{duration}}<br>📅 {{event_date_long}} · {{start_time}}<br>📍 {{event_address}}')+'Remaining balance: <b>{{balance}}</b>, due by {{balance_due}}. Pay by '+ZELLE['en']+' or by card from your link.','View my contract and receipt')}),
 ('reminder-7d','#8D53D9',
  {'es':('¡Falta una semana para la fiesta de {{child_name}}! 🎈','¡Ya falta una semana! 🎈','Confirma por favor la dirección, la hora y si habrá piñata o pastel, para que todo salga perfecto.'+BOX.format(inner='📅 {{event_date_long}} · {{start_time}}<br>📍 {{event_address}}<br>Saldo pendiente: <b>{{balance}}</b> (vence {{balance_due}})'),'Confirmar detalles'),
   'en':("One week until {{child_name}}'s party! 🎈",'Just one week to go! 🎈','Please confirm the address, the time, and whether there will be a piñata or cake, so everything goes perfectly.'+BOX.format(inner='📅 {{event_date_long}} · {{start_time}}<br>📍 {{event_address}}<br>Remaining balance: <b>{{balance}}</b> (due {{balance_due}})'),'Confirm details')}),
 ('balance-due','#122F94',
  {'es':('Recordatorio: tu saldo vence {{balance_due}}','Un recordatorio amable, {{first_name}}','Tu saldo de <b>{{balance}}</b> vence {{balance_due}}.'+BOX.format(inner=ZELLE['es']+'<br>o con tarjeta desde tu enlace.'),'Pagar mi saldo'),
   'en':('Reminder: your balance is due {{balance_due}}','A friendly reminder, {{first_name}}','Your balance of <b>{{balance}}</b> is due {{balance_due}}.'+BOX.format(inner=ZELLE['en']+'<br>or by card from your link.'),'Pay my balance')}),
 ('reschedule','#122F94',
  {'es':('Confirma el cambio de tu evento','Preparamos el cambio de tu evento','Tu nueva fecha sería el <b>{{new_date}}</b> a las <b>{{new_time}}</b>. {{fee_line}}'+BOX.format(inner='Acéptalo desde tu enlace para confirmarlo. Hasta entonces, tu fecha actual no cambia.'),'Revisar y aceptar'),
   'en':('Confirm the change to your event',"We've prepared your event change",'Your new date would be <b>{{new_date}}</b> at <b>{{new_time}}</b>. {{fee_line}}'+BOX.format(inner='Accept it from your link to confirm. Until then, your current date stays the same.'),'Review and accept')}),
 ('cancelled','#5B6385',
  {'es':('Confirmamos la cancelación de tu evento','Tu evento fue cancelado','Confirmamos la cancelación de tu evento del {{event_date_long}}. {{policy_line}}<br><br>Si en el futuro quieres celebrar con nosotros, aquí estaremos.',None),
   'en':('Your event cancellation is confirmed','Your event has been cancelled','We confirm the cancellation of your event on {{event_date_long}}. {{policy_line}}<br><br>Whenever you would like to celebrate with us in the future, we will be here.',None)}),
 ('thank-you','#FF017C',
  {'es':('¡Gracias por celebrar con Waoo Party! 💛','¡Gracias, {{first_name}}! 💛','Esperamos que {{child_name}} la haya pasado increíble. Si te gustó, una reseña en Google nos ayudaría muchísimo a seguir creciendo.','Dejar una reseña'),
   'en':('Thank you for celebrating with Waoo Party! 💛','Thank you, {{first_name}}! 💛','We hope {{child_name}} had an amazing time. If you enjoyed it, a Google review would mean the world to us.','Leave a review')}),
]
HREF={'thank-you':'https://g.page/r/CVT57MD29OwXEAE/review'}
n=0
for mid,band,langs in M:
    for lang,(subject,title,body,btn) in langs.items():
        button=BTN.format(href=HREF.get(mid,'{{portal_link}}'),label=btn) if btn else ''
        closing=CLOSE[lang] if mid not in ('cancelled',) else ('Gracias por tu comprensión.' if lang=='es' else 'Thank you for your understanding.')
        pre=html.unescape(subject)
        out=LAYOUT.format(id=mid,lang=lang,subject=html.escape(subject),preheader=html.escape(pre),band=band,title=title,body=body,button=button,closing=closing,footer=FOOT[lang])
        open(f'{OUT}/{mid}-{lang}.html','w').write(out); n+=1
print(n,'files')
