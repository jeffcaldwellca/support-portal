# Design: SEO/AEO-Optimized GitHub Pages Site for Support Portal

- **Date:** 2026-06-19
- **Status:** Approved (pending spec review)
- **Author:** Jeff Caldwell (with Claude)
- **Topic:** Custom GitHub Pages landing + docs hub, optimized for SEO and AEO

## Summary

Build a custom, fast, static marketing **landing page + documentation hub** for the
`support-portal` open-source project, published via GitHub Pages at
`https://jeffcaldwell.ca/support-portal/`. The site is optimized for both **SEO**
(Google/Bing) and **AEO** (AI answer engines such as ChatGPT, Perplexity, Claude, and
Google AI Overviews) to maximize discoverability and adoption by FreeScout
administrators and self-hosters.

## Goals

1. Convert visitors (FreeScout admins / IT teams) into adopters via a clean, trustworthy landing page.
2. Rank well for long-tail intent: *"FreeScout customer portal," "FreeScout end-user ticket form," "self-hosted help desk portal for FreeScout."*
3. Be highly extractable by AI answer engines (clear definitions, FAQ, structured data, `llms.txt`).
4. Provide a real documentation hub (each page = its own SEO target), sourced from the existing README.
5. Visually match the existing product identity so the site and app feel like one thing.

## Non-Goals

- No backend, forms, or dynamic features on the marketing site — fully static.
- No blog/changelog at launch (can be added later).
- No paid analytics or third-party trackers at launch (keep it fast and private).
- No fake/placeholder testimonials or `aggregateRating` structured data.

## Context

- **Project:** `support-portal` — a self-hosted, open-source (GPL v3) PHP/Slim web app that
  gives end users a friendly, form-based front end to **FreeScout** (open-source help desk).
  Features: dynamic ticket forms, LDAP/local auth, file uploads, ticket dashboard, two-way
  messaging, configurable branding. Repo: `github.com/jeffcaldwellca/support-portal`.
- **Hosting:** GitHub Pages **project page** served under the user's `jeffcaldwell.ca` user
  site. Final URL base: `https://jeffcaldwell.ca/support-portal/`. Astro `base` = `/support-portal`.
  **No `CNAME` file** is added to this repo — the apex domain is owned by the user-pages repo;
  adding one here would conflict.
- **Brand assets (already exist in repo):** `public/assets/img/logo-full.svg` (black "IT"
  ticket mark + info badge), `logo-white.png`, favicons, `site.webmanifest`. Product UI uses a
  vivid blue (~`#2563EB`) primary on white, clean Bootstrap-based layout. Reference screenshot:
  `screenshot.png` (1370×527).

## Decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| Primary goal | Landing page + docs hub |
| Domain | `jeffcaldwell.ca/support-portal` (base path `/support-portal`) |
| Visual direction | Clean modern SaaS, light default + dark-mode toggle |
| Build stack | Astro (static) + Tailwind CSS, deployed via GitHub Actions |
| Docs scope | Full docs hub (multi-page), sourced from README |

## Architecture

### Directory layout

The Astro project lives in a new `site/` directory, fully isolated from the PHP app.

