<?php

namespace Tests\Unit;

use App\Http\Controllers\InventarioController;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ejecuta la validación real de H01-A en store/update, sin arrancar Laravel ni SQL. */
final class OpeningCatalogueGuardTest extends TestCase
{
    public static function physicalInputs(): array
    {
        $rows=[];
        foreach (['store','update'] as $method) foreach (['existencia_actual','current_volume'] as $field) {
            foreach ([0,null,'2'] as $value) $rows[]=[$method,$field,$value];
        }
        return $rows;
    }

    #[DataProvider('physicalInputs')]
    public function test_catalogue_still_rejects_physical_input_before_persistence(string $method,string $field,$value): void
    {
        $request=new class extends Request {
            public function validate(array $rules, array $messages=[]): array
            {
                $factory=new Factory(new Translator(new ArrayLoader,'es'));
                return $factory->make($this->all(),$rules,$messages)->validate();
            }
        };
        $request->replace(['producto'=>'Solo validación','precio_c'=>'10','type'=>'producto_venta',$field=>$value]);
        try {
            $controller=new InventarioController;
            if ($method==='store') $controller->store($request);
            else $controller->update($request,1);
            self::fail('Debe rechazar antes de cualquier modelo o transacción.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field,$e->errors());
        }
    }
}
