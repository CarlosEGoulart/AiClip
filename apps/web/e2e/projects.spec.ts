import { expect, test } from '@playwright/test'

function uniqueEmail() {
  return `e2e-proj-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

function uniqueProjectName() {
  return `Project ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`
}

async function waitForAuthReady(page: import('@playwright/test').Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(page.getByRole('heading', { name: 'Create your workspace' })),
  ).toBeVisible({ timeout: 15000 })
}

async function switchToRegister(page: import('@playwright/test').Page) {
  await waitForAuthReady(page)
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function registerUser(page: import('@playwright/test').Page, name: string, email: string) {
  // Get CSRF cookie (page.evaluate with fetch manages cookies automatically)
  await page.evaluate(async () => {
    await fetch('/sanctum/csrf-cookie', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    })
  })

  // Register via browser fetch (cookies auto-managed)
  await page.evaluate(async ({ name, email }) => {
    const response = await fetch('/api/v1/auth/register', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ name, email, password: 'password123', password_confirmation: 'password123' }),
    })
    if (response.status !== 201) {
      throw new Error(`Register failed: ${response.status}`)
    }
  }, { name, email })

  // Login via browser fetch
  await page.evaluate(async ({ email }) => {
    const response = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password: 'password123' }),
    })
    if (response.status !== 200) {
      throw new Error(`Login failed: ${response.status}`)
    }
  }, { email })

  // Navigate to app - browser now has session cookie
  await page.goto('/')
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 })
}

test.describe('Project Management', () => {
  test('authenticated user can see project creation form', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Project User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
    await expect(page.getByText('Create New Project')).toBeVisible()
    await expect(page.getByLabel('Project Name')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create Project' })).toBeVisible()
  })

  test('user can create a project after login', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Creator User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()
  })

  test('project persists after page reload', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Persistent User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.reload()
    await expect(page.getByText(projectName)).toBeVisible()
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
  })

  test('user can delete own project', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Deleter User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Confirm delete project' })).toBeVisible()
    await page.getByRole('button', { name: 'Confirm delete project' }).click()
    await expect(page.getByText(projectName)).not.toBeVisible()
  })

  test('validation errors displayed for empty project name', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Validation User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(/required/i)).toBeVisible()
  })

  test('multiple projects are listed', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Multi User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName1 = uniqueProjectName()
    const projectName2 = uniqueProjectName()

    await page.getByLabel('Project Name').fill(projectName1)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName1)).toBeVisible()

    await page.getByLabel('Project Name').fill(projectName2)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName2)).toBeVisible()

    const cards = page.locator('[role="article"]')
    await expect(cards).toHaveCount(2)
  })
})
