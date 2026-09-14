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

test.describe('Project Management', () => {
  test('authenticated user can see project creation form', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Project User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()
    await expect(page.getByText('Create New Project')).toBeVisible()
    await expect(page.getByLabel('Project Name')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create Project' })).toBeVisible()
  })

  test('user can create a project after login', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Creator User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()

    await expect(page.getByText(projectName)).toBeVisible()
    await expect(page.getByLabel('Project Name')).toHaveValue('')
  })

  test('project persists after page reload', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Persistent User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.reload()
    await expect(page.getByText(projectName)).toBeVisible()
    await expect(page.getByText('Signed in as')).toBeVisible()
  })

  test('user can delete own project', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Deleter User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.getByRole('button', { name: `Delete ${projectName}` }).click()
    await expect(page.getByText('Delete this project?')).toBeVisible()
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click()

    await expect(page.getByText(projectName)).not.toBeVisible()
  })

  test('validation errors displayed for empty project name', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Validation User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

    await page.getByRole('button', { name: 'Create Project' }).click()

    await expect(page.getByText(/required/i)).toBeVisible()
  })

  test('multiple projects are listed', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Multi User', email)
    await expect(page.getByText('Signed in as')).toBeVisible()

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
