<?php
namespace App\Http\Middleware;
use App\Services\PilotDemoReadOnly;
use Closure;
use Illuminate\Http\Request;
class PilotDemoAccess
{
    public function handle(Request $request,Closure $next){
        PilotDemoReadOnly::installation();
        if(!$request->is('__pilot-demo/login')&&!auth()->check())return redirect()->route('pilot.demo.login');
        return $next($request);
    }
}
