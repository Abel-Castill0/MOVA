// Fuente única para el estado de RechargeRequest — antes duplicada, con el
// mismo color pero nombres de función distintos, en Teacher/Credits/Index.vue
// (rechargeLabel/rechargeBadge) y Admin/Recharges/Index.vue (statusLabel/
// statusBadge). Mismo patrón que utils/statusColors.js, dominio distinto
// (recarga de créditos, no clase/solicitud) — no se fusiona con ese archivo
// porque son dos ciclos de vida de negocio independientes.
const RECHARGE_STATUS_STYLES = {
  pending: { label: 'Pendiente', color: 'bg-amber-50 text-amber-700' },
  approved: { label: 'Aprobada', color: 'bg-green-50 text-green-700' },
  rejected: { label: 'Rechazada', color: 'bg-red-50 text-red-700' },
  reversed: { label: 'Revertida', color: 'bg-rose-50 text-rose-700' },
}

const FALLBACK = { label: null, color: 'bg-slate-100 text-slate-700' }

export function rechargeStatusStyle(status) {
  return RECHARGE_STATUS_STYLES[status] ?? { ...FALLBACK, label: status }
}
