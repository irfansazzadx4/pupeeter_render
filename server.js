const express = require("express");
const puppeteer = require("puppeteer");
const cors = require("cors");

const FONT_BASE64 = require("./font.b64.js");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const PORT = process.env.PORT || 10000;
const SECRET = process.env.SECRET || "nid_pdf_secret_2025";

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
  res.json({ ok: true, time: Date.now(), fontLoaded: true });
});

// ─── PDF API ───
app.post("/pdf", async (req, res) => {
  try {
    if (req.body.secret !== SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { html } = req.body;
    if (!html) return res.status(400).json({ error: "No HTML" });

    const executablePath =
      process.env.PUPPETEER_EXECUTABLE_PATH || puppeteer.executablePath();

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
app.listen(PORT, () => console.log("🚀 Server running on port", PORT));
