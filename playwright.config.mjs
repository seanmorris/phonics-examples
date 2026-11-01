import {defineConfig} from '@playwright/test';

export default defineConfig({
	testDir: './tests/browser'
	, timeout: 60_000
	, expect: {timeout: 15_000}
	, use: {baseURL: 'http://127.0.0.1:4173', headless: true}
	, webServer: {
		command: 'npm run build && node scripts/serve.mjs --port 4173'
		, url: 'http://127.0.0.1:4173/'
		, timeout: 180_000
		, reuseExistingServer: true
	}
});

