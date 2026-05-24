const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");
const { execSync, execFileSync } = require("child_process");
const fs = require("fs");

const FONT_BASE64 = require("./font.b64.js");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const PORT = process.env.PORT || 10000;
const SECRET = process.env.SECRET || "nid_pdf_secret_2025";

// ─── CHROME INSTALL ON STARTUP ───
function ensureChrome() {
  const chromePath = "/opt/render/.cache/puppeteer/chrome/linux-131.0.6778.204/chrome-linux64/chrome";
  
  if (fs.existsSync(chromePath)) {
    console.log("✅ Chrome already exists:", chromePath);
    return chromePath;
  }

  console.log("🔄 Chrome not found, installing...");
  try {
    execSync("npx puppeteer browsers install chrome", {
      stdio: "inherit",
      timeout: 120000,
    });
    console.log("✅ Chrome installed!");
  } catch (e) {
    console.error("❌ Chrome install failed:", e.message);
  }

  // Install হওয়ার পর path find করো
  try {
    const found = execSync(
      "find /opt/render/.cache/puppeteer -name 'chrome' -type f 2>/dev/null | head -1"
    ).toString().trim();
    if (found) {
      console.log("✅ Chrome found at:", found);
      return found;
    }
  } catch {}

  return null;
}

let CHROME_PATH = null;

// ─── CSS ───
function fontCSS() {
  return `
@font-face {
  font-family: 'SolaimanLipi';
  src: url('data:font/truetype;base64,${FONT_BASE64}') format('truetype');
  font-weight: normal;
  font-style: normal;
  font-display: block;
}
* {
  font-family: 'SolaimanLipi', Arial, sans-serif !important;
}`;
}

// ─── HTML INJECT ───
function inject(html) {
  const style = `<style>${fontCSS()}</style>`;
  return html.includes("</head>")
    ? html.replace("</head>", style + "</head>")
    : style + html;
}

// ─── HEALTH CHECK ───
app.get("/ping", (req, res) => {
  res.json({
    ok: true,
    time: Date.now(),
    fontLoaded: true,
    chromePath: CHROME_PATH || "NOT FOUND",
    chromeExists: CHROME_PATH ? fs.existsSync(CHROME_PATH) : false,
  });
});

// ─── PDF API ───
app.post("/pdf", async (req, res) => {
  try {
    if (req.body.secret !== SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { html } = req.body;
    if (!html) return res.status(400).json({ error: "No HTML" });

    // Runtime-এ Chrome না থাকলে আবার install করো
    if (!CHROME_PATH || !fs.existsSync(CHROME_PATH)) {
      console.log("⚠️ Chrome missing at runtime, reinstalling...");
      CHROME_PATH = ensureChrome();
    }

    if (!CHROME_PATH) {
      return res.status(500).json({ error: "Chrome install failed" });
    }

    console.log("🖥️ Using Chrome:", CHROME_PATH);

    const browser = await puppeteer.launch({
      headless: "new",
      executablePath: CHROME_PATH,
      args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--single-process",
        "--no-zygote",
      ],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 900, deviceScaleFactor: 2 });
    await page.setContent(inject(html), { waitUntil: "networkidle0" });
    await page.evaluate(() => document.fonts?.ready);
    await new Promise((r) => setTimeout(r, 1500));

    const pdf = await page.pdf({ format: "A4", printBackground: true });
    await browser.close();

    res.json({ success: true, pdf: pdf.toString("base64"), size: pdf.length });
  } catch (err) {
    console.error("❌ PDF Error:", err.message);
    res.status(500).json({ error: err.message });
  }
});

// ─── START ───
app.listen(PORT, async () => {
  console.log("🚀 Server running on port", PORT);
  CHROME_PATH = ensureChrome();
});
