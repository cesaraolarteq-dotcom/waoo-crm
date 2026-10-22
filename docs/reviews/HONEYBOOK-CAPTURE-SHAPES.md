# HoneyBook capture structure for the independent verifier (value-free)

Real run on crm-test, 2026-10-22, main b32b03f, snapshot of 2026-10-21 15:47 (267 projects). **Only keys and types below; no values.**

## Result of `waoo:honeybook:verify` (aggregates)

- Coverage: source 267 = history 241 + operating 23 + declared exclusions 3; missing 0; duplicates in destination 0; extras 0. **Agrees with Claude's act.**
- Originals: 515 in CRM, 515 internal hashes valid, **509 source-byte matches**; 5 "missing" belong to the 3 declared exclusions; 6 "different" are invoice pages re-printed later from HoneyBook (each print carries its own print timestamp; same page).
- Fields: matched 297, different 518, unverified 9,780 (48,849 counting source fields). **These are decoder gaps, not data differences:**
  - `different` = 259 `project_status` + 259 `source_snapshot.(project_)status`: source `workspaces[].status` = `client` (HoneyBook "booked") vs CRM `completed`/`confirmed`. Vocabulary, not a mismatch. Owner rule used at import: booked + past date → completed (owner-declared cancellations → cancelled); booked + future → confirmed; `lead`/`lead_sent` → lead/prospect.
  - `unverified` with `source_field_absent` for project_name/event_date/times/totals/paid/contract/sms on ~264 projects, plus `duplicate_source_project` 267 and `ambiguous_source_field` 1,033: the decoder treats the same project id seen in several files as duplicates/ambiguous and drops the facts.

## Requested decoder amendment (Codex)

1. One project id legitimately appears in several files. Use **projects.json** as the authority for project facts: `id`, `name`, `project_date` (YYYY-MM-DD or null), `project_time_start` / `project_time_end` ("3:00 pm" style; may contain U+202F narrow no-break space), `lead_source_label`, `created_at`. Other files reference the project by `project_id`; do not count those as duplicate projects.
2. Booking status: `workspaces.json → workspaces[].status` (`client`, `lead`, `lead_sent`) and `archived`; join by `project_id`. Compare through the vocabulary above.
3. Contact binding: `contacts-pipeline-files.json → contacts[].workspaces.data[].project_id` (and `.id` = workspace id). `contacts[].full_name/email/phone_number/sms_consent_confirmed`.
4. Money: `file-responses.json[smart_file_id].payments` has `total/subtotal/tax/discount/amount_paid/balance_due` as `{amount: integer cents, currency}`; `line_items[]` (`price.amount` cents, `quantity`, `children[]`); `schedule[]`. Paid entries with method/date are in `workspaces.json → workspace_files[workspace_id][].payment_variables[].value` (`amount`, `amount_paid` in dollars, `is_paid`, `charge_date`, `payment_method_name`, `payment_method_type_name`, `payment_status_label`). A smart file belongs to a project via `project_id`; only files with a non-cancelled payment variable count toward the agreement (others were superseded/duplicate files).
5. Signatures: `file-responses.json[...].signatures.signatures[]` (`signee_type` client/vendor, `required`, `signed`, `signed_at`); "client signed" = all required client signatures signed.
6. Questionnaire: `questionnaire.questions[]` (`question` HTML text, `answer`).
7. Response metadata (`stages`, pagination) is not needed for coverage: the capture contains full lists (no cursors) and `projects` length = 267.

## Value-free shapes

