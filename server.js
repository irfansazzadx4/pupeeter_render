const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");
const https = require("https");
const http = require("http");

const app = express();

app.use(cors());
app.use(express.json({ limit: "50mb" }));

const SECRET = process.env.SECRET || "nid_pdf_secret_2025";
const PORT = process.env.PORT || 8080;

/*──────────────────────────────
  FONT CONFIG
──────────────────────────────*/

const FONT_URL = "https://fonts.maateen.me/solaiman-lipi/SolaimanLipi.ttf";

let fontBase64 = null;
let fontLoaded = false;

/*──────────────────────────────
  FETCH
──────────────────────────────*/

function fetchBuffer(url) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith("https") ? https : http;

    const req = mod.get(url, res => {
      if ([301,302].includes(res.statusCode)) {
        return resolve(fetchBuffer(res.headers.location));
      }

      if (res.statusCode !== 200) {
        return reject(new Error("HTTP " + res.statusCode));
      }

      const chunks = [];
      res.on("data", c => chunks.push(c));
      res.on("end", () => resolve(Buffer.concat(chunks)));
    });

    req.on("error", reject);
    req.setTimeout(15000, () => req.destroy());
  });
}

/*──────────────────────────────
  LOAD FONT
──────────────────────────────*/

async function loadFont() {
  try {
    const buf = await fetchBuffer(FONT_URL);
    fontBase64 = buf.toString("base64");
    fontLoaded = true;
    console.log("✅ Font Loaded");
  } catch (e) {
    console.log("❌ Font Load Failed:", e.message);
  }
}

/*──────────────────────────────
  CSS
──────────────────────────────*/

function getFontCSS() {
  return `
@font-face {
  font-family: 'SolaimanLipi';
  src: url('data:font/truetype;base64,${fontBase64}') format('truetype');
  font-weight: normal;
  font-style: normal;
  font-display: swap;
}

* {
  font-family: 'SolaimanLipi', Arial, sans-serif !important;
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
}
`;
}

/*──────────────────────────────
  INJECT HTML
──────────────────────────────*/

function inject(html) {
  const style = `<style>${getFontCSS()}</style>`;
  return html.includes("</head>")
    ? html.replace("</head>", style + "</head>")
    : style + html;
}

/*──────────────────────────────
  KEEP ALIVE ROUTE
──────────────────────────────*/

app.get("/ping", (req, res) => {
  res.json({ ok: true, time: Date.now() });
});

/*──────────────────────────────
  PDF API
──────────────────────────────*/

app.post("/pdf", async (req, res) => {
  try {
    if (req.body.secret !== SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { html } = req.body;
    if (!html) {
      return res.status(400).json({ error: "No HTML" });
    }

    if (!fontLoaded) await loadFont();

    const browser = await puppeteer.launch({
      headless: "new",
      args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu"
      ],
    });

    try {
      const page = await browser.newPage();

      await page.setViewport({
        width: 1200,
        height: 900,
        deviceScaleFactor: 2
      });

      await page.setContent(inject(html), {
        waitUntil: "networkidle0"
      });

      await page.evaluate(() => document.fonts?.ready);

      await new Promise(r => setTimeout(r, 1500));

      const pdf = await page.pdf({
        format: "A4",
        printBackground: true
      });

      return res.json({
        success: true,
        pdf: pdf.toString("base64"),
        size: pdf.length
      });

    } finally {
      await browser.close().catch(() => {});
    }

  } catch (err) {
    console.log(err);
    res.status(500).json({ error: err.message });
  }
});

/*──────────────────────────────
  STATUS
──────────────────────────────*/

app.get("/", (req, res) => {
  res.json({
    status: "ok",
    fontLoaded,
    service: "PDF API"
  });
});

/*──────────────────────────────
  START FONT + SERVER
──────────────────────────────*/

loadFont();

app.listen(PORT, () => {
  console.log("🚀 Server running on", PORT);
});
