// PreToolUse(Edit|Write|MultiEdit|NotebookEdit): protege archivos sensibles.
//  - ASK  : editar .env reales / qa/auth / *.local.env; modificar una migración ya
//           existente (CLAUDE.md §3: forward-fix, no reescribir migraciones aplicadas).
//  - DENY : escribir en un archivo versionable contenido con forma de secreto real.
// Crear una migración nueva, o cualquier otro archivo normal, pasa sin salida.
import fs from 'node:fs';
import path from 'node:path';
import { readInput, projectDir, ask, deny, relPath, scanText, isSensitiveFile } from './lib.mjs';

const input = readInput();
const ti = input.tool_input ?? {};
const file = ti.file_path ?? ti.notebook_path ?? '';
if (!file) process.exit(0);

const root = projectDir(input);
const rel = relPath(path.isAbsolute(file) ? file : path.join(input.cwd || root, file), root);

// Texto que se va a escribir (Write.content, Edit.new_string, MultiEdit.edits[].new_string).
const newText = [ti.content, ti.new_string, ...(Array.isArray(ti.edits) ? ti.edits.map((e) => e.new_string) : []), ti.new_source]
  .filter((s) => typeof s === 'string')
  .join('\n');

if (isSensitiveFile(rel)) {
  ask(`${rel} es un archivo de entorno/credenciales (ignorado por Git). AGENTS.md: modifícalo solo dentro del alcance autorizado y sin mostrar valores.`);
  process.exit(0);
}

// Un .example o archivo versionable no debe recibir secretos reales.
const hard = scanText(newText).filter((h) => h.severity === 'hard');
if (hard.length) {
  deny(`El contenido para ${rel} contiene algo con forma de ${[...new Set(hard.map((h) => h.name))].join(', ')}. No escribas secretos en archivos versionables; usa el .env local ignorado o el gestor de secretos.`);
  process.exit(0);
}

if (/^database\/migrations\/.+\.php$/.test(rel)) {
  const abs = path.isAbsolute(file) ? file : path.join(root, rel);
  if (fs.existsSync(abs)) {
    ask(`${rel} ya existe: una migración aplicada en un entorno compartido no se reescribe. Crea una migración forward-fix (CLAUDE.md §3), salvo que sea una migración aún sin aplicar/commitear.`);
  }
}
