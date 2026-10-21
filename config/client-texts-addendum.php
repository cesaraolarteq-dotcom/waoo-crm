<?php
// Owner-approved addendum to the frozen client-texts-2026-10-20 snapshot (that file stays immutable).
// Source: docs/templates/APPROVED-CLIENT-TEXTS-2026-10-21.json. Text changes require a new owner-approved version.
return json_decode(<<<'APPROVED_TEXTS'
{
 "approved_by": "owner (César) in chat, 2026-10-21: the unpaid quick booking message shows no amount; the link shows Zelle (50%, no fee) and Stripe (with the 3% fee)",
 "date": "2026-10-21",
 "version": "client-texts-2026-10-21",
 "texts": {
  "quick_unpaid_link_msg": {
   "status": "approved",
   "title": "Mensaje con el enlace cuando el depósito todavía no se recibió (Reserva rápida)",
   "when": "Lo copias o lo abres en WhatsApp/SMS al crear una Reserva rápida con «Todavía no»",
   "es": "¡Hola {first_name}! Para confirmar tu {package} del {event_date}, completa tus datos y paga tu depósito aquí: {link}. Puedes pagar por Zelle o con tarjeta. ¡Estamos emocionados de ser parte de tu celebración!",
   "en": "Hi {first_name}! To confirm your {package} on {event_date}, complete your details and pay your deposit here: {link}. You can pay by Zelle or card. We're excited to be part of your celebration!",
   "order": 1
  }
 }
}
APPROVED_TEXTS, true, 512, JSON_THROW_ON_ERROR);
