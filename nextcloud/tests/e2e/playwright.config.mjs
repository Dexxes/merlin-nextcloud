// Browser smoke tests against a running Nextcloud with Merlin (see ../README.md).
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: '.',
	timeout: 90_000,
	expect: { timeout: 30_000 },
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL: process.env.MERLIN_TEST_BASE_URL || 'http://localhost:8080',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		locale: 'de-DE',
	},
	projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
