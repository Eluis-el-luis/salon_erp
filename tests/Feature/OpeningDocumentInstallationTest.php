<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class OpeningDocumentInstallationTest extends TestCase
{
    private function businessFingerprint(): array
    {
        $tables=DB::table('information_schema.TABLES')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())
            ->where('TABLE_TYPE','BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
        $out=[];
        foreach (array_diff($tables,['inventory_openings','inventory_opening_lines','migrations']) as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new \RuntimeException('Tabla inesperada');
            $rows=array_map(fn($r)=>hash('sha256',serialize((array)$r)),DB::table($table)->get()->all()); sort($rows);
            $ddl=(array)DB::selectOne("SHOW CREATE TABLE `$table`");
            $out[$table]=[hash('sha256',implode('',$rows)),hash('sha256',array_values($ddl)[1])];
        }
        return $out;
    }

    public function test_installation_and_empty_reversal_preserve_every_historical_table(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0,DB::transactionLevel());
        $this->assertSame(78,DB::table('migrations')->count());
        $this->assertFalse(Schema::hasTable('inventory_openings'));
        $this->assertFalse(Schema::hasTable('inventory_opening_lines'));
        $before=$this->businessFingerprint();
        $old=DB::table('migrations')->orderBy('id')->get()->toJson();
        DB::connection()->beforeExecuting(function ($sql,$bindings) {
            if (preg_match('/^\s*(select|show)\b/i',$sql)) return;
            if (preg_match('/^(?:create|alter|drop) table (?:if exists )?`?(inventory_openings|inventory_opening_lines)`?(?:\s|\(|$)/i',$sql)) return;
            if (str_starts_with($sql,'insert into `migrations`') && in_array('2026_11_14_000005_create_inventory_opening_documents',$bindings,true)) return;
            throw new \LogicException('Instalación fuera del alcance documental.');
        });
        $path='database/migrations/2026_11_14_000005_create_inventory_opening_documents.php';
        $this->artisan('migrate',['--path'=>$path,'--force'=>true])->assertExitCode(0);
        $this->assertSame(79,DB::table('migrations')->count());
        $this->assertSame($before,$this->businessFingerprint());
        $this->assertSame($old,DB::table('migrations')->where('migration','<>',basename($path,'.php'))->orderBy('id')->get()->toJson());
        $this->assertSame(0,DB::table('inventory_openings')->count());
        $this->assertSame(0,DB::table('inventory_opening_lines')->count());
        $migration=require base_path($path);
        $migration->down();
        $this->assertFalse(Schema::hasTable('inventory_openings'));
        $this->assertFalse(Schema::hasTable('inventory_opening_lines'));
        $this->assertSame($before,$this->businessFingerprint());
        $migration->up();
        $this->assertTrue(Schema::hasTable('inventory_openings'));
        $this->assertTrue(Schema::hasTable('inventory_opening_lines'));
        $this->assertSame($before,$this->businessFingerprint());
    }
}
