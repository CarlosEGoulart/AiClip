import { expect, test } from '@playwright/test'

const API_HOST = 'http://127.0.0.1:8000'

function uniqueEmail() {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

async function waitForAuthReady(page: import('@playwright/test').Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign In' }).or(page.getByRole('heading', { name: 'Create Account' })),
  ).toBeVisible({ timeout: 15000 })
}

async function switchToRegister(page: import('@playwright/test').Page) {
  await waitForAuthReady(page)
  const registerHeading = page.getByRole('heading', { name: 'Create Account' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function registerUser(page: import('@playwright/test').Page, name: string, email: string) {
  await switchToRegister(page)
  await page.getByLabel('Name').fill(name)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('password123')
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123')
  await page.getByRole('button', { name: 'Create Account' }).click()
}

test.describe('Auth Lifecycle', () => {
  test('guest → register → authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'E2E User', email)

    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('E2E User')).toBeVisible()
    await expect(page.getByText(email)).toBeVisible()
  })

  test('authenticated → page reload → still authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Reload User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.reload()
    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Reload User')).toBeVisible()
  })

  test('authenticated → logout → guest', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Logout User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
    await expect(page.getByText('Signed in as')).not.toBeVisible()
  })

  test('old authentication no longer works after logout', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Replay User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    const cookies = await page.context().cookies()
    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()

    await page.context().addCookies(cookies)
    await page.goto('/')
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
    await expect(page.getByText('Replay User')).not.toBeVisible()
  })

  test('existing user → login → authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Login User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()

    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByRole('button', { name: 'Sign In' }).click()

    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Login User')).toBeVisible()
  })

  test('authenticated login → reload → still authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Persistent User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()

    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByRole('button', { name: 'Sign In' }).click()
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.reload()
    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Persistent User')).toBeVisible()
  })

  test('invalid credentials → generic controlled error', async ({ page }) => {
    await waitForAuthReady(page)
    await page.getByLabel('Email').fill('nobody@example.com')
    await page.getByLabel('Password', { exact: true }).fill('wrongpassword')
    await page.getByRole('button', { name: 'Sign In' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
    await expect(page.getByText('Signed in as')).not.toBeVisible()
  })

  test('duplicate registration → validation feedback', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'First User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()

    await switchToRegister(page)
    await page.getByLabel('Name').fill('Second User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByLabel('Confirm Password', { exact: true }).fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()

    await expect(page.getByText('already been taken')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()
  })

  test('password confirmation mismatch → validation feedback', async ({ page }) => {
    await switchToRegister(page)
    await page.getByLabel('Name').fill('Mismatch User')
    await page.getByLabel('Email').fill(uniqueEmail())
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByLabel('Confirm Password', { exact: true }).fill('different-password')
    await page.getByRole('button', { name: 'Create Account' }).click()

    await expect(page.getByText('confirmation')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()
  })
})

test.describe('CSRF and Storage Security', () => {
  test('CSRF cookie is set and XSRF token is sent with state-changing requests', async ({ page }) => {
    const csrfRequests: string[] = []

    page.on('request', (request) => {
      const url = new URL(request.url()).pathname
      if (url.startsWith('/api/v1/auth/') && request.method() === 'POST') {
        const xsrf = request.headers()['x-xsrf-token']
        if (xsrf) csrfRequests.push(xsrf)
      }
    })

    await waitForAuthReady(page)

    await page.getByLabel('Email').fill('test@example.com')
    await page.getByLabel('Password', { exact: true }).fill('password')
    await page.getByRole('button', { name: 'Sign In' }).click()
    await page.waitForTimeout(1000)

    expect(csrfRequests.length).toBeGreaterThan(0)
  })

  test('no authentication bearer token in localStorage or sessionStorage', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Storage User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    const localStorageKeys = await page.evaluate(() => Object.keys(localStorage))
    const sessionStorageKeys = await page.evaluate(() => Object.keys(sessionStorage))

    for (const key of localStorageKeys) {
      const value = await page.evaluate((k) => localStorage.getItem(k), key)
      expect(value).not.toMatch(/bearer/i)
      expect(value).not.toMatch(/token.*auth/i)
    }

    for (const key of sessionStorageKeys) {
      const value = await page.evaluate((k) => sessionStorage.getItem(k), key)
      expect(value).not.toMatch(/bearer/i)
      expect(value).not.toMatch(/token.*auth/i)
    }

    const cookies = await page.context().cookies()
    expect(cookies.length).toBeGreaterThan(0)
  })
})
