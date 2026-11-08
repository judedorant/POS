import { defineConfig } from '@playwright/test'
export default defineConfig({testDir:'tests/Browser',timeout:60000,workers:1,use:{baseURL:process.env.POS_BROWSER_URL??'http://localhost:8880',launchOptions:{executablePath:process.env.CHROMIUM_PATH||undefined,args:['--no-sandbox']}}})
