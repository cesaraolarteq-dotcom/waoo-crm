# Waoo Party contract template — v1 (DOC-00 draft)

Status: **draft for owner review**. Source: the owner's current HoneyBook contract (a real signed copy pasted in chat on 2026-10-15). **All client data was removed** and replaced with merge fields. The terms text (sections 1–10 and the Entertainment Flow clause) is copied **verbatim**. Only the changes the owner approved on 2026-10-15 are applied, and each is marked `[CHANGED]`. Do not edit the terms without owner approval and a version bump.

**Owner requirement (2026-10-15): the template must be editable in the future from the CRM.** César or Maybeli can add or change clauses through an admin editor that saves a **new template version** (number, date, author, change note, preview, diff against the previous version). New contracts use the active version. **Contracts already sent or signed never change**: each keeps the exact version and immutable signed PDF it was created with. If a booked event needs the new terms, the change goes through an addendum or a new contract that the client accepts again (v2 §9). A lawyer review is recommended before go-live (PROJECT_PLAN v2 §9).

## Merge fields

| Field | Source in the CRM | Notes |
| --- | --- | --- |
| `{{client.first_name}}`, `{{client.last_name}}` | Customer | The adult contracting party |
| `{{client.address}}` | Customer | Billing/contact address |
| `{{client.email}}`, `{{client.phone}}` | Customer | |
| `{{event.date_long}}` | Event | For example "Sun, Oct 4, 2026", America/Chicago |
| `{{event.start_time}}`, `{{event.end_time}}` | Event | For example "5:30 pm" |
| `{{event.address}}` | EventLocation | **New field**: the event address can differ from the client address. HoneyBook only showed the client address |
| `{{#each services}}` … `{{/each}}` | QuoteRevision lines (accepted) | Name, total, duration, includes list, long description from the catalog version |
| `{{event.characters}}` | Event | Shown **only when the package includes characters**. The send is blocked if it is required and empty |
| `{{quote.travel_fee}}` | QuoteLine (travel) | |
| `{{payments.deposit_received}}`, `{{payments.deposit_date}}` | Ledger | The real amount received (D3) |
| `{{payments.balance_amount}}`, `{{payments.balance_due_date}}` | Ledger + policy | The balance is due 24 h before the event (clause 1.2) |
| `{{payments.balance_label}}` | Computed | "Remaining 50% balance due" only when the balance is exactly 50% of the total; otherwise "Remaining balance due" (v2 §8, owner OK) |
| `{{provider.signed_at}}`, `{{client.signed_at}}`, signer evidence | SignatureEvidence | Per D4 (provider or in-house) |
| `{{template.version}}` | TemplateVersion | Printed in the footer |

---

## Services Agreement — Statement of Work

**Client Information:**
Name: {{client.first_name}} {{client.last_name}}
Address: {{client.address}}
Email: {{client.email}}
Phone: {{client.phone}}

**Event Location:** {{event.address}}  `[CHANGED: new line; HoneyBook only had the client address]`

**Term:**
Event Date: {{event.date_long}}
Service Start Time: {{event.start_time}}
Service End Time: {{event.end_time}}

