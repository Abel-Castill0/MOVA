import { createHmac } from 'node:crypto';

// P0-C — TOTP RFC 6238 (SHA1, 6 dígitos, 30 s) para el admin QA sintético
// que siembra qa/stabilization-server.php. Nunca se usa contra producción.
export const QA_ADMIN_TOTP_SECRET = 'MOVAQAE2ETOTPSECRETBASE32ONLYAAA';

function base32Decode(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of input.replace(/=+$/, '').toUpperCase()) {
    const v = alphabet.indexOf(c);
    if (v < 0) throw new Error('base32 inválido');
    bits += v.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

export function totp(secret = QA_ADMIN_TOTP_SECRET, now = Date.now()) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)));
  const h = createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const o = h[h.length - 1] & 0xf;
  const code = ((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).toString();
  return code.padStart(6, '0');
}

/** Tras el login: si el servidor pide el challenge MFA admin, lo resuelve. */
export async function passAdminMfaIfPrompted(page) {
  await page.waitForURL(/\/dashboard|\/admin\/mfa\/challenge/);
  if (!/\/admin\/mfa\/challenge/.test(page.url())) return;
  await page.locator('#code').fill(totp());
  await page.getByRole('button', { name: 'Verificar' }).click();
  await page.waitForURL(/\/dashboard/);
}
