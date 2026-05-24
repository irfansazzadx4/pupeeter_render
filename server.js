const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");
const { execSync } = require("child_process");

const FONT_BASE64 = require("./font.b64.js");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const PORT = process.env.PORT || 10000;
const SECRET = process.env.SECRET || "nid_pdf_secret_2025";

// ─── CHROME PATH ───
function getChromePath() {
  // 1. Environment variable থেকে
  if (process.env.PUPPETEER_EXECUTABLE_PATH) {
    console.log("✅ Chrome from env:", process.env.PUPPETEER_EXECUTABLE_PATH);
    return process.env.PUPPETEER_EXECUTABLE_PATH;
  }

  // 2. Puppeteer auto-detect
  try {
    const path = puppeteer.executablePath();
    if (path) {
      console.log("✅ Chrome from puppeteer:", path);
      return path;
    }
  } catch (e) {
    console.log("⚠️ puppeteer.executablePath() failed:", e.message);
  }

  // 3. Manual search
  try {
    const path = execSync(
      "find /opt/render/.cache/puppeteer -name 'chrome' -type f 2>/dev/null | head -1"
    )
      .toString()
      .trim();
    if (path) {
      console.log("✅ Chrome found via search:", path);
      return path;
    }
  } catch (e) {
    console.log("⚠️ Manual search failed:", e.message);
  }

  // 4. Common paths
  const commonPaths = [
    "/usr/bin/google-chrome",
    "/usr/bin/chromium-browser",
    "/usr/bin/chromium",
  ];
  for (const p of commonPaths) {
    try {
      execSync(`test -f ${p}`);
      console.log("✅ Chrome found at common path:", p);
      return p;
    } catch {}
  }

  console.log("❌ Chrome not found anywhere!");
  return null;
}

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
  const chromePath = getChromePath();
  res.json({
    ok: true,
    time: Date.now(),
    fontLoaded: true,
    chromePath: chromePath || "NOT FOUND",
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

    const executablePath = getChromePath();
    if (!executablePath) {
      return res.status(500).json({
        error: "Chrome not found. Please check Render build logs.",
      });
    }

    const browser = await puppeteer.launch({
      headless: "new",
      executablePath,
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

    res.json({
      success: true,
      pdf: pdf.toString("base64"),
      size: pdf.length,
    });
  } catch (err) {
    console.error("❌ PDF Error:", err.message);
    res.status(500).json({ error: err.message });
  }
});

// ─── START ───
app.listen(PORT, () => {
  console.log("🚀 Server running on port", PORT);
  const chromePath = getChromePath();
  if (chromePath) {
    console.log("✅ Chrome ready:", chromePath);
  } else {
    console.log("❌ Chrome NOT found at startup!");
  }
});
