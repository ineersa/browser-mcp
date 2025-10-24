#!/usr/bin/env node
'use strict';

const [, , targetUrl] = process.argv;

if (!targetUrl) {
  console.error('Usage: puppeteer-fetch.js <url>');
  process.exit(1);
}

const maxWaitMs = Number(process.env.PUPPETEER_MAX_WAIT_MS ?? 30000);
const idleWaitMs = Number(process.env.PUPPETEER_IDLE_WAIT_MS ?? 2000);
const launchArgs = [
  '--no-sandbox',
  '--disable-setuid-sandbox',
  '--disable-blink-features=AutomationControlled',
  '--disable-dev-shm-usage',
  '--disable-accelerated-2d-canvas',
  '--disable-gpu',
];
const scrollDelayMs = Number(process.env.PUPPETEER_SCROLL_DELAY_MS ?? 1000);
const maxScrolls = Number(process.env.PUPPETEER_MAX_SCROLLS ?? 100);
const path = require('path');

async function delay(page, ms) {
  if (typeof page.waitForTimeout === 'function') {
    await page.waitForTimeout(ms);
    return;
  }
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function scrollDown(page) {
  let previousHeight = -1;
  let scrollCount = 0;

  while (scrollCount < maxScrolls) {
    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight);
    });

    await delay(page, scrollDelayMs);

    const newHeight = await page.evaluate(() => document.body.scrollHeight);
    if (newHeight === previousHeight) {
      break;
    }

    previousHeight = newHeight;
    scrollCount += 1;
  }

  await page.evaluate(() => {
    window.scrollTo(0, 0);
  });
}

async function acceptCookies(page) {
  try {
    // Wait a bit for cookie banner to appear
    await delay(page, 500);

    // Common cookie acceptance selectors for various sites including GitHub
    const cookieSelectors = [
      'button[data-action="click:cookie-banner#accept"]', // GitHub specific
      'button:has-text("Accept")',
      'button:has-text("Accept all")',
      'button:has-text("Accept cookies")',
      'button[aria-label*="Accept"]',
      'button[aria-label*="accept"]',
      'button#onetrust-accept-btn-handler',
      'button.accept-cookies',
      'button.cookie-accept',
      'a[href*="accept"]',
    ];

    for (const selector of cookieSelectors) {
      try {
        const button = await page.$(selector);
        if (button) {
          await button.click();
          await delay(page, 300);
          break;
        }
      } catch {
        // Try next selector
      }
    }
  } catch {
    // Best effort - don't fail if cookie acceptance fails
  }
}

async function waitForIncludeFragments(page) {
  try {
    await page.evaluate(async () => {
      const fragments = Array.from(document.querySelectorAll('include-fragment[src], include-fragment[data-src]'));
      if (fragments.length === 0) {
        return;
      }

      const listeners = fragments.map((fragment) => {
        if (fragment.loaded && typeof fragment.loaded.then === 'function') {
          return fragment.loaded.catch(() => {});
        }

        return new Promise((resolve) => {
          const clean = () => {
            fragment.removeEventListener('load', onLoad);
            fragment.removeEventListener('error', onError);
            resolve();
          };
          function onLoad() {
            clean();
          }
          function onError() {
            clean();
          }
          fragment.addEventListener('load', onLoad, { once: true });
          fragment.addEventListener('error', onError, { once: true });
          // Some include-fragment elements store the resolved HTML in innerHTML immediately.
          // Resolve quickly if the element already has child nodes.
          if (fragment.childElementCount > 0) {
            clean();
          }
        });
      });

      await Promise.all(listeners);
    });
  } catch {
    // include-fragment only exists on some GitHub pages; ignore errors
  }
}

const extraModulePaths = (() => {
  const sep = process.platform === 'win32' ? ';' : ':';
  const paths = [];
  const envPath = process.env.PUPPETEER_MODULE_PATH;
  if (envPath) {
    paths.push(...envPath.split(sep));
  }
  if (process.env.NODE_PATH) {
    paths.push(...process.env.NODE_PATH.split(sep));
  }
  try {
    const { execSync } = require('child_process');
    const npmRoot = execSync('npm root -g', {
      stdio: ['ignore', 'pipe', 'ignore'],
      encoding: 'utf8',
    }).trim();
    if (npmRoot) {
      paths.push(npmRoot);
    }
  } catch {
    // ignore missing npm
  }

  return [...new Set(paths)]
    .filter(Boolean)
    .map((p) => path.resolve(p));
})();

function loadModule(name) {
  try {
    return require(name);
  } catch (err) {
    for (const candidate of extraModulePaths) {
      try {
        const resolved = require.resolve(name, { paths: [candidate] });
        return require(resolved);
      } catch {
        continue;
      }
    }
    throw err;
  }
}

async function main() {
  let puppeteer = null;
  let extraLoaded = false;
  try {
    const extra = loadModule('puppeteer-extra');
    try {
      const StealthPlugin = loadModule('puppeteer-extra-plugin-stealth');
      extra.use(StealthPlugin());
      puppeteer = extra;
      extraLoaded = true;
    } catch {
      extraLoaded = false;
    }
  } catch {
    extraLoaded = false;
  }

  if (null === puppeteer) {
    puppeteer = loadModule('puppeteer');
  }

  const browser = await puppeteer.launch({ headless: true, args: launchArgs });

  try {
    const page = await browser.newPage();

    // Set a realistic user agent
    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    );

    // Set viewport to common resolution
    await page.setViewport({ width: 1920, height: 1080 });

    // Enhanced stealth measures
    await page.evaluateOnNewDocument(() => {
      // Webdriver property
      Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined,
      });

      // Override the plugins length
      Object.defineProperty(navigator, 'plugins', {
        get: () => [1, 2, 3, 4, 5],
      });

      // Override languages
      Object.defineProperty(navigator, 'languages', {
        get: () => ['en-US', 'en'],
      });

      // Chrome runtime
      window.chrome = {
        runtime: {},
      };

      // Permissions
      const originalQuery = window.navigator.permissions.query;
      window.navigator.permissions.query = (parameters) =>
        parameters.name === 'notifications'
          ? Promise.resolve({ state: Notification.permission })
          : originalQuery(parameters);
    });

    page.setDefaultNavigationTimeout(maxWaitMs);
    page.setDefaultTimeout(maxWaitMs);

    await page.goto(targetUrl, {
      waitUntil: ['domcontentloaded', 'networkidle2'],
      timeout: maxWaitMs,
    });

    // Accept cookies if banner appears
    await acceptCookies(page);

    // Add random delay to appear more human-like
    await delay(page, Math.random() * 1000 + 500);

    await scrollDown(page);
    await waitForIncludeFragments(page);
    await delay(page, idleWaitMs);

    const html = await page.content();
    process.stdout.write(html);
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  const message = err && err.stack ? err.stack : String(err);
  console.error(message);
  process.exit(2);
});
