<?php
require dirname(__DIR__).'/bootstrap.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$app['config']->set('database.connections',['mysql'=>config('database.connections.mysql')]);
$db=$app['db']->connection();Tests\Support\TestDatabaseSafety::assertServerIdentity($db->selectOne('SELECT DATABASE() AS database_name,CURRENT_USER() AS account_name,@@port AS server_port'));
$db->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');$f=json_decode(file_get_contents(__DIR__.'/../../storage/app/private/ac04b2b4b-http-fixture.json'),true,512,JSON_THROW_ON_ERROR);
echo json_encode(['bank'=>$db->table('cuentas_bancarias')->where('id',$f['bank'])->first(['id','saldo_actual']),'operations'=>$db->table('accounting_event_operations')->where('bank_account_id',$f['bank'])->get(['id','status','result_movement_id','result_asiento_id']),'movements'=>$db->table('movimientos_bancarios')->where('cuenta_bancaria_id',$f['bank'])->count()]),PHP_EOL;
foreach([$f['funds'],$f['expense'],(int)$db->table('cuentas_contables')->where('codigo','6.9')->value('id')] as $account){
    $r=$db->selectOne('SELECT CAST(COALESCE(SUM(d.debe-d.haber),0) AS CHAR) AS final_balance, CAST(COALESCE(SUM(CASE WHEN m.cuenta_bancaria_id=? THEN d.debe-d.haber ELSE 0 END),0) AS CHAR) AS phase_delta, CAST(COALESCE(SUM(d.debe-d.haber),0)-COALESCE(SUM(CASE WHEN m.cuenta_bancaria_id=? THEN d.debe-d.haber ELSE 0 END),0) AS CHAR) AS baseline_balance FROM detalle_asientos d LEFT JOIN movimientos_bancarios m ON m.asiento_contable_id=d.asiento_id WHERE d.cuenta_id=?',[$f['bank'],$f['bank'],$account]);
    echo json_encode(['account'=>$account,'balances'=>$r]),PHP_EOL;
}
