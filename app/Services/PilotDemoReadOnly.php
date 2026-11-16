<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;

/** Proyección y revisión sin persistencia. Nunca invoca escritores financieros. */
final class PilotDemoReadOnly
{
    public const CONTRACT='AC04B2B4A_READ_ONLY_DEMO_V1';
    private ?PDO $pdo=null;
    public static function installation(): void {
        abort_unless(defined('SALON_PILOT_DEMO_READONLY')&&SALON_PILOT_DEMO_READONLY===self::CONTRACT
            &&defined('SALON_TEST_DATABASE_BOOTSTRAPPED')&&SALON_TEST_DATABASE_BOOTSTRAPPED
            &&defined('SALON_TEST_DATABASE_RESET_ALLOWED')&&!SALON_TEST_DATABASE_RESET_ALLOWED
            &&app()->environment('testing')&&in_array(PHP_SAPI,['cli','cli-server'],true),404);
        try{
            \Tests\Support\TestDatabaseSafety::assertApplicationRoot(dirname(__DIR__,2),app()->basePath());
            \Tests\Support\TestDatabaseSafety::assertConfiguration(app()->environment(),config('database'));
            foreach(DB::getConnections() as $name=>$connection){if($name!=='mysql')throw new \LogicException('Conexión alternativa');\Tests\Support\TestDatabaseSafety::assertConnection($connection->getConfig());}
        }catch(\Throwable){abort(404);}
    }
    private function connection(): PDO {
        self::installation();if($this->pdo)return $this->pdo;
        $cfg=config('database.connections.mysql');
        $p=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $i=$p->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port,CURRENT_ROLE() AS roles')->fetch(PDO::FETCH_OBJ);
        \Tests\Support\TestDatabaseSafety::assertServerIdentity($i);if($i->roles!=='NONE')throw new \RuntimeException('Roles SQL inesperados');
        $p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();$this->pdo=$p;return $p;
    }
    public function __destruct(){if($this->pdo?->inTransaction())$this->pdo->rollBack();}
    private function rows(string $sql,array $parameters=[]): array {$s=$this->connection()->prepare($sql);$s->execute($parameters);return $s->fetchAll(PDO::FETCH_OBJ);}
    private function one(string $sql,array $parameters=[]): ?object {return $this->rows($sql,$parameters)[0]??null;}
    private function actor(): int {
        self::installation();$u=auth()->id()?DB::table('users')->where('id',auth()->id())->first():null;
        if(!$u||!$u->is_active||!in_array($u->role,['alvaro','contador'],true))abort(403);
        $this->connection();return (int)$u->id;
    }
    private function invalid(string $m): never {throw ValidationException::withMessages(['demo'=>$m]);}
    private function grant(int $id,bool $owned=true): object {
        $actor=$this->actor();$g=$this->one('SELECT * FROM accounting_policy_authorizations WHERE id=?',[$id]);
        if(!$g||($owned&&(int)$g->actor_id!==$actor))abort(403);
        $e=json_decode($g->evidence,true,512,JSON_THROW_ON_ERROR);
        if($g->purpose!=='simulation'||$g->environment!=='testing'||$g->action!=='enablement'||$g->event_key!==AccountingEventAuthorizationService::EVENT||$g->currency!=='NIO'||($e['version']??null)!==1||hash('sha256',AccountingPolicyService::canonical($e))!==$g->evidence_hash)$this->invalid('Habilitación sintética incompatible.');
        foreach($e['authorization']??[] as $key=>$value)if(!property_exists($g,$key)||($value===null?$g->$key!==null:(string)$value!==(string)$g->$key))$this->invalid('Evidencia documental incompatible.');
        return $g;
    }
    private function context(object $g,bool $historical=false): array {
        $bank=$this->one('SELECT b.id,b.alias,b.activa,b.saldo_actual,b.cuenta_contable_id,m.codigo AS currency FROM cuentas_bancarias b JOIN monedas m ON m.id=b.moneda_id WHERE b.id=?',[$g->bank_account_id]);
        $policy=$this->one('SELECT id,version_number,status,kind,content_hash,valid_from,valid_until FROM accounting_policy_versions WHERE id=?',[$g->policy_version_id]);
        if(!$bank||(!$historical&&$bank->currency!=='NIO')||!$policy||$policy->content_hash!==$g->policy_hash||$policy->status!=='published'||$policy->kind!=='enterprise')$this->invalid('Banco o versión documental incompatibles.');
        $bindings=$this->rows("SELECT id,semantic_role,account_id,account_snapshot FROM accounting_account_bindings WHERE policy_version_id=? AND bank_account_id=? AND semantic_role IN ('fondos.banco','gasto.cargo_bancario') ORDER BY semantic_role",[$policy->id,$bank->id]);
        if(count($bindings)!==2)$this->invalid('Faltan las dos asociaciones documentadas.');
        foreach($bindings as $b){$b->snapshot=json_decode($b->account_snapshot,true,512,JSON_THROW_ON_ERROR);}
        $revoked=(bool)$this->one("SELECT id FROM accounting_policy_authorizations WHERE action='revocation' AND parent_id IN (?,?) LIMIT 1",[$g->id,$g->parent_id]);
        return compact('bank','policy','bindings','revoked');
    }
    public function index(): array {
        $actor=$this->actor();
        $grants=$this->rows("SELECT a.id,a.bank_account_id,a.valid_from,a.valid_until,b.alias FROM accounting_policy_authorizations a JOIN cuentas_bancarias b ON b.id=a.bank_account_id JOIN monedas m ON m.id=b.moneda_id WHERE a.actor_id=? AND a.purpose='simulation' AND a.environment='testing' AND a.action='enablement' AND a.event_key='bancos.cargo_gasto.nio' AND m.codigo='NIO' ORDER BY a.id DESC",[$actor]);
        $operations=$this->rows("SELECT operation_uuid,status,classification,amount,recognition_date FROM accounting_event_operations WHERE created_by=? AND purpose='simulation' AND environment='testing' AND event_key='bancos.cargo_gasto.nio' ORDER BY id DESC LIMIT 100",[$actor]);
        return compact('grants','operations');
    }
    public function executionInput(array $input): array {
        $actor=$this->actor();$keys=['authorization_id','uuid','classification','amount','recognition_date','reference','description'];
        if(array_diff(array_keys($input),$keys)||!isset($input['authorization_id'])||!ctype_digit((string)$input['authorization_id']))$this->invalid('Campos desconocidos o habilitación inválida.');
        $g=$this->grant((int)$input['authorization_id']);unset($input['authorization_id']);$n=app(AccountingEventIdentityService::class)->normalize($input);
        $content=app(AccountingEventIdentityService::class)->simulationContent($g,$n,$actor);$hash=hash('sha256',AccountingPolicyService::canonical($content));
        $existing=$this->one('SELECT * FROM accounting_event_operations WHERE operation_uuid=?',[$n['uuid']]);
        if($existing){if((int)$existing->created_by!==$actor)abort(403);app(AccountingEventIdentityService::class)->verifyDocument($existing);if($existing->content_hash!==$hash)abort(409,'Conflicto de identidad: consulte el UUID original; no genere otro automáticamente.');}
        return compact('n','g','hash','existing');
    }
    public function review(array $input): array {
        $data=$this->executionInput($input);$n=$data['n'];$g=$data['g'];$hash=$data['hash'];$existing=$data['existing'];
        $ctx=$this->context($g,$existing&&$existing->status==='confirmed');
        $problems=[];$now=now('UTC')->format('Y-m-d H:i:s');
        if($ctx['revoked'])$problems[]='Autorización retirada: solo historia consultable.';
        if($g->valid_from>$now||$g->valid_until<=$now)$problems[]='Habilitación fuera de vigencia actual.';
        if(!$ctx['bank']->activa)$problems[]='Banco inactivo.';
        $period=$this->rows('SELECT estado FROM periodos_contables WHERE fecha_inicio<=? AND fecha_fin>=?',[$n['recognition_date'],$n['recognition_date']]);
        if(count($period)!==1||$period[0]->estado!=='abierto')$problems[]='Período no disponible o cerrado. Esta revisión no crea períodos.';
        return $ctx+compact('n','g','hash','existing','problems');
    }
    public function document(string $uuid): array {
        $actor=$this->actor();$uuid=strtolower(trim($uuid));if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))abort(404);
        $o=$this->one('SELECT * FROM accounting_event_operations WHERE operation_uuid=?',[$uuid]);if(!$o||(int)$o->created_by!==$actor)abort(403);
        if($o->purpose!=='simulation'||$o->environment!=='testing'||$o->event_key!==AccountingEventAuthorizationService::EVENT)$this->invalid('Documento fuera del ensayo.');
        app(AccountingEventIdentityService::class)->verifyDocument($o);$g=$this->grant((int)$o->authorization_id,false);$ctx=$this->context($g,true);
        foreach(['policy_version_id','policy_hash','bank_account_id','currency'] as $key)if((string)$o->$key!==(string)$g->$key)$this->invalid('Referencias históricas cruzadas.');
        $movement=null;$entry=null;$details=[];$resolutions=[];$refund=null;$refundLines=[];
        if($o->status==='prepared'){if($o->result_movement_id!==null||$o->result_asiento_id!==null)$this->invalid('Prepared incompleto o contradictorio.');}
        elseif($o->status==='confirmed'){
            $movement=$this->one('SELECT * FROM movimientos_bancarios WHERE id=?',[$o->result_movement_id]);$entry=$this->one('SELECT * FROM asientos_contables WHERE id=?',[$o->result_asiento_id]);
            $details=$this->rows('SELECT * FROM detalle_asientos WHERE asiento_id=? ORDER BY id',[$o->result_asiento_id]);$resolutions=$this->rows('SELECT * FROM accounting_entry_line_resolutions WHERE operation_id=? ORDER BY semantic_role',[$o->id]);
            if(!$movement||!$entry||count($details)!==2||count($resolutions)!==2||(int)$movement->cuenta_bancaria_id!==(int)$o->bank_account_id||(int)$movement->asiento_contable_id!==(int)$entry->id||$movement->monto!==$o->amount||$movement->tipo!=='cargo'||$entry->accounting_policy_hash!==$o->policy_hash)$this->invalid('Resultado confirmado sin correspondencias íntegras.');
            foreach($resolutions as $r){$line=array_values(array_filter($details,fn($l)=>(int)$l->id===(int)$r->detail_id))[0]??null;if(!$line||(int)$line->cuenta_id!==(int)$r->account_id||(int)$r->asiento_id!==(int)$entry->id)$this->invalid('Correspondencia de cuenta incompatible.');}
            $refund=$this->one("SELECT * FROM bank_charge_reversals WHERE original_operation_id=? AND status='confirmed' AND created_by=?",[$o->id,$actor]);
            if($refund){$e=json_decode($refund->evidence,true,512,JSON_THROW_ON_ERROR);if($refund->purpose!=='simulation'||($e['synthetic']??null)!==true||hash('sha256',AccountingPolicyService::canonical($e))!==$refund->evidence_hash)$this->invalid('Devolución documental incompatible.');$refundLines=$this->rows('SELECT * FROM bank_charge_reversal_lines WHERE reversal_id=? ORDER BY id',[$refund->id]);if(count($refundLines)!==2)$this->invalid('Devolución sin enlaces completos.');}
        }else $this->invalid('Estado documental no reconocido.');
        return $ctx+compact('o','g','movement','entry','details','resolutions','refund','refundLines');
    }
}
