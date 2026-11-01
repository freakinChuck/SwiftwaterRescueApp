// Kopiert die Oberfläche der Einsatz-App nach www/ (Inhalt der iOS-/Android-App).
// Die API und die Verwaltung (admin.html) bleiben auf dem Server der jeweiligen Installation.
import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'www');
const FILES = ['index.html', 'common.js', 'common.css'];

rmSync(out, { recursive: true, force: true });
mkdirSync(out);
for (const f of FILES) cpSync(join(root, f), join(out, f));
console.log(`www/ erstellt: ${FILES.join(', ')}`);
