/**
 * Sestaví samostatný HTML náhled (dist/preview.html) bez externích skriptů:
 * Tailwind CSS i JS bundle (React, recharts, framer-motion, lucide-react) jsou vložené inline.
 */
import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const root = dirname(fileURLToPath(import.meta.url));
const dist = join(root, "dist");
mkdirSync(dist, { recursive: true });

const css = execFileSync(
  join(root, "node_modules", ".bin", "tailwindcss"),
  ["-c", join(root, "tailwind.config.cjs"), "-i", join(root, "preview", "input.css"), "--minify"],
  { cwd: root, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] },
);

const result = await build({
  entryPoints: [join(root, "preview", "main.jsx")],
  bundle: true,
  minify: true,
  format: "iife",
  target: "es2020",
  jsx: "automatic",
  define: { "process.env.NODE_ENV": '"production"' },
  legalComments: "none",
  write: false,
  logLevel: "warning",
});

// Inline <script> nesmí obsahovat ukončovací tag.
const js = result.outputFiles[0].text.replace(/<\/script/gi, "<\\/script");
const safeCss = css.replace(/<\/style/gi, "<\\/style");

const html = `<title>AI Model Studio</title>
<meta name="color-scheme" content="dark">
<style>
:root { color-scheme: dark; }
html, body { background: #020617; color: #f1f5f9; }
${safeCss}
</style>
<div id="root"></div>
<script>${js}</script>
`;

const out = join(dist, "preview.html");
writeFileSync(out, html);
console.log(`dist/preview.html: ${(Buffer.byteLength(html) / 1024).toFixed(0)} kB`);
