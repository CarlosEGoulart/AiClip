import { expect, test } from '@playwright/test'

const API_HOST = 'http://127.0.0.1:8000'

function uniqueEmail() {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

test.describe('Auth Lifecycle', () => {
  test('guest → register → authenticated', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()

    const email = uniqueEmail()
    await page.getByLabel('Name').fill('E2E User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()

    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('E2E User')).toBeVisible()
    await expect(page.getByText(email)).toBeVisible()
  })

  test('authenticated → page reload → still authenticated', async ({ page }) => {
    await page.goto('/')
    const email = uniqueEmail()
    await page.getByLabel('Name').fill('Reload User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.reload()
    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Reload User')).toBeVisible()
  })

  test('authenticated → logout → guest', async ({ page }) => {
    await page.goto('/')
    const email = uniqueEmail()
    await page.getByLabel('Name').fill('Logout User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).click()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
    await expect(page.getByText('Signed in as')).not.toBeVisible()
  })

  test('old authentication no longer works after logout', async ({ page }) => {
    await page.goto('/')
    const email = uniqueEmail()
    await page.getByLabel('Name').fill('Replay User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
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
    const apiContext = await page.context().request
    await apiContext.post(`${API_HOST}/api/v1/auth/register`, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: 'http://localhost:5173' },
      data: { name: 'Login User', email, password: 'password123', password_confirmation: 'password123' },
    })

    await page.goto('/')
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()

    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByRole('button', { name: 'Sign In' }).click()

    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Login User')).toBeVisible()
  })

  test('authenticated login → reload → still authenticated', async ({ page }) => {
    const email = uniqueEmail()
    const apiContext = await page.context().request
    await apiContext.post(`${API_HOST}/api/v1/auth/register`, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: 'http://localhost:5173' },
      data: { name: 'Persistent User', email, password: 'password123', password_confirmation: 'password123' },
    })

    await page.goto('/')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByRole('button', { name: 'Sign In' }).click()
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.reload()
    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Persistent User')).toBeVisible()
  })

  test('invalid credentials → generic controlled error', async ({ page }) => {
    await page.goto('/')
    await page.getByLabel('Email').fill('nobody@example.com')
    await page.getByLabel('Password').fill('wrongpassword')
    await page.getByRole('button', { name: 'Sign In' }).click()

    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Sign In' })).toBeVisible()
    await expect(page.getByText('Signed in as')).not.toBeVisible()
  })

  test('duplicate registration → validation feedback', async ({ page }) => {
    const email = uniqueEmail()
    const apiContext = await page.context().request
    await apiContext.post(`${API_HOST}/api/v1/auth/register`, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: 'http://localhost:5173' },
      data: { name: 'First User', email, password: 'password123', password_confirmation: 'password123' },
    })

    await page.goto('/')
    await page.getByRole('button', { name: 'Create one' }).click()
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()

    await page.getByLabel('Name').fill('Second User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()

    await expect(page.getByText('already been taken')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()
  })

  test('password confirmation mismatch → validation feedback', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('button', { name: 'Create one' }).click()
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()

    await page.getByLabel('Name').fill('Mismatch User')
    await page.getByLabel('Email').fill(uniqueEmail())
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('different-password')
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

    await page.goto('/')
    const xsrfCookie = await page.context().cookies().then(cookies => cookies.find(c => c.name === 'XSRF-TOKEN'))
    expect(xsrfCookie).toBeDefined()
    expect(xsrfCookie!.httpOnly).toBe(false)

    await page.getByLabel('Email').fill('test@example.com')
    await page.getByLabel('Password').fill('password')
    await page.getByRole('button', { name: 'Sign In' }).click()
    await page.waitForTimeout(1000)

    const cookies = await page.context().cookies()
    const sessionCookie = cookies.find(c => c.name === process.env.SESSION_COOKIE_NAME || c.name.includes('laravel_session'))
    expect(sessionCookie).toBeDefined()

    expect(csrfRequests.length).toBeGreaterThan(0)
  })

  test('no authentication bearer token in localStorage or sessionStorage', async ({ page }) => {
    await page.goto('/')
    const email = uniqueEmail()
    await page.getByLabel('Name').fill('Storage User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill('password123')
    await page.getByLabel('Confirm Password').fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
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
    const xsrf = cookies.find(c => c.name === 'XSRF-TOKEN')
    expect(xsrf).toBeDefined()

    const session = cookies.find(c => c.name.includes('laravel_session'))
    expect(session).toBeDefined()
    expect(session!.httpOnly).toBe(true)
  })
})
