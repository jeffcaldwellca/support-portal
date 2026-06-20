import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  site: 'https://jeffcaldwell.ca',
  base: '/support-portal',
  trailingSlash: 'ignore',
  integrations: [
    sitemap({
      serialize(item) {
        const root = 'https://jeffcaldwell.ca/support-portal/';
        if (item.url !== root) {
          item.url = item.url.replace(/\/$/, '');
        }
        return item;
      },
    }),
  ],
  vite: { plugins: [tailwindcss()] },
});
