import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const dist = join(here, '../dist');
const errors = [];

function htmlFiles(dir) {
  const out = [];
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) out.push(...htmlFiles(p));
    else if (e.name.endsWith('.html')) out.push(p);
  }
  return out;
}

function check(cond, msg) { if (!cond) errors.push(msg); }

if (!existsSync(dist)) {
  console.error('dist/ not found — run `npm run build` first.');
  process.exit(1);
}

// Required static artifacts.
for (const f of ['sitemap-index.xml', 'robots.txt', 'llms.txt', 'og-image.png']) {
  check(existsSync(join(dist, f)), `Missing artifact: ${f}`);
}

// Per-page checks.
const files = htmlFiles(dist);
const titles = new Map();
for (const file of files) {
  const html = readFileSync(file, 'utf8');
  const rel = file.replace(dist, '');
  const title = (html.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
  check(title.trim().length > 0, `${rel}: missing <title>`);
  if (title) titles.set(title, (titles.get(title) || 0) + 1);
  check(/<meta name="description" content="[^"]+"/.test(html), `${rel}: missing meta description`);
  check(/<link rel="canonical" href="https:\/\/jeffcaldwell\.ca\/support-portal/.test(html), `${rel}: missing/invalid canonical`);
  check(/<meta property="og:title"/.test(html), `${rel}: missing og:title`);
  check(/<meta property="og:image"/.test(html), `${rel}: missing og:image`);
}

// Duplicate titles.
for (const [t, n] of titles) check(n === 1, `Duplicate <title> across ${n} pages: "${t}"`);

// Structured-data presence on key pages.
const must = [
  ['index.html', 'SoftwareApplication'],
  [join('faq', 'index.html'), 'FAQPage'],
  [join('docs', 'installation', 'index.html'), 'HowTo'],
  [join('docs', 'overview', 'index.html'), 'TechArticle'],
];
for (const [rel, type] of must) {
  const f = join(dist, rel);
  if (!existsSync(f)) { errors.push(`Missing page: ${rel}`); continue; }
  check(readFileSync(f, 'utf8').includes(`"@type":"${type}"`), `${rel}: missing JSON-LD ${type}`);
}

if (errors.length) {
  console.error('SEO check FAILED:\n' + errors.map((e) => ' - ' + e).join('\n'));
  process.exit(1);
}
console.log(`SEO check passed: ${files.length} pages, all artifacts present.`);
