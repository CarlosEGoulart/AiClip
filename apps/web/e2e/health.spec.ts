import { test, expect } from '@playwright/test'

test.describe('Health Check Flow', () => {
  test('displays health status from API', async ({ page }) => {
    await page.goto('/')

    // Should show loading state initially
    const status = page.getByRole('status')
    await expect(status).toBeVisible()

    // After API responds, should show health data
    await expect(page.getByText('Status: ok')).toBeVisible({ timeout: 10000 })
    await expect(page.getByText('Database: connected')).toBeVisible()
  })

  test('page loads without console errors', async ({ page }) => {
    const errors: string[] = []
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text())
    })

    await page.goto('/')
    await page.waitForTimeout(2000)

    // No critical console errors
    const criticalErrors = errors.filter(
      (e) => !e.includes('favicon') && !e.includes('HMR')
    )
    expect(criticalErrors).toHaveLength(0)
  })

  test('responsive layout at mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/')
    await expect(page.getByText('Health Status')).toBeVisible({ timeout: 10000 })
  })

  test('responsive layout at tablet viewport', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 })
    await page.goto('/')
    await expect(page.getByText('Health Status')).toBeVisible({ timeout: 10000 })
  })

  test('responsive layout at desktop viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto('/')
    await expect(page.getByText('Health Status')).toBeVisible({ timeout: 10000 })
  })
})