**Payment:** `[CHANGED: the schedule lists real payments and the real balance]`
{{#each payments.received}}
Received on {{date}}, in the amount of {{amount}} ({{method}})
{{/each}}
{{payments.balance_label}}: {{payments.balance_amount}}, due on {{payments.balance_due_date}}

**Services:**
{{#each services}}
{{name}} for a total of {{total}}
Duration: {{duration}}
Includes:
{{#each includes}}• {{this}}
{{/each}}

{{long_description}}
{{/each}}

{{#if event.requires_characters}}
**Character:** {{event.characters}}
{{/if}}

We are happy to accommodate adjustments to the flow of activities with advance notice to ensure the experience aligns perfectly with your event.

Travel fee for a total of {{quote.travel_fee}}

This statement of work ("Statement of Work") and the Service Terms and Conditions attached hereto (collectively, the "Agreement") is entered into by and between the client specified in the Statement of Work ("Client") and Waoo Party LLC ("Provider"). Any capitalized terms used but not defined in this Statement of Work have the meaning set forth in the Service Terms and Conditions.

Accepted and agreed by their duly authorized representatives of the parties as of the Effective Date.

## ENTERTAINMENT SERVICES AGREEMENT

Between Waoo Party LLC and the Client

This Entertainment Services Agreement ("Agreement") is entered into by and between Waoo Party LLC, hereinafter referred to as "the Provider", and {{client.first_name}} {{client.last_name}} Hereinafter referred to as "the Client", for the purpose of outlining the terms and conditions under which the Provider will deliver entertainment services, including but not limited to character rentals, games, recreational activities, and/or educational activity stations for events.

### Entertainment Flow & Creative Direction Clause
To ensure the highest quality experience, Waoo Party LLC maintains full creative direction and control over the structure, flow, and execution of all activities during the event. Our team is trained to adapt entertainment based on the age group, energy level, and overall engagement of the guests to deliver the best possible interaction and results.

While we are happy to consider special requests in advance, real-time direction or interference with the planned activities may affect the quality of the experience. For this reason, we kindly ask that clients allow our team to lead and manage the entertainment as designed.

If the ability of our team to perform and manage the event is significantly restricted or overridden during the service, Waoo Party LLC reserves the right to modify or discontinue the service. In such cases, no refunds will be issued, as the agreed service and time will have already been allocated and reserved.

This policy ensures that all guests receive the engaging, organized, and high-quality experience that defines our service.

### 1. Reservation and Payments
1.1. To reserve the event date, the Client must make a non-refundable deposit of 50% of the total service amount.
1.2. The remaining balance {{payments.balance_amount}} must be paid no later than 24 hours prior to the event.
1.3. Failure to complete payment or communicate with the Provider may result in cancellation of the service without refund of the deposit.

### 2. Changes, Rescheduling, and Cancellations
2.1. Any modification requested by the Client after booking will be subject to availability and will incur an additional fee of $100.
2.2. Cancellations by the Client will not result in any refund of the deposit under any circumstances.
2.3. In the event of adverse weather conditions, the Provider reserves the sole discretion to determine whether services can be safely performed. If rescheduling is approved, it will be subject to availability and a rescheduling fee of $100 will apply.
2.4. If the event is canceled by the Client within 48 hours prior to the scheduled time, the Provider reserves the right to retain 100% of the total payment.
2.5. Rescheduling is permitted one (1) time only and must occur within 60 days of the original event date. Additional rescheduling requests will be treated as a cancellation and a new booking will be required.
2.6. The initial deposit represents 50% of the total service cost and is strictly non-refundable under all circumstances.

### 3. Arrival, Setup, and Logistics
3.1. The Provider agrees to arrive approximately 5 to 10 minutes prior to the scheduled start time to complete setup, sound checks, and area preparation.
3.2. If access to the event location is delayed, obstructed, or restricted, causing a delay in starting the services, the Client shall be held responsible for such delays.
3.3. The Provider shall not be held liable for delays caused by the Client, lack of access, electrical power, space, or adequate safety conditions.
3.4. The Client is responsible for verifying that the event location permits all planned activities, including but not limited to sound systems, performances, piñatas, or entertainment services. The Provider shall not be held responsible for any restrictions imposed by the venue.

### 4. Service Duration and Additional Charges
4.1. Service time will begin to count at the scheduled start time, even if the event begins later due to reasons not attributable to the Provider.
4.2. If Provider personnel are unable to begin services due to reasons attributable to the Client, a waiting fee of $150 per hour (charged in full-hour increments) will apply.
4.3. Additional hours of service will be charged at $200 per hour, subject to staff availability.
4.4. Downgrades or reductions to the agreed service package are not permitted once the reservation has been confirmed.
4.5. If delays caused by the Client exceed 30 minutes, the Provider reserves the right to reduce service time accordingly or cancel the service without refund.

### 5. Conduct and Safety
5.1. Any physical or verbal aggression toward staff members, or damage to costumes, equipment, or materials, will result in the immediate termination of services without refund.
5.2. The Client agrees to maintain a safe, respectful, and family-friendly environment during the event.
5.3. The Client is fully responsible for supervising children and guests during the event.
5.4. The Provider reserves the right to pause or suspend activities if any condition is deemed unsafe for staff or participants.
5.5. If at any time staff feel unsafe, they reserve the right to immediately leave the premises without refund.

### 6. Liability Waiver
6.1. The Client acknowledges that participation in entertainment activities, games, and educational stations may involve minor physical risks, such as trips or falls.
6.2. By signing this Agreement, the Client releases and holds harmless WAOO Party LLC, its owners, employees, and contractors from any and all liability, claims, damages, injuries, or losses arising from the participation of minors or guests in event activities, unless caused by proven negligence on the part of the Provider.
6.3. The Client assumes full responsibility for any material or personal damages sustained by guests or third parties during the event, including damages resulting from misuse of equipment or games.
6.4. The Provider shall not be responsible for any injuries or damages resulting from misuse, improper handling, or unauthorized use of equipment by guests.

### 7. Limitation of Liability
7.1. The Provider shall not be liable for any indirect, incidental, or consequential damages, including but not limited to loss of income, reputation, or enjoyment of the event.
7.2. In no event shall the Provider's total liability exceed the total amount paid by the Client for the contracted services.
7.3. The Provider shall not be responsible for accidents occurring outside the designated service area, nor for issues related to terrain conditions, venue facilities, or furnishings.
7.4. The Client agrees to be financially responsible for any damage, loss, or theft of the Provider's equipment, costumes, or materials caused by the Client or their guests.

### 8. Force Majeure and Weather Conditions
8.1. In the event of circumstances beyond the Provider's control, including but not limited to severe weather conditions, accidents, medical emergencies, road closures, staff illness, or other force majeure events, the Provider reserves the right to reschedule the event or issue a service credit for future use. No refunds shall be issued under such circumstances.
8.2. Weather conditions shall not automatically qualify as force majeure if the event can reasonably proceed under proper covered or indoor conditions provided by the Client.
8.3. In the event of rain or adverse weather conditions, the Client is solely responsible for providing a safe, dry, and adequately covered environment that fully protects the Provider's staff, costumes, and equipment. This includes, but is not limited to, tents, canopies, umbrellas, or any other appropriate covering to ensure that all personnel, materials, and costumes remain completely dry upon arrival and during the event.
8.4. The Client must ensure that appropriate coverage is available from the moment of arrival, including a clear and protected path for staff, equipment, and performers to enter the event space without exposure to rain or unsafe conditions.
8.5. If adequate protection is not provided, the Provider reserves the right to delay, pause, or refuse to perform services until appropriate conditions are met. Any delays caused by unsafe weather conditions or lack of proper coverage will be subject to a waiting fee of $150 per hour, subject to staff availability.
8.6. The Provider shall not be held responsible for any inability to perform services due to unsafe weather conditions or inadequate protection provided by the Client.
8.7. If the Client chooses not to proceed with the event due to weather conditions within 48 hours of the scheduled service, the following will apply:
• If the final payment has not been completed, the initial 50% deposit remains strictly non-refundable.
• If the event has been paid in full, the Client may receive a 50% service credit toward a future event, valid for 60 days and subject to availability.
8.8. All decisions regarding safety, weather conditions, and the ability to perform services shall be made at the sole discretion of the Provider.

### 9. Governing Law and Jurisdiction
This Agreement shall be governed by and construed in accordance with the laws of the State of Texas.
Any dispute arising under this Agreement shall be submitted to the courts of the county where WAOO Party LLC maintains its principal place of business, and both parties consent to the exclusive jurisdiction of said courts.

### 10. Acceptance and Electronic Signature
By signing this Agreement, the Client acknowledges that they have read, understood, and agreed to all terms and conditions contained herein, and agree to comply with them in full.

The electronic signature or digital acceptance of this Agreement shall carry the same legal validity and enforceability as a handwritten signature, pursuant to the Texas Uniform Electronic Transactions Act (UETA) and the federal Electronic Signatures in Global and National Commerce Act (E-SIGN Act).

---

Provider: Waoo Party LLC · Signed: {{provider.signed_at}} · {{provider.email}}
Client: {{client.first_name}} {{client.last_name}} · Signed: {{client.signed_at}} · {{client.email}}
Template version: {{template.version}}

---

## Review notes for the owner (not part of the contract)

1. **The payment schedule header in HoneyBook was ambiguous** ("100% of the total due on…" for the remaining half). v1 lists the real payments plus "Remaining (50%) balance due". Owner approved.
2. **Clause 1.1 vs D7 (no hold before payment):** the clause says the deposit reserves the date, which matches D7. No text change.
3. **Event address:** added as a separate line. Today only the client's address appears.
4. **Consistency to check with a lawyer (no change made):** 2.6 repeats 1.1/2.2; 4.2 (waiting fee in full hours) and 8.5 (waiting fee "subject to staff availability") are worded differently; "WAOO Party LLC" vs "Waoo Party LLC" capitalization.
5. **Long service descriptions** (like the Magic Package paragraph in the original) now come from the catalog (`docs/catalog/catalog-draft.md`), so each package's text is written once.
6. **Provider email:** the original showed the HoneyBook account email. v1 uses `{{provider.email}}`; sales@waooparty.com per D8 unless the owner prefers another one.
