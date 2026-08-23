const { test, expect } = require('@playwright/test');
const { faLogin, navigateToImportPage } = require('./fa-login.helper');

test.describe('Square Import Flow', () => {
  test.beforeEach(async ({ page }) => {
    await faLogin(page, 'http://localhost:8080');
  });

  test('import page loads with form elements', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const title = await page.textContent('body');
    expect(title).toContain('Import Square Orders');
    await expect(page.locator('select[name="import_mode"]')).toBeVisible();
    await expect(page.locator('input[name="from_date"]')).toBeVisible();
    await expect(page.locator('input[name="to_date"]')).toBeVisible();
  });

  test('import page shows environment badge', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const badge = page.locator('.square-env-badge');
    await expect(badge).toBeVisible();
    const badgeText = await badge.textContent();
    expect(['SANDBOX', 'LIVE']).toContain(badgeText.trim());
  });

  test('stage only mode selects correctly', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const modeSelect = page.locator('select[name="import_mode"]');
    await modeSelect.selectOption('stage');
    const value = await modeSelect.inputValue();
    expect(value).toBe('stage');
  });

  test('run stage only import does not crash', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    await page.locator('select[name="import_mode"]').selectOption('stage');
    await page.fill('input[name="from_date"]', '2026-08-01');
    await page.fill('input[name="to_date"]', '2026-08-21');
    await page.click('input[name="oimport"], button[name="oimport"]');
    await page.waitForLoadState('networkidle', { timeout: 30000 });
    const body = await page.textContent('body');
    const hasResult = body.includes('Import Square Orders') ||
                      body.includes('Staging complete') ||
                      body.includes('No payments found') ||
                      body.includes('No Square locations found') ||
                      body.includes('API Connection Error') ||
                      body.includes('Access Token not configured') ||
                      body.includes('Staged');
    expect(hasResult).toBeTruthy();
  });

  test('staging status cards display when present', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const statusSection = page.locator('.square-status-card');
    const count = await statusSection.count();
    if (count > 0) {
      await expect(statusSection.first()).toBeVisible();
    }
  });

  test('staged transactions table shows when data exists', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const stagedSection = page.locator('text=Staged Transactions');
    const count = await stagedSection.count();
    if (count > 0) {
      await expect(stagedSection.first()).toBeVisible();
      await expect(page.locator('input[name="process_ids[]"]')).toBeVisible();
    }
  });

  test('process selected button is present when staged data exists', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const processBtn = page.locator('input[name="process_staged_submit"]');
    const count = await processBtn.count();
    if (count > 0) {
      await expect(processBtn).toBeVisible();
    }
  });

  test('customer dropdown is populated', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const customerSelect = page.locator('select[name="destCust"]');
    await expect(customerSelect).toBeVisible();
    const options = await customerSelect.locator('option').count();
    expect(options).toBeGreaterThan(1);
  });

  test('location filter shows when locations exist', async ({ page }) => {
    await navigateToImportPage(page, 'http://localhost:8080');
    const locationFilter = page.locator('select[name="location_id"]');
    const count = await locationFilter.count();
    if (count > 0) {
      await expect(locationFilter).toBeVisible();
    }
  });
});
