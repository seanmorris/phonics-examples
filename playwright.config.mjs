import {defineConfig} from '@playwright/test';
import {slow} from './tests/browser/slow.mjs';

export default defineConfig({
	testDir: './tests/browser'
	, timeout: slow(60_000)
	, expect: {timeout: slow(15_000)}
	, use: {
		baseURL: 'http://127.0.0.1:4173', headless: true
		// Without these Chromium draws WebGL on a software path about five times slower. Two terrain tests took
		// 667 seconds on two cores without them and 145 seconds with them.
		, launchOptions: {args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader']}
		// Recording every software-WebGL frame is costly; retain actions, DOM and network evidence.
		, trace: {mode: 'retain-on-failure', screenshots: false}
		, screenshot: 'only-on-failure'
	}
	, webServer: {
		command: 'npm run build && node scripts/serve.mjs --port 4173'
		, url: 'http://127.0.0.1:4173/'
		, timeout: 180_000
		, reuseExistingServer: true
	}
});

