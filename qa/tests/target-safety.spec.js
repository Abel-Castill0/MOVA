// @ts-check
//
// F-24A — test de infraestructura, no de la aplicación: garantiza que el
// guard central (qa/lib/enforce-safe-target.mjs) realmente falla cerrado
// ante un host de producción y realmente deja pasar hosts locales. No
// necesita navegador ni servidor — no usa el fixture `page`, así que corre
// rápido incluso sin `php artisan serve` levantado. Se corre igual con
// cualquiera de los dos configs (default o local): ninguno de los dos debe
// romper este archivo, es exactamente lo que valida.
import { test, expect } from '@playwright/test';
import { enforceSafeTarget } from '../lib/enforce-safe-target.mjs';

const OVERRIDE_ENV_VAR = 'I_UNDERSTAND_E2E_REMOTE_TARGET_IS_DANGEROUS';

test.describe('enforceSafeTarget (guard central de targeting)', () => {
  test('localhost pasa sin fricción', () => {
    expect(() => enforceSafeTarget('http://localhost:8000')).not.toThrow();
  });

  test('127.0.0.1 pasa sin fricción', () => {
    expect(() => enforceSafeTarget('http://127.0.0.1:8000')).not.toThrow();
  });

  test('::1 (loopback IPv6) pasa sin fricción', () => {
    expect(() => enforceSafeTarget('http://[::1]:8000')).not.toThrow();
  });

  test('0.0.0.0 NO está en la allowlist — es una dirección de bind, no un target de navegación', () => {
    expect(() => enforceSafeTarget('http://0.0.0.0:8000')).toThrow(/no está en la allowlist local/);
  });

  test('localhost con trailing slash resuelve al mismo host (URL normaliza, no comparamos strings crudos)', () => {
    expect(() => enforceSafeTarget('http://localhost:8000/')).not.toThrow();
  });

  test('localhost con una ruta después del puerto también resuelve al mismo host', () => {
    expect(() => enforceSafeTarget('http://localhost:8000/login')).not.toThrow();
  });

  test('la URL real de producción de MOVA debe fallar cerrado', () => {
    expect(() =>
      enforceSafeTarget('https://mova-production-8750.up.railway.app')
    ).toThrow(/no está en la allowlist local/);
  });

  test('cualquier host remoto arbitrario debe fallar cerrado sin override', () => {
    delete process.env[OVERRIDE_ENV_VAR];
    expect(() => enforceSafeTarget('https://algun-host-externo.com')).toThrow();
  });

  test('un override explícito, exacto y sobre HTTPS SÍ permite un host remoto', () => {
    process.env[OVERRIDE_ENV_VAR] = 'staging.ejemplo.test';
    expect(() => enforceSafeTarget('https://staging.ejemplo.test')).not.toThrow();
    delete process.env[OVERRIDE_ENV_VAR]; // no contaminar otros tests
  });

  test('el override NO aplica si el host no coincide exactamente', () => {
    process.env[OVERRIDE_ENV_VAR] = 'staging.ejemplo.test';
    expect(() => enforceSafeTarget('https://otro-host.test')).toThrow();
    delete process.env[OVERRIDE_ENV_VAR];
  });

  test('el override NO alcanza sobre HTTP plano, aunque el host coincida', () => {
    // Un remoto real nunca debería probarse por HTTP sin cifrar — si alguien
    // exporta el override, igual debe usar HTTPS.
    process.env[OVERRIDE_ENV_VAR] = 'staging.ejemplo.test';
    expect(() => enforceSafeTarget('http://staging.ejemplo.test')).toThrow(/solo se acepta sobre HTTPS/);
    delete process.env[OVERRIDE_ENV_VAR];
  });

  test('un BASE_URL malformado falla con un mensaje claro, no con un TypeError críptico', () => {
    expect(() => enforceSafeTarget('no-es-una-url')).toThrow(/BASE_URL inválido/);
  });
});
