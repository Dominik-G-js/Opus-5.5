# Redesign přehledu – Dark Luxury

Prototyp nového přehledu administrace AI Model Studia jako jedna React komponenta
([`AdminDashboard.jsx`](AdminDashboard.jsx)). Používá jen core Tailwind třídy (žádné `w-[347px]`),
`lucide-react`, `recharts` a `framer-motion`, takže jde vložit rovnou do Claude React artefaktu.

Všechna čísla jsou **ukázková**: generují se deterministicky podle data a živé platby v hlavičce
jsou simulované. Doménový model odpovídá PHP aplikaci (Kč, hrubé tržby → poplatky → čistý příjem →
náklady → zisk, měsíční cíl ze zisku, AI politika platforem).

## Co obsahuje
- **Hlavička** – živé hrubé tržby s animovaným počítadlem, srovnání s předchozím obdobím, přepínač
  Dnes / 7 dní / 30 dní / YTD, filtry podle modelek a platforem, pauza simulace.
- **KPI** – čistý příjem, poplatky platforem, náklady, zisk + ROI, každé se sparkline a změnou.
- **Revenue & Analytics Hub** – skládaný AreaChart (předplatné, spropitné, PPV), přepínání typů,
  vlastní tooltip s rozpadem podle platforem, tabulkové zobrazení.
- **Podíl platforem** – donut graf, čistá částka po poplatku a štítek AI politiky (OnlyFans = AI persona zakázána).
- **Karty modelek** – avatar se stavem, tržby a růst, rozpad podle platforem, nejziskovější kanál,
  běžící automatizace. Klik na kartu vyfiltruje celý přehled.
- **Poslední vysoké platby** a **měsíční cíl** s odhadem a potřebným denním tempem.

## Náhled lokálně
```bash
cd design/admin-redesign
npm install
npm run build        # → dist/preview.html (vše inline, otevři v prohlížeči)
```

Barvy grafů jsou ověřené na odlišitelnost i pro barvoslepé (tmavé pozadí).
