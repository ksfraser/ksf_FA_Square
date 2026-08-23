async function faLogin(page, baseURL) {
  await page.goto(baseURL + '/');
  await page.waitForSelector('input[name="user_name_entry_field"]', { timeout: 10000 });
  await page.fill('input[name="user_name_entry_field"]', 'opencode');
  await page.fill('input[name="password"]', 'opencode');
  await page.selectOption('select[name="company_login_name"]', '0');
  await Promise.all([
    page.waitForNavigation({ timeout: 15000 }),
    page.click('input[type="submit"], button[type="submit"]'),
  ]);
  await page.waitForSelector('a:has-text("Logout")', { timeout: 15000 });
}

async function navigateToImportPage(page, baseURL) {
  await page.goto(baseURL + '/modules/ksf_FA_Square/pages/import.php');
  await page.waitForLoadState('networkidle');
}

module.exports = { faLogin, navigateToImportPage };