```
site/
  astro.config.mjs          # site + base, integrations (sitemap, mdx, tailwind)
  package.json
  tsconfig.json
  public/                   # static passthrough: favicons, manifest, og image, llms.txt, robots.txt
    favicon.ico, favicon-16x16.png, favicon-32x32.png, apple-touch-icon.png
    android-chrome-192x192.png, android-chrome-512x512.png
    site.webmanifest
    og-image.png            # 1200x630 branded card
    llms.txt
    robots.txt
    logo-full.svg, logo-white.png
  src/
    styles/
      tokens.css            # brand design tokens (colors, radius, shadow)
      global.css            # Tailwind layers + base styles
    components/
      Header.astro          # logo + nav + dark-mode toggle + GitHub link
      Footer.astro
      ThemeToggle.astro     # dark-mode toggle (minimal inline JS, no FOUC)
      Seo.astro             # <head> meta: title, description, canonical, OG/Twitter, JSON-LD slot
      JsonLd.astro          # renders a JSON-LD <script> from a passed object
      FeatureCard.astro
      Hero.astro
      Faq.astro             # renders Q&A list + emits FAQPage JSON-LD
      DocsSidebar.astro
      Prose.astro           # styled wrapper for rendered Markdown
    layouts/
      BaseLayout.astro      # html shell, Header/Footer, Seo, theme bootstrap
      DocsLayout.astro      # BaseLayout + sidebar + prev/next + breadcrumbs + TechArticle JSON-LD
    content/
      config.ts             # content collection schema for docs
      docs/                 # one Markdown/MDX file per docs page (see Site Map)
    pages/
      index.astro           # landing page
      faq.astro             # dedicated FAQ page
      docs/[...slug].astro  # renders docs collection
      404.astro
    data/
      features.ts           # feature list (icon, title, blurb)
      faq.ts                # FAQ Q&A used by both /faq and landing teaser
      seo.ts                # site-wide SEO constants + per-keyword map
```

### Tooling

- **Astro** (latest) — static output (`output: 'static'`), ships ~zero JS for top Core Web Vitals.
- **Tailwind CSS** (Astro integration) + `tokens.css` design tokens. Dark mode via the `class`
  strategy (`darkMode: 'class'`) toggled on `<html>`.
- **Integrations:** `@astrojs/sitemap` (sitemap.xml), `@astrojs/mdx` (docs authoring).
- **Search (optional, included):** **Pagefind** — static, build-time index, client-side search
  over the docs. No server. Wired into the build step. If it adds friction, it can be deferred;
  it is the only non-essential dependency.
- **Fonts:** self-hosted **Inter** variable font (woff2), `preload`ed, with a system-font
  fallback stack to prevent layout shift. No external font CDN.

### Base-path handling

All internal links and asset references must respect `base: '/support-portal'`. Use Astro's
URL helpers / `import.meta.env.BASE_URL` (never hardcode root-absolute `/foo` paths). Canonical
URLs, OG URLs, sitemap entries, and the JSON-LD `url`/`@id` fields all use the absolute base
`https://jeffcaldwell.ca/support-portal/...`. Use a single `absUrl(path)` helper in `seo.ts`.

## Site Map & Page Specs

Each page has a unique `<title>`, meta description, canonical URL, and (where relevant) JSON-LD.

| Route | Purpose | Primary keywords | JSON-LD |
| --- | --- | --- | --- |
| `/` | Landing / conversion | "FreeScout customer portal", "self-hosted help desk ticket form", "FreeScout end-user portal" | `SoftwareApplication`, `WebSite`, `BreadcrumbList` |
| `/docs/overview` | What it is / why | "FreeScout portal overview", "FreeScout self-service" | `TechArticle`, `BreadcrumbList` |
| `/docs/installation` | Install guide | "install FreeScout portal", "FreeScout API setup" | `HowTo`, `BreadcrumbList` |
| `/docs/configuration` | Env vars, form fields, branding | "FreeScout portal configuration", "FREESCOUT_MAILBOX_ID" | `TechArticle`, `BreadcrumbList` |
| `/docs/authentication` | LDAP / local / both | "FreeScout LDAP authentication", "self-hosted portal LDAP" | `TechArticle`, `BreadcrumbList` |
| `/docs/deployment` | Docker, Apache, Nginx | "FreeScout portal Docker", "deploy help desk portal" | `HowTo`, `BreadcrumbList` |
| `/docs/managing-users` | Local user CLI | "manage local users help desk portal" | `TechArticle`, `BreadcrumbList` |
| `/docs/usage` | Submitting/managing tickets | "submit support ticket portal", "ticket dashboard" | `TechArticle`, `BreadcrumbList` |
| `/docs/troubleshooting` | Common problems | "FreeScout API not working", "ticket not created" | `TechArticle`, `BreadcrumbList` |
| `/docs/security` | Security model | "FreeScout portal security", "CSRF help desk portal" | `TechArticle`, `BreadcrumbList` |
| `/faq` | AEO-tuned Q&A | "what is a FreeScout portal", "is it free", "does it support LDAP" | `FAQPage`, `BreadcrumbList` |
| `/404` | Not found | — | — |

