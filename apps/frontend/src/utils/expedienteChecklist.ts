export interface ChecklistVariable {
  variable: any
  evidencias: any[]
  completo: boolean
}

export interface ChecklistRubro {
  nombre: string
  variables: ChecklistVariable[]
  completos: number
  total: number
}

export interface ChecklistResumen {
  rubros: ChecklistRubro[]
  totalCompletos: number
  totalRequisitos: number
  /** -1 si no falta nada. */
  primerRubroIncompletoIndex: number
}

/**
 * Agrupa la tabla de evaluación (snapshot) por rubro y cruza cada variable
 * con sus evidencias — la misma agrupación la usa el expediente completo
 * (acordeón) y el resumen de progreso en Mis Postulaciones (una sola fuente
 * de verdad para "qué cuenta como completo").
 *
 * Una variable con fuente='etapa' (evento presencial) no requiere documento
 * — cuenta como completa siempre, nunca aparece como pendiente.
 */
export function construirChecklist(tablaSnapshot: any, evidencias: any[]): ChecklistResumen {
  const rubrosSnapshot = tablaSnapshot?.rubros ?? []

  const rubros: ChecklistRubro[] = rubrosSnapshot.map((rubro: any) => {
    const variables: ChecklistVariable[] = (rubro.variables ?? []).map((variable: any) => {
      const evs = evidencias.filter((ev: any) => ev.evidencia?.variable_id === variable.id)
      const completo = variable.fuente === 'etapa' ? true : evs.length > 0
      return { variable, evidencias: evs, completo }
    })
    const completos = variables.filter((v) => v.completo).length
    return { nombre: rubro.nombre, variables, completos, total: variables.length }
  })

  const totalCompletos    = rubros.reduce((acc, r) => acc + r.completos, 0)
  const totalRequisitos   = rubros.reduce((acc, r) => acc + r.total, 0)
  const primerRubroIncompletoIndex = rubros.findIndex((r) => r.completos < r.total)

  return { rubros, totalCompletos, totalRequisitos, primerRubroIncompletoIndex }
}
