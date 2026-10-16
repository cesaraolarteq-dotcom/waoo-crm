<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\{ConvertLead, ReadLeads, UpdateLead};
use Illuminate\Http\Request;

final class LeadController extends Controller
{
    public function index(Request $request, ReadLeads $service) { return response()->json($service->index($request->user(), $request->query())); }
    public function show(Request $request, Lead $lead, ReadLeads $service) { return response()->json($service->show($request->user(), $lead->id)); }
    public function update(Request $request, Lead $lead, UpdateLead $service) { return response()->json($service->execute($request->user(), $lead->id, $request->all())); }
    public function convert(Request $request, Lead $lead, ConvertLead $service)
    {
        return response()->json($service->execute($request->user(), $lead->id, $request->all(), (string) $request->header('Idempotency-Key')), 201);
    }
}
