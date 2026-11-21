<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\{Articulo, Cita, Cliente, Servicio, ServicioVariante, Usuario, Venta};
use App\Support\InventoryDecimal as D;
use Illuminate\Support\Facades\Validator;

/** Invariantes del carrito nuevo. No revaloriza documentos ya emitidos. */
class CarritoVentaService
{
    public const MAX_CENTAVOS = 9999999999; // sales y sale_details: decimal(10,2).

    public static function reglas(): array
    {
        $decimal = fn ($scale, $max) => function ($attribute, $value, $fail) use ($scale, $max) {
            try { D::scaled($value, $scale, $max); }
            catch (ContabilidadException $e) { $fail($e->getMessage()); }
        };

        return [
            'cart' => 'required|array|min:1|list',
            'cart.*' => 'required|array',
            'cart.*.type' => 'required|in:servicio,producto,producto_venta,producto_interno',
            'cart.*.id' => 'required|integer|min:1',
            'cart.*.quantity' => ['bail', 'required', $decimal(0, 2147483647), 'numeric', 'min:1'],
            'cart.*.precio_c' => ['required', $decimal(2, self::MAX_CENTAVOS)],
            'cart.*.variant_id' => 'nullable|integer|min:1',
            'cart.*.stylist_id' => 'nullable|integer|min:1',
            'cart.*.appointment_id' => 'nullable|integer|min:1',
            'cart.*.composition_reference' => 'nullable|string|size:64',
            'cart.*.consumo' => 'nullable|array|list',
            'cart.*.consumo.*' => 'required|array',
            'cart.*.consumo.*.item_id' => 'required|integer|min:1',
            'cart.*.consumo.*.cantidad' => ['required', $decimal(2, self::MAX_CENTAVOS)],
            'discount' => ['nullable', $decimal(2, self::MAX_CENTAVOS)],
        ];
    }

    /** Se invoca dentro de la transacción, ANTES de crear/borrar documentos o detalles. */
    public function preparar(array $cart, $discount = null, ?int $clientId = null): array
    {
        // También protege consumidores que no pasan por VentaController.
        $data = Validator::make(['cart' => $cart, 'discount' => $discount], self::reglas())->validate();
        $lines = [];
        $appointments = [];
        foreach ($data['cart'] as $index => $row) {
            // validate() no filtra por sí solo claves extra de arrays padre.
            $line = array_intersect_key($row, array_flip(['id', 'type', 'quantity', 'precio_c',
                'variant_id', 'stylist_id', 'appointment_id', 'consumo', 'composition_reference']));
            $line['id'] = (int) $line['id'];
            $line['quantity'] = D::scaled($line['quantity'], 0, 2147483647);
            foreach (['variant_id', 'stylist_id', 'appointment_id'] as $key) {
                $line[$key] = isset($line[$key]) ? (int) $line[$key] : null;
            }
            if ($line['stylist_id'] && !$this->usuarioExiste($line['stylist_id'])) {
                throw new ContabilidadException('El colaborador de la línea no existe.');
            }
            if ($line['type'] === 'servicio') {
                $service = $this->servicio($line['id']);
                if (!$service || !$service->is_active) throw new ContabilidadException('El servicio no está disponible para venta.');
                $price = self::centavos($service->getRawOriginal('price'));
                if ($line['variant_id']) {
                    $variant = $this->variante($line['variant_id']);
                    if (!$variant || !$variant->is_active || (int) $variant->service_id !== $line['id']) {
                        throw new ContabilidadException('La variante seleccionada no pertenece al servicio activo facturado.');
                    }
                    $price = self::centavos($variant->getRawOriginal('price'));
                }
                $line = $this->consumos($line, $service);
                if ($line['appointment_id']) {
                    $appointments[$line['appointment_id']][] = $index;
                }
            } else {
                $item = $this->articulo($line['id']);
                // El POS vigente vende tanto reventa como artículos internos, incluso envases fraccionables.
                if (!$item || $item->deleted_at || !in_array($item->type, ['producto_venta', 'producto_interno'], true)) {
                    throw new ContabilidadException('El artículo no está disponible para venta.');
                }
                if ($line['type'] !== 'producto' && $line['type'] !== $item->type) {
                    throw new ContabilidadException('El tipo enviado no corresponde al artículo.');
                }
                if ($line['variant_id'] || $line['appointment_id'] || !empty($line['consumo'])) {
                    throw new ContabilidadException('Un producto no admite variante de servicio, cita ni receta.');
                }
                $line['type'] = 'producto';
                $price = self::centavos($item->getRawOriginal('precio_c'));
            }
            if (!$line['appointment_id'] && self::centavos($line['precio_c']) !== $price) {
                throw new ContabilidadException('El precio no coincide con el catálogo vigente. Recargue el producto o servicio.');
            }
            $line['precio_c'] = D::text($price);
            $lines[$index] = $line;
        }
        foreach ($appointments as $id => $indexes) {
            $appointment = $this->cita($id);
            if (!$appointment) throw new ContabilidadException('La cita no existe.');
            $assigned = $appointment->estilistas->modelKeys();
            $fullPrices = true;
            foreach ($indexes as $index) {
                if ($lines[$index]['id'] !== (int) $appointment->service_id) throw new ContabilidadException('El servicio no corresponde a la cita.');
                $fullPrices = $fullPrices && self::centavos($cart[$index]['precio_c']) === self::centavos($lines[$index]['precio_c']);
            }
            // Conservar las líneas completas admitidas por H16. El vínculo de ejecución es alcance H06.
            if ($fullPrices) continue;
            $received = [];
            $prices = self::repartir(self::centavos($lines[$indexes[0]]['precio_c']), count($indexes));
            foreach ($indexes as $position => $index) {
                $line = $lines[$index];
                if ($line['variant_id'] || $line['quantity'] !== 1) throw new ContabilidadException('El reparto de cita requiere cantidad uno y su servicio base.');
                if (self::centavos($cart[$index]['precio_c']) !== $prices[$position]) {
                    throw new ContabilidadException('El reparto del precio de la cita no coincide. Recargue la cita en el POS.');
                }
                $received[] = $line['stylist_id'];
                $lines[$index]['precio_c'] = D::text($prices[$position]);
            }
            sort($assigned); sort($received);
            if ($received !== ($assigned ?: [null])) throw new ContabilidadException('El reparto debe incluir una línea por estilista de la cita.');
        }
        $subtotal = 0;
        foreach ($lines as $line) {
            $price = self::centavos($line['precio_c']);
            if ($price > intdiv(self::MAX_CENTAVOS - $subtotal, $line['quantity'])) {
                throw new ContabilidadException('El subtotal excede el límite de la factura.');
            }
            $subtotal += $price * $line['quantity'];
        }
        $percent = $discount === null && $clientId ? $this->porcentajeGrupo($clientId) : '0';
        return ['cart' => $lines] + self::totales($subtotal, $discount, $percent);
    }

