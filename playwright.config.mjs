// Local browser check only (tests/Browser/README.md): it needs the running site, so it is NOT part of CI.
import { defineConfig } from '@playwright/test';

const rules = process.env.PW_HOST_RULES || '';

export default defineConfig({
    testDir: 'tests/Browser',
    timeout: 120000,
    workers: 1,
    reporter: 'list',
    outputDir: 'tests/Browser/out/results',
    use: {
        baseURL: process.env.SITE_URL || 'https://easyco-main.test',
        ignoreHTTPSErrors: true,
        locale: 'bg-BG',
        viewport: { width: 1280, height: 900 },
        launchOptions: {
            executablePath: process.env.PW_CHROMIUM || undefined,
            args: rules ? [`--host-resolver-rules=${rules}`] : [],
        },
    },
});
