<?php

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\Resultado;
use Illuminate\Support\Collection;

/**
 * ResultadosService — Genera el ranking y publica resultados.
 *
 * Reglas del reglamento (requisitos-sistema.md §5 y §10):
 *  1. Ordenar por puntaje_total DESC.
 *  2. Primer lugar = ganador, siguientes = reserva (hasta MAX_RESERVAS), resto = no_ganador.
 *  3. Empate exacto en una posición que define ganador/reserva → NO se resuelve
 *     automáticamente. Queda en estado `empate_pendiente` hasta que la comisión
 *     registra manualmente el orden decidido (ver resolverEmpate()), con
 *     trazabilidad de quién decidió y cuándo. No existe sorteo ni
 *     aleatoriedad real en ningún punto de este flujo — el orden entre
 *     empatados, mientras no se resuelva, es simplemente el de inserción en
 *     BD y NO debe usarse como decisión de negocio.
 *  4. Si no hay postulantes o el mejor puntaje no alcanza el mínimo → plaza desierta.
 *
 * El orden de reserva importa operativamente: si el ganador declina o el
 * primer reserva ya no está disponible, la comisión contacta al siguiente en
 * el orden — por eso un empate en cualquier posición contactable
 * (1..1+MAX_RESERVAS) requiere decisión humana explícita, no solo el empate
 * en la posición de ganador.
 */
class ResultadosService
{
    // N° máximo de reservas: confirmado verbalmente por el cliente
    // (Vicerrectorado Académico), 2026-07-21 — no está escrito en
    // requisitos-sistema.md ni en tablas-evaluacion-convocatorias.md. Dejar
    // registrado aquí para que sea trazable si se cuestiona el valor.
    const MAX_RESERVAS = 3;

    // Fallback SOLO mientras un anexo no tenga su propio
    // puntaje_minimo_aprobatorio configurado (tabla_snapshot). Los mínimos
    // reales por anexo (55/52/60 + sub-mínimos de "Aptitud Docente") siguen
    // pendientes de confirmación del cliente — ver CONTEXTO.md. Este
    // fallback preserva el comportamiento actual (que corrió con 50 sin
    // reclamos) hasta que cada anexo tenga su mínimo real configurado.
    const PUNTAJE_MINIMO_APROBATORIO_FALLBACK = 50.0;

