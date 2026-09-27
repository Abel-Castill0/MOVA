// @ts-check
//
// Regresión XSS del chatbot Movi en un navegador REAL: ChatbotWidget.vue usa
// v-html para renderizar negritas y las "pills" de acción, así que el texto
// del proveedor de IA (no confiable) tiene que llegar al DOM escapado. Este
// spec monta el widget compilado de verdad en la landing y responde el POST
// de MOVA con payloads hostiles vía page.route — nunca se llama a Gemini ni
// al backend (el chatbot está apagado por defecto; aquí no importa).
//
// Prueba: ningún payload ejecuta JS (window.__xss sigue undefined, ningún
// dialog), no aparece ninguna etiqueta <script>/<img>/<iframe> nueva dentro
// del chat, y el texto se ve literal.

import { test, expect } from '@playwright/test';

const PAYLOADS = [
  '<script>window.__xss = "script"</script>',
  '<img src=x onerror="window.__xss=\'img\'">',
  '**<img src=x onerror="window.__xss=\'bold-img\'">**',
  '<iframe src="javascript:window.parent.__xss=\'iframe\'"></iframe>',
];

test.describe('Chatbot Movi — el texto del proveedor nunca se ejecuta', () => {
  for (const payload of PAYLOADS) {
    test(`escapa ${payload.slice(0, 32)}…`, async ({ page }) => {
      const dialogs = [];
      page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });

      await page.route('**/chatbot/message', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, reply: payload }),
      }));

      await page.goto('/');
      // Teclado, no clic: el lanzador flotante está animado (hover/escala) y
      // la ruta de teclado es la que de verdad importa para accesibilidad.
      await page.getByRole('button', { name: 'Abrir chat con Movi' }).focus();
      await page.keyboard.press('Enter');
      const input = page.getByLabel('Escribe tu consulta a Movi');
      await expect(input).toBeVisible();
      await input.fill('hola');
      await input.press('Enter');

      const chat = page.getByRole('dialog', { name: 'Ventana de chat con Movi' })
        .or(page.locator('[aria-label="Ventana de chat con Movi"]'));

      // El payload aparece como texto literal (escapado), no interpretado.
      const visibleText = payload.replace(/\*\*/g, '');
      await expect(chat.getByText(visibleText, { exact: false }).first()).toBeVisible({ timeout: 10_000 });

      await page.waitForTimeout(300);
      expect(await page.evaluate(() => window.__xss)).toBeUndefined();
      expect(dialogs).toEqual([]);
      expect(await chat.locator('script, img[src="x"], iframe').count()).toBe(0);
    });
  }
});
