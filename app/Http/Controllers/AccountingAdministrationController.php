<?php

namespace App\Http\Controllers;

use App\Services\AccountingEventAuthorizationService as Authorizations;
use App\Support\AccountingSimulationBoundary as Boundary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administración documental sintética; sin consumidor financiero ni acceso en desarrollo. */
class AccountingAdministrationController extends Controller
{
    private function guard(): void {
        abort_unless(app()->environment('testing')&&PHP_SAPI==='cli'&&defined('SALON_TEST_DATABASE_BOOTSTRAPPED'),404);
        Boundary::assert(Boundary::CONTRACT);
    }
    public function index() {
        $this->guard();
        return view('accounting.policy-administration',['versions'=>DB::table('accounting_policy_versions')->orderByDesc('id')->get(),
            'banks'=>DB::table('cuentas_bancarias')->where('activa',true)->orderBy('id')->get(),'review'=>null]);
    }
    public function review(Request $request,int $version) {
        $this->guard();$d=$request->validate(['bank'=>'required|integer|min:1']);
        return view('accounting.policy-administration',['versions'=>[],'banks'=>[],
            'review'=>app(Authorizations::class)->reviewForAdministration($version,(int)$d['bank'])]);
    }
    public function record(Request $request,int $target,string $action) {
        $this->guard();
        $allowed=['reference','reason'];if($action==='approve')$allowed=array_merge($allowed,['bank','hash','from','until']);
        if($action==='enable')$allowed=array_merge($allowed,['from','until']);
        if(array_diff(array_keys($request->except('_token')),$allowed))throw ValidationException::withMessages(['request'=>'Campos autoritativos o desconocidos no permitidos.']);
        $rules=['reference'=>'required|string|max:160','reason'=>'required|string|max:2000'];
        if($action==='approve')$rules+=['bank'=>'required|integer|min:1','hash'=>'required|string|size:64'];
        if(in_array($action,['approve','enable']))$rules+=['from'=>'required|string','until'=>'required|string'];
        $d=$request->validate($rules);$s=app(Authorizations::class);
        $a=match($action){
            'approve'=>$s->approveForAdministration($target,(int)$d['bank'],$d['hash'],$d['from'],$d['until'],$d['reference'],$d['reason']),
            'enable'=>$s->enableForAdministration($target,$d['from'],$d['until'],$d['reference'],$d['reason']),
            'revoke'=>$s->revokeForAdministration($target,$d['reference'],$d['reason']),
            default=>abort(404),
        };
        return redirect()->route('accounting.administration.review',['version'=>$a->policy_version_id,'bank'=>$a->bank_account_id])
            ->with('success','Acto sintético registrado. No autoriza contabilización empresarial.');
    }
}
