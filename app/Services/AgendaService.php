<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\{Cita, Servicio, Usuario, Venta};
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mutex por estilista, compartido por agenda y transiciones de ventas. */
class AgendaService
{
    private const OCUPAN = ['pendiente', 'completada'];

    public function reservar(array $data): Cita
    {
        // Solo escrituras locales: es seguro reintentar un deadlock, sin efectos externos.
        return DB::transaction(function () use ($data) {
            $ids = $this->ids($data['stylists']);
            $this->bloquearEstilistas($ids);
            $servicio = Servicio::findOrFail($data['service_id']);
            $duracion = $this->duracion($servicio->getRawOriginal('duration'));
            $inicio = CarbonImmutable::parse($data['appointment_date'], config('app.timezone'))
                ->setTimezone(config('app.timezone'));
            $this->sinConflictos($ids, $inicio, $duracion);
            $cita = Cita::create([
                'client_id' => $data['client_id'], 'service_id' => $servicio->id,
                'appointment_date' => $inicio->format('Y-m-d H:i:s'),
                'duration_minutes' => $duracion, 'notes' => $data['notes'] ?? null,
                'status' => 'pendiente',
            ]);
            $cita->estilistas()->sync($ids);
            return $cita->refresh();
        }, 3);
    }

    public function cambiarEstado(int $id, string $estado): Cita
    {
        if (!in_array($estado, ['pendiente', 'completada', 'cancelada', 'no_asistio'], true)) {
            throw ValidationException::withMessages(['status' => 'Estado de cita no válido.']);
        }
        return DB::transaction(function () use ($id, $estado) {
            $cita = $this->bloquearCitas([$id])->get($id);
            if (in_array($estado, ['cancelada', 'no_asistio'], true)) {
                // Lectura actual. Ventas adquiere antes los mismos mutex y la cita.
                $vigente = Venta::where('appointment_id', $id)
                    ->where('status', '<>', Venta::ESTADO_ANULADA)->orderBy('id')->lockForUpdate()->first();
                if ($vigente) throw ValidationException::withMessages([
                    'financial_link' => 'La cita tiene una venta vigente o pendiente; no puede liberar su horario desde agenda.',
                ]);
            }
            if (in_array($estado, self::OCUPAN, true) && !in_array($cita->status, self::OCUPAN, true)) {
                $ids = $this->ids($cita->estilistas->modelKeys());
                $this->sinConflictos($ids, CarbonImmutable::parse($cita->appointment_date, config('app.timezone')),
                    $this->duracion($cita->getRawOriginal('duration_minutes')), $cita->id);
            }
            $cita->update(['status' => $estado]);
            return $cita;
        }, 3);
    }

    /** Requiere una transacción exterior; orden: usuarios -> citas -> ventas. */
    public function bloquearCitas(array $ids): Collection
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('Agenda requiere una transacción activa.');
        $ids = $this->ids($ids, false);
        if (!$ids) return collect();
        // Esta lectura solo descubre mutex; NO decide estado, asignación o conflictos.
        $antes = $this->ids(DB::table('appointment_user')->whereIn('appointment_id', $ids)->pluck('user_id')->all(), false);
        if ($antes) $this->bloquearEstilistas($antes);
        $citas = Cita::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($citas->count() !== count($ids)) throw ValidationException::withMessages(['appointment_id' => 'La cita ya no existe.']);
        $pivot = DB::table('appointment_user')->whereIn('appointment_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $actuales = $this->ids($pivot->pluck('user_id')->all(), false);
        if ($actuales !== $antes) throw ValidationException::withMessages(['availability' => 'La asignación cambió. Vuelva a consultar la agenda.']);
        foreach ($citas as $cita) {
            // Evitar una carga de relación por snapshot anterior de REPEATABLE READ.
            $asignados = $pivot->where('appointment_id', $cita->id)->pluck('user_id')->all();
            $cita->setRelation('estilistas', Usuario::whereIn('id', $asignados)->orderBy('id')->lockForUpdate()->get());
        }
        return $citas;
    }

