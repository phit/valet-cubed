// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
	site: 'https://valetcubed.com',
	integrations: [
		starlight({
			title: 'Valet Cubed',
			description: 'A fast, minimal PHP development environment for Linux.',
			logo: {
				light: './src/assets/logo-light.svg',
				dark: './src/assets/logo-dark.svg',
				replacesTitle: true,
			},
			favicon: '/favicon.svg',
			social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/phit/valet-cubed' }],
			editLink: {
				baseUrl: 'https://github.com/phit/valet-cubed/edit/main/docs/',
			},
			lastUpdated: true,
			sidebar: [
				{
					label: 'Getting started',
					items: [
						'getting-started/requirements',
						'getting-started/installation',
						'getting-started/upgrading',
					],
				},
				{
					label: 'Guides',
					items: [
						'guides/serving-sites',
						'guides/php-versions',
						'guides/databases',
						'guides/services',
					],
				},
				{
					label: 'How it works',
					items: ['how-it-works/dns', 'how-it-works/drivers'],
				},
				{
					label: 'Reference',
					items: ['reference/commands', 'reference/troubleshooting', 'reference/contributing'],
				},
			],
		}),
	],
});
