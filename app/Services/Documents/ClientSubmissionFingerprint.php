<?php
namespace App\Services\Documents;

use App\Models\ClientDetailsSubmission;
use App\Services\Finance\MutationKey;

/** Versioned legal provenance; adding a model attribute cannot change signed evidence. */
final class ClientSubmissionFingerprint
{
    private const DATA = ['first_name','last_name','phone','email','billing_address','event_address','event_postal_code','locale',
        'start_time_confirmed','requested_start_at','child_name','child_age','theme','notes','event_language','setup_area',
        'children_count','sms_consent','sms_consent_version'];

    public static function capture(ClientDetailsSubmission $submission): array
    {
        $data = array_intersect_key($submission->data, array_flip(self::DATA));
        $consent = $submission->data['sms_consent_record'] ?? null;
        $data['sms_consent_record'] = is_array($consent)
            ? array_intersect_key($consent, array_flip(['version','text','sha256','accepted_at'])) : null;
        $hash = MutationKey::hash(['schema_version' => 1, 'id' => $submission->id, 'event_id' => $submission->event_id,
            'customer_id' => $submission->customer_id, 'portal_grant_id' => $submission->portal_grant_id,
            'revision' => $submission->revision, 'event_version' => $submission->event_version, 'data' => $data,
            'base_customer_snapshot' => array_intersect_key($submission->base_customer_snapshot, array_flip(['name','email','phone','locale'])),
            'submitted_at' => $submission->submitted_at->utc()->toIso8601String()]);
        return ['schema_version' => 1, 'submission_id' => $submission->id, 'submission_revision' => $submission->revision, 'submission_sha256' => $hash];
    }
}
