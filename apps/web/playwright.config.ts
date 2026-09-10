import { defineConfig } from '@playwright/test'

const API_HOST = 'http://127.0.0.1:8000'
const WEB_HOST = 'http://127.0.0.1:5173'

export default defineConfig({
  testDir: './e2e',
  timeout: 60000,
  retries: 0,
  outputDir: './test-results/output',
  reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
  use: {
    baseURL: WEB_HOST,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: [
    {
      command: 'php artisan serve --host=127.0.0.1 --port=8000 --tries=0',
      cwd: '../api',
      url: `${API_HOST}/api/v1/health`,
      reuseExistingServer: false,
      timeout: 60000,
    },
    {
      command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort',
      url: WEB_HOST,
      reuseExistingServer: false,
      timeout: 120000,
      env: {
        VITE_API_BASE_URL: '',
      },
    },
  ],
  projects: [
    {
      name: 'mobile',
      use: { browserName: 'chromium', viewport: { width: 390, height: 844 } },
    },
    {
      name: 'tablet',
      use: { browserName: 'chromium', viewport: { width: 768, height: 1024 } },
    },
    {
      name: 'desktop',
      use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } },
    },
  ],
})
