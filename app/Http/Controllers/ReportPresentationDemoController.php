<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\ReportPresentationDemo;
use App\Support\ReportDemoBoundary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Hash};

final class ReportPresentationDemoController extends Controller
{
    public function page()
    {
        ReportDemoBoundary::installation();
        if(auth()->check())ReportDemoBoundary::actor();
        return view('accounting.report-demo',['comparison'=>null,'problem'=>null]);
    }
    public function login(Request $r)
    {
        ReportDemoBoundary::installation();
        abort_if(array_diff(array_keys($r->except('_token')),['email','password']),422);
        $v=$r->validate(['email'=>'required|email','password'=>'required|string|max:1024']);
        $u=Usuario::where('email',$v['email'])->first();
        if(!ReportDemoBoundary::allowsActor($u)||!Hash::check($v['password'],$u->password))return response('Acceso de ensayo rechazado.',403);
        Auth::login($u,false);$r->session()->regenerate();return redirect('/__report-demo');
    }
    public function logout(Request $r)
    {
        ReportDemoBoundary::actor();Auth::logoutCurrentDevice();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/__report-demo/login');
    }
    public function compare(Request $r,ReportPresentationDemo $demo)
    {
        ReportDemoBoundary::actor();
        try{return response()->view('accounting.report-demo',['comparison'=>$demo->compare($r->except('_token')),'problem'=>null]);}
        catch(\Illuminate\Validation\ValidationException|\DomainException $e){return response()->view('accounting.report-demo',['comparison'=>null,'problem'=>'Comparación rechazada: revise fechas, filtros y límites de la demostración. No se generó un reporte completo.'],422);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable){return response('Lectura no disponible. No se generó un reporte completo.',503);}
    }
}