```json
{
 "projects.json": {
  "top": "list[267]",
  "item": {
   "id": "str",
   "name": "str",
   "project_date": "NoneType",
   "project_end_date": "NoneType",
   "project_time_start": "NoneType",
   "project_time_end": "NoneType",
   "project_timezone": "str",
   "project_timezone_iana": "str",
   "project_location": "NoneType",
   "project_details": "NoneType",
   "guest_count": "NoneType",
   "budget": "NoneType",
   "lead_source_id": "str",
   "lead_source_label": "NoneType",
   "lead_source_open_text": "NoneType",
   "availability_type": "NoneType",
   "source": "str",
   "client_portal_visible": "bool",
   "project_type_id": "str",
   "creator_id": "str",
   "payroll_employee_ids": [],
   "dates": [],
   "workspaces": "NoneType",
   "custom_fields": {
    "data": [
     {
      "field_id": "str",
      "name": "str",
      "type": "str",
      "value": "NoneType"
     }
    ],
    "total_items": "int"
   },
   "created_at": "str",
   "updated_at": "str"
  }
 },
 "contacts-pipeline-files.json": {
  "contacts": "list[267]",
  "stages": "list[8]",
  "pipeline_active": "list[0]",
  "pipeline_archived": "list[0]",
  "smart_files": "list[277]",
  "contacts[0]": {
   "id": "str",
   "company_id": "str",
   "client_id": "str",
   "vendor_id": "NoneType",
   "user_id": "str",
   "client_organization_id": "NoneType",
   "user": "NoneType",
   "kind": "str",
   "first_name": "str",
   "last_name": "str",
   "full_name": "str",
   "email": "str",
   "phone_number": "str",
   "address": "str",
   "website_url": "str",
   "company_name": "NoneType",
   "company_type": "NoneType",
   "job_title": "str",
   "private_notes": "NoneType",
   "preferred": "bool",
   "manually_added": "bool",
   "unsubscribed": "bool",
   "sandboxed": "bool",
   "tag_ids": [],
   "interaction": {
    "type": "str",
    "context_type": "str",
    "interaction_at": "str",
    "interaction_user_id": "str"
   },
   "action_suggestions": "NoneType",
   "workspaces": {
    "data": [
     {
      "id": "str",
      "project_id": "str",
      "project_name": "str",
      "pipeline_stage": "str",
      "archived_reason": "str",
      "is_archived": "bool",
      "user_type": "str",
      "members": [
       {
        "id": "…",
        "first_name": "…",
        "last_name": "…",
        "full_name": "…",
        "email": "…",
        "phone_number": "…",
        "address": "…",
        "job_title": "…",
        "avatar_url": "…",
        "kind": "…",
        "company": "…"
       }
      ],
      "created_at": "str"
     }
    ],
    "total_items": "int"
   },
   "tags": {
    "data": [],
    "total_items": "int"
   },
   "client_organization": "NoneType",
   "enrichment": "NoneType",
   "sms_consent_confirmed": "bool",
   "user_source": "str",
   "custom_fields": {
    "data": [
     {
      "field_id": "str",
      "name": "str",
      "type": "str",
      "value": "NoneType"
     }
    ],
    "total_items": "int"
   },
   "created_at": "str",
   "updated_at": "str"
  },
  "smart_files[0]": {
   "id": "str",
   "name": "str",
   "capabilities": [
    "str"
   ],
   "status": "str",
   "published": "bool",
   "workspace_id": "str",
   "project_id": "str",
   "sent_at": "str",
   "created_at": "str",
   "updated_at": "str"
  }
 },
 "workspaces.json": {
  "workspaces": "list[267]",
  "workspaces[0]": {
   "id": "str",
   "project_id": "str",
   "name": "NoneType",
   "kind": "str",
   "status": "str",
   "source": "NoneType",
   "archived": "bool",
   "inactive_user_ids": [],
   "creator_id": "str",
   "creator_company_id": "str",
   "member_ids": [
    "str"
   ],
   "tag_ids": [],
   "created_at": "str",
   "updated_at": "str",
   "creator": "NoneType",
   "pipeline_stage": "NoneType",
   "scheduled_sessions": "NoneType"
  },
  "workspace_files": "dict workspace_id -> list",
  "workspace_files[*][0]": {
   "source_attribution": "str",
   "was_already_published": "bool",
   "template": "bool",
   "expired": "bool",
   "expired_at": "NoneType",
   "view_only_file_url": "NoneType",
   "scheduled_to_be_sent": "NoneType",
   "actions": [
    {
     "type": "str",
     "status": "str",
     "page_number": "int",
     "required": "bool",
     "completed_count": "int",
     "total_count": "int"
    }
   ],
   "id": "str",
   "title": "str",
   "status": "str",
   "flow_type": "str",
   "workspace_id": "str",
   "project_id": "str",
   "company_id": "str",
   "published": "bool",
   "revision_id": "str",
   "file_url": "str",
   "created_at": "str",
   "updated_at": "str",
   "payment_variables": [
    {
     "variable_id": "str",
     "type": "str",
     "connected_block_id": "str",
     "value": {
      "payment_id": "str",
      "amount": "int",
      "due_date": "str",
      "charge_date": "str",
      "charge_id": "str",
      "is_tbd": "bool",
      "is_paid": "bool",
      "is_pending": "bool",
      "invoice_id": "str",
      "tip_paid": "int",
      "is_auto_paid": "bool",
      "pending_undo_mark_as_paid": "NoneType",
      "last_refund_timestamp": "NoneType",
      "payment_status_label": "str",
      "last_reminder_timestamp": "NoneType",
      "last_reminder_mail_open_timestamp": "NoneType",
      "auto_payment_reminder_sent_on": "NoneType",
      "charge_statement_descriptor": "NoneType",
      "milestone_completed_on": "NoneType",
      "transferred_to_vendor": "bool",
      "payment_method_id": "str",
      "hb_transaction_fee_amount": "int",
      "client_fee_amount": "int",
      "client_fee_tax_amount_cents": "int",
      "vendor_fee_amount": "int",
      "payout_arrival_date": "NoneType",
      "discount_amount": "int",
      "charge_notes": "NoneType",
      "refund_notes": "NoneType",
      "payment_method_last4": "NoneType",
      "payment_method_name": "str",
      "payment_method_type": "str",
      "payment_method_type_name": "str",
      "payment_payer_email": "str",
      "payment_client_org_id": "NoneType",
      "payment_payer_name": "str",
      "payment_index": "int",
      "currency": "str",
      "is_recurring_payment": "bool",
      "recurring_payment_number": "NoneType",
      "invoice_header_snapshot": "NoneType",
      "late_fee_amount_cents": "int",
      "late_fee_waived_ts": "NoneType",
      "photo_gallery_order_shipping_cents": "int",
      "photo_gallery_order_tax_cents": "int",
      "amount_to_refund": "int",
      "is_refunded": "bool",
      "is_manual": "bool",
      "refunded_amount": "int",
      "estimated_deposit_date": "NoneType",
      "is_stripe_deposit_date": "bool",
      "is_instant_deposit": "bool",
      "allow_instant_payout": "bool",
      "payment_action_status": "NoneType",
      "amount_paid": "int",
      "late_fee_paid": "int",
      "dispute_status": "NoneType",
      "payment_status_tracking": [
       {
        "status": "str",
        "date": "str",
        "status_category": "str"
       }
      ],
      "payment_statuses": [
       {
        "status": "str",
        "date": "str",
        "status_category": "str"
       }
      ],
      "instant_payout_theoretical_fee": "float"
     }
    }
   ],
   "pages_total": "int",
   "pages_viewed": "int",
   "questions_total": "int",
   "questions_answered": "int"
  }
 },
 "file-responses.json": {
  "top": "dict smart_file_id -> response",
  "response": {
   "payments": {
    "status": "str",
    "total": {
     "amount": "int",
     "currency": "str"
    },
    "subtotal": {
     "amount": "int",
     "currency": "str"
    },
    "tax": {
     "amount": "int",
     "currency": "str"
    },
    "discount": {
     "amount": "int",
     "currency": "str"
    },
    "amount_paid": {
     "amount": "int",
     "currency": "str"
    },
    "balance_due": {
     "amount": "int",
     "currency": "str"
    },
    "line_items": [
     {
      "id": "str",
      "title": "str",
      "description": "str",
      "price": {
       "amount": "int",
       "currency": "str"
      },
      "quantity": "int",
      "unit": "str",
      "service_type": "str",
      "children": [
       {
        "id": "str",
        "title": "str",
        "description": "str",
        "price": {
         "amount": "…",
         "currency": "…"
        },
        "quantity": "int",
        "unit": "str",
        "service_type": "str",
        "children": []
       }
      ]
     }
    ],
    "schedule": [
     {
      "amount": {
       "amount": "int",
       "currency": "str"
      },
      "due_date": "str",
      "paid": "bool",
      "paid_at": "NoneType"
     }
    ]
   },
   "signatures": {
    "all_required_signed": "bool",
    "signatures": [
     {
      "signee_name": "str",
      "signee_type": "str",
      "required": "bool",
      "signed": "bool",
      "signed_at": "str"
     }
    ]
   },
   "service_selection": "NoneType",
   "questionnaire": "NoneType"
  }
 }
}
```
