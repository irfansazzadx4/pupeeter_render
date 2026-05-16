const express   = require("express");
const puppeteer = require("puppeteer");
const cors      = require("cors");

const app = express();
app.use(cors());
app.use(express.json({ limit: "10mb" }));

const SECRET = process.env.SECRET || "nid_pdf_secret_2025";
const PORT   = process.env.PORT   || 8080;

// ── Bangla font টা absolute URL দিয়ে override করো ──
// CSS এ /fonts/Bangla.ttf relative path আছে যেটা Puppeteer খুঁজে পায় না
const FONT_FIX_CSS = `
<style id="font-fix">
@font-face {
    font-family: Bangla;
    src: url(https://auto.onlinebd.top/fonts/Bangla.ttf) format("truetype");
    font-weight: 400;
    font-style: normal;
}
* { font-family: Bangla, Arial, sans-serif !important; }
.bn { font-family: Bangla, Arial, sans-serif !important; }
.sans { font-family: Arial, sans-serif !important; }
</style>`;

function injectFontFix(html) {
    if (html.includes('</head>')) {
        return html.replace('</head>', FONT_FIX_CSS + '\n</head>');
    }
    return FONT_FIX_CSS + html;
}

app.post("/pdf", async (req, res) => {
    if (req.body.secret !== SECRET) {
        return res.status(403).json({ success: false, error: "Unauthorized" });
    }

    const { html, url } = req.body;
    if (!html && !url) {
        return res.status(400).json({ success: false, error: "No HTML or URL provided" });
    }

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
            ],
        });

        const page = await browser.newPage();
        await page.setViewport({ width: 1200, height: 900, deviceScaleFactor: 2 });
        page.setDefaultNavigationTimeout(90000);
        page.setDefaultTimeout(90000);

        if (url) {
            await page.goto(url, { waitUntil: "networkidle0", timeout: 90000 });
        } else {
            // Font fix inject করো
            const fixedHtml = injectFontFix(html);
            await page.setContent(fixedHtml, { waitUntil: "networkidle0", timeout: 90000 });
        }

        // ── সব image load হওয়া পর্যন্ত wait ──
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

        // ── Barcode canvas render হওয়া পর্যন্ত wait ──
        await page.evaluate(() => new Promise(resolve => {
            const check = () => {
                const canvas = document.querySelector('#barcode canvas');
                if (canvas && canvas.width > 0) return resolve();
                setTimeout(check, 150);
            };
            check();
            setTimeout(resolve, 5000);
        })).catch(() => {});

        // ── Font download + render এর জন্য extra wait ──
        await new Promise(r => setTimeout(r, 3000));

        const pdfBuf = await page.pdf({
            format           : "Letter",
            printBackground  : true,
            margin           : { top: "0", right: "0", bottom: "0", left: "0" },
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

app.get("/", (req, res) => res.json({ status: "ok", service: "NID PDF API" }));

app.listen(PORT, () => console.log(`✅ PDF API on port ${PORT}`));
