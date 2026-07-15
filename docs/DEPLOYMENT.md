# Deployment notes

## PDF rendering (invoices, receipts, report exports)

### The symptom

On Laravel Cloud, downloading an invoice fails with:

```
The command "… node …/vendor/spatie/browsershot/…/browser.cjs …" failed.
Error: Cannot find module 'puppeteer'
```

### Why

The default `browsershot` driver shells out to **Node**, which must be able to
`require('puppeteer')`, and puppeteer in turn needs a **Chrome binary**. Node
itself is present on Laravel Cloud (the error above proves it — Node v24 ran),
but `node_modules` is not part of the runtime image, so the `puppeteer` module
can't be resolved. This is a known limitation: Laravel Cloud gives you no
reliable place to keep Node modules and a ~150 MB Chrome binary at runtime.

This is precisely why `spatie/laravel-pdf` v2 (we're on v2.12) moved to a
**driver architecture** — the driver is swappable via config with no change to
application code. Our four PDF call sites just say `Pdf::view(…)`; the driver
decides how the HTML becomes a PDF.

### The fix — pick a driver that needs no Node and no Chrome binary

Set these in the Laravel Cloud environment. **No code change, no deploy script.**

#### Option A — Cloudflare Browser Rendering (simplest)

Real Chrome, hosted by Cloudflare. Nothing to install.

1. Create a Cloudflare account → **Manage account → Account API tokens**
2. Create a token with the **Account.Browser Run** permission (read + write)
3. Set on Laravel Cloud:

```dotenv
LARAVEL_PDF_DRIVER=cloudflare
CLOUDFLARE_API_TOKEN=your-token
CLOUDFLARE_ACCOUNT_ID=your-account-id
```

Free plan allows **6 requests/minute and 10 minutes of rendering per day** —
fine for the council's invoice volume, but note it's a per-minute cap if
someone bulk-downloads. Paid plans lift it.

#### Option B — Gotenberg (keeps documents under council control)

Same Chrome rendering, but you host it. Use this if the council does not want
invoice contents (tenant names, national ID / company registration numbers)
leaving its own infrastructure — see the data note below. Requires somewhere to
run the Gotenberg Docker image, reachable from the app:

```dotenv
LARAVEL_PDF_DRIVER=gotenberg
GOTENBERG_URL=https://gotenberg.internal.example
GOTENBERG_USERNAME=…   # optional
GOTENBERG_PASSWORD=…   # optional
```

#### Option C — stay on Browsershot (self-hosted / VPS only)

Only viable where you control the box and can keep `node_modules` + Chrome on
disk. Install the dependencies, then point the config at them:

```dotenv
LARAVEL_PDF_NODE_MODULES_PATH=/var/www/html/node_modules
LARAVEL_PDF_CHROME_PATH=/path/to/chrome-headless-shell
LARAVEL_PDF_NO_SANDBOX=true          # required in any container
```

`LARAVEL_PDF_NO_SANDBOX=true` is mandatory when Chrome runs inside a container;
without it Chrome exits immediately.

#### Not an option — DOMPDF

`dompdf` needs no binaries and looks tempting, but **do not use it here**:

- it has no Thaana/RTL shaping, so every Dhivehi tenant name in the register
  would be mangled, and
- it doesn't support the flexbox letterhead in `resources/views/pdf/*`.

### Data residency note

Options A and B send the rendered document's HTML — which contains tenant
names, registry numbers and addresses — to the rendering host. The app is
already hosted outside the Maldives on Laravel Cloud, so Option A is not a new
category of exposure, but if the council wants documents rendered only on
infrastructure it controls, use **Option B** with a self-hosted Gotenberg.

### Thaana fonts — important, independent of driver

The register holds Dhivehi (Thaana) property and tenant names. PDF templates
therefore embed **Noto Sans Thaana** (SIL OFL) as base64 via
`resources/views/pdf/partials/fonts.blade.php`.

This is not optional polish. Slim Linux containers and hosted renderers ship no
Thaana fonts, so without the embedded face every Thaana name renders as tofu
boxes (□□□). It only ever looked correct in local development because macOS
happens to ship a Thaana font. The `unicode-range` on the `@font-face` confines
it to Thaana codepoints, so Latin text still uses the system stack.

Verify a deployed PDF actually embeds it — the font name should appear with a
subset prefix:

```bash
strings invoice.pdf | grep -i notosansthaana    # → FAAAAA+NotoSansThaana
```

### Local development

Unchanged — `browsershot` remains the default driver, using the project's local
puppeteer and downloaded `chrome-headless-shell`:

```bash
npm install
npx puppeteer browsers install chrome-headless-shell
```