    /**
     * Genera y persiste el ranking de una plaza dentro de una convocatoria.
     *
     * Los grupos empatados cuya posición de inicio cae dentro del rango
     * contactable (1..1+MAX_RESERVAS) se dejan en `empate_pendiente` — todas
     * las filas del grupo comparten la posición de INICIO del grupo hasta
     * que resolverEmpate() asigna el orden final decidido por la comisión.
     */
    public function generarRankingPlaza(Convocatoria $convocatoria, Plaza $plaza): Collection
    {
        // Borrar resultados previos de esta plaza (para poder recalcular)
        Resultado::where('plaza_id', $plaza->id)
            ->where('convocatoria_id', $convocatoria->id)
            ->delete();

        // Obtener evaluaciones cerradas de postulaciones a esta plaza
        $evaluaciones = Evaluacion::whereHas('postulacion', function ($q) use ($plaza) {
                $q->where('plaza_id', $plaza->id)
                  ->whereNull('deleted_at');
            })
            ->where('estado', Evaluacion::ESTADO_CERRADA)
            ->whereNotNull('puntaje_total')
            ->with(['postulacion.convocatoria', 'puntajes'])
            ->get();

        if ($evaluaciones->isEmpty()) {
            // Plaza desierta — sin postulantes evaluados
            Resultado::create([
                'convocatoria_id' => $convocatoria->id,
                'plaza_id'        => $plaza->id,
                'postulacion_id'  => null,
                'evaluacion_id'   => null,
                'puntaje_total'   => 0,
                'posicion'        => 1,
                'estado'          => Resultado::ESTADO_DESIERTA,
            ]);

            $plaza->update(['estado' => 'desierta']);
            return collect();
        }

        $snapshot = $convocatoria->tabla_snapshot ?? [];
        $minimoAprobatorio = $snapshot['puntaje_minimo_aprobatorio']
            ?? self::PUNTAJE_MINIMO_APROBATORIO_FALLBACK;

        // Ordenar por puntaje DESC. Este orden es solo un criterio de
        // agrupación para detectar empates — dentro de un grupo empatado NO
        // se usa para decidir nada (ver bloque de empates abajo).
        $ordenadas = $evaluaciones
            ->sortByDesc('puntaje_total')
            ->values();

        // Elegible = alcanza el mínimo total Y cumple todos los mínimos de
        // sub-rubro (ej. "Aptitud Docente") — el total por sí solo no basta.
        $mejorElegible = $ordenadas->first(
            fn (Evaluacion $ev) => (float) $ev->puntaje_total >= $minimoAprobatorio
                && $this->cumpleMinimosSubRubro($ev, $snapshot)
        );

        // Si nadie alcanza el mínimo (total o de sub-rubro) → desierta.
        // Se reporta el mejor puntaje real (no 0) aunque nadie haya sido
        // elegible, para no perder esa información en el resultado.
        if (!$mejorElegible) {
            Resultado::create([
                'convocatoria_id' => $convocatoria->id,
                'plaza_id'        => $plaza->id,
                'postulacion_id'  => null,
                'evaluacion_id'   => null,
                'puntaje_total'   => (float) $ordenadas->first()->puntaje_total,
                'posicion'        => 1,
                'estado'          => Resultado::ESTADO_DESIERTA,
            ]);
            $plaza->update(['estado' => 'desierta']);
            return collect();
        }

        // Los no-elegibles (no alcanzan mínimo total o de sub-rubro) quedan
        // fuera del ranking contactable — se excluyen antes de agrupar
        // empates, para que un no-elegible con puntaje alto no ocupe una
        // posición de ganador/reserva.
        $ordenadas = $ordenadas->filter(
            fn (Evaluacion $ev) => (float) $ev->puntaje_total >= $minimoAprobatorio
                && $this->cumpleMinimosSubRubro($ev, $snapshot)
        )->values();

        $rangoContactable = 1 + self::MAX_RESERVAS; // posiciones 1..4 (ganador + reservas)
        $resultados = collect();
        $n = $ordenadas->count();
        $i = 0;
        $posicionActual = 0;

        while ($i < $n) {
            // Extender el grupo mientras el puntaje sea exactamente igual
            $j = $i;
            while ($j + 1 < $n
                && (float) $ordenadas[$j + 1]->puntaje_total === (float) $ordenadas[$i]->puntaje_total) {
                $j++;
            }

            $tamanoGrupo   = $j - $i + 1;
            $posicionInicio = $posicionActual + 1;
            $esEmpate       = $tamanoGrupo > 1;
            $requiereDecision = $esEmpate && $posicionInicio <= $rangoContactable;

            if ($requiereDecision) {
                $plaza->update(['estado' => 'en_proceso']); // aún no se puede cubrir/declarar desierta

                for ($k = $i; $k <= $j; $k++) {
                    $evaluacion = $ordenadas[$k];
                    $resultados->push(Resultado::create([
                        'convocatoria_id' => $convocatoria->id,
                        'plaza_id'        => $plaza->id,
                        'postulacion_id'  => $evaluacion->postulacion_id,
                        'evaluacion_id'   => $evaluacion->id,
                        'puntaje_total'   => (float) $evaluacion->puntaje_total,
                        'posicion'        => $posicionInicio, // provisional: inicio del grupo
                        'estado'          => Resultado::ESTADO_EMPATE_PENDIENTE,
                        'empatada'        => true,
                    ]));
                }
            } else {
                for ($k = $i; $k <= $j; $k++) {
                    $posicion = $posicionActual + ($k - $i) + 1;
                    $estado   = $this->estadoParaPosicion($posicion, $rangoContactable);

                    if ($posicion === 1) {
                        $plaza->update(['estado' => 'cubierta']);
                    }

                    $evaluacion = $ordenadas[$k];
                    $resultados->push(Resultado::create([
                        'convocatoria_id' => $convocatoria->id,
                        'plaza_id'        => $plaza->id,
                        'postulacion_id'  => $evaluacion->postulacion_id,
                        'evaluacion_id'   => $evaluacion->id,
                        'puntaje_total'   => (float) $evaluacion->puntaje_total,
                        'posicion'        => $posicion,
                        'estado'          => $estado,
                        'empatada'        => $esEmpate,
                    ]));
                }
            }

            $posicionActual += $tamanoGrupo;
            $i = $j + 1;
        }

        return $resultados;
    }

