const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");
const { execSync } = require("child_process");
const fs = require("fs");

const FONT_BASE64 = require("./font.b64.js");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const PORT = process.env.PORT || 10000;
const SECRET = process.env.SECRET || "nid_pdf_secret_2025";

// ─── CHROME ───
function ensureChrome() {
  try {
    const found = execSync(
      "find /opt/render/.cache/puppeteer -name 'chrome' -type f 2>/dev/null | head -1"
    ).toString().trim();
    if (found && fs.existsSync(found)) {
      console.log("✅ Chrome found:", found);
      return found;
    }
  } catch {}

  console.log("🔄 Installing Chrome...");
  try {
    execSync("npx puppeteer browsers install chrome", {
      stdio: "inherit",
      timeout: 120000,
    });
  } catch (e) {
    console.error("❌ Chrome install failed:", e.message);
    return null;
  }

  try {
    const found = execSync(
      "find /opt/render/.cache/puppeteer -name 'chrome' -type f 2>/dev/null | head -1"
    ).toString().trim();
    if (found && fs.existsSync(found)) {
      console.log("✅ Chrome installed:", found);
      return found;
    }
  } catch {}

  return null;
}

let CHROME_PATH = null;

// ─── FONT CSS ───
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

// ─── PING ───
app.get("/ping", (req, res) => {
  res.json({
    ok: true,
    time: Date.now(),
    fontLoaded: true,
    chromePath: CHROME_PATH || "NOT FOUND",
    chromeExists: CHROME_PATH ? fs.existsSync(CHROME_PATH) : false,
  });
});

// ─── PDF ───
app.post("/pdf", async (req, res) => {
  let browser = null;
  try {
    if (req.body.secret !== SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { html } = req.body;
    if (!html) return res.status(400).json({ error: "No HTML" });

    // Chrome আছে কিনা চেক করো
    if (!CHROME_PATH || !fs.existsSync(CHROME_PATH)) {
      console.log("⚠️ Chrome missing, reinstalling...");
      CHROME_PATH = ensureChrome();
    }

    if (!CHROME_PATH) {
      return res.status(500).json({ error: "Chrome install failed" });
    }

    browser = await puppeteer.launch({
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

    const pdfBuffer = await page.pdf({ format: "A4", printBackground: true });
    await browser.close();
    browser = null;

    // ✅ Header validate করো
    const header = pdfBuffer.slice(0, 4).toString("ascii");
    console.log("📄 PDF header:", header, "| Size:", pdfBuffer.length);

    if (header !== "%PDF") {
      return res.status(500).json({ error: "Puppeteer invalid PDF generated" });
    }

    // ✅ Base64 encode করো
    const base64 = pdfBuffer.toString("base64");

    // ✅ Verify: decode করে আবার check করো
    const verify = Buffer.from(base64, "base64");
    const verifyHeader = verify.slice(0, 4).toString("ascii");
    console.log("✅ Verify header:", verifyHeader, "| Size:", verify.length);

    res.json({
      success: true,
      pdf: base64,
      size: pdfBuffer.length,
    });

  } catch (err) {
    if (browser) {
      try { await browser.close(); } catch {}
    }
    console.error("❌ PDF Error:", err.message);
    res.status(500).json({ error: err.message });
  }
});

// ─── START ───
app.listen(PORT, () => {
  console.log("🚀 Server running on port", PORT);
  CHROME_PATH = ensureChrome();
});
