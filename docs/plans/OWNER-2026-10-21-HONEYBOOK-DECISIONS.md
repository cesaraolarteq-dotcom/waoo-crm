# HoneyBook migration — owner decisions (César, 2026-10-21)

Source: read-only HoneyBook API copy taken by Claude on 2026-10-21 (262 projects, 262 contacts, 272 smart files, 537 scheduled payments, questionnaire answers). The copy is private on the owner's Mac, outside git. Aggregates only here.

Already done: the 18 future parties are staged in IMPORT-01 on crm-test (batch 1: 15 ready, 3 whose HoneyBook total includes the 3% card processing fee, needing an owner note). Money reconciles with HoneyBook (paid $6,745.25, balance $6,102.50). Nothing has been committed yet; the owner maps customer/package/characters per row.

Decisions (the owner chose these in the "Mudanza de HoneyBook" page):

| Information | Count | Decision |
| --- | --- | --- |
| Celebrant name and age (questionnaire) | 69 / 68 | Event field, shown on the staff sheet |
| Number of children | 68 | Event field |
| Indoor / outdoor | 71 | Event field, shown on the staff sheet |
| Party language | 70 | Event field (no staff filter needed) |
| Special requests | 40 | Event notes visible to staff |
| Lead source (WhatsApp 97, Instagram 80, Facebook 53 booked) | 242 | Field on customer and event, with a report |
| SMS consent given in HoneyBook | 100 contacts | Honor the HoneyBook consent in the CRM (keep source/provenance) |
| Customer address | 254 | Do not add; use only the party address |
| Private contact notes | 7 | Do not bring |
| HoneyBook "tax" line (owner correction 2026-10-21: it is the **3% card processing fee**, not sales tax) | 33 projects, $620.93 | Do not bring as a separate field; the source totals keep it. Never label it sales tax |
| Payments marked "cash" in HoneyBook | 381 manual vs 92 card | Show as "Zelle/efectivo (HoneyBook)" |
| Past parties with a balance | 6, $1,657.14 | Resolved by the owner per project (private file `owner-balance-resolutions.json`): 3 paid outside HoneyBook (never marked), 1 duplicate smart file (the real package was one file, paid in full in two payments; the extra payment rows are duplicates), 1 cancelled (no second payment), 1 cancelled **pending reschedule: must be imported into the CRM** so it can be rebooked |
| Future parties without client signature | 11 of 18 | They sign in HoneyBook |
| Leads that never booked | 11 | History only |
| Next-year birthday reminder | 68 with date + age | Prepare the campaign; nothing goes live before the owner approves the email |
| Business stats (weekdays, lead time, ZIPs, top packages) | all | CRM reports page and website/ads |
| Emails/messages per project | — | Not needed |
| Contract and invoice PDFs | 265 / 272 | All. HoneyBook's API gives no PDFs; contract text and signature metadata are available. Original PDFs need a manual or browser download. |

Questionnaire answers map onto the fields the portal already collects (`child_name`, `child_age`, `children_count`, `setup_area`, `event_language`, `notes`), marked as HoneyBook source data rather than a client portal submission.
