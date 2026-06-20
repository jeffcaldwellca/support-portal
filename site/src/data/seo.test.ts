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
