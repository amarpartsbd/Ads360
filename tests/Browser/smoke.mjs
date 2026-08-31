/**
 * Loads the application in a real browser and fails on anything the browser
 * complains about.
 *
 * The rest of the suite proves the server returns the right thing. Nothing in
 * it executes React, which is how three faults reached production in a row: a
 * content security policy that blocked the one inline script the page needs, a
 * button component that threw before rendering anything at all, and a sign-in
 * that landed administrators on a page refusing them. Every one was invisible
 * to PHPUnit, to `tsc`, to ESLint and to the Vite build, and every one was
 * obvious the moment a browser opened the page.
 *
 * So this asserts three things per page, in a browser, signed in as the kind of
 * person who uses it:
 *
 *   - nothing was logged as an error and nothing threw;
 *   - React actually mounted, rather than leaving an empty root;
 *   - the page reached is the page asked for, not a redirect to a refusal.
 *
 * Run it against a server with the demo data seeded:
 *
 *   npm run test:browser                      # http://127.0.0.1:8123
 *   npm run test:browser -- --url=https://…   # anything already running
 *
 * `tests/Browser/run.sh` does the seeding, building and serving for you.
 */
import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const url =
    (process.argv.find((a) => a.startsWith('--url=')) ?? '').slice(6) ||
    process.env.SMOKE_URL ||
    'http://127.0.0.1:8123';

const base = url.replace(/\/$/, '');

/**
 * A Chromium already on the machine, for images that ship one and do not want a
 * second copy downloaded. Playwright's own browsers are used when this is unset,
 * which is the ordinary case and what CI does.
 */
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined;

/**
 * The password DemoDataSeeder gives every fixture account. Only ever seeded
 * outside production — the seeder refuses to create these accounts there.
 */
const DEMO_PASSWORD = 'Ads360-Demo-Password!1';

/**
 * A time-based one-time code, so the test can enrol an authenticator the way a
 * person does rather than reaching past the requirement.
 *
 * Written out rather than pulled from a package: it is twenty lines, it is
 * RFC 6238 and cannot change, and a test dependency that generates
 * authentication codes is not one to take on trust.
 */
function base32Decode(input) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const character of input.replace(/=+$/, '').toUpperCase()) {
        const index = alphabet.indexOf(character);

        if (index !== -1) {
            bits += index.toString(2).padStart(5, '0');
        }
    }

    const bytes = [];

    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

function oneTimeCode(secret) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));

    const digest = createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = digest[digest.length - 1] & 0x0f;

    return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
}

/**
 * Enrols an authenticator by clicking through the screen, the way the person
 * enrolling has to (spec §9).
 *
 * Driven through the interface rather than through Fortify's endpoints on
 * purpose. The endpoints worked all along; what did not exist was a screen with
 * anything on it, so an administrator was held at a page that offered them no
 * way past it. A test that posted to the endpoints would have passed against
 * that page, which makes it the wrong test.
 */
async function enrolAuthenticator(page, base, password) {
    await page.goto(`${base}/admin/security/two-factor`, { waitUntil: 'networkidle' });

    await page.getByRole('button', { name: /begin setup/i }).click();

    // Enrolment is a privileged action, so the password is asked for again.
    const passwordField = page.locator('input[type="password"]');
    await passwordField.waitFor({ state: 'visible', timeout: 10000 });
    await passwordField.fill(password);
    await page.getByRole('button', { name: /continue/i }).click();

    // The secret is offered for anyone whose camera cannot manage the QR code,
    // and this test reads it for the same reason.
    const secretField = page.getByTestId('two-factor-secret');
    await secretField.waitFor({ state: 'visible', timeout: 10000 });
    const secret = (await secretField.innerText()).replace(/\s/g, '');

    await page.getByTestId('two-factor-qr').waitFor({ state: 'visible', timeout: 10000 });

    await page.locator('input[name="code"]').fill(oneTimeCode(secret));
    await page.getByRole('button', { name: /confirm and turn on/i }).click();

    await page.getByText(/two-factor authentication is on/i).waitFor({ timeout: 10000 });
}