    /**
     * La comisión registra el orden decidido manualmente para un grupo de
     * postulaciones empatadas (estado `empate_pendiente`), identificado por
     * la posición de inicio del grupo dentro del ranking de la plaza.
     *
     * $ordenPostulacionIds debe contener exactamente los mismos
     * postulacion_id del grupo pendiente, en el orden decidido (primero =
     * mejor posición). Registra quién decidió y cuándo para auditoría.
     */
    public function resolverEmpate(
        Convocatoria $convocatoria,
        Plaza $plaza,
        int $posicionInicio,
        array $ordenPostulacionIds,
        int $decididoPorId
    ): Collection {
        $pendientes = Resultado::where('convocatoria_id', $convocatoria->id)
            ->where('plaza_id', $plaza->id)
            ->where('estado', Resultado::ESTADO_EMPATE_PENDIENTE)
            ->where('posicion', $posicionInicio)
            ->get()
            ->keyBy('postulacion_id');

        if ($pendientes->isEmpty()) {
            throw new \RuntimeException('No hay un grupo empatado pendiente en esa posición.');
        }

        if ($pendientes->keys()->sort()->values()->toArray() !== collect($ordenPostulacionIds)->sort()->values()->toArray()) {
            throw new \InvalidArgumentException('El orden proporcionado no coincide exactamente con el grupo empatado pendiente.');
        }

        $rangoContactable = 1 + self::MAX_RESERVAS;
        $resultados = collect();

        foreach ($ordenPostulacionIds as $index => $postulacionId) {
            $posicionFinal = $posicionInicio + $index;
            $estado        = $this->estadoParaPosicion($posicionFinal, $rangoContactable);

            $resultado = $pendientes->get($postulacionId);
            $resultado->update([
                'posicion'     => $posicionFinal,
                'estado'       => $estado,
                'orden_manual' => true,
                'decidido_por' => $decididoPorId,
                'decidido_en'  => now(),
            ]);

            if ($posicionFinal === 1) {
                $plaza->update(['estado' => 'cubierta']);
            }

            $resultados->push($resultado->fresh());
        }

        return $resultados;
    }

    private function estadoParaPosicion(int $posicion, int $rangoContactable): string
    {
        if ($posicion === 1) {
            return Resultado::ESTADO_GANADOR;
        }

        return $posicion <= $rangoContactable
            ? Resultado::ESTADO_RESERVA
            : Resultado::ESTADO_NO_GANADOR;
    }

    /**
     * ¿Esta evaluación cumple todos los mínimos de sub-rubro configurados
     * para el anexo (ej. "Aptitud Docente")? El mínimo total no basta —
     * confirmado por el cliente que además hay un piso por sub-rubro,
     * potencialmente sobre un grupo de rubros (rollup), no solo uno.
     *
     * Sin `minimos_subrubro` configurado (anexo aún sin los valores
     * confirmados por el cliente) → true, no bloquea nada todavía.
     */
    private function cumpleMinimosSubRubro(Evaluacion $evaluacion, array $snapshot): bool
    {
        $minimos = $snapshot['minimos_subrubro'] ?? [];

        if (empty($minimos)) {
            return true;
        }

        $puntajePorVariable = $evaluacion->puntajes->keyBy('variable_id');

        foreach ($minimos as $grupo) {
            $sumaGrupo = 0.0;
            $rubroIds  = collect($grupo['rubro_ids'] ?? []);

            foreach ($snapshot['rubros'] as $rubroData) {
                if (!$rubroIds->contains($rubroData['id'])) {
                    continue;
                }

                // Todas las variables de un rubro comparten puntaje_subrubro
                // (calculado en CalculadorService) — basta leerlo de una.
                $primeraVariableId = $rubroData['variables'][0]['id'] ?? null;
                $puntaje = $primeraVariableId ? $puntajePorVariable->get($primeraVariableId) : null;
                $sumaGrupo += (float) ($puntaje->puntaje_subrubro ?? 0);
            }

            if ($sumaGrupo < (float) ($grupo['minimo'] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Publica los resultados de toda la convocatoria (todas las plazas deben
     * tener ranking generado y sin empates pendientes de resolver).
     */
    public function publicarResultados(Convocatoria $convocatoria, int $publicadoPorId): void
    {
        $ahora = now();

        Resultado::where('convocatoria_id', $convocatoria->id)
            ->whereNull('publicado_en')
            ->update([
                'publicado_en' => $ahora,
                'publicado_por' => $publicadoPorId,
            ]);

        $convocatoria->update(['estado' => Convocatoria::ESTADO_CERRADA]);
    }
}
