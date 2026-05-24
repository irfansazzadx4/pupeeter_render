const express   = require("express");
const puppeteer = require("puppeteer");
const cors      = require("cors");
const https     = require("https");
const http      = require("http");

const app = express();
app.use(cors());
app.use(express.json({ limit: "50mb" }));

const SECRET = process.env.SECRET || "nid_pdf_secret_2025";
const PORT   = process.env.PORT   || 8080;

// ── Font sources (Bangla + Arial) ──
const FONT_SOURCES = {
  bangla: [
    "https://auto.onlinebd.top/fonts/Bangla.ttf",
    "https://onlinebd.top/fonts/Bangla.ttf",
    "https://fonts.maateen.me/solaiman-lipi/SolaimanLipi.ttf",
  ],
  arial: [
    "https://auto.onlinebd.top/fonts/Arial.ttf",
    "https://onlinebd.top/fonts/Arial.ttf",
    // fallback: Arial is usually available on system, but embed anyway
  ],
};

let fonts = { bangla: null, arial: null };
let fontsLoaded = false;

function fetchUrl(url) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith("https") ? https : http;
    const req = mod.get(url, {
      headers: { "User-Agent": "Mozilla/5.0 NIDBot/1.0" },
      timeout: 15000,
    }, (res) => {
      if (res.statusCode === 301 || res.statusCode === 302) {
        return resolve(fetchUrl(res.headers.location));
      }
      if (res.statusCode !== 200) {
        return reject(new Error(`HTTP ${res.statusCode}`));
      }
      const chunks = [];
      res.on("data", c => chunks.push(c));
      res.on("end", () => resolve(Buffer.concat(chunks)));
    });
    req.on("error", reject);
    req.on("timeout", () => { req.destroy(); reject(new Error("timeout")); });
  });
}

async function loadFontFromSources(sources, name) {
  for (const url of sources) {
    try {
      console.log(`⏳ Loading ${name}: ${url}`);
      const buf = await fetchUrl(url);
      if (buf.length > 5000) {
        const b64 = buf.toString("base64");
        console.log(`✅ ${name} loaded: ${Math.round(buf.length / 1024)}KB`);
        return b64;
      }
    } catch (e) {
      console.log(`⚠️ ${name} failed: ${url} — ${e.message}`);
    }
  }
  return null;
}

async function loadAllFonts() {
  [fonts.bangla, fonts.arial] = await Promise.all([
    loadFontFromSources(FONT_SOURCES.bangla, "Bangla"),
    loadFontFromSources(FONT_SOURCES.arial,  "Arial"),
  ]);
  fontsLoaded = true;
  console.log(`✅ Fonts ready — Bangla: ${fonts.bangla ? "OK" : "MISSING"}, Arial: ${fonts.arial ? "OK" : "MISSING"}`);
}

function buildFontCSS() {
  let css = "";

  if (fonts.bangla) {
    css += `
@font-face {
  font-family: 'Bangla';
  src: url('data:font/truetype;base64,${fonts.bangla}') format('truetype');
  font-weight: normal; font-style: normal;
}
@font-face {
  font-family: 'Solaimanlipi';
  src: url('data:font/truetype;base64,${fonts.bangla}') format('truetype');
  font-weight: normal; font-style: normal;
}
@font-face {
  font-family: 'Solaiman Lipi';
  src: url('data:font/truetype;base64,${fonts.bangla}') format('truetype');
  font-weight: normal; font-style: normal;
}`;
  }

  if (fonts.arial) {
    css += `
@font-face {
  font-family: 'Arial';
  src: url('data:font/truetype;base64,${fonts.arial}') format('truetype');
  font-weight: normal; font-style: normal;
}`;
  }

  css += `
* {
  font-family: 'Bangla', 'Solaimanlipi', 'Solaiman Lipi', Arial, sans-serif !important;
}`;

  return css;
}

function injectFontFix(html) {
  // পুরনো embedded-fonts style সরাও (conflict এড়াতে)
  let fixed = html.replace(/<style[^>]*id="embedded-fonts"[^>]*>[\s\S]*?<\/style>/gi, "");
  // পুরনো font-fix style সরাও
  fixed = fixed.replace(/<style[^>]*id="font-fix"[^>]*>[\s\S]*?<\/style>/gi, "");

  const tag = `<style id="font-fix">${buildFontCSS()}</style>`;

  if (fixed.includes("</head>")) {
    return fixed.replace("</head>", tag + "\n</head>");
  }
  return tag + fixed;
}

// Startup font load
loadAllFonts();

app.post("/pdf", async (req, res) => {
  if (req.body.secret !== SECRET) {
    return res.status(403).json({ success: false, error: "Unauthorized" });
  }

  const { html, url } = req.body;
  if (!html && !url) {
    return res.status(400).json({ success: false, error: "No HTML or URL provided" });
  }

  if (!fontsLoaded) await loadAllFonts();

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: "new",
      args: [
        "--no-sandbox",
        "--disable-setuid-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--no-zygote",
        "--disable-web-security",
        "--allow-running-insecure-content",
        "--font-render-hinting=none",
      ],
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 900, deviceScaleFactor: 2 });
    page.setDefaultNavigationTimeout(90000);
    page.setDefaultTimeout(90000);

    if (url) {
      await page.goto(url, { waitUntil: "networkidle0", timeout: 90000 });
    } else {
      const fixedHtml = injectFontFix(html);
      await page.setContent(fixedHtml, { waitUntil: "networkidle0", timeout: 90000 });
    }

    // Images load wait
    await page.evaluate(async () => {
      const imgs = Array.from(document.images);
      if (!imgs.length) return;
      await Promise.allSettled(
        imgs.map(img => {
          if (img.complete) return Promise.resolve();
          return new Promise(resolve => {
            img.onload  = resolve;
            img.onerror = resolve;
            setTimeout(resolve, 8000);
          });
        })
      );
    });

    // Barcode canvas wait
    await page.evaluate(() => new Promise(resolve => {
      const check = () => {
        const canvas = document.querySelector("#barcode canvas");
        if (canvas && canvas.width > 0) return resolve();
        setTimeout(check, 150);
      };
      check();
      setTimeout(resolve, 5000);
    })).catch(() => {});

    // Font render wait
    await new Promise(r => setTimeout(r, 2000));

    const pdfBuf = await page.pdf({
      format          : "A4",
      printBackground : true,
      margin          : { top: "0", right: "0", bottom: "0", left: "0" },
      preferCSSPageSize: true,
    });

    console.log(`✅ PDF: ${pdfBuf.length} bytes`);
    res.json({
      success: true,
      pdf    : Buffer.from(pdfBuf).toString("base64"),
      size   : pdfBuf.length,
    });

  } catch (err) {
    console.error("❌ PDF error:", err.message);
    res.status(500).json({ success: false, error: err.message });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
});

app.get("/font-status", (_, res) => res.json({
  fontsLoaded,
  bangla: fonts.bangla ? `${Math.round(fonts.bangla.length * 3/4 / 1024)}KB` : "missing",
  arial:  fonts.arial  ? `${Math.round(fonts.arial.length  * 3/4 / 1024)}KB` : "missing",
}));

app.get("/reload-fonts", async (_, res) => {
  fonts = { bangla: null, arial: null };
  fontsLoaded = false;
  await loadAllFonts();
  res.json({ fontsLoaded, bangla: !!fonts.bangla, arial: !!fonts.arial });
});

app.get("/", (_, res) => res.json({ status: "ok", service: "NID PDF API", fontsLoaded }));

app.listen(PORT, () => console.log(`✅ PDF API on port ${PORT}`));