### Landing page sections (`/`)

1. **Hero** — H1 with primary keyword, one-sentence value prop, two CTAs ("View on GitHub",
   "Read the Docs"), framed product screenshot.
2. **Definitional intro** — a short, extractable "Support Portal is a self-hosted, open-source
   end-user portal for FreeScout that…" paragraph (AEO).
3. **Feature cards** — dynamic forms, LDAP + local auth, file uploads, ticket dashboard, two-way
   messaging, branding (from `features.ts`).
4. **How it works** — 3-step (user submits form → ticket created in FreeScout → user tracks status).
5. **Request types** — onboarding, problem, change, software/access request showcase.
6. **Requirements / tech** — PHP 8.1+, FreeScout + API module, Docker option.
7. **FAQ teaser** — top 3–4 questions linking to `/faq`.
8. **Final CTA + footer** — GitHub, docs, license, FreeScout acknowledgment.

## Content Sourcing Map (README → site)

| README section | Destination |
| --- | --- |
| Intro + Features | Landing hero, intro, feature cards |
| Prerequisites / FreeScout modules | `/docs/installation` (Prerequisites) |
| Installation steps 1–6 | `/docs/installation` (with `HowTo` steps) |
| Configuration tables (FreeScout, Security/Auth) | `/docs/configuration` |
| Authentication modes | `/docs/authentication` |
| Docker / Apache / Nginx | `/docs/deployment` |
| Managing Local Users | `/docs/managing-users` |
| Usage (submit/manage tickets) | `/docs/usage` |
| Troubleshooting | `/docs/troubleshooting` |
| Security Considerations | `/docs/security` |
| (synthesized) | `/faq`, `/docs/overview`, `llms.txt` |

Docs content is **reorganized and SEO-tuned**, not invented. The README remains the canonical
source; docs pages link back to the repo where appropriate.

## Design System

- **Direction:** clean modern SaaS — light by default, generous whitespace, rounded cards,
  subtle shadows, framed screenshot.
- **Tokens** (`tokens.css`):
  - Brand blue `#2563EB` (primary), blue-dark `#1D4ED8` (hover), near-black `#111212` (logo/ink),
    grays for text/borders/surfaces; semantic CSS variables for light + dark.
- **Typography:** Inter variable (self-hosted), system fallback. Clear type scale; H1 large/bold.
- **Dark mode:** `class` strategy on `<html>`; respects `prefers-color-scheme`; user choice
  persisted to `localStorage`; inline pre-hydration script to avoid flash of incorrect theme.
- **Accessibility:** WCAG AA contrast in both themes, visible focus states, keyboard-navigable
  nav + sidebar + theme toggle, `alt` text on all images, `prefers-reduced-motion` respected,
  skip-to-content link.
- **Responsive:** mobile-first; docs sidebar collapses to a drawer/disclosure on small screens.

## SEO Plan

- **Per-page meta:** unique `<title>` (≤60 chars) and meta description (≤155 chars) via the
  `Seo.astro` component, driven by frontmatter / page props.
- **Canonical:** absolute canonical `<link>` on every page using the base URL.
- **Open Graph + Twitter:** `og:title/description/url/image/type`, `twitter:card=summary_large_image`,
  pointing to a branded `og-image.png` (1200×630).
