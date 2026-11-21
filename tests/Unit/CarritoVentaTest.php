<?php

namespace Tests\Unit;

use App\Exceptions\ContabilidadException;
use App\Models\{Articulo, Cita, Cliente, DetalleVenta, FormulaServicio, Servicio, ServicioVariante, Usuario, Venta};
use App\Services\CarritoVentaService;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\{Collection, Model};
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, ValidationException};
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CarritoVentaTest extends TestCase
{
    private CarritoCatalogoFixture $service;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'es')));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldNotReceive('connection');
        Model::setConnectionResolver($resolver);
        $this->service = new CarritoCatalogoFixture;
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function line(array $extra = []): array
    {
        return array_replace(['id' => 10, 'type' => 'producto', 'quantity' => 1, 'precio_c' => '10.01'], $extra);
    }

    public static function invalidLines(): array
    {
        $cases = [];
        foreach ([0, -1, 'x', NAN, INF, 'NaN', 'Infinity', '1e2', '1.001', '0.5', true, null, [], 2147483648] as $i => $quantity) {
            $cases['quantity '.$i] = [self::line(['quantity' => $quantity])];
        }
        foreach ([-1, '1.001', 'x', INF, '1e2', null, true, '999999999.99', '0.01'] as $i => $price) {
            $cases['price '.$i] = [self::line(['precio_c' => $price])];
        }
        foreach ([['type' => 'desconocido'], ['id' => 999], ['id' => 13], ['id' => 14],
            ['type' => 'servicio', 'id' => 999], ['type' => 'servicio', 'id' => 21],
            ['variant_id' => 30], ['type' => 'producto_interno'], ['stylist_id' => 999],
            ['consumo' => [['item_id' => 12, 'cantidad' => '1']]],
            ['type' => 'servicio', 'id' => 20, 'precio_c' => '10.01', 'variant_id' => 31],
            ['type' => 'servicio', 'id' => 20, 'precio_c' => '10.01', 'variant_id' => 32],
            ['type' => 'servicio', 'id' => 20, 'precio_c' => '150.01', 'variant_id' => 999]] as $i => $extra) {
            $cases['identity '.$i] = [self::line($extra)];
        }
        return $cases;
    }

    #[DataProvider('invalidLines')]
    public function test_rejects_invalid_lines_before_any_sql(array $line): void
    {
        try {
            $this->service->preparar([$line], '0');
            self::fail('Debía rechazar la línea.');
        } catch (ContabilidadException|ValidationException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function test_product_service_mixed_cart_variants_and_exact_totals(): void
    {
        $product = $this->service->preparar([self::line(['quantity' => 3])], '0');
        self::assertSame('30.03', $product['subtotal']);
        $service = self::line(['type' => 'servicio', 'id' => 20, 'precio_c' => '100.00']);
        self::assertSame('100.00', $this->service->preparar([$service], '0')['total']);
        self::assertSame('200.00', $this->service->preparar([array_replace($service, ['quantity' => 2])], '0')['total']);
        $variant = array_replace($service, ['variant_id' => 30, 'precio_c' => '150.01']);
        $mixed = $this->service->preparar([self::line(), $variant], '10.02');
        self::assertSame('160.02', $mixed['subtotal']);
        self::assertSame('150.00', $mixed['total']);
        self::assertSame(30, $mixed['cart'][1]['variant_id']);
        self::assertSame('producto', $this->service->preparar([self::line(['type' => 'producto_venta'])])['cart'][0]['type']);
        self::assertSame('producto', $this->service->preparar([self::line(['id' => 11, 'type' => 'producto_interno'])])['cart'][0]['type']);
    }

    public function test_extra_fields_are_not_economic_authority_or_mass_assignment(): void
    {
        $result = $this->service->preparar([self::line(['sale_id' => 99, 'unit_price' => 0, 'subtotal' => -1,
            'status' => 'pagada', 'service_id' => 20, 'producto' => 'Nombre manipulado'])], '0');
        self::assertSame('10.01', $result['total']);
        foreach (['sale_id', 'unit_price', 'subtotal', 'status', 'service_id', 'producto'] as $key) self::assertArrayNotHasKey($key, $result['cart'][0]);
    }

    public function test_automatic_discount_rounds_half_up_and_explicit_zero_wins(): void
    {
        $result = $this->service->preparar([self::line(['quantity' => 5])], null, 50);
        self::assertSame('50.05', $result['subtotal']);
        self::assertSame('5.01', $result['discount']);
        self::assertSame('45.04', $result['total']);
        self::assertSame('0.00', $this->service->preparar([self::line()], '0', 50)['discount']);
        self::assertSame('0.00', $this->service->preparar([self::line()], '10.01')['total']);
    }

    public static function invalidDiscounts(): array
    {
        return array_map(fn ($d) => [$d], ['-1', '10.02', '1.001', 'x', INF, true]);
    }

    #[DataProvider('invalidDiscounts')]
    public function test_rejects_invalid_discount($discount): void
    {
        try {
            $this->service->preparar([self::line()], $discount);
            self::fail('Descuento inválido.');
        } catch (ContabilidadException|ValidationException $e) {
            self::assertNotEmpty($e->getMessage());
        }
    }

    public function test_empty_cart_and_subtotal_overflow_are_rejected(): void
    {
        foreach ([[], [self::line(['quantity' => 2147483647])], [self::line(), self::line(['quantity' => 2147483647])]] as $cart) {
            try {
                $this->service->preparar($cart);
                self::fail('Carrito inválido.');
            } catch (ContabilidadException|ValidationException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_real_zero_consumption_is_preserved_and_protected_fields_are_dropped(): void
    {
        $line = self::line(['id' => 20, 'type' => 'servicio', 'precio_c' => '100',
            'consumo' => [['item_id' => 12, 'cantidad' => '0', 'quantity_standard' => 999, 'sale_detail_id' => 999]]]);
        self::assertSame([['item_id' => 12, 'cantidad' => '0.00']], $this->service->preparar([$line])['cart'][0]['consumo']);
        foreach ([[['item_id' => 99, 'cantidad' => 0]], [['item_id' => 12, 'cantidad' => '0.001']],
            [['item_id' => 12, 'cantidad' => 0], ['item_id' => 12, 'cantidad' => 1]]] as $consumption) {
            try {
                $this->service->preparar([array_replace($line, ['consumo' => $consumption])]);
                self::fail('Consumo inválido.');
            } catch (ContabilidadException|ValidationException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function test_appointment_price_is_split_exactly_from_catalogue(): void
    {
        $cart = [];
        foreach (['33.34', '33.33', '33.33'] as $i => $price) $cart[] = self::line([
            'id' => 20, 'type' => 'servicio', 'precio_c' => $price, 'appointment_id' => 60, 'stylist_id' => $i + 70,
        ]);
        self::assertSame('100.00', $this->service->preparar($cart)['total']);
        $full = array_replace($cart[0], ['precio_c' => '100.00']);
        self::assertSame('200.00', $this->service->preparar([$full, $full])['total']); // Contrato H16 existente.
        $cart[0]['precio_c'] = '33.33';
        $this->expectException(ContabilidadException::class);
        $this->service->preparar($cart);
    }

    public function test_approval_validates_frozen_prices_without_repricing_the_catalogue(): void
    {
        $sale = new Venta;
        $sale->setRawAttributes(['subtotal' => '14.02', 'discount' => '1.01', 'total' => '13.01'], true);
        $detail = new DetalleVenta;
        $detail->setRawAttributes(['service_id' => null, 'item_id' => 10, 'quantity' => 2, 'unit_price' => '7.01'], true);
        $sale->setRelation('detalles', new Collection([$detail]));
        $this->service->validarDocumento($sale); // Precio actual es 10.01; se conserva 7.01.
        self::assertSame('7.01', $detail->getRawOriginal('unit_price'));
        $sale->setRawAttributes(['subtotal' => '14.02', 'discount' => '20.00', 'total' => '0.00'], true);
        $this->expectException(ContabilidadException::class);
        $this->service->validarDocumento($sale);
    }

    public function test_approval_rejects_malformed_stored_details_and_inconsistent_totals(): void
    {
        foreach ([['quantity' => 0], ['quantity' => -1], ['quantity' => '1.1'],
            ['unit_price' => '-1'], ['service_id' => 20], ['item_id' => null], []] as $extra) {
            $sale = new Venta;
            $sale->setRawAttributes(['subtotal' => '14.03', 'discount' => '0', 'total' => '14.03'], true);
            $detail = new DetalleVenta;
            $detail->setRawAttributes(array_replace(['item_id' => 10, 'service_id' => null, 'quantity' => 2, 'unit_price' => '7.01'], $extra), true);
            $sale->setRelation('detalles', new Collection([$detail]));
            try {
                $this->service->validarDocumento($sale);
                self::fail('No debe aprobar datos incompatibles.');
            } catch (ContabilidadException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function test_old_structural_boundary_accepted_the_original_h05_payload(): void
    {
        $legacy = new Factory(new Translator(new ArrayLoader, 'es'));
        // Reglas originales relevantes: los campos de línea no tenían reglas.
        $payload = ['cart' => [self::line(['quantity' => -1, 'precio_c' => '0.01'])], 'discount' => 0];
        self::assertFalse($legacy->make($payload, ['cart' => 'required|array', 'discount' => 'nullable|numeric|min:0'])->fails());
        self::assertSame(-0.01, $payload['cart'][0]['quantity'] * $payload['cart'][0]['precio_c']);
        $this->expectException(ValidationException::class);
        $this->service->preparar($payload['cart'], 0);
    }

    public function test_approval_checks_document_before_inventory_or_any_write(): void
    {
        $sale = Mockery::mock(Venta::class)->makePartial();
        $sale->setRawAttributes(['id' => 90, 'status' => Venta::ESTADO_PENDIENTE_APROBACION,
            'subtotal' => '0', 'discount' => '0', 'total' => '0'], true);
        $detail = new DetalleVenta;
        $detail->setRawAttributes(['item_id' => 10, 'service_id' => null, 'quantity' => 0, 'unit_price' => '10.01'], true);
        $sale->setRelation('detalles', new Collection([$detail]));
        $sale->shouldReceive('load')->once()->with('detalles')->andReturnSelf();
        $sale->shouldNotReceive('update', 'save');
        $db = Mockery::mock();
        $db->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        Container::getInstance()->instance('db', $db);
        $agenda = Mockery::mock(\App\Services\AgendaService::class);
        $agenda->shouldReceive('bloquearVenta')->once()->with(90, true)->andReturn($sale);
        Container::getInstance()->instance(\App\Services\AgendaService::class, $agenda);
        $this->expectException(ContabilidadException::class);
        (new \App\Services\AprobacionService)->aprobarVenta(90, 70);
    }
}

/** Catálogos en memoria. Ningún override persiste o reemplaza reglas económicas. */
class CarritoCatalogoFixture extends CarritoVentaService
{
    protected function servicio(int $id): ?Servicio
    {
        if (!in_array($id, [20, 21])) return null;
        $s = new Servicio;
        $s->setRawAttributes(['id' => $id, 'price' => '100.00', 'is_active' => $id === 20], true);
        $item = new Articulo;
        $item->setRawAttributes(['id' => 12, 'is_fractionable' => true], true);
        $formula = new FormulaServicio;
        $formula->setRawAttributes(['item_id' => 12, 'quantity_used' => '10.00'], true);
        $formula->setRelation('articulo', $item);
        $s->setRelation('formulas', new Collection([$formula]));
        return $s;
    }
    protected function articulo(int $id): ?Articulo
    {
        if (!in_array($id, [10, 11, 13, 14])) return null;
        $item = new Articulo;
        $item->setRawAttributes(['id' => $id, 'type' => [10 => 'producto_venta', 11 => 'producto_interno', 13 => 'servicio', 14 => 'producto_venta'][$id],
            'precio_c' => '10.01', 'deleted_at' => $id === 14 ? '2026-01-01' : null], true);
        return $item;
    }
    protected function variante(int $id): ?ServicioVariante
    {
        if (!in_array($id, [30, 31, 32])) return null;
        $v = new ServicioVariante;
        $v->setRawAttributes(['id' => $id, 'service_id' => $id === 31 ? 99 : 20, 'price' => '150.01', 'is_active' => $id !== 32], true);
        return $v;
    }
    protected function usuarioExiste(int $id): bool { return in_array($id, [70, 71, 72]); }
    protected function porcentajeGrupo(int $id) { return '10.00'; }
    protected function cita(int $id): ?Cita
    {
        if ($id !== 60) return null;
        $c = new Cita;
        $c->setRawAttributes(['id' => 60, 'service_id' => 20]);
        $c->setRelation('estilistas', new Collection(array_map(function ($id) {
            $u = new Usuario; $u->setRawAttributes(['id' => $id]); return $u;
        }, [70, 71, 72])));
        return $c;
    }
}
