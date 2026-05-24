const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");
const https = require("https");
const http = require("http");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const PORT = process.env.PORT || 10000;
const SECRET = process.env.SECRET || "nid_pdf_secret_2025";

// ─── FONT (Google Fonts থেকে নেওয়া - বেশি reliable) ───
const FONT_URL =
  "https://fonts.gstatic.com/s/solaimanlipi/v3/q5u7SOmy6GGizpXmua_risU3V4bKvnqMPH.woff2";

let fontBase64 = null;

// ─── FETCH ───
function fetchBuffer(url, redirectCount = 0) {
  return new Promise((resolve, reject) => {
    if (redirectCount > 5) return reject(new Error("Too many redirects"));
    const mod = url.startsWith("https") ? https : http;
    const req = mod.get(url, (res) => {
      if ([301, 302].includes(res.statusCode)) {
        return resolve(fetchBuffer(res.headers.location, redirectCount + 1));
      }
      if (res.statusCode !== 200) {
        return reject(new Error("HTTP " + res.statusCode + " for " + url));
      }
      const chunks = [];
      res.on("data", (c) => chunks.push(c));
      res.on("end", () => resolve(Buffer.concat(chunks)));
    });
    req.on("error", reject);
    req.setTimeout(20000, () => {
      req.destroy();
      reject(new Error("Timeout fetching " + url));
    });
  });
}

// ─── LOAD FONT ───
async function loadFont() {
  // Multiple fallback URLs
  const urls = [
    "https://fonts.gstatic.com/s/solaimanlipi/v3/q5u7SOmy6GGizpXmua_risU3V4bKvnqMPH.woff2",
    "https://raw.githubusercontent.com/itfoundry/solaimanlipi/master/fonts/SolaimanLipi.ttf",
    "https://github.com/itfoundry/solaimanlipi/raw/master/fonts/SolaimanLipi.ttf",
  ];

  for (const url of urls) {
    try {
      console.log("🔄 Trying font URL:", url);
      const buf = await fetchBuffer(url);
      fontBase64 = buf.toString("base64");
      console.log("✅ Font loaded from:", url, `(${buf.length} bytes)`);
      return;
    } catch (e) {
      console.log("⚠️ Font URL failed:", url, e.message);
    }
  }
  console.log("❌ All font URLs failed - will use system font");
}

// ─── CSS ───
function fontCSS() {
  if (!fontBase64) {
    // Fallback: system Bangla fonts
    return `* { font-family: 'Noto Sans Bengali', Arial, sans-serif !important; }`;
  }

  // woff2 নাকি ttf সেটা URL দিয়ে বোঝো
  const isWoff2 = true;
  const format = isWoff2 ? "woff2" : "truetype";
  const mimeType = isWoff2 ? "font/woff2" : "font/truetype";

  return `
@font-face {
  font-family: 'SolaimanLipi';
  src: url('data:${mimeType};base64,${fontBase64}') format('${format}');
  font-weight: normal;
  font-style: normal;
  font-display: block;
}
* {
  font-family: 'SolaimanLipi', 'Noto Sans Bengali', Arial, sans-serif !important;
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
  res.json({ ok: true, time: Date.now(), fontLoaded: !!fontBase64 });
});

// ─── PDF API ───
app.post("/pdf", async (req, res) => {
  try {
    if (req.body.secret !== SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { html } = req.body;
    if (!html) return res.status(400).json({ error: "No HTML" });

    if (!fontBase64) await loadFont();

    // Chrome executable path - Render cache path
    const executablePath =
      process.env.PUPPETEER_EXECUTABLE_PATH ||
      puppeteer.executablePath(); // auto-detect fallback

    console.log("🖥️ Chrome path:", executablePath);

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
        "--disable-extensions",
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
loadFont();
app.listen(PORT, () => console.log("🚀 Server running on port", PORT));