- **Sitemap:** `@astrojs/sitemap` generates `sitemap-index.xml`/`sitemap-0.xml`.
- **robots.txt:** allows all, references the sitemap URL.
- **Icons/manifest:** reuse existing favicons + `site.webmanifest` (paths adjusted for base).
- **Performance:** static HTML, minimal JS (theme toggle + optional Pagefind), responsive
  images with width/height to avoid CLS, preloaded font, no render-blocking third parties.
- **Internal linking:** landing → docs → related docs; descriptive anchor text; breadcrumbs.
- **Verification placeholders:** Google Search Console + Bing Webmaster meta tag slots in
  `seo.ts` (left blank until the user provides tokens). Post-launch: submit sitemap to both.

## AEO Plan

- **`llms.txt`** (served at `/support-portal/llms.txt`): concise project summary, what it is,
  who it's for, key capabilities, install one-liner, and links to repo + key docs pages.
- **Structured data (JSON-LD):**
  - `/` → `SoftwareApplication` (name, description, `applicationCategory: "BusinessApplication"`,
    `operatingSystem: "Linux, Docker"`, `offers` price `0` / free, `softwareRequirements: "PHP 8.1+"`,
    `license`, `codeRepository`) + `WebSite`.
  - `/docs/installation`, `/docs/deployment` → `HowTo` with ordered steps.
  - `/faq` → `FAQPage` with `Question`/`Answer` pairs (emitted from `faq.ts`).
  - docs pages → `TechArticle` + `BreadcrumbList`.
  - **No `aggregateRating`** until real reviews exist.
- **Content style for extraction:** question-phrased H2/H3 headings; short, direct answers in the
  first sentence; clear definitional opening sentences; structured tables for env vars; semantic
  HTML landmarks (`<header>`, `<nav>`, `<main>`, `<article>`, `<footer>`).

## Deployment

- **Workflow:** `.github/workflows/deploy-pages.yml`
  - Trigger: `push` to `main` (and `workflow_dispatch`); ideally path-filtered to `site/**` and
    the workflow file.
  - Steps: checkout → setup Node (LTS) → `npm ci` in `site/` → `npm run build` (includes Pagefind
    if enabled) → `actions/upload-pages-artifact` (`site/dist`) → `actions/deploy-pages`.
  - Permissions: `pages: write`, `id-token: write`; concurrency group for Pages.
- **One-time repo setting:** Settings → Pages → **Source: GitHub Actions**.
- **Astro config:** `site: 'https://jeffcaldwell.ca'`, `base: '/support-portal'`,
  `trailingSlash` chosen to match GitHub Pages behavior (default `'ignore'`; verify links resolve
  with the base prefix).
- **No `CNAME`** committed in this repo.

## Success Criteria / Verification

- **Lighthouse (mobile):** SEO **100**, Performance **≥95**, Accessibility **≥95**, Best Practices **≥95**.
- **Structured data:** validates in Google Rich Results Test / schema.org validator with no errors
  for `SoftwareApplication`, `FAQPage`, `HowTo`, `TechArticle`, `BreadcrumbList`.
- **Metadata:** every page has a unique title + description and a correct absolute canonical.
- **Discoverability artifacts:** `sitemap` and `robots.txt` present and correct; `llms.txt` reachable.
- **Functionality:** dark-mode toggle works without FOUC; docs sidebar + (optional) search work;
  all internal links resolve under `/support-portal/`; zero console errors; responsive on mobile.
- **Build:** `npm run build` in `site/` succeeds; Actions deploy publishes the site.

## Future (not in this build)

- Per-page generated OG images.
- Blog/changelog/release notes.
- Privacy-friendly analytics (e.g., self-hosted) if desired.
- i18n.

## File Inventory (created/modified)

- **Created:** everything under `site/` (Astro project, components, layouts, content, styles,
  public assets, `llms.txt`, `robots.txt`, `og-image.png`); `.github/workflows/deploy-pages.yml`.
- **Modified:** none in the PHP app required. (Optionally add a "Website/Docs" link to `README.md`.)
