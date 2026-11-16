// Bloqueo compartido: un modal no debe desbloquear la página mientras
// siga abierto otro modal o el drawer móvil de navegación.
let owners = 0;
let previousOverflow = '';

export function lockBodyScroll() {
  if (typeof document === 'undefined' || !document.body) return () => {};
  if (owners === 0) previousOverflow = document.body.style.overflow;
  owners += 1;
  document.body.style.overflow = 'hidden';

  let released = false;
  return () => {
    if (released) return;
    released = true;
    owners = Math.max(0, owners - 1);
    if (owners === 0) {
      document.body.style.overflow = previousOverflow;
      previousOverflow = '';
    }
  };
}