    public static function centavos($value): int
    {
        return D::scaled($value, 2, self::MAX_CENTAVOS);
    }

    public static function totales(int $subtotal, $discount, $percent = '0'): array
    {
        if ($subtotal < 0 || $subtotal > self::MAX_CENTAVOS) throw new ContabilidadException('Subtotal fuera de límite.');
        $rate = D::scaled($percent, 2, 10000);
        $cents = $discount === null ? D::ratio($subtotal, $rate, 10000) : self::centavos($discount);
        if ($cents > $subtotal) throw new ContabilidadException('El descuento no puede superar el subtotal.');
        return ['subtotal' => D::text($subtotal), 'discount' => D::text($cents), 'total' => D::text($subtotal - $cents)];
    }

    public static function repartir(int $price, int $count): array
    {
        if ($price < 0 || $count < 1) throw new ContabilidadException('Reparto de precio inválido.');
        return array_map(fn ($i) => intdiv($price, $count) + ($i < $price % $count ? 1 : 0), range(0, $count - 1));
    }

    /** Aprobar conserva precios guardados; solo comprueba coherencia, sin consultar tarifas actuales. */
    public function validarDocumento(Venta $sale): void
    {
        $subtotal = 0;
        if ($sale->detalles->isEmpty()) throw new ContabilidadException('La factura no tiene detalles.');
        foreach ($sale->detalles as $detail) {
            $quantity = D::scaled($detail->getRawOriginal('quantity'), 0, 2147483647);
            $price = self::centavos($detail->getRawOriginal('unit_price'));
            if (!$quantity || (bool) $detail->service_id === (bool) $detail->item_id) throw new ContabilidadException('Detalle de factura inválido.');
            if ($price > intdiv(self::MAX_CENTAVOS - $subtotal, $quantity)) throw new ContabilidadException('Subtotal fuera de límite.');
            $subtotal += $price * $quantity;
        }
        $totals = self::totales($subtotal, $sale->getRawOriginal('discount'));
        if (self::centavos($sale->getRawOriginal('subtotal')) !== $subtotal
            || self::centavos($sale->getRawOriginal('total')) !== self::centavos($totals['total'])) {
            throw new ContabilidadException('El documento no coincide con sus detalles. Requiere revisión antes de aprobar.');
        }
    }

    private function consumos(array $line, Servicio $service): array
    {
        if (!isset($line['consumo'])) return $line;
        $seen = [];
        $formulas = $service->formulas->keyBy('item_id');
        foreach ($line['consumo'] as &$row) {
            $id = (int) $row['item_id'];
            $formula = $formulas->get($id);
            if (isset($seen[$id]) || !$formula?->articulo) throw new ContabilidadException('Consumo duplicado, ajeno o archivado.');
            $amount = D::scaled($row['cantidad'], $formula->articulo->is_fractionable ? 2 : 0, $formula->articulo->is_fractionable ? self::MAX_CENTAVOS : 99999999);
            $row = ['item_id' => $id, 'cantidad' => $formula->articulo->is_fractionable ? D::text($amount) : (string) $amount];
            $seen[$id] = true;
        }
        unset($row);
        return $line;
    }

    protected function servicio(int $id): ?Servicio { return Servicio::with('formulas.articulo')->find($id); }
    protected function articulo(int $id): ?Articulo { return Articulo::find($id); }
    protected function variante(int $id): ?ServicioVariante { return ServicioVariante::find($id); }
    protected function cita(int $id): ?Cita
    {
        // El controlador ya adquirió los mutex de agenda; leer su asignación actual, no un snapshot anterior.
        $cita = Cita::lockForUpdate()->find($id);
        if ($cita) $cita->setRelation('estilistas', $cita->estilistas()->orderBy('users.id')->lockForUpdate()->get());
        return $cita;
    }
    protected function usuarioExiste(int $id): bool { return Usuario::whereKey($id)->exists(); }
    protected function porcentajeGrupo(int $id) { return Cliente::find($id)?->grupo?->getRawOriginal('discount_percent') ?? '0'; }
}