const journeys = [
    {
        who: 'platform owner',
        email: 'owner@ads360.test',
        // Required before the administration area serves anything (spec §9).
        enrolsAuthenticator: true,
        landsOn: '/admin/dashboard',
        pages: [
            '/admin/dashboard',
            '/admin/clients',
            '/admin/campaigns',
            '/admin/ad-accounts',
            '/admin/analytics',
            '/admin/finance/wallets',
            '/admin/finance/approvals',
            '/admin/finance/deposits',
            '/admin/finance/exchange-rates',
            '/admin/finance/pricing',
            '/admin/verification',
            '/admin/risk',
            '/admin/audit-logs',
        ],
    },
    {
        who: 'client owner',
        email: 'client.owner@demo-retail.test',
        landsOn: '/app/dashboard',
        pages: [
            '/app/dashboard',
            '/app/campaigns',
            '/app/creatives',
            '/app/wallet',
            '/app/wallet/transactions',
            '/app/analytics',
            '/app/assets',
            '/app/team',
            '/app/verification',
            '/app/settings/organization',
        ],
    },
];

/**
 * Noise a browser produces that says nothing about this application: a preload
 * hint the page did not get round to using, and requests blocked by whatever
 * the person running this has installed.
 */
const ignorable = [/was preloaded using link preload but not used/i, /favicon/i];

const isNoise = (text) => ignorable.some((pattern) => pattern.test(text));

async function run() {
    const browser = await chromium.launch({ executablePath });
    const failures = [];

    for (const journey of journeys) {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await context.newPage();

        let current = '(signing in)';
        const complain = (what) => {
            if (!isNoise(what)) {
                failures.push(`${journey.who} — ${current}\n    ${what}`);
            }
        };

        page.on('console', (m) => m.type() === 'error' && complain(`console error: ${m.text()}`));
        page.on('pageerror', (e) => complain(`uncaught: ${e.message}`));
        page.on('requestfailed', (r) => complain(`request failed: ${r.url()}`));

        // Signed in through the form rather than by planting a session cookie:
        // where a sign-in lands is itself something that has been wrong before.
        await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
        await page.fill('input[type="email"]', journey.email);
        await page.fill('input[type="password"]', DEMO_PASSWORD);
        await Promise.all([
            page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        if (journey.enrolsAuthenticator) {
            current = '(enrolling an authenticator)';
            await enrolAuthenticator(page, base, DEMO_PASSWORD);
            await page.goto(`${base}${journey.landsOn}`, { waitUntil: 'networkidle' });
        }

        const landed = new URL(page.url()).pathname;

        if (landed !== journey.landsOn) {
            failures.push(`${journey.who} — landed on ${landed}, expected ${journey.landsOn}`);
        }

        for (const path of journey.pages) {
            current = path;
            await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });

            const arrived = new URL(page.url()).pathname;

            if (arrived !== path) {
                complain(`redirected to ${arrived}`);
                continue;
            }

            const mounted = await page.evaluate(() => {
                const root = document.getElementById('app');

                return root ? root.children.length : 0;
            });

            if (mounted === 0) {
                complain('React did not mount — #app is empty');
            }
        }

        // A sidebar with nothing in it is a working page nobody can use, and
        // looks identical to a broken one from the outside.
        current = journey.landsOn;
        await page.goto(`${base}${journey.landsOn}`, { waitUntil: 'networkidle' });
        const navLinks = await page.locator('nav a[href]').count();

        if (navLinks === 0) {
            complain('the navigation has no links at all');
        } else {
            console.log(`  ${journey.who}: ${navLinks} navigation links`);
        }

        await context.close();
    }

    await browser.close();

    if (failures.length > 0) {
        console.error(`\n${failures.length} problem(s):\n`);
        failures.forEach((f) => console.error(`  - ${f}`));
        process.exit(1);
    }

    console.log('\nNo console errors, nothing unmounted, every page reachable.');
}

run().catch((error) => {
    console.error(`\nThe smoke test could not run against ${base}:\n  ${error.message}`);
    console.error('\nIs the server up and the demo data seeded? tests/Browser/run.sh does both.');
    process.exit(1);
});
