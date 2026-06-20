# SEO/AEO GitHub Pages Site — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a fast, custom Astro static site (landing page + docs hub) for the `support-portal` project, published to GitHub Pages at `https://jeffcaldwell.ca/support-portal/`, optimized for SEO and AEO.

**Architecture:** An isolated Astro project under `site/` produces a static build. A shared `BaseLayout` injects per-page SEO metadata and JSON-LD via a `Seo` component backed by a framework-agnostic `seo.ts` module (unit-tested). Docs pages are a Markdown content collection rendered through a `DocsLayout`. A post-build Node script asserts the SEO/AEO artifacts exist. GitHub Actions builds and deploys.

**Tech Stack:** Astro v6 (static), Tailwind CSS v4 (`@tailwindcss/vite`), `@astrojs/sitemap`, `@fontsource-variable/inter`, Vitest (unit tests), `sharp` (OG image rasterization), GitHub Actions (`withastro/action@v6` + `actions/deploy-pages@v5`).

## Global Constraints

- Node version: **24** (GitHub Action default); Astro **v6**.
- `site: 'https://jeffcaldwell.ca'`, `base: '/support-portal'`, `trailingSlash: 'ignore'` — copy verbatim into `astro.config.mjs`.
- **Do NOT add a `CNAME` file** to this repo (the apex domain is owned by the user-pages repo).
- All page source lives under `site/`. **Do not modify the PHP app** (only an optional README link).
- All internal links/assets use the base prefix via `withBase(...)` or `import.meta.env.BASE_URL`; all metadata URLs are absolute via `absUrl(...)`. Never hardcode root-absolute `/foo` paths.
- **No fake structured data**: never emit `aggregateRating`, reviews, or testimonials that don't exist.
- Accessibility: WCAG AA contrast in light + dark, visible focus states, keyboard-navigable, `alt` on images, `prefers-reduced-motion` respected, skip-to-content link.
- Project facts to use in copy/metadata: **free**, **GPL v3**, **self-hosted**, requires **PHP 8.1+** and a **FreeScout instance with the API module**. Repo: `https://github.com/jeffcaldwellca/support-portal`.
- Work happens on the existing `gh-pages-seo-site` branch. Commit after each task. Leave the repo's pre-existing unrelated working-tree changes untouched (only `git add` the files named in each task).

---

### Task 1: Scaffold Astro project + config + tooling

**Files:**
- Create: `site/` (Astro minimal scaffold), `site/astro.config.mjs`, `site/package.json` (scripts), `site/vitest.config.ts`
- Create: `site/.gitignore`

**Interfaces:**
- Produces: a buildable Astro project rooted at `site/`; npm scripts `dev`, `build`, `preview`, `test`, `check:seo`, `verify`; config exporting `site`/`base`/`trailingSlash` and Tailwind + sitemap.

- [ ] **Step 1: Scaffold the project (non-interactive)**

Run from the repo root:

```bash
npm create astro@latest site -- --template minimal --install --no-git --skip-houston --yes
```

- [ ] **Step 2: Add Tailwind v4 and the sitemap integration**

```bash
cd site && npx astro add tailwind sitemap --yes
```

- [ ] **Step 3: Add remaining dependencies**

```bash
npm install @fontsource-variable/inter
npm install -D vitest sharp
```

- [ ] **Step 4: Overwrite `site/astro.config.mjs` with the exact config**

```js
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  site: 'https://jeffcaldwell.ca',
  base: '/support-portal',
  trailingSlash: 'ignore',
  integrations: [sitemap()],
  vite: { plugins: [tailwindcss()] },
});
```

- [ ] **Step 5: Set the npm scripts in `site/package.json`**

Replace the `"scripts"` block with:

```json
"scripts": {
  "dev": "astro dev",
  "build": "astro build",
  "preview": "astro preview",
  "prebuild": "node scripts/gen-og.mjs",
  "test": "vitest run",
  "check:seo": "node scripts/check-seo.mjs",
  "verify": "npm run build && npm run check:seo"
}
```

- [ ] **Step 6: Create `site/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'node',
  },
});
```

- [ ] **Step 7: Append to `site/.gitignore`**

Ensure these lines exist (the scaffold creates most):

```
dist/
node_modules/
.astro/
```

- [ ] **Step 8: Verify the build works**

The `prebuild` script does not exist yet, so temporarily run the build directly:

