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
