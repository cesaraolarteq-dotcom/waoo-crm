<?php
namespace App\Services\Leads;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Lead, LeadSubmission};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\{Rule, ValidationException};

final class ReceiveWebsiteLead
{
    public function execute(string $body, string $timestamp, string $key, string $signature): Lead
    {
        $secret = config('waoo.lead_shared_secret');
        abort_unless(app()->environment(['local','testing','staging']) && config('waoo.sandbox_lead_intake')
            && is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 512, 503, 'Lead intake unavailable.');
        abort_if(strlen($body) > 65536, 413);
        abort_unless(preg_match('/^[1-9][0-9]{9}$/D', $timestamp)
            && abs(now()->getTimestamp() - (int) $timestamp) <= 300
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key)
            && preg_match('/^sha256=[0-9a-f]{64}$/D', $signature)
            && hash_equals('sha256='.hash_hmac('sha256', $timestamp."\n".$key."\n".$body, $secret), $signature), 401, 'Invalid lead authentication.');
        try { $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw ValidationException::withMessages(['payload' => 'Invalid JSON payload.']); }
        $data = Validator::make(['payload' => $payload], [
            'payload' => 'required|array:name,phone,email,event_date,start_time,zip,event_type,package,message,travel_estimate_text,locale,consent,demand_flag,submitted_at,page',
            'payload.name' => 'required|string|max:255', 'payload.phone' => 'required|string|max:40', 'payload.email' => 'required|email|max:255',
            'payload.event_date' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01',
            'payload.start_time' => 'present|nullable|date_format:H:i', 'payload.zip' => ['required','string','regex:/^[0-9]{5}(?:-[0-9]{4})?$/'],
            'payload.event_type' => 'required|string|max:100', 'payload.package' => 'required|string|max:100',
            'payload.message' => 'present|nullable|string|max:4000', 'payload.travel_estimate_text' => 'present|nullable|string|max:1000',
            'payload.locale' => ['required', Rule::in(['en','es'])], 'payload.demand_flag' => ['required', Rule::in(['open','filling','full','unknown'])],
            'payload.submitted_at' => ['required','date','before_or_equal:'.now()->addMinutes(5)->toIso8601String(), new ExplicitOffset],
            'payload.page' => ['required', Rule::in(['/contact'])], 'payload.consent' => 'present|nullable|array:text,version',
            'payload.consent.text' => 'required_with:payload.consent|string|max:4000',
            'payload.consent.version' => 'required_with:payload.consent|string|max:120',
        ])->validate()['payload'];
        $hash = hash('sha256', $body);
        if ($existing = LeadSubmission::where('request_key', $key)->first()) return $this->retry($existing, $hash);
        try {
            return DB::transaction(function () use ($data, $hash, $key) {
                $submission = LeadSubmission::create(['request_key' => $key, 'payload_sha256' => $hash, 'payload' => $data,
                    'submitted_at' => CarbonImmutable::parse($data['submitted_at'])->utc(), 'received_at' => now()]);
                $lead = Lead::create(['lead_submission_id' => $submission->id, 'status' => 'new', 'version' => 1]);
                Audit::recordSystem('lead.received', $lead, [], ['lead_submission_id' => $submission->id]);
                app(\App\Services\Mail\CaptureMailOccurrence::class)->record('inquiry_received', $submission->id, ['lead_id' => $lead->id, 'submission_id' => $submission->id]);
                return $lead;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $existing = LeadSubmission::where('request_key', $key)->first();
            if (!$existing) abort(503, 'Lead persistence unavailable.');
            return $this->retry($existing, $hash);
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::error('lead.persistence_unavailable');
            abort(503, 'Lead persistence unavailable.');
        }
    }
    private function retry(LeadSubmission $submission, string $hash): Lead
    {
        RecordWriteRejected::unless(hash_equals($submission->payload_sha256, $hash), 409, 'Idempotency key already used.');
        return Lead::where('lead_submission_id', $submission->id)->firstOrFail();
    }
}