Run: `cd site && npx astro build`
Expected: build succeeds and writes `site/dist/` (the scaffold's default page).

- [ ] **Step 9: Commit**

```bash
git add site/.gitignore site/package.json site/package-lock.json site/astro.config.mjs site/vitest.config.ts site/src site/public site/tsconfig.json
git commit -m "chore(site): scaffold Astro project with Tailwind, sitemap, vitest"
```

---

### Task 2: Design tokens + global styles + fonts

**Files:**
- Create/Overwrite: `site/src/styles/global.css`

**Interfaces:**
- Produces: Tailwind v4 loaded with class-based dark mode; brand design tokens exposed as Tailwind theme values (`--color-brand`, `--font-sans`); base body styles.

- [ ] **Step 1: Write `site/src/styles/global.css`**

```css
@import "tailwindcss";

/* Enable class-based dark mode (Tailwind v4 defaults to media queries). */
@custom-variant dark (&:where(.dark, .dark *));

@theme {
  --font-sans: "Inter Variable", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;

  --color-brand: #2563eb;
  --color-brand-dark: #1d4ed8;
  --color-ink: #111212;
}

html {
  font-family: var(--font-sans);
}

@media (prefers-reduced-motion: reduce) {
  html { scroll-behavior: auto; }
  *, *::before, *::after {
    animation-duration: 0.001ms !important;
    transition-duration: 0.001ms !important;
  }
}

/* Visible focus ring for keyboard users. */
:focus-visible {
  outline: 2px solid var(--color-brand);
  outline-offset: 2px;
}
```

- [ ] **Step 2: Verify Tailwind compiles**

Run: `cd site && npx astro build`
Expected: build succeeds (global.css is imported by a layout in a later task; for now confirm no Tailwind parse error by temporarily importing it — skip if not yet referenced). If unreferenced, this step just confirms the file has no syntax errors via `npx astro check` is not required; proceed.

- [ ] **Step 3: Commit**

```bash
git add site/src/styles/global.css
git commit -m "feat(site): add brand design tokens and global styles"
```

---

### Task 3: SEO module with unit tests (TDD)

**Files:**
- Create: `site/src/data/seo.ts`
- Test: `site/src/data/seo.test.ts`

**Interfaces:**
- Produces:
  - `SITE` — constants object: `{ origin, base, name, shortName, tagline, description, repo, ogImage, locale, googleSiteVerification, bingSiteVerification }`
  - `absUrl(path?: string): string` — absolute URL including origin + base
  - `withBase(path?: string): string` — site-relative URL including base prefix
  - `softwareApplicationLd(): object`, `webSiteLd(): object`, `faqPageLd(items: {q:string;a:string}[]): object`, `howToLd(input: {name:string;description:string;steps:string[]}): object`, `techArticleLd(input: {title:string;description:string;path:string}): object`, `breadcrumbLd(items: {name:string;path:string}[]): object`

- [ ] **Step 1: Write the failing tests**

`site/src/data/seo.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { absUrl, withBase, faqPageLd, howToLd, breadcrumbLd, softwareApplicationLd } from './seo';

describe('absUrl', () => {
  it('returns base root for "/"', () => {
    expect(absUrl('/')).toBe('https://jeffcaldwell.ca/support-portal/');
  });
  it('joins a sub path under base', () => {
    expect(absUrl('/faq')).toBe('https://jeffcaldwell.ca/support-portal/faq');
  });
  it('normalizes a path without a leading slash', () => {
    expect(absUrl('docs/overview')).toBe('https://jeffcaldwell.ca/support-portal/docs/overview');
  });
});

describe('withBase', () => {
  it('prefixes the base path', () => {
    expect(withBase('/docs/overview')).toBe('/support-portal/docs/overview');
  });
  it('returns base root for "/"', () => {
    expect(withBase('/')).toBe('/support-portal/');
  });
});

describe('json-ld builders', () => {
  it('faqPageLd emits a FAQPage with mainEntity questions', () => {
    const ld = faqPageLd([{ q: 'Is it free?', a: 'Yes.' }]) as any;
    expect(ld['@type']).toBe('FAQPage');
    expect(ld.mainEntity[0]['@type']).toBe('Question');
    expect(ld.mainEntity[0].acceptedAnswer.text).toBe('Yes.');
  });
  it('howToLd emits ordered HowToStep items', () => {
    const ld = howToLd({ name: 'Install', description: 'Steps', steps: ['A', 'B'] }) as any;
    expect(ld['@type']).toBe('HowTo');
    expect(ld.step).toHaveLength(2);
    expect(ld.step[0]['@type']).toBe('HowToStep');
  });
  it('breadcrumbLd numbers positions from 1', () => {
    const ld = breadcrumbLd([{ name: 'Home', path: '/' }, { name: 'FAQ', path: '/faq' }]) as any;
    expect(ld.itemListElement[0].position).toBe(1);
    expect(ld.itemListElement[1].item).toBe('https://jeffcaldwell.ca/support-portal/faq');
  });
  it('softwareApplicationLd marks the app as free', () => {
    const ld = softwareApplicationLd() as any;
    expect(ld['@type']).toBe('SoftwareApplication');
    expect(ld.offers.price).toBe('0');
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd site && npm test`
Expected: FAIL — `./seo` cannot be resolved / exports undefined.

- [ ] **Step 3: Implement `site/src/data/seo.ts`**

```ts
export const SITE = {
  origin: 'https://jeffcaldwell.ca',
  base: '/support-portal',
  name: 'Support Portal for FreeScout',
  shortName: 'Support Portal',
  tagline: 'A friendly self-hosted customer portal for FreeScout',
  description:
    'Support Portal is a free, self-hosted, open-source end-user portal for FreeScout. It gives your users a clean form-based interface to submit support tickets, attach files, and track status — with LDAP or local authentication.',
  repo: 'https://github.com/jeffcaldwellca/support-portal',
  ogImage: '/og-image.png',
  locale: 'en_US',
  googleSiteVerification: '',
  bingSiteVerification: '',
} as const;

function normalize(path = '/'): string {
  if (path === '/' || path === '') return '/';
  return path.startsWith('/') ? path : `/${path}`;
}

const baseNoSlash = SITE.base.replace(/\/$/, '');

export function withBase(path = '/'): string {
  const clean = normalize(path);
  return clean === '/' ? `${baseNoSlash}/` : `${baseNoSlash}${clean}`;
}

export function absUrl(path = '/'): string {
  return `${SITE.origin}${withBase(path)}`;
}

export function softwareApplicationLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: SITE.name,
    description: SITE.description,
    applicationCategory: 'BusinessApplication',
    operatingSystem: 'Linux, Docker',
    softwareRequirements: 'PHP 8.1+, FreeScout with the API module',
    url: absUrl('/'),
    codeRepository: SITE.repo,
    license: 'https://www.gnu.org/licenses/gpl-3.0.html',
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
  };
}

export function webSiteLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: SITE.name,
    url: absUrl('/'),
  };
}

export function faqPageLd(items: { q: string; a: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((it) => ({
      '@type': 'Question',
      name: it.q,
      acceptedAnswer: { '@type': 'Answer', text: it.a },
    })),
  };
}

export function howToLd(input: { name: string; description: string; steps: string[] }) {
  return {
    '@context': 'https://schema.org',
    '@type': 'HowTo',
    name: input.name,
    description: input.description,
    step: input.steps.map((text, i) => ({
      '@type': 'HowToStep',
      position: i + 1,
      text,
    })),
  };
}

export function techArticleLd(input: { title: string; description: string; path: string }) {
  return {
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    headline: input.title,
    description: input.description,
    url: absUrl(input.path),
  };
}

export function breadcrumbLd(items: { name: string; path: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((it, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: it.name,
      item: absUrl(it.path),
    })),
  };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd site && npm test`
Expected: PASS (all tests green).

- [ ] **Step 5: Commit**

```bash
git add site/src/data/seo.ts site/src/data/seo.test.ts
git commit -m "feat(site): add SEO/JSON-LD module with unit tests"
```

---

### Task 4: Head/SEO components + BaseLayout + Header/Footer/ThemeToggle

**Files:**
- Create: `site/src/components/JsonLd.astro`, `site/src/components/Seo.astro`, `site/src/components/Header.astro`, `site/src/components/Footer.astro`, `site/src/components/ThemeToggle.astro`
- Create: `site/src/layouts/BaseLayout.astro`

**Interfaces:**
- Consumes: `SITE`, `absUrl`, `withBase` from `../data/seo`.
- Produces: `BaseLayout` with props `{ title: string; description: string; path: string; ogType?: string; jsonLd?: object | object[] }` and a default `<slot />` for page content.

- [ ] **Step 1: Create `site/src/components/JsonLd.astro`**

```astro
---
interface Props { data: object; }
const { data } = Astro.props;
---
<script type="application/ld+json" set:html={JSON.stringify(data)} />
```

- [ ] **Step 2: Create `site/src/components/Seo.astro`**

```astro
---
import { SITE, absUrl } from '../data/seo';
import JsonLd from './JsonLd.astro';

interface Props {
  title: string;
  description: string;
  path: string;
  ogType?: string;
  jsonLd?: object | object[];
}
const { title, description, path, ogType = 'website', jsonLd } = Astro.props;
const canonical = absUrl(path);
const fullTitle = path === '/' ? `${SITE.shortName} — ${title}` : `${title} — ${SITE.shortName}`;
const ogImage = absUrl(SITE.ogImage);
const lds = jsonLd ? (Array.isArray(jsonLd) ? jsonLd : [jsonLd]) : [];
---
<title>{fullTitle}</title>
<meta name="description" content={description} />
<link rel="canonical" href={canonical} />
<meta property="og:type" content={ogType} />
<meta property="og:title" content={fullTitle} />
<meta property="og:description" content={description} />
<meta property="og:url" content={canonical} />
<meta property="og:image" content={ogImage} />
<meta property="og:site_name" content={SITE.shortName} />
<meta property="og:locale" content={SITE.locale} />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content={fullTitle} />
<meta name="twitter:description" content={description} />
<meta name="twitter:image" content={ogImage} />
{SITE.googleSiteVerification && <meta name="google-site-verification" content={SITE.googleSiteVerification} />}
{SITE.bingSiteVerification && <meta name="msvalidate.01" content={SITE.bingSiteVerification} />}
{lds.map((ld) => <JsonLd data={ld} />)}
```

- [ ] **Step 3: Create `site/src/components/ThemeToggle.astro`**

```astro
---
---
<button
  id="theme-toggle"
  type="button"
  aria-label="Toggle dark mode"
  class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
>
  <svg class="h-5 w-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
    <circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
  </svg>
  <svg class="hidden h-5 w-5 dark:block" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
  </svg>
</button>
<script is:inline>
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sp-theme', isDark ? 'dark' : 'light');
  });
</script>
```

- [ ] **Step 4: Create `site/src/components/Header.astro`**

```astro
---
import { SITE, withBase } from '../data/seo';
import ThemeToggle from './ThemeToggle.astro';
const nav = [
  { label: 'Features', href: withBase('/#features') },
  { label: 'Docs', href: withBase('/docs/overview') },
  { label: 'FAQ', href: withBase('/faq') },
];
---
<header class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-950/80">
  <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
    <a href={withBase('/')} class="flex items-center gap-2" aria-label={`${SITE.shortName} home`}>
      <img src={withBase('/logo-full.svg')} alt={`${SITE.shortName} logo`} width="40" height="27" class="h-7 w-auto dark:invert" />
      <span class="font-semibold text-slate-900 dark:text-white">{SITE.shortName}</span>
    </a>
    <nav class="flex items-center gap-1 text-sm" aria-label="Primary">
      {nav.map((n) => (
        <a href={n.href} class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">{n.label}</a>
      ))}
      <a href={SITE.repo} class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">GitHub</a>
      <ThemeToggle />
    </nav>
  </div>
</header>
```

- [ ] **Step 5: Create `site/src/components/Footer.astro`**

```astro
---
import { SITE, withBase } from '../data/seo';
const year = new Date().getFullYear();
---
<footer class="mt-20 border-t border-slate-200 py-10 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
  <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 sm:flex-row sm:items-center sm:justify-between">
    <p>© {year} {SITE.name}. Free &amp; open-source under GPL v3.</p>
    <nav class="flex gap-4" aria-label="Footer">
      <a class="hover:text-slate-900 dark:hover:text-white" href={withBase('/docs/overview')}>Docs</a>
      <a class="hover:text-slate-900 dark:hover:text-white" href={withBase('/faq')}>FAQ</a>
      <a class="hover:text-slate-900 dark:hover:text-white" href={SITE.repo}>GitHub</a>
      <a class="hover:text-slate-900 dark:hover:text-white" href="https://freescout.net/">FreeScout</a>
    </nav>
  </div>
</footer>
```

- [ ] **Step 6: Create `site/src/layouts/BaseLayout.astro`**

```astro
---
import '@fontsource-variable/inter';
import '../styles/global.css';
import Header from '../components/Header.astro';
import Footer from '../components/Footer.astro';
import Seo from '../components/Seo.astro';
import { withBase } from '../data/seo';

interface Props {
  title: string;
  description: string;
  path: string;
  ogType?: string;
  jsonLd?: object | object[];
}
const { title, description, path, ogType, jsonLd } = Astro.props;
---
<!doctype html>
<html lang="en" class="scroll-smooth">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" href={withBase('/favicon.ico')} sizes="any" />
    <link rel="icon" type="image/png" sizes="32x32" href={withBase('/favicon-32x32.png')} />
    <link rel="icon" type="image/png" sizes="16x16" href={withBase('/favicon-16x16.png')} />
    <link rel="apple-touch-icon" href={withBase('/apple-touch-icon.png')} />
    <link rel="manifest" href={withBase('/site.webmanifest')} />
    <meta name="theme-color" content="#2563eb" />
    <script is:inline>
      (() => {
        try {
          const s = localStorage.getItem('sp-theme');
          const d = s ? s === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
          document.documentElement.classList.toggle('dark', d);
        } catch (_) {}
      })();
    </script>
    <Seo title={title} description={description} path={path} ogType={ogType} jsonLd={jsonLd} />
  </head>
  <body class="min-h-screen bg-white text-slate-700 antialiased dark:bg-slate-950 dark:text-slate-300">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-white">Skip to content</a>
    <Header />
    <main id="main"><slot /></main>
    <Footer />
  </body>
</html>
```

- [ ] **Step 7: Verify the layout builds (temporary smoke page)**

Replace `site/src/pages/index.astro` contents temporarily:

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout title="Home" description="Smoke test" path="/">
  <h1 class="mx-auto max-w-6xl px-4 py-10 text-3xl font-bold">It builds.</h1>
</BaseLayout>
```

Run: `cd site && npx astro build`
Expected: build succeeds; `site/dist/index.html` contains `<link rel="canonical" href="https://jeffcaldwell.ca/support-portal/">`.

- [ ] **Step 8: Commit**

```bash
git add site/src/components site/src/layouts site/src/pages/index.astro
git commit -m "feat(site): add SEO head, base layout, header, footer, theme toggle"
```

---

### Task 5: Feature + FAQ data (with shape tests)

**Files:**
- Create: `site/src/data/features.ts`, `site/src/data/faq.ts`
- Test: `site/src/data/content.test.ts`

**Interfaces:**
- Produces:
  - `FEATURES: { title: string; blurb: string; icon: string }[]` (icon = inline SVG path `d` string)
  - `FAQ_ITEMS: { q: string; a: string }[]`

- [ ] **Step 1: Write the failing tests**

`site/src/data/content.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { FEATURES } from './features';
import { FAQ_ITEMS } from './faq';

describe('content data', () => {
  it('has at least 6 features each with title, blurb, icon', () => {
    expect(FEATURES.length).toBeGreaterThanOrEqual(6);
    for (const f of FEATURES) {
      expect(f.title.length).toBeGreaterThan(0);
      expect(f.blurb.length).toBeGreaterThan(0);
      expect(f.icon.length).toBeGreaterThan(0);
    }
  });
  it('has at least 6 FAQ items each with a question and answer', () => {
    expect(FAQ_ITEMS.length).toBeGreaterThanOrEqual(6);
    for (const it of FAQ_ITEMS) {
      expect(it.q.endsWith('?')).toBe(true);
      expect(it.a.length).toBeGreaterThan(0);
    }
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd site && npm test`
Expected: FAIL — modules not found.

- [ ] **Step 3: Implement `site/src/data/features.ts`**

```ts
export interface Feature { title: string; blurb: string; icon: string; }

// icon = the `d` attribute of a 24x24 stroke icon path.
export const FEATURES: Feature[] = [
  { title: 'Dynamic request forms', blurb: 'Conditional fields per request type — onboarding, problems, changes, access and software requests.', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2' },
  { title: 'LDAP & local auth', blurb: 'Authenticate against Active Directory / LDAP, local SQLite accounts, or let users choose.', icon: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2' },
  { title: 'File attachments', blurb: 'Users attach screenshots and documents; uploads are type-, size- and content-validated.', icon: 'M21.44 11.05 12 20.5a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48' },
  { title: 'Ticket dashboard', blurb: 'A "My Tickets" view with real-time status — Active, Pending, Closed — and full history.', icon: 'M3 13h8V3H3v10Zm10 8h8V3h-8v18ZM3 21h8v-6H3v6Z' },
  { title: 'Two-way messaging', blurb: 'Users reply to tickets and read responses from support staff in a single thread.', icon: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z' },
  { title: 'Custom branding', blurb: 'Configure company name, logo, colors, icon, and support contacts to match your org.', icon: 'M12 2 2 7l10 5 10-5-10-5ZM2 17l10 5 10-5M2 12l10 5 10-5' },
];
```

- [ ] **Step 4: Implement `site/src/data/faq.ts`**

```ts
export interface FaqItem { q: string; a: string; }

export const FAQ_ITEMS: FaqItem[] = [
  {
    q: 'What is Support Portal for FreeScout?',
    a: 'Support Portal is a free, self-hosted, open-source web app that gives your end users a friendly form-based interface to submit support tickets to FreeScout, attach files, and track ticket status — without giving them access to the FreeScout agent UI.',
  },
  {
    q: 'Is Support Portal free?',
    a: 'Yes. It is open-source software released under the GNU General Public License v3.0 and is free to self-host.',
  },
  {
    q: 'What do I need to run it?',
    a: 'PHP 8.1+ with the pdo_sqlite, curl, json, mbstring and fileinfo extensions, Composer, a web server (Apache or Nginx), and a FreeScout instance with the API module installed. The ldap extension is needed only for LDAP authentication.',
  },
  {
    q: 'Does it support LDAP / Active Directory?',
    a: 'Yes. It supports LDAP/Active Directory, local SQLite accounts, or both at once. LDAP binds over StartTLS or LDAPS and escapes credentials and the username filter.',
  },
  {
    q: 'Does it require FreeScout modules?',
    a: 'The FreeScout API module is required. The Custom Fields and Tags modules are optional and enhance functionality, but the portal works with just the API module.',
  },
  {
    q: 'Can I run it with Docker?',
    a: 'Yes. The repository includes a Dockerfile and docker-compose.yml so you can run the portal in a container.',
  },
  {
    q: 'Is it secure?',
    a: 'The app requires a CSRF secret to boot, validates all file uploads, throttles login attempts per username and IP, sets HttpOnly/SameSite=Strict/Secure cookies, and sends a Content-Security-Policy and hardening headers on every response.',
  },
];
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd site && npm test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add site/src/data/features.ts site/src/data/faq.ts site/src/data/content.test.ts
git commit -m "feat(site): add feature and FAQ data with shape tests"
```

---

### Task 6: Landing page

**Files:**
- Create: `site/src/components/Hero.astro`, `site/src/components/FeatureCard.astro`
- Overwrite: `site/src/pages/index.astro`
- Create: `site/public/screenshot.png` (copied from repo root)

**Interfaces:**
- Consumes: `BaseLayout`, `FEATURES`, `FAQ_ITEMS`, `softwareApplicationLd`, `webSiteLd`, `withBase`, `SITE`.

- [ ] **Step 1: Copy the product screenshot into the site's public dir**

```bash
cp screenshot.png site/public/screenshot.png
```

- [ ] **Step 2: Create `site/src/components/FeatureCard.astro`**

```astro
---
interface Props { title: string; blurb: string; icon: string; }
const { title, blurb, icon } = Astro.props;
---
<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
  <div class="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-brand dark:bg-blue-950/50">
    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d={icon} />
    </svg>
  </div>
  <h3 class="mb-1 text-lg font-semibold text-slate-900 dark:text-white">{title}</h3>
  <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-400">{blurb}</p>
</div>
```

- [ ] **Step 3: Create `site/src/components/Hero.astro`**

```astro
---
import { SITE, withBase } from '../data/seo';
---
<section class="mx-auto max-w-6xl px-4 pb-10 pt-16 text-center sm:pt-24">
  <a href={SITE.repo} class="mb-6 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
    Free &amp; open-source · GPL v3
  </a>
  <h1 class="mx-auto max-w-3xl text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
    A friendly customer portal for <span class="text-brand">FreeScout</span>
  </h1>
  <p class="mx-auto mt-5 max-w-2xl text-lg text-slate-600 dark:text-slate-400">
    Support Portal is a self-hosted, open-source end-user portal for FreeScout. Give your users a clean
    form-based way to submit tickets, attach files, and track status — without exposing the agent UI.
  </p>
  <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
    <a href={withBase('/docs/installation')} class="rounded-xl bg-brand px-5 py-3 font-semibold text-white shadow-sm hover:bg-brand-dark">Get started</a>
    <a href={SITE.repo} class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-800 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-800">View on GitHub</a>
  </div>
  <div class="mt-14">
    <img
      src={withBase('/screenshot.png')}
      alt="Support Portal showing the request-type selection screen with onboarding, technical problem and change request cards"
      width="1370" height="527"
      class="mx-auto w-full max-w-5xl rounded-2xl border border-slate-200 shadow-xl dark:border-slate-800"
      loading="eager"
    />
  </div>
</section>
```

- [ ] **Step 4: Overwrite `site/src/pages/index.astro`**

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
import Hero from '../components/Hero.astro';
import FeatureCard from '../components/FeatureCard.astro';
import { FEATURES } from '../data/features';
import { FAQ_ITEMS } from '../data/faq';
import { SITE, withBase, softwareApplicationLd, webSiteLd } from '../data/seo';

const steps = [
  { n: '1', t: 'User submits a request', d: 'They pick a request type and fill in a dynamic form tailored to it.' },
  { n: '2', t: 'A ticket is created in FreeScout', d: 'The portal calls the FreeScout API and creates the ticket in your mailbox.' },
  { n: '3', t: 'User tracks and replies', d: 'They watch status updates and reply in a two-way thread from their dashboard.' },
];
const jsonLd = [softwareApplicationLd(), webSiteLd()];
---
<BaseLayout
  title={SITE.tagline}
  description={SITE.description}
  path="/"
  jsonLd={jsonLd}
>
  <Hero />

  <section id="features" class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-3xl font-bold text-slate-900 dark:text-white">Everything your users need</h2>
    <p class="mx-auto mt-3 max-w-2xl text-center text-slate-600 dark:text-slate-400">A focused front door to FreeScout, built for end users.</p>
    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {FEATURES.map((f) => <FeatureCard title={f.title} blurb={f.blurb} icon={f.icon} />)}
    </div>
  </section>

  <section class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-3xl font-bold text-slate-900 dark:text-white">How it works</h2>
    <div class="mt-10 grid gap-6 md:grid-cols-3">
      {steps.map((s) => (
        <div class="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
          <div class="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand font-bold text-white">{s.n}</div>
          <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{s.t}</h3>
          <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{s.d}</p>
        </div>
      ))}
    </div>
  </section>

  <section class="mx-auto max-w-3xl px-4 py-16">
    <h2 class="text-center text-3xl font-bold text-slate-900 dark:text-white">Frequently asked questions</h2>
    <div class="mt-8 divide-y divide-slate-200 dark:divide-slate-800">
      {FAQ_ITEMS.slice(0, 4).map((it) => (
        <details class="group py-4">
          <summary class="cursor-pointer list-none font-medium text-slate-900 dark:text-white">{it.q}</summary>
          <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{it.a}</p>
        </details>
      ))}
    </div>
    <p class="mt-6 text-center text-sm"><a class="font-semibold text-brand hover:underline" href={withBase('/faq')}>See all FAQs →</a></p>
  </section>

  <section class="mx-auto max-w-6xl px-4 py-16 text-center">
    <div class="rounded-3xl bg-slate-900 px-6 py-14 dark:bg-slate-900">
      <h2 class="text-3xl font-bold text-white">Self-host it in minutes</h2>
      <p class="mx-auto mt-3 max-w-xl text-slate-300">PHP 8.1+, a FreeScout instance with the API module, and you're live. Docker supported.</p>
      <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href={withBase('/docs/installation')} class="rounded-xl bg-brand px-5 py-3 font-semibold text-white hover:bg-brand-dark">Read the docs</a>
        <a href={SITE.repo} class="rounded-xl border border-slate-600 px-5 py-3 font-semibold text-white hover:bg-slate-800">Star on GitHub</a>
      </div>
    </div>
  </section>
</BaseLayout>
```

- [ ] **Step 5: Verify the build and metadata**

Run: `cd site && npx astro build`
Expected: build succeeds; `site/dist/index.html` contains `"@type":"SoftwareApplication"` and `id="features"`.

- [ ] **Step 6: Commit**

```bash
git add site/src/components/Hero.astro site/src/components/FeatureCard.astro site/src/pages/index.astro site/public/screenshot.png
git commit -m "feat(site): build landing page with hero, features, FAQ teaser, JSON-LD"
```

---

### Task 7: Docs content collection + DocsLayout + routing

**Files:**
- Create: `site/src/content.config.ts`
- Create: `site/src/components/DocsSidebar.astro`, `site/src/components/Prose.astro`
- Create: `site/src/layouts/DocsLayout.astro`
- Create: `site/src/pages/docs/[...slug].astro`
- Create: `site/src/content/docs/overview.md` (placeholder content so routing builds; full content lands in Task 8)

**Interfaces:**
- Consumes: `astro:content` (`defineCollection`, `glob`, `getCollection`, `render`), `BaseLayout`, `techArticleLd`, `howToLd`, `breadcrumbLd`, `withBase`.
- Produces: a `docs` collection with schema `{ title, description, order, howTo? }`; routes at `/docs/<id>`; `DocsLayout` props `{ entry, allDocs }`.

- [ ] **Step 1: Create `site/src/content.config.ts`**

```ts
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const docs = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/docs' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    order: z.number(),
    howTo: z
      .object({
        name: z.string(),
        description: z.string(),
        steps: z.array(z.string()),
      })
      .optional(),
  }),
});

export const collections = { docs };
```

- [ ] **Step 2: Create `site/src/components/Prose.astro`**

```astro
---
---
<div class="prose-doc max-w-none">
  <slot />
</div>
<style is:global>
  .prose-doc { color: rgb(51 65 85); line-height: 1.7; }
  .dark .prose-doc { color: rgb(203 213 225); }
  .prose-doc h2 { margin-top: 2rem; margin-bottom: .75rem; font-size: 1.5rem; font-weight: 700; color: rgb(15 23 42); scroll-margin-top: 5rem; }
  .prose-doc h3 { margin-top: 1.5rem; margin-bottom: .5rem; font-size: 1.2rem; font-weight: 600; color: rgb(15 23 42); }
  .dark .prose-doc h2, .dark .prose-doc h3 { color: #fff; }
  .prose-doc p, .prose-doc ul, .prose-doc ol, .prose-doc pre, .prose-doc table { margin-bottom: 1rem; }
  .prose-doc ul { list-style: disc; padding-left: 1.25rem; }
  .prose-doc ol { list-style: decimal; padding-left: 1.25rem; }
  .prose-doc a { color: #2563eb; text-decoration: underline; }
  .prose-doc code { background: rgb(241 245 249); padding: .1rem .35rem; border-radius: .35rem; font-size: .9em; }
  .dark .prose-doc code { background: rgb(30 41 59); }
  .prose-doc pre { background: rgb(15 23 42); color: rgb(226 232 240); padding: 1rem; border-radius: .75rem; overflow-x: auto; }
  .prose-doc pre code { background: transparent; padding: 0; }
  .prose-doc table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  .prose-doc th, .prose-doc td { border: 1px solid rgb(226 232 240); padding: .5rem .75rem; text-align: left; }
  .dark .prose-doc th, .dark .prose-doc td { border-color: rgb(51 65 85); }
</style>
```

- [ ] **Step 3: Create `site/src/components/DocsSidebar.astro`**

```astro
---
import { withBase } from '../data/seo';
interface Props { items: { id: string; title: string }[]; currentId: string; }
const { items, currentId } = Astro.props;
---
<nav aria-label="Docs" class="text-sm">
  <ul class="space-y-1">
    {items.map((it) => (
      <li>
        <a
          href={withBase(`/docs/${it.id}`)}
          aria-current={it.id === currentId ? 'page' : undefined}
          class:list={[
            'block rounded-lg px-3 py-2',
            it.id === currentId
              ? 'bg-blue-50 font-semibold text-brand dark:bg-blue-950/50'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
          ]}
        >{it.title}</a>
      </li>
    ))}
  </ul>
</nav>
```

- [ ] **Step 4: Create `site/src/layouts/DocsLayout.astro`**

```astro
---
import type { CollectionEntry } from 'astro:content';
import BaseLayout from './BaseLayout.astro';
import DocsSidebar from '../components/DocsSidebar.astro';
import Prose from '../components/Prose.astro';
import { withBase, techArticleLd, howToLd, breadcrumbLd } from '../data/seo';

interface Props {
  entry: CollectionEntry<'docs'>;
  allDocs: CollectionEntry<'docs'>[];
}
const { entry, allDocs } = Astro.props;
const { title, description, howTo } = entry.data;
const path = `/docs/${entry.id}`;

const items = allDocs.map((d) => ({ id: d.id, title: d.data.title }));
const idx = allDocs.findIndex((d) => d.id === entry.id);
const prev = idx > 0 ? allDocs[idx - 1] : null;
const next = idx < allDocs.length - 1 ? allDocs[idx + 1] : null;

const jsonLd: object[] = [
  techArticleLd({ title, description, path }),
  breadcrumbLd([
    { name: 'Home', path: '/' },
    { name: 'Docs', path: '/docs/overview' },
    { name: title, path },
  ]),
];
if (howTo) jsonLd.push(howToLd(howTo));
---
<BaseLayout title={title} description={description} path={path} ogType="article" jsonLd={jsonLd}>
  <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-[16rem_1fr]">
    <aside class="md:sticky md:top-20 md:h-max">
      <details class="md:open" open>
        <summary class="mb-2 cursor-pointer font-semibold text-slate-900 md:pointer-events-none dark:text-white">Documentation</summary>
        <DocsSidebar items={items} currentId={entry.id} />
      </details>
    </aside>
    <article>
      <nav aria-label="Breadcrumb" class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        <a class="hover:underline" href={withBase('/')}>Home</a> ·
        <a class="hover:underline" href={withBase('/docs/overview')}>Docs</a> ·
        <span class="text-slate-700 dark:text-slate-300">{title}</span>
      </nav>
      <h1 class="mb-6 text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">{title}</h1>
      <Prose><slot /></Prose>
      <div class="mt-12 flex justify-between border-t border-slate-200 pt-6 text-sm dark:border-slate-800">
        {prev ? <a class="text-brand hover:underline" href={withBase(`/docs/${prev.id}`)}>← {prev.data.title}</a> : <span />}
        {next ? <a class="text-brand hover:underline" href={withBase(`/docs/${next.id}`)}>{next.data.title} →</a> : <span />}
      </div>
    </article>
  </div>
</BaseLayout>
```

- [ ] **Step 5: Create `site/src/pages/docs/[...slug].astro`**

```astro
---
import { getCollection, render } from 'astro:content';
import DocsLayout from '../../layouts/DocsLayout.astro';

export async function getStaticPaths() {
  const all = (await getCollection('docs')).sort((a, b) => a.data.order - b.data.order);
  return all.map((entry) => ({
    params: { slug: entry.id },
    props: { entry, allDocs: all },
  }));
}

const { entry, allDocs } = Astro.props;
const { Content } = await render(entry);
---
<DocsLayout entry={entry} allDocs={allDocs}>
  <Content />
</DocsLayout>
```

- [ ] **Step 6: Create a minimal `site/src/content/docs/overview.md` so routing builds**

```md
---
title: Overview
description: What Support Portal for FreeScout is and who it is for.
order: 1
---

## Overview

Placeholder — replaced with full content in the next task.
```

- [ ] **Step 7: Verify docs routing builds**

Run: `cd site && npx astro build`
Expected: build succeeds; `site/dist/docs/overview/index.html` exists and contains `"@type":"TechArticle"`.

- [ ] **Step 8: Commit**

```bash
git add site/src/content.config.ts site/src/components/DocsSidebar.astro site/src/components/Prose.astro site/src/layouts/DocsLayout.astro site/src/pages/docs/[...slug].astro site/src/content/docs/overview.md
git commit -m "feat(site): add docs content collection, layout and routing"
```

---

### Task 8: Docs content pages (sourced from README)

**Files:**
- Overwrite: `site/src/content/docs/overview.md`
- Create: `installation.md`, `configuration.md`, `authentication.md`, `deployment.md`, `managing-users.md`, `usage.md`, `troubleshooting.md`, `security.md` (all under `site/src/content/docs/`)

**Interfaces:**
- Consumes: the `docs` schema from Task 7 (`title`, `description`, `order`, optional `howTo`).
- Produces: nine docs pages with unique titles/descriptions. `installation.md` and `deployment.md` include a `howTo` block.

**Source of truth:** the repo `README.md`. Transcribe the referenced sections faithfully into Markdown; do not invent behavior. Keep the env-var tables exactly as reproduced below (they are SEO-relevant).

- [ ] **Step 1: Overwrite `overview.md`**

```md
---
title: Overview
description: Support Portal is a free, self-hosted end-user portal for FreeScout — dynamic ticket forms, LDAP/local auth, file uploads, and a status dashboard.
order: 1
---

## What is Support Portal?

Support Portal is a free, self-hosted, open-source web app that gives your end users a friendly,
form-based interface to **FreeScout** (the open-source help desk). Users submit support requests,
attach files, and track ticket status — without ever seeing the FreeScout agent UI.

## Who it's for

IT teams and help desks already running FreeScout who want a clean, branded front door for their
end users, with corporate (LDAP/Active Directory) or local authentication.

## Key features

- Multiple request types — onboarding, problem, change, software request, access request, and more
- Dynamic forms with conditional fields per request type
- LDAP/Active Directory and/or local authentication
- File attachments with validation
- A "My Tickets" dashboard with real-time status (Active, Pending, Closed)
- Two-way messaging on tickets
- Configurable branding (name, logo, colors, contacts)

## How it fits together

The portal talks to FreeScout over its REST API. Tickets created in the portal appear in your
FreeScout mailbox; replies sync both ways. See [Installation](/support-portal/docs/installation) to get started.
```

- [ ] **Step 2: Create `installation.md` (includes `howTo`)**

Transcribe README **Prerequisites** (lines ~20–52) and **Installation** steps 1–6 (lines ~54–181) into the body under the headings shown. Use the frontmatter exactly as below.

```md
---
title: Installation
description: How to install Support Portal for FreeScout — prerequisites, Composer, environment config, web server setup, and connecting the FreeScout API.
order: 2
howTo:
  name: Install Support Portal for FreeScout
  description: Install and configure the self-hosted FreeScout customer portal.
  steps:
    - Clone the repository from GitHub.
    - Install PHP dependencies with Composer.
    - Copy .env.example to .env and configure FreeScout API, auth, and security settings.
    - Create the data, uploads, logs and cache directories and set permissions.
    - Point your web server (Apache or Nginx) document root at the public directory.
    - Install the FreeScout API module, generate an API key, and set the mailbox ID.
---

## Prerequisites

Transcribe README "Prerequisites" (Required list + FreeScout Modules: required and optional) here.

## 1. Clone the repository

(README Installation step 1 — include the `git clone` + `cd` code block.)

## 2. Install dependencies

(README step 2 — `composer install`.)

## 3. Configure environment

(README step 3 — `cp .env.example .env` and the annotated `.env` block.)

## 4. Set up database and directories

(README step 4 — `mkdir -p` and `chmod` code block + the note that SQLite is created on first run.)

## 5. Configure your web server

(README step 5 — both the Apache and Nginx config blocks, and the `a2enmod` note.)

## 6. Configure FreeScout

(README step 6 — install API module, generate API key, set mailbox ID, optional modules.)
```

- [ ] **Step 3: Create `configuration.md` (reproduce the env-var tables exactly)**

```md
---
title: Configuration
description: Configure Support Portal — FreeScout API and mailbox settings, security and auth environment variables, form fields, and branding.
order: 3
---

## FreeScout environment variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FREESCOUT_API_URL` | Yes | - | The base URL for your FreeScout API (e.g., `https://helpdesk.example.com/api`) |
| `FREESCOUT_API_KEY` | Yes | - | API key generated in FreeScout Admin > Manage > API |
| `FREESCOUT_MAILBOX_ID` | Recommended | Auto-detect | The numeric ID of the FreeScout mailbox to submit tickets to. If not set, the application uses the first available mailbox from the API. |
| `FREESCOUT_CACHE_TTL` | No | `30` | Seconds to cache a user's ticket list, avoiding a blocking API call on every page load. `0` disables caching. |

**Finding your Mailbox ID:** In FreeScout, go to Manage > Mailboxes and open the mailbox; the ID is the
number in the URL (e.g. `/mailboxes/1/edit` means ID `1`). You can also call `GET /api/mailboxes`.

## Security & auth environment variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `CSRF_SECRET` | Yes | - | Secret for CSRF token HMAC. App refuses to boot if unset/placeholder. Generate with `php -r "echo bin2hex(random_bytes(32));"`. |
| `COOKIE_SECURE` | No | `true` | Adds the `Secure` flag to the session cookie. Set `false` only for local HTTP dev. |
| `LDAP_ENCRYPTION` | No | `tls` | LDAP transport: `tls` (StartTLS), `ssl` (LDAPS), or `none`. Avoid `none` outside trusted networks. |

## Form fields

Form fields are defined in `config/form_fields.yaml`. Configure how fields map to FreeScout in
`config/form_fields.yaml` or `config/freescout_mappings.php`.

## Branding

Transcribe the README "Branding" `.env` block (COMPANY_NAME, PORTAL_TITLE, BRAND_ICON, USE_LOGO,
SUPPORT_EMAIL, SUPPORT_PHONE, IT_CONTACT_EMAIL, SELF_SERVICE_URL) here.
```

- [ ] **Step 4: Create `authentication.md`**

```md
---
title: Authentication
description: Support Portal supports LDAP/Active Directory, local SQLite accounts, or both — with TLS LDAP binds and escaped credentials.
order: 4
---

## Authentication modes

The application supports three authentication modes:

1. **LDAP only** — Corporate directory authentication
2. **Local auth only** — SQLite-based local accounts
3. **Both** — Users choose their authentication method

## LDAP configuration

Transcribe the README LDAP `.env` options (LDAP_HOST, LDAP_PORT, LDAP_ENCRYPTION, LDAP_BASE_DN, …)
and the note to bind over TLS/SSL. Reference `.env.example` for the full list.

## Local accounts & self-service registration

Users can create their own accounts at `/auth/register` when `ENABLE_LOCAL_AUTH=true`.
See [Managing Users](/support-portal/docs/managing-users) for CLI account management.
```

- [ ] **Step 5: Create `deployment.md` (includes `howTo`)**

```md
---
title: Docker & Deployment
description: Deploy Support Portal for FreeScout with Docker, Apache, or Nginx, and schedule the maintenance cleanup job.
order: 5
howTo:
  name: Deploy Support Portal with Docker
  description: Run the self-hosted FreeScout portal in production.
  steps:
    - Build or pull the image using the provided Dockerfile.
    - Provide environment variables (FreeScout API, auth, CSRF secret).
    - Start the stack with docker compose up -d.
    - Schedule the cleanup script via cron for maintenance.
---

## Docker

Transcribe the relevant Docker usage. The repository includes a `Dockerfile` and
`docker-compose.yml`; document `docker compose up -d` and required environment variables.

## Apache

(README Apache `<VirtualHost>` block + `a2enmod rewrite headers` note.)

## Nginx

(README Nginx `server { … }` block.)

## Maintenance (cleanup cron)

Reproduce the README cron line:

```cron
*/15 * * * * php /var/www/html/bin/cleanup.php >> /var/www/html/logs/cleanup.log 2>&1
```
```

- [ ] **Step 6: Create `managing-users.md`**

```md
---
title: Managing Users
description: Manage local Support Portal accounts via self-service registration or the command-line user management script.
order: 6
---

## Self-service registration

Users can create their own accounts at `/auth/register` when `ENABLE_LOCAL_AUTH=true`.

## Command-line management

Reproduce the README "Command-Line Management" code block (create, list, reset-password,
disable/enable, delete) using `php bin/manage-local-users.php …`.
```

- [ ] **Step 7: Create `usage.md`**

```md
---
title: Usage
description: How end users submit support tickets and track them in Support Portal — request types, attachments, status, and replies.
order: 7
---

## Submitting a new ticket

Transcribe README "Submitting a New Ticket" steps 1–6.

## Managing your tickets

Transcribe README "Managing Your Tickets" (dashboard, status, responses, replies, progress) and
the per-ticket details list.
```

- [ ] **Step 8: Create `troubleshooting.md`**

```md
---
title: Troubleshooting
description: Fix common Support Portal issues — FreeScout API failures, LDAP authentication problems, and file upload errors.
order: 8
---

## FreeScout API issues

Transcribe README "FreeScout API Issues" (problem + solutions).

## Authentication issues

Transcribe README "Authentication Issues" (LDAP failure; cannot create local account).

## File upload issues

Transcribe README "File Upload Issues" (problem + solutions).
```

- [ ] **Step 9: Create `security.md`**

```md
---
title: Security
description: Support Portal's security model — required CSRF secret, validated uploads, TLS LDAP, login throttling, and hardening headers.
order: 9
---

## Security considerations

Transcribe README "Security Considerations" bullet list (API keys, CSRF secret, LDAP TLS, file
uploads, database, HTTPS, session security, brute-force throttling, headers).
```

- [ ] **Step 10: Verify all docs build with unique metadata**

Run: `cd site && npx astro build`
Expected: build succeeds; `site/dist/docs/installation/index.html` contains `"@type":"HowTo"`; each `site/dist/docs/*/index.html` has a distinct `<title>`.

- [ ] **Step 11: Commit**

```bash
git add site/src/content/docs
git commit -m "docs(site): add full documentation pages sourced from README"
```

---

### Task 9: Dedicated FAQ page

**Files:**
- Create: `site/src/components/Faq.astro`
- Create: `site/src/pages/faq.astro`

**Interfaces:**
- Consumes: `BaseLayout`, `FAQ_ITEMS`, `faqPageLd`, `breadcrumbLd`.

- [ ] **Step 1: Create `site/src/components/Faq.astro`**

```astro
---
import { FAQ_ITEMS } from '../data/faq';
---
<div class="mx-auto max-w-3xl divide-y divide-slate-200 dark:divide-slate-800">
  {FAQ_ITEMS.map((it) => (
    <details class="py-5">
      <summary class="cursor-pointer list-none text-lg font-medium text-slate-900 dark:text-white">{it.q}</summary>
      <p class="mt-3 text-slate-600 dark:text-slate-400">{it.a}</p>
    </details>
  ))}
</div>
```

- [ ] **Step 2: Create `site/src/pages/faq.astro`**

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
import Faq from '../components/Faq.astro';
import { FAQ_ITEMS } from '../data/faq';
import { faqPageLd, breadcrumbLd } from '../data/seo';

const jsonLd = [
  faqPageLd(FAQ_ITEMS.map((it) => ({ q: it.q, a: it.a }))),
  breadcrumbLd([{ name: 'Home', path: '/' }, { name: 'FAQ', path: '/faq' }]),
];
---
<BaseLayout
  title="FAQ"
  description="Answers to common questions about Support Portal for FreeScout: what it is, whether it's free, requirements, LDAP support, Docker, and security."
  path="/faq"
  jsonLd={jsonLd}
>
  <section class="mx-auto max-w-3xl px-4 py-16">
    <h1 class="text-center text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">Frequently asked questions</h1>
    <p class="mx-auto mt-3 max-w-xl text-center text-slate-600 dark:text-slate-400">Everything you need to know about the self-hosted FreeScout customer portal.</p>
    <div class="mt-10"><Faq /></div>
  </section>
</BaseLayout>
```

- [ ] **Step 3: Verify**

Run: `cd site && npx astro build`
Expected: `site/dist/faq/index.html` contains `"@type":"FAQPage"`.

- [ ] **Step 4: Commit**

```bash
git add site/src/components/Faq.astro site/src/pages/faq.astro
git commit -m "feat(site): add dedicated FAQ page with FAQPage JSON-LD"
```

---

### Task 10: Public artifacts — favicons, manifest, robots, llms.txt, OG image

**Files:**
- Copy: favicon set + logos into `site/public/`
- Create: `site/public/site.webmanifest`, `site/public/robots.txt`, `site/public/llms.txt`
- Create: `site/src/assets/og-image.svg`, `site/scripts/gen-og.mjs`

**Interfaces:**
- Produces: all static SEO/brand assets at the site root; `og-image.png` generated by `prebuild`.

- [ ] **Step 1: Copy brand assets from the app into the site**

```bash
cp public/assets/img/favicon.ico site/public/favicon.ico
cp public/assets/img/favicon-16x16.png site/public/favicon-16x16.png
cp public/assets/img/favicon-32x32.png site/public/favicon-32x32.png
cp public/assets/img/apple-touch-icon.png site/public/apple-touch-icon.png
cp public/assets/img/android-chrome-192x192.png site/public/android-chrome-192x192.png
cp public/assets/img/android-chrome-512x512.png site/public/android-chrome-512x512.png
cp public/assets/img/logo-full.svg site/public/logo-full.svg
cp public/assets/img/logo-white.png site/public/logo-white.png
```

- [ ] **Step 2: Create `site/public/site.webmanifest` (icon paths prefixed with the base)**

```json
{
  "name": "Support Portal for FreeScout",
  "short_name": "Support Portal",
  "icons": [
    { "src": "/support-portal/android-chrome-192x192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/support-portal/android-chrome-512x512.png", "sizes": "512x512", "type": "image/png" }
  ],
  "theme_color": "#2563eb",
  "background_color": "#ffffff",
  "display": "standalone",
  "start_url": "/support-portal/"
}
```

- [ ] **Step 3: Create `site/public/robots.txt`**

```
User-agent: *
Allow: /

Sitemap: https://jeffcaldwell.ca/support-portal/sitemap-index.xml
```

> NOTE: This file is served at `/support-portal/robots.txt`, not the apex `/robots.txt` (which the user-pages repo owns). It is included for completeness; the effective crawl directive is the apex robots.txt. The sitemap is submitted directly in Search Console (see Task 14).

- [ ] **Step 4: Create `site/public/llms.txt`**

```
# Support Portal for FreeScout

> A free, self-hosted, open-source end-user portal for FreeScout. It gives users a clean,
> form-based interface to submit support tickets, attach files, and track status — with LDAP
> or local authentication — without exposing the FreeScout agent UI.

## Facts
- License: GNU GPL v3.0 (free, open-source, self-hosted)
- Requirements: PHP 8.1+, a FreeScout instance with the API module
- Authentication: LDAP/Active Directory, local SQLite accounts, or both
- Deployment: Apache, Nginx, or Docker
- Repository: https://github.com/jeffcaldwellca/support-portal

## Docs
- Overview: https://jeffcaldwell.ca/support-portal/docs/overview
- Installation: https://jeffcaldwell.ca/support-portal/docs/installation
- Configuration: https://jeffcaldwell.ca/support-portal/docs/configuration
- Authentication: https://jeffcaldwell.ca/support-portal/docs/authentication
- Docker & Deployment: https://jeffcaldwell.ca/support-portal/docs/deployment
- FAQ: https://jeffcaldwell.ca/support-portal/faq
```

- [ ] **Step 5: Create `site/src/assets/og-image.svg` (1200×630)**

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <rect width="1200" height="630" fill="#ffffff"/>
  <rect width="1200" height="12" fill="#2563eb"/>
  <text x="80" y="250" font-family="sans-serif" font-size="76" font-weight="800" fill="#111212">Support Portal</text>
  <text x="80" y="340" font-family="sans-serif" font-size="44" font-weight="600" fill="#2563eb">for FreeScout</text>
  <text x="80" y="430" font-family="sans-serif" font-size="32" fill="#475569">A friendly, self-hosted customer portal.</text>
  <text x="80" y="478" font-family="sans-serif" font-size="32" fill="#475569">Dynamic forms · LDAP/local auth · file uploads.</text>
  <text x="80" y="575" font-family="sans-serif" font-size="26" fill="#94a3b8">Free &amp; open-source · GPL v3 · github.com/jeffcaldwellca/support-portal</text>
</svg>
```

- [ ] **Step 6: Create `site/scripts/gen-og.mjs`**

```js
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
```

- [ ] **Step 7: Generate the OG image and verify it builds end-to-end**

Run: `cd site && npm run build`
Expected: `prebuild` prints "Generated public/og-image.png"; build succeeds; `site/dist/og-image.png`, `site/dist/robots.txt`, `site/dist/llms.txt`, and `site/dist/sitemap-index.xml` all exist.

- [ ] **Step 8: Commit**

```bash
git add site/public site/src/assets/og-image.svg site/scripts/gen-og.mjs
git commit -m "feat(site): add favicons, manifest, robots, llms.txt and generated OG image"
```

---

### Task 11: Post-build SEO/AEO verification script

**Files:**
- Create: `site/scripts/check-seo.mjs`

**Interfaces:**
- Consumes: the built `site/dist/` output.
- Produces: a script that exits non-zero if any SEO/AEO artifact is missing or invalid (wired to `npm run check:seo` / `npm run verify`).

- [ ] **Step 1: Create `site/scripts/check-seo.mjs`**

```js
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
```

- [ ] **Step 2: Run the full verify**

Run: `cd site && npm run verify`
Expected: build runs, then "SEO check passed: N pages, all artifacts present." (exit 0).

- [ ] **Step 3: Commit**

```bash
git add site/scripts/check-seo.mjs
git commit -m "test(site): add post-build SEO/AEO verification script"
```

---

### Task 12: GitHub Actions deploy workflow

**Files:**
- Create: `.github/workflows/deploy-pages.yml`

**Interfaces:**
- Produces: a workflow that builds `site/` and deploys to GitHub Pages on push to `main`.

- [ ] **Step 1: Create `.github/workflows/deploy-pages.yml`**

```yaml
name: Deploy site to GitHub Pages

on:
  push:
    branches: [main]
    paths:
      - 'site/**'
      - '.github/workflows/deploy-pages.yml'
  workflow_dispatch:

permissions:
  contents: read
  pages: write
  id-token: write

concurrency:
  group: pages
  cancel-in-progress: true

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v6
      - name: Build with Astro
        uses: withastro/action@v6
        with:
          path: site
  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - name: Deploy to GitHub Pages
        id: deployment
        uses: actions/deploy-pages@v5
```

- [ ] **Step 2: Validate YAML syntax**

Run: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy-pages.yml')); print('valid yaml')"`
Expected: `valid yaml`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/deploy-pages.yml
git commit -m "ci: deploy site to GitHub Pages via withastro/action"
```

> NOTE (one-time, manual, by the repo owner): In GitHub → Settings → Pages, set **Source: GitHub Actions**. The workflow only deploys from `main`; this plan's branch must be merged first (see Task 14 / branch finish).

---

### Task 13 (OPTIONAL): Client-side docs search with Pagefind

Skip unless search is wanted at launch. Self-contained; can be added later.

**Files:**
- Modify: `site/astro.config.mjs`, `site/src/layouts/DocsLayout.astro`, `site/package.json`

**Interfaces:**
- Consumes: built docs HTML. Produces: a `<Search />` UI in the docs sidebar.

- [ ] **Step 1: Install the integration**

```bash
cd site && npm install astro-pagefind
```

- [ ] **Step 2: Register it in `site/astro.config.mjs`**

Add the import and integration:

```js
import pagefind from 'astro-pagefind';
// ...
integrations: [sitemap(), pagefind()],
```

- [ ] **Step 3: Add the search box to `DocsLayout.astro`**

In the `<aside>`, above `<DocsSidebar … />`, add:

```astro
---
import Search from 'astro-pagefind/components/Search';
---
<Search id="docs-search" className="mb-4" uiOptions={{ showImages: false }} />
```

(Place the `import` with the other imports at the top of the frontmatter.)

- [ ] **Step 4: Verify search index is generated**

Run: `cd site && npm run build`
Expected: build succeeds; `site/dist/pagefind/` directory exists.

- [ ] **Step 5: Commit**

```bash
git add site/astro.config.mjs site/src/layouts/DocsLayout.astro site/package.json site/package-lock.json
git commit -m "feat(site): add Pagefind client-side docs search"
```

---

### Task 14: README link + final verification & branch finish

**Files:**
- Modify: `README.md` (add a Website/Docs link near the top)

**Interfaces:**
- Produces: a finished, verified site ready to merge.

- [ ] **Step 1: Add a Website/Docs link to `README.md`**

Immediately after the screenshot line near the top, insert:

```md
**📖 Website & docs:** https://jeffcaldwell.ca/support-portal/
```

- [ ] **Step 2: Run the full local verification**

```bash
cd site && npm test && npm run verify
```
Expected: unit tests pass; build succeeds; "SEO check passed".

- [ ] **Step 3: Preview and eyeball locally**

Run: `cd site && npm run preview`
Then open the printed URL and confirm: landing renders, dark-mode toggle works without flash, docs sidebar + prev/next work, internal links resolve under `/support-portal/`, no console errors. Stop the preview when done.

- [ ] **Step 4: Manual external validations (record results)**

- Lighthouse (Chrome DevTools, mobile) on the landing + one docs page: confirm SEO 100, Performance ≥95, Accessibility ≥95, Best Practices ≥95.
- Paste the landing, `/faq`, and `/docs/installation` HTML or URL into the Google Rich Results Test / schema.org validator: confirm `SoftwareApplication`, `FAQPage`, `HowTo`, `TechArticle`, `BreadcrumbList` validate with no errors.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: link to the project website"
```

- [ ] **Step 6: Finish the branch**

Use the superpowers:finishing-a-development-branch skill to merge `gh-pages-seo-site` to `main` (this triggers the deploy workflow once Pages Source is set to GitHub Actions).

> POST-DEPLOY follow-ups (owner, manual):
> 1. Settings → Pages → Source = GitHub Actions (one-time).
> 2. Submit `https://jeffcaldwell.ca/support-portal/sitemap-index.xml` to Google Search Console and Bing Webmaster Tools; add verification tokens to `SITE.googleSiteVerification` / `SITE.bingSiteVerification` in `site/src/data/seo.ts` if using meta-tag verification.
> 3. Optional: in the user-pages repo, add a `Sitemap:` line for this site to the apex `/robots.txt`, and consider an apex `/llms.txt` that references this project.

---

## Self-Review

**Spec coverage:**
- Architecture & tooling (Astro/Tailwind/sitemap, `site/` isolation, base path) → Tasks 1, 2, 12. ✓
- Site map (landing, 9 docs pages, FAQ, 404) → Tasks 6, 7, 8, 9. *(404: Astro emits a default; a custom 404 is optional and not separately tasked — acceptable, low SEO value.)*
- Content sourcing from README → Task 8 (explicit section mapping). ✓
- SEO plan (titles/descriptions, canonical, OG/Twitter, sitemap, robots, icons/manifest, fonts, verification slots) → Tasks 2, 4, 10; verified in 11. ✓
- AEO plan (llms.txt, JSON-LD: SoftwareApplication/WebSite/HowTo/FAQPage/TechArticle/BreadcrumbList, extractable content) → Tasks 3, 4, 6, 7, 8, 9, 10. ✓
- Design system (clean SaaS, dark mode no-FOUC, a11y, responsive) → Tasks 2, 4, 6, 7. ✓
- Success criteria / verification → Tasks 11, 14. ✓
- Deployment (workflow, Pages source note, no CNAME) → Task 12; constraints. ✓

**Placeholder scan:** Code/config steps contain complete content. The only "transcribe README section X" directives are in Task 8 (content pages), which point to exact, existing README sections rather than leaving logic undefined; frontmatter and headings are fully specified, and SEO-critical tables/intros are reproduced verbatim. Acceptable for content transcription.

**Type consistency:** `seo.ts` exports (`SITE`, `absUrl`, `withBase`, `softwareApplicationLd`, `webSiteLd`, `faqPageLd`, `howToLd`, `techArticleLd`, `breadcrumbLd`) are used with matching names/signatures in `Seo.astro`, `BaseLayout.astro`, `index.astro`, `DocsLayout.astro`, `faq.astro`. `BaseLayout` props `{title, description, path, ogType?, jsonLd?}` match all call sites. `docs` schema `{title, description, order, howTo?}` matches `DocsLayout` usage and the Task 8 frontmatter. `DocsLayout` props `{entry, allDocs}` match `[...slug].astro`. ✓
