# Sitio de arquitectura de MOVA

Sitio HTML de una sola página con **17 diagramas** (despliegue y red, ER, estados, secuencias, mapa de APIs), índice lateral, tema claro/oscuro y mapeo a normas ISO. Los diagramas se dibujan en el navegador con Mermaid 11; no hay servidor de aplicación ni dependencias de Node.

| Archivo | Qué es |
|---|---|
| `index.html` | Sitio generado (no editar a mano) |
| `build.py` | Generador (Python 3, solo biblioteca estándar) |
| `diagrams/*.mmd` | Diagramas propios del sitio: mapa de APIs, red, webhook |
| `normas.md` | Mapeo diagrama → ISO/IEC 25010, 29119, 27001, 27701, 42001 e ISO 22301 (se incrusta en el sitio) |

Los demás diagramas **no se duplican**: se extraen de los bloques ```` ```mermaid ```` de `../01-deployment.md`, `../02-data-model.md`, `../03-state-machines.md` y `../04-flows.md`.

## Cómo abrirlo

Necesita Internet para cargar Mermaid (`cdn.jsdelivr.net`) y Tailwind (`cdn.tailwindcss.com`).

```bash
python -m http.server 8765 --directory docs/architecture/site
```

Abre <http://127.0.0.1:8765/index.html>. También funciona con doble clic sobre `index.html` en la mayoría de navegadores; si el tuyo bloquea módulos desde `file://`, usa el comando anterior.

## Cómo actualizarlo

1. Cambia el diagrama en su fuente: el bloque Mermaid del `.md` correspondiente o el `.mmd` en `diagrams/`.
2. Si añades o mueves un diagrama, edita la lista `DIAGRAMS` de `build.py` (id, categoría, título, nota y etiquetas ISO) y, si el origen es un `.md`, el índice del bloque.
3. Regenera:

   ```bash
   python docs/architecture/site/build.py
   ```

4. Valida los diagramas nuevos o modificados con Mermaid CLI y revisa la consola del navegador:

   ```bash
   npx -y @mermaid-js/mermaid-cli@11.4.2 -i docs/architecture/site/diagrams/<archivo>.mmd -o /tmp/out.svg
   ```

5. Actualiza `normas.md` si cambian los controles, los gaps o el RTO/RPO.

## Trampas conocidas de Mermaid

- No uses `;` dentro del texto de un mensaje de secuencia (termina la sentencia).
- En ER, los comentarios de atributo van entre comillas dobles y sin `&`.
- En `<script type="text/plain">` el código **no** se escapa como HTML (el generador ya lo hace bien).

## Alcance y límites

- Estado: CURRENT, derivado del código de `master` y del Ledger (2026-10-19).
- Las etiquetas ISO son un mapeo orientativo de ingeniería; **MOVA no está certificada**.
- Para ISO/IEC 42001 se usa la edición **2023** (la vigente); no existe una edición 2025.
