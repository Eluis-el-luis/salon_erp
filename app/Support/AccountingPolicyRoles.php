<?php

namespace App\Support;

/** Reglas estructurales del efecto, no códigos de cuenta ni fórmulas editables. */
final class AccountingPolicyRoles
{
    public const CORE = ['inventario.reventa','inventario.insumos','costo.producto','costo.servicio','ingreso.producto','ingreso.servicio'];

    public static function rule(string $role): array
    {
        $rules = [
            'inventario.reventa'=>['activo','deudora',['global','item']],
            'inventario.insumos'=>['activo','deudora',['global','item']],
            'costo.producto'=>['costo','deudora',['global','item']],
            'costo.servicio'=>['costo','deudora',['global']],
            'ingreso.producto'=>['ingreso','acreedora',['global','item']],
            'ingreso.servicio'=>['ingreso','acreedora',['global']],
            'fondos.banco'=>['activo','deudora',['bank_account']],
            'gasto.cargo_bancario'=>['gasto','deudora',['bank_account']],
            // Reservado: AC-03A no decide una política patrimonial de apertura.
            'apertura.inventario.contrapartida'=>[null,null,['global']],
        ];
        if (!isset($rules[$role])) throw new \DomainException('Papel semántico no registrado.');
        [$type,$nature,$scopes]=$rules[$role];
        return ['version'=>1,'role'=>$role,'type'=>$type,'nature'=>$nature,'scopes'=>$scopes,
            'publication_blocked'=>$role==='apertura.inventario.contrapartida'];
    }
}
