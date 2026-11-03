<?php
namespace App\Http\Controllers\Staff;

use App\Models\StaffAccessGrant;
use App\Services\Staff\{StaffContext, StaffGuard, ReadStaffWeek};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffPortalController
{
    public function page(Request $request)
    {
        StaffGuard::transport($request);
        abort_if($request->user()!==null || $request->session()->has('portal_grant_id'),403,'Abre este enlace en una sesión separada.');
        if (!$request->cookie(StaffContext::COOKIE) || $request->boolean('exchange')) return response()->view('staff.access');
        try { $context=StaffContext::fromRequest($request); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode()!==401) throw $e;
            return response()->view('staff.access');
        }
        return response()->view('staff.semana',['vm'=>app(ReadStaffWeek::class)->execute($context)]);
    }
    public function week(Request $request,ReadStaffWeek $service)
    {
        return response()->json($service->execute(StaffContext::fromRequest($request)));
    }
    public function answer(Request $request,\App\Services\Staff\SaveStaffAnswers $service)
    {
        $data=$request->validate(['event_id'=>'required|integer','answer'=>'required|in:yes,no','note'=>'nullable|string|max:300']);
        $service->job(StaffContext::fromRequest($request),(int)$data['event_id'],$data['answer'],$data['note']??null);
        return redirect('/staff')->with('staff_saved','answer');
    }
    public function availability(Request $request,\App\Services\Staff\SaveStaffAnswers $service)
    {
        $data=$request->validate(['week'=>'required|date_format:Y-m-d','days'=>'array','days.*'=>'in:yes,no','note'=>'nullable|string|max:300']);
        $service->availability(StaffContext::fromRequest($request),$data['week'],$data['days']??[],$data['note']??null);
        return redirect('/staff')->with('staff_saved','availability');
    }
    public function session(Request $request)
    {
        StaffGuard::transport($request);
        abort_if($request->user()!==null || $request->session()->has('portal_grant_id'),403,'Abre este enlace en una sesión separada.');
        $data=$request->validate(['token'=>'required|string|regex:/^[A-Za-z0-9_-]{43}$/D']);
        $hint=StaffAccessGrant::where('token_hash',hash('sha256',$data['token']))->first();
        abort_unless($hint,401,'Este acceso no es válido.');
        $token=StaffGuard::token();
        DB::transaction(function () use ($hint,$token) {
            try { [$grant]=StaffGuard::grant($hint->id); }
            catch (\App\Exceptions\RecordWriteRejected|\Illuminate\Database\Eloquent\ModelNotFoundException) { abort(401,'Este acceso no es válido.'); }
            DB::table('staff_portal_sessions')->insert(['staff_access_grant_id'=>$grant->id,'token_hash'=>hash('sha256',$token),
                'expires_at'=>\Carbon\CarbonImmutable::now('UTC')->addDays(7),'created_at'=>now(),'updated_at'=>now()]);
            $grant->last_opened_at=now(); $grant->save();
        },3);
        return response()->json(['ok'=>true])->cookie(StaffContext::COOKIE,$token,7*24*60,'/staff',null,
            !app()->environment(['local','testing']),true,false,'strict');
    }
    public function logout(Request $request)
    {
        StaffGuard::transport($request);
        $token=$request->cookie(StaffContext::COOKIE);
        if (is_string($token)) DB::table('staff_portal_sessions')->where('token_hash',hash('sha256',$token))->delete();
        return response()->json(['ok'=>true])->withoutCookie(StaffContext::COOKIE,'/staff');
    }
}
