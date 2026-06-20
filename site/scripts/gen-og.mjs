import sharp from 'sharp';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const svg = readFileSync(join(here, '../src/assets/og-image.svg'));
const outDir = join(here, '../public');
mkdirSync(outDir, { recursive: true });

await sharp(svg).png().toFile(join(outDir, 'og-image.png'));
console.log('Generated public/og-image.png');
