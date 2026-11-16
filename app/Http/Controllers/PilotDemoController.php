<?php
namespace App\Http\Controllers;
use App\Services\PilotDemoReadOnly;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Hash};
class PilotDemoController extends Controller
{
    private function render(callable $read){
        try{return $read();}catch(\Illuminate\Validation\ValidationException $e){return redirect()->route('pilot.demo.index')->withErrors($e->errors())->withInput(request()->only(['authorization_id','uuid','classification','amount','recognition_date','reference','description']));}
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\PDOException|\JsonException|\RuntimeException $e){abort(503,'Resultado temporalmente desconocido o no disponible. Consulte por UUID; no reenvíe otra operación.');}
    }
    public function login(){return view('accounting.pilot-demo',['screen'=>'login']);}
    public function authenticate(Request $request){
        $d=$request->validate(['email'=>'required|email','password'=>'required|string|max:1024']);$u=Usuario::where('email',$d['email'])->first();
        if(!$u||!$u->is_active||!in_array($u->role,['alvaro','contador'],true)||!Hash::check($d['password'],$u->password))return back()->withErrors(['email'=>'Acceso de ensayo no válido.'])->onlyInput('email');
        Auth::login($u,false);$request->session()->regenerate();return redirect()->route('pilot.demo.index');
    }
    public function logout(Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('pilot.demo.login');}
    public function index(PilotDemoReadOnly $demo){return $this->render(fn()=>view('accounting.pilot-demo',$demo->index()+['screen'=>'index']));}
    public function review(Request $request,PilotDemoReadOnly $demo){return $this->render(fn()=>view('accounting.pilot-demo',['screen'=>'review','review'=>$demo->review($request->except('_token'))]));}
    public function document(string $uuid,PilotDemoReadOnly $demo){return $this->render(fn()=>view('accounting.pilot-demo',['screen'=>'document','document'=>$demo->document($uuid)]));}
    public function confirm(Request $request,PilotDemoReadOnly $demo){
        \App\Support\AccountingHttpSimulationBoundary::assert(\App\Support\AccountingHttpSimulationBoundary::CONTRACT);
        try {
            $review=$demo->executionInput($request->except('_token'));
            $operation=app(\App\Services\AccountingBankChargeSimulation::class)->executeForSyntheticHttp((int)$review['g']->id,$review['n']);
            return redirect()->route('pilot.demo.document',['uuid'=>$operation->operation_uuid])->withHeaders([
                'Cache-Control'=>'no-store',
                'X-Synthetic-Connection'=>(string)\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
                'X-Synthetic-Process'=>(string)getmypid(),
            ]);
        }catch(\Illuminate\Validation\ValidationException|\App\Exceptions\ContabilidadException $e){return response('Operación rechazada: revise importe, período y habilitación. No se confirmó el cargo. Consulte el UUID original.',422);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable $e){error_log('AC04B2B4B '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine());abort(503,'Resultado temporalmente desconocido. Consulte el UUID original antes de repetir; no genere otro.');}
    }
    public function lookup(Request $request){$d=$request->validate(['uuid'=>'required|string|max:36']);return redirect()->route('pilot.demo.document',['uuid'=>strtolower(trim($d['uuid']))]);}
}