    public function validarParaFacturar(array $ids): Collection
    {
        try {
            $citas = $this->bloquearCitas($ids);
        } catch (ValidationException $error) {
            throw new ContabilidadException($error->validator->errors()->first());
        }
        foreach ($citas as $cita) {
            if (!in_array($cita->status, self::OCUPAN, true)) throw new ContabilidadException(
                'La cita #'.$cita->id.' está cancelada o marcada no_asistio. Reactívela primero desde agenda validando disponibilidad.'
            );
        }
        return $citas;
    }

    public function completarDesdeVenta(array $ids): void
    {
        foreach ($this->validarParaFacturar($ids) as $cita) {
            if ($cita->status !== 'completada') $cita->update(['status' => 'completada']);
        }
    }

    /** Aprobación/anulación: descubrir vínculo, mutex, cita y finalmente venta actual. */
    public function bloquearVenta(int $id, bool $paraFacturar): Venta
    {
        $anterior = Venta::findOrFail($id);
        $ids = $anterior->appointment_id ? [(int)$anterior->appointment_id] : [];
        if ($paraFacturar) $this->validarParaFacturar($ids);
        else $this->bloquearCitas($ids);
        $venta = Venta::lockForUpdate()->findOrFail($id);
        if ($venta->appointment_id !== $anterior->appointment_id) throw new ContabilidadException('El vínculo de la cita cambió. Vuelva a consultar la venta.');
        return $venta;
    }

    public function restaurarTrasAnulacion(int $id): void
    {
        $cita = $this->bloquearCitas([$id])->get($id);
        // Conserva la regla existente de refacturación: ambos estados ocupan igual.
        if ($cita->status === 'completada') $cita->update(['status' => 'pendiente']);
    }

    private function bloquearEstilistas(array $ids): void
    {
        $usuarios = Usuario::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($usuarios->count() !== count($ids)) throw ValidationException::withMessages(['stylists' => 'Uno de los estilistas ya no existe.']);
    }

    private function ids(array $ids, bool $required = true): array
    {
        foreach ($ids as $id) if (filter_var($id, FILTER_VALIDATE_INT) === false || (int)$id <= 0) throw ValidationException::withMessages(['stylists' => 'Identificador de cita o estilista no válido.']);
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC);
        if ($required && !$ids) throw ValidationException::withMessages(['stylists' => 'Seleccione al menos un estilista.']);
        return $ids;
    }

    private function duracion(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) throw ValidationException::withMessages(['duration_minutes' => 'El servicio debe tener una duración positiva en minutos.']);
        return (int)$value;
    }

    private function sinConflictos(array $ids, CarbonImmutable $inicio, int $duracion, ?int $excluir = null): void
    {
        $fin = $inicio->addMinutes($duracion);
        if ($inicio->year < 1000 || $fin->year > 9999) throw ValidationException::withMessages(['appointment_date' => 'El intervalo está fuera del rango permitido.']);
        // JOIN + lectura bloqueante: datos actuales, incluso si ya existía un snapshot.
        $conflicto = DB::table('appointments as a')->join('appointment_user as p', 'p.appointment_id', '=', 'a.id')
            ->whereIn('p.user_id', $ids)->whereIn('a.status', self::OCUPAN)
            ->when($excluir, fn($q) => $q->where('a.id', '<>', $excluir))
            ->where('a.appointment_date', '<', $fin->format('Y-m-d H:i:s'))
            ->whereRaw('DATE_ADD(a.appointment_date, INTERVAL a.duration_minutes MINUTE) > ?', [$inicio->format('Y-m-d H:i:s')])
            ->orderBy('a.id')->lockForUpdate()->first(['a.id']);
        if ($conflicto) throw ValidationException::withMessages(['availability' => 'Uno de los estilistas ya tiene otra cita en ese horario. Seleccione otro horario o estilista.']);
    }
}
