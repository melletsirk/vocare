import type { StepperStep } from '@/components/ui/Stepper.vue'

const PASOS: StepperStep[] = [
  { key: 'preparar',   label: 'Preparar' },
  { key: 'enviada',    label: 'Enviada' },
  { key: 'evaluacion', label: 'En evaluación' },
  { key: 'resultado',  label: 'Resultado' },
]

/**
 * Traduce el estado real de una postulación (y si ya tiene resultado
 * publicado) al paso del stepper — una sola fuente de verdad para Mis
 * Postulaciones y el detalle de postulación, que muestran el mismo progreso
 * en dos formatos distintos.
 */
export function pasoActualDe(postulacion: any, tieneResultado: boolean): string {
  if (!postulacion.fecha_envio) return 'preparar'
  if (tieneResultado) return 'resultado'
  return 'evaluacion'
}

export function pasosConEstado(postulacion: any): StepperStep[] {
  if (postulacion.estado === 'rechazada') {
    return PASOS.map((p) => (p.key === 'evaluacion' ? { ...p, state: 'error' } : p))
  }
  return PASOS
}
