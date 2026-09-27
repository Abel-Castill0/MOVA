import { execFileSync } from 'node:child_process';

/**
 * AZ-3G0-A: los specs corren dentro del container e2e_qa (PHP 8.3 local, ver
 * docker/qa-e2e-serve.sh), así que esto siempre invoca un binario `php`
 * local — nunca Docker CLI ni una ruta específica de una máquina. QA_PHP_BIN
 * existe solo para apuntar a un binario distinto de `php` si algún día se
 * corre este mismo helper fuera del container (host con PHP >= 8.2 en PATH).
 */
export function runPhp(args, options) {
  const bin = process.env.QA_PHP_BIN || 'php';
  return execFileSync(bin, args, options);
}
