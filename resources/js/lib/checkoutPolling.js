// Presupuesto de consulta (polling) del checkout de Mercado Pago. Vive aparte de Checkout.vue para poder
// probarse sin navegador (scripts/check-checkout-poll.mjs) y para que el plazo que se muestra al usuario
// salga SIEMPRE de los mismos números que usa el polling real.

// 3DS CHALLENGE: la ventana oficial del Challenge es de ~5 minutos; Yape casi siempre resuelve en los primeros escalones.
export const MAX_POLL_ATTEMPTS = 46

// Backoff progresivo: agresivo al inicio (la mayoría de los pagos Yape se resuelven en segundos) y más espaciado después.
export const POLL_INTERVAL_STEPS = [
  { afterAttempt: 0, ms: 3000 },
  { afterAttempt: 10, ms: 5000 },
  { afterAttempt: 20, ms: 8000 },
  { afterAttempt: 30, ms: 10000 },
]

/** Espera (ms) antes del próximo poll, dado cuántos se han hecho ya. */
export function pollIntervalMs(attempts) {
  let ms = POLL_INTERVAL_STEPS[0].ms
  for (const step of POLL_INTERVAL_STEPS) {
    if (attempts >= step.afterAttempt) ms = step.ms
  }

  return ms
}

/** Segundos totales hasta que se hace el ÚLTIMO poll permitido (suma de las esperas previas a cada poll). */
export function pollBudgetSeconds() {
  let total = 0
  for (let attempts = 0; attempts < MAX_POLL_ATTEMPTS; attempts++) {
    total += pollIntervalMs(attempts)
  }

  return total / 1000
}

/** Texto del plazo mostrado al usuario, derivado del presupuesto real (nunca escrito a mano). */
export function pollBudgetLabel() {
  const minutes = Math.max(1, Math.round(pollBudgetSeconds() / 60))

  return minutes === 1 ? '1 minuto' : `${minutes} minutos`
}

/** 'continue' mientras queden polls; 'exhausted' cuando ya se hicieron todos y el estado sigue sin resolverse. */
export function pollOutcome(attemptsDone) {
  return attemptsDone >= MAX_POLL_ATTEMPTS ? 'exhausted' : 'continue'
}
