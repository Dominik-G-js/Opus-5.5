/**
 * AI Model Studio – přehled administrace v „Dark Luxury“ vzhledu.
 *
 * Samostatná React komponenta (Tailwind core třídy, lucide-react, recharts, framer-motion),
 * kterou jde vložit přímo do Claude React artefaktu. Všechna data jsou UKÁZKOVÁ a generují se
 * deterministicky (stejné datum = stejná čísla); živé platby v hlavičce jsou simulované.
 *
 * Doménový model odpovídá PHP aplikaci: částky v CZK, hrubé tržby − poplatky platforem = čistý
 * příjem, čistý příjem − náklady = zisk, měsíční cíl počítaný ze zisku, AI politika platforem.
 */
import React, { useEffect, useId, useMemo, useRef, useState } from "react";
import { AnimatePresence, MotionConfig, animate, motion, useReducedMotion } from "framer-motion";
import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  ArrowDownRight,
  ArrowUpRight,
  Bot,
  Clapperboard,
  Coins,
  Crown,
  Gem,
  Gift,
  Heart,
  Layers,
  LayoutDashboard,
  Link2,
  Megaphone,
  Menu,
  MessageSquareLock,
  Pause,
  Play,
  Radio,
  Receipt,
  RefreshCw,
  Settings,
  Shield,
  ShieldAlert,
  ShieldCheck,
  ShieldQuestion,
  Sparkles,
  Target,
  TrendingUp,
  Wallet,
  Wand2,
  X,
} from "lucide-react";

/* ------------------------------------------------------------------ */
/* Konfigurace a ukázková data                                         */
/* ------------------------------------------------------------------ */

const FONT_STACK = '"Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
const FONT_IMPORT =
  "@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');";

/** Pořadí platforem je zároveň pořadí barev (ověřeno na čitelnost i pro barvoslepé). */
const PLATFORMS = [
  { id: "fanvue", name: "Fanvue", color: "#c026d3", dot: "bg-fuchsia-600", fee: 0.2, policy: "allowed", sync: "Fanvue API" },
  { id: "patreon", name: "Patreon", color: "#ea580c", dot: "bg-orange-600", fee: 0.1, policy: "sfw", sync: "CSV import" },
  { id: "onlyfans", name: "OnlyFans", color: "#2563eb", dot: "bg-blue-600", fee: 0.2, policy: "banned", sync: "CSV import" },
  { id: "loyalfans", name: "Loyalfans", color: "#0d9488", dot: "bg-teal-600", fee: 0.2, policy: "unknown", sync: "CSV import" },
];
const PLATFORM_BY_ID = Object.fromEntries(PLATFORMS.map((p) => [p.id, p]));

/** Texty vychází z výčtu ai_policy v aplikaci (src/Support/Labels.php) a rešerše v docs/. */
const POLICIES = {
  allowed: {
    label: "AI povoleno",
    icon: ShieldCheck,
    className: "border-emerald-500/20 bg-emerald-500/10 text-emerald-300",
    note: "AI modelky povolené s jasným označením AI.",
  },
  sfw: {
    label: "Jen SFW",
    icon: Shield,
    className: "border-amber-500/20 bg-amber-500/10 text-amber-300",
    note: "Bez explicitního obsahu.",
  },
  banned: {
    label: "AI persona zakázána",
    icon: ShieldAlert,
    className: "border-rose-500/20 bg-rose-500/10 text-rose-300",
    note: "Čistě AI persona porušuje podmínky platformy – hrozí ban účtu.",
  },
  unknown: {
    label: "Neověřeno",
    icon: ShieldQuestion,
    className: "border-slate-500/20 bg-slate-500/10 text-slate-300",
    note: "Pravidla pro AI obsah zatím neověřena.",
  },
};

const REVENUE_TYPES = [
  { id: "subscription", label: "Předplatné", color: "#7c3aed", dot: "bg-violet-600", icon: Crown },
  { id: "tip", label: "Spropitné", color: "#db2777", dot: "bg-pink-600", icon: Gift },
  { id: "ppv", label: "PPV zprávy", color: "#0284c7", dot: "bg-sky-600", icon: MessageSquareLock },
];
const REVENUE_TYPE_BY_ID = Object.fromEntries(REVENUE_TYPES.map((t) => [t.id, t]));

/** Custom video se doručuje jako placená zpráva, proto se v grafu počítá do PPV. */
const TX_KINDS = {
  tip: { label: "Spropitné", icon: Gift, revenueType: "tip" },
  ppv: { label: "PPV zpráva", icon: MessageSquareLock, revenueType: "ppv" },
  custom: { label: "Custom video", icon: Clapperboard, revenueType: "ppv" },
};

const MODEL_STATUS = {
  campaign: {
    label: "Aktivní kampaň",
    icon: Megaphone,
    dot: "bg-pink-500",
    ping: "bg-pink-400",
    pill: "border-pink-500/20 bg-pink-500/10 text-pink-300",
  },
  automation: {
    label: "Automatizace online",
    icon: Bot,
    dot: "bg-emerald-400",
    ping: "bg-emerald-400",
    pill: "border-emerald-500/20 bg-emerald-500/10 text-emerald-300",
  },
  paused: {
    label: "Pozastavená",
    icon: Pause,
    dot: "bg-slate-500",
    ping: null,
    pill: "border-slate-500/20 bg-slate-500/10 text-slate-300",
  },
};

/** dailyBase = dnešní průměrné hrubé tržby v Kč za den; trend = denní exponenciální růst. */
const MODELS = [
  {
    id: "luna",
    name: "Luna Vale",
    initials: "LV",
    niche: "Gothic glamour",
    handle: "lunavale",
    status: "campaign",
    automation: "Kampaň TikTok → Fanvue, 6. den",
    avatar: "from-rose-500 via-pink-500 to-fuchsia-600",
    dailyBase: 2600,
    trend: 0.0016,
    platformMix: { fanvue: 0.52, patreon: 0.08, onlyfans: 0.28, loyalfans: 0.12 },
    typeMix: { subscription: 0.34, tip: 0.18, ppv: 0.48 },
  },
  {
    id: "mia",
    name: "Mia Sol",
    initials: "MS",
    niche: "Beach & fitness",
    handle: "miasol.ai",
    status: "automation",
    automation: "AI chat 24/7, 3 PPV sekvence",
    avatar: "from-amber-400 via-orange-500 to-pink-500",
    dailyBase: 1900,
    trend: 0.0011,
    platformMix: { fanvue: 0.61, patreon: 0.22, onlyfans: 0, loyalfans: 0.17 },
    typeMix: { subscription: 0.46, tip: 0.21, ppv: 0.33 },
  },
  {
    id: "aiko",
    name: "Aiko Mori",
    initials: "AM",
    niche: "Cosplay & anime",
    handle: "aikomori",
    status: "campaign",
    automation: "Promo na Redditu a X, 4 subreddity",
    avatar: "from-sky-400 via-indigo-500 to-purple-600",
    dailyBase: 1500,
    trend: 0.0024,
    launchedDaysAgo: 210,
    platformMix: { fanvue: 0.44, patreon: 0.31, onlyfans: 0.15, loyalfans: 0.1 },
    typeMix: { subscription: 0.52, tip: 0.26, ppv: 0.22 },
  },
  {
    id: "noir",
    name: "Valentina Noir",
    initials: "VN",
    niche: "Luxury lifestyle",
    handle: "valentinanoir",
    status: "paused",
    automation: "Pauza: přetrénování LoRA",
    avatar: "from-slate-400 via-purple-700 to-slate-900",
    dailyBase: 1100,
    trend: -0.0006,
    pausedDaysAgo: 12,
    platformMix: { fanvue: 0.7, patreon: 0, onlyfans: 0, loyalfans: 0.3 },
    typeMix: { subscription: 0.28, tip: 0.3, ppv: 0.42 },
  },
];
const MODEL_BY_ID = Object.fromEntries(MODELS.map((m) => [m.id, m]));

/** Patreon je jen SFW – tržby jsou tam hlavně z členství. */
const PLATFORM_TYPE_MIX = { patreon: { subscription: 0.8, tip: 0.12, ppv: 0.08 } };

const COST_SHARE = 0.14; // generování, video, chatování – podíl z tržeb modelky
const PAUSED_COST_SHARE = 0.22; // pauza = trénink nové LoRA
const SHARED_DAILY_COST = 260; // hosting, doména, drobné nástroje
const TOOL_SUBSCRIPTIONS_MONTHLY = 2400; // předplatné AI nástrojů, účtuje se 1. v měsíci
const MONTHLY_GOAL = 150000; // cíl čistého zisku za měsíc
const HIGH_TX_MIN = 400; // od jaké částky je platba „vysoká“

/** Denní průběh tržeb po hodinách (večer nejvíc). */
const HOUR_WEIGHTS = [3, 2, 1.5, 1, 0.8, 0.7, 0.8, 1.2, 1.8, 2.4, 2.8, 3, 3.3, 3.4, 3.3, 3.4, 3.8, 4.4, 5.2, 6.2, 7.2, 7.8, 7.4, 5.4];
const HOUR_TOTAL = HOUR_WEIGHTS.reduce((a, b) => a + b, 0);
const HOUR_SHARE = HOUR_WEIGHTS.map((w) => w / HOUR_TOTAL);
const WEEKDAY_FACTOR = [1.12, 0.88, 0.92, 0.96, 1, 1.12, 1.18]; // ne, po … so

const FANS = [
  { name: "velvet_whale", top: true },
  { name: "Tomasz K.", top: false },
  { name: "midnight_rider", top: true },
  { name: "jk_collector", top: false },
  { name: "dreamer_77", top: false },
  { name: "Marcus B.", top: true },
  { name: "neonheart", top: false },
  { name: "Hiro_T", top: false },
  { name: "blue.velvet", top: false },
  { name: "silentfan", top: false },
];

const PERIODS = [
  { id: "today", label: "Dnes", title: "dnes", compare: "vs. včera ve stejný čas" },
  { id: "7d", label: "7 dní", title: "posledních 7 dní", compare: "vs. předchozích 7 dní" },
  { id: "30d", label: "30 dní", title: "posledních 30 dní", compare: "vs. předchozích 30 dní" },
  { id: "ytd", label: "YTD", title: "od začátku roku", compare: "vs. stejné období loni" },
];
const PERIOD_BY_ID = Object.fromEntries(PERIODS.map((p) => [p.id, p]));

const NAV_ITEMS = [
  { label: "Přehled", icon: LayoutDashboard, active: true },
  { label: "Modelky", icon: Sparkles },
  { label: "Příjmy", icon: Wallet },
  { label: "Fanoušci", icon: Heart },
  { label: "Náklady", icon: Receipt },
  { label: "Odkazy", icon: Link2 },
  { label: "Platformy", icon: Layers },
  { label: "AI nástroje", icon: Wand2 },
  { label: "Nastavení", icon: Settings },
];

/* ------------------------------------------------------------------ */
/* Pomocné funkce                                                      */
/* ------------------------------------------------------------------ */

const CZK = new Intl.NumberFormat("cs-CZ", { style: "currency", currency: "CZK", maximumFractionDigits: 0 });
const COMPACT = new Intl.NumberFormat("cs-CZ", { notation: "compact", maximumFractionDigits: 1 });
const PERCENT = new Intl.NumberFormat("cs-CZ", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const SHARE = new Intl.NumberFormat("cs-CZ", { maximumFractionDigits: 0 });
const DAY_MONTH = new Intl.DateTimeFormat("cs-CZ", { day: "numeric", month: "numeric" });
const WEEKDAY = new Intl.DateTimeFormat("cs-CZ", { weekday: "short" });
const LONG_DATE = new Intl.DateTimeFormat("cs-CZ", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
const MONTH_NAME = new Intl.DateTimeFormat("cs-CZ", { month: "long" });
const TIME = new Intl.DateTimeFormat("cs-CZ", { hour: "2-digit", minute: "2-digit" });

const formatCzk = (value) => CZK.format(Math.round(value));
const formatShare = (part, total) => `${SHARE.format(total > 0 ? (part / total) * 100 : 0)} %`;

function cn(...classes) {
  return classes.filter(Boolean).join(" ");
}

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date, days) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
}

/** Počet kalendářních dní mezi dvěma půlnocemi (zaokrouhlení kvůli změně času). */
function daysBetween(from, to) {
  return Math.round((startOfDay(to) - startOfDay(from)) / 86400000);
}

function dateKey(date) {
  return `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;
}

function daysInMonth(date) {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
}

/** Deterministický šum 0–1 z libovolných částí klíče (FNV-1a + promíchání). */
function noise(...parts) {
  const text = parts.join("|");
  let h = 2166136261;
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  h ^= h >>> 13;
  h = Math.imul(h, 1274126177);
  h ^= h >>> 16;
  return (h >>> 0) / 4294967296;
}

function pickWeighted(items, weightOf, random) {
  const total = items.reduce((sum, item) => sum + weightOf(item), 0);
  let threshold = random * total;
  for (const item of items) {
    threshold -= weightOf(item);
    if (threshold < 0) return item;
  }
  return items[items.length - 1];
}

/** Podíl denních tržeb, který už „proběhl“ k danému času. */
function dayFraction(now) {
  const hour = now.getHours();
  let fraction = 0;
  for (let h = 0; h < hour; h++) fraction += HOUR_SHARE[h];
  return fraction + HOUR_SHARE[hour] * (now.getMinutes() / 60);
}

function growth(current, previous) {
  if (previous <= 0) return current > 0 ? null : 0;
  return (current - previous) / previous;
}

function relativeTime(timestamp, nowMs) {
  const minutes = Math.max(0, Math.floor((nowMs - timestamp) / 60000));
  if (minutes < 1) return "právě teď";
  if (minutes < 60) return `před ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `před ${hours} h`;
  return `${DAY_MONTH.format(new Date(timestamp))} ${TIME.format(new Date(timestamp))}`;
}

/* ------------------------------------------------------------------ */
/* Generování a agregace dat                                           */
/* ------------------------------------------------------------------ */

/** Denní tržby [modelka][platforma][typ][den] a náklady od 1. 1. loňského roku do dneška. */
function buildDataset(today) {
  const start = new Date(today.getFullYear() - 1, 0, 1);
  const days = [];
  for (let d = start; d <= today; d = addDays(d, 1)) days.push(d);
  const last = days.length - 1;

  const revenue = MODELS.map(() => PLATFORMS.map(() => REVENUE_TYPES.map(() => new Float64Array(days.length))));
  const costs = MODELS.map(() => new Float64Array(days.length));
  const sharedCosts = new Float64Array(days.length);

  days.forEach((date, i) => {
    const key = dateKey(date);
    const daysAgo = last - i;
    const dayFactor = WEEKDAY_FACTOR[date.getDay()] * (1 + 0.07 * Math.sin(i / 11));

    MODELS.forEach((model, mi) => {
      if (model.launchedDaysAgo !== undefined && daysAgo > model.launchedDaysAgo) return;
      const trend = Math.exp(model.trend * (i - last));
      const paused = model.pausedDaysAgo !== undefined && daysAgo < model.pausedDaysAgo;
      const promo = noise(key, model.id, "promo") > 0.965 ? 1.9 : 1;

      PLATFORMS.forEach((platform, pi) => {
        const share = model.platformMix[platform.id] ?? 0;
        if (share === 0) return;
        const typeMix = PLATFORM_TYPE_MIX[platform.id] ?? model.typeMix;
        REVENUE_TYPES.forEach((type, ti) => {
          const jitter = 0.72 + 0.56 * noise(key, model.id, platform.id, type.id);
          const pauseFactor = paused ? (type.id === "subscription" ? 0.72 : 0.12) : 1;
          const promoFactor = type.id === "subscription" ? 1 : promo;
          revenue[mi][pi][ti][i] =
            model.dailyBase * trend * dayFactor * share * typeMix[type.id] * jitter * pauseFactor * promoFactor;
        });
      });

      costs[mi][i] = model.dailyBase * trend * (paused ? PAUSED_COST_SHARE : COST_SHARE) * (0.75 + 0.5 * noise(key, model.id, "cost"));
    });

    sharedCosts[i] = SHARED_DAILY_COST + (date.getDate() === 1 ? TOOL_SUBSCRIPTIONS_MONTHLY : 0);
  });

  return { days, todayIndex: last, revenue, costs, sharedCosts };
}

function zeroMap(items) {
  return Object.fromEntries(items.map((item) => [item.id, 0]));
}

function createTotals() {
  return {
    gross: 0,
    fees: 0,
    costs: 0,
    byType: zeroMap(REVENUE_TYPES),
    byPlatform: zeroMap(PLATFORMS),
    byModel: Object.fromEntries(
      MODELS.map((m) => [m.id, { gross: 0, byType: zeroMap(REVENUE_TYPES), byPlatform: zeroMap(PLATFORMS) }]),
    ),
  };
}

function addRevenue(totals, modelId, platform, typeId, amount) {
  const model = totals.byModel[modelId];
  totals.gross += amount;
  totals.fees += amount * platform.fee;
  totals.byType[typeId] += amount;
  totals.byPlatform[platform.id] += amount;
  model.gross += amount;
  model.byType[typeId] += amount;
  model.byPlatform[platform.id] += amount;
}

/** Přičte den `i` s vahou `weight` (1 = celý den, méně = jen část dne). */
function addDay(totals, data, i, weight, filter) {
  if (i < 0 || weight <= 0) return;
  MODELS.forEach((model, mi) => {
    if (!filter.model(model.id)) return;
    PLATFORMS.forEach((platform, pi) => {
      if (!filter.platform(platform.id)) return;
      REVENUE_TYPES.forEach((type, ti) => {
        const amount = data.revenue[mi][pi][ti][i] * weight;
        if (amount > 0) addRevenue(totals, model.id, platform, type.id, amount);
      });
    });
    totals.costs += data.costs[mi][i] * weight;
  });
  // Společné náklady (hosting, předplatné nástrojů) nepatří žádné modelce.
  if (filter.allModels) totals.costs += data.sharedCosts[i] * weight;
}

function addEvent(totals, event, filter) {
  if (!filter.model(event.modelId) || !filter.platform(event.platformId)) return;
  addRevenue(totals, event.modelId, PLATFORM_BY_ID[event.platformId], TX_KINDS[event.kind].revenueType, event.amount);
}

function createFilter(models, platforms) {
  return {
    model: (id) => models.size === 0 || models.has(id),
    platform: (id) => platforms.size === 0 || platforms.has(id),
    allModels: models.size === 0,
  };
}

/** Dny aktuálního a srovnávacího období; poslední den obou se bere jen do stejného času. */
function periodRanges(periodId, data, now) {
  const t = data.todayIndex;
  const fraction = dayFraction(now);
  const span = (from, to) => {
    const list = [];
    for (let i = Math.max(0, from); i <= to; i++) list.push({ i, weight: i === to ? fraction : 1 });
    return list;
  };

  switch (periodId) {
    case "today":
      return { current: span(t, t), previous: span(t - 1, t - 1) };
    case "7d":
      return { current: span(t - 6, t), previous: span(t - 13, t - 7) };
    case "30d":
      return { current: span(t - 29, t), previous: span(t - 59, t - 30) };
    default: {
      const today = data.days[t];
      const yearStart = t - daysBetween(new Date(today.getFullYear(), 0, 1), today);
      const lastYearDay = new Date(
        today.getFullYear() - 1,
        today.getMonth(),
        Math.min(today.getDate(), daysInMonth(new Date(today.getFullYear() - 1, today.getMonth(), 1))),
      );
      return { current: span(yearStart, t), previous: span(0, daysBetween(data.days[0], lastYearDay)) };
    }
  }
}

/** Rozdělení aktuálního období na body grafu (hodiny / dny / týdny). */
function buildBuckets(periodId, data, ranges, now) {
  if (periodId === "today") {
    const hour = now.getHours();
    return HOUR_SHARE.map((share, h) => ({
      label: `${h}:00`,
      tooltipLabel: `Dnes ${h}:00–${h}:59`,
      future: h > hour,
      entries: h > hour ? [] : [{ i: data.todayIndex, weight: share * (h === hour ? now.getMinutes() / 60 : 1) }],
    }));
  }

  if (periodId !== "ytd") {
    return ranges.current.map((entry) => {
      const date = data.days[entry.i];
      return {
        label: periodId === "7d" ? `${WEEKDAY.format(date)} ${DAY_MONTH.format(date)}` : DAY_MONTH.format(date),
        tooltipLabel: `${WEEKDAY.format(date)} ${DAY_MONTH.format(date)}${entry.i === data.todayIndex ? " (zatím)" : ""}`,
        future: false,
        entries: [entry],
      };
    });
  }

  const weeks = new Map();
  ranges.current.forEach((entry) => {
    const date = data.days[entry.i];
    const monday = addDays(date, -((date.getDay() + 6) % 7));
    const key = dateKey(monday);
    if (!weeks.has(key)) {
      weeks.set(key, {
        label: DAY_MONTH.format(monday),
        tooltipLabel: `Týden od ${DAY_MONTH.format(monday)}`,
        future: false,
        entries: [],
      });
    }
    weeks.get(key).entries.push(entry);
  });
  return [...weeks.values()];
}

function aggregate({ data, periodId, now, filter, events }) {
  const ranges = periodRanges(periodId, data, now);
  const current = createTotals();
  const previous = createTotals();
  ranges.current.forEach(({ i, weight }) => addDay(current, data, i, weight, filter));
  ranges.previous.forEach(({ i, weight }) => addDay(previous, data, i, weight, filter));

  const buckets = buildBuckets(periodId, data, ranges, now).map((bucket) => {
    const totals = createTotals();
    bucket.entries.forEach(({ i, weight }) => addDay(totals, data, i, weight, filter));
    return { ...bucket, totals };
  });

  // Živé platby patří do dnešního dne: dnes do své hodiny, jinak do posledního bodu grafu.
  const lastPast = buckets.reduce((last, bucket, index) => (bucket.future ? last : index), 0);
  events.forEach((event) => {
    addEvent(current, event, filter);
    const index = periodId === "today" ? Math.min(new Date(event.ts).getHours(), lastPast) : buckets.length - 1;
    addEvent(buckets[index].totals, event, filter);
  });

  return { current, previous, buckets };
}

/** Měsíční cíl: celé studio bez filtrů, zisk = čistý příjem − náklady. */
function monthlyGoal(data, now, events) {
  const t = data.todayIndex;
  const today = data.days[t];
  const fraction = dayFraction(now);
  const filter = createFilter(new Set(), new Set());
  const totals = createTotals();
  let fixedCosts = 0;
  for (let i = t - today.getDate() + 1; i <= t; i++) {
    const weight = i === t ? fraction : 1;
    addDay(totals, data, i, weight, filter);
    if (data.days[i].getDate() === 1) fixedCosts += TOOL_SUBSCRIPTIONS_MONTHLY * weight;
  }
  events.forEach((event) => addEvent(totals, event, filter));

  const profit = totals.gross - totals.fees - totals.costs;
  const totalDays = daysInMonth(today);
  const elapsedDays = today.getDate() - 1 + fraction;
  const remainingDays = Math.max(totalDays - elapsedDays, 0.01);
  const pace = (profit + fixedCosts) / Math.max(elapsedDays, 0.01);
  const projected = pace * totalDays - fixedCosts;

  return {
    month: MONTH_NAME.format(today),
    profit,
    projected,
    pace,
    needed: Math.max(0, MONTHLY_GOAL - profit) / remainingDays,
    progress: Math.min(1, Math.max(0, profit / MONTHLY_GOAL)),
    remainingDays: Math.ceil(remainingDays),
  };
}

function buildRecentTransactions(nowMs) {
  const active = MODELS.filter((m) => m.status !== "paused");
  const list = [];
  let ts = nowMs - 3 * 60000;
  for (let k = 0; k < 30; k++) {
    const random = (salt) => noise("tx", k, salt);
    const model = random("paused") > 0.9 ? MODEL_BY_ID.noir : pickWeighted(active, (m) => m.dailyBase, random("model"));
    const platform = pickWeighted(
      PLATFORMS.filter((p) => (model.platformMix[p.id] ?? 0) > 0),
      (p) => model.platformMix[p.id],
      random("platform"),
    );
    const roll = random("kind");
    const kind = roll < 0.5 ? "tip" : roll < 0.84 ? "ppv" : "custom";
    const r = random("amount");
    const raw = kind === "tip" ? 300 + r * r * 3200 : kind === "ppv" ? 290 + r * 1200 : 1900 + r * 4600;
    const fan = FANS[Math.floor(random("fan") * FANS.length)];
    list.push({
      id: `seed-${k}`,
      ts,
      modelId: model.id,
      platformId: platform.id,
      kind,
      amount: Math.round(raw / 10) * 10,
      fan: fan.name,
      topFan: fan.top,
      live: false,
    });
    ts -= (14 + random("gap") * 140) * 60000;
  }
  return list;
}

let liveCounter = 0;

/** Simulovaná živá platba (v ostré verzi ji dodá synchronizace z API / CSV). */
function createLiveEvent(ts) {
  const model = pickWeighted(
    MODELS.filter((m) => m.status !== "paused"),
    (m) => m.dailyBase,
    Math.random(),
  );
  const platform = pickWeighted(
    PLATFORMS.filter((p) => (model.platformMix[p.id] ?? 0) > 0),
    (p) => model.platformMix[p.id],
    Math.random(),
  );
  const roll = Math.random();
  const kind = roll < 0.55 ? "tip" : roll < 0.9 ? "ppv" : "custom";
  const raw = kind === "tip" ? 100 + Math.random() ** 2 * 1400 : kind === "ppv" ? 150 + Math.random() * 750 : 1500 + Math.random() * 3000;
  const fan = FANS[Math.floor(Math.random() * FANS.length)];
  liveCounter += 1;
  return {
    id: `live-${ts}-${liveCounter}`,
    ts,
    modelId: model.id,
    platformId: platform.id,
    kind,
    amount: Math.round(raw / 10) * 10,
    fan: fan.name,
    topFan: fan.top,
    live: true,
  };
}

/* ------------------------------------------------------------------ */
/* UI primitiva (shadcn/ui styl)                                       */
/* ------------------------------------------------------------------ */

const GLOWS = {
  pink: "absolute -top-10 -left-10 h-40 w-40 rounded-full bg-pink-500/10 blur-3xl pointer-events-none",
  violet: "absolute -top-12 -right-12 h-48 w-48 rounded-full bg-purple-600/15 blur-3xl pointer-events-none",
  rose: "absolute -bottom-16 -right-10 h-44 w-44 rounded-full bg-rose-500/10 blur-3xl pointer-events-none",
};

function Card({ className, glow, children }) {
  return (
    <div
      className={cn(
        "relative overflow-hidden rounded-2xl border border-purple-500/10 bg-slate-900/60 shadow-2xl shadow-purple-950/20 backdrop-blur-xl",
        className,
      )}
    >
      {glow && <div aria-hidden="true" className={GLOWS[glow]} />}
      <div className="relative">{children}</div>
    </div>
  );
}

/** Fázované načtení sekcí. */
function Reveal({ delay = 0, className, children }) {
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4, delay, ease: "easeOut" }}
    >
      {children}
    </motion.div>
  );
}

function Eyebrow({ children, className }) {
  return <p className={cn("text-xs font-medium uppercase tracking-wider text-slate-400", className)}>{children}</p>;
}

function SectionTitle({ eyebrow, title, children }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div>
        <Eyebrow>{eyebrow}</Eyebrow>
        <h2 className="mt-1 text-lg font-semibold tracking-wide text-slate-100">{title}</h2>
      </div>
      {children}
    </div>
  );
}

function Badge({ className, icon: Icon, children, title }) {
  return (
    <span
      title={title}
      className={cn("inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium", className)}
    >
      {Icon && <Icon className="h-3 w-3" aria-hidden="true" />}
      {children}
    </span>
  );
}

function PolicyBadge({ policy }) {
  const config = POLICIES[policy];
  return (
    <Badge className={config.className} icon={config.icon} title={config.note}>
      {config.label}
    </Badge>
  );
}

function PlatformBadge({ platformId }) {
  const platform = PLATFORM_BY_ID[platformId];
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-white/5 px-2 py-0.5 text-xs font-medium text-slate-200">
      <span className={cn("h-2 w-2 rounded-full", platform.dot)} aria-hidden="true" />
      {platform.name}
    </span>
  );
}

function DeltaPill({ value, invert = false, neutral = false }) {
  if (value === null) {
    return (
      <span className="inline-flex items-center rounded-full bg-purple-500/10 px-2 py-0.5 text-xs font-semibold text-purple-300 ring-1 ring-purple-500/20">
        nové
      </span>
    );
  }
  const up = value >= 0;
  const good = neutral ? null : invert ? !up : up;
  const tone =
    good === null
      ? "bg-slate-500/10 text-slate-300 ring-slate-500/20"
      : good
        ? "bg-emerald-500/10 text-emerald-400 ring-emerald-500/20"
        : "bg-rose-500/10 text-rose-400 ring-rose-500/20";
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  return (
    <span className={cn("inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ring-1", tone)}>
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {up ? "+" : "−"}
      {PERCENT.format(Math.abs(value * 100))} %
    </span>
  );
}

function AnimatedNumber({ value, format = formatCzk, className }) {
  const reduceMotion = useReducedMotion();
  const [display, setDisplay] = useState(0);
  const from = useRef(0);

  useEffect(() => {
    if (reduceMotion) {
      from.current = value;
      setDisplay(value);
      return undefined;
    }
    const controls = animate(from.current, value, {
      duration: 0.9,
      ease: [0.16, 1, 0.3, 1],
      onUpdate: (latest) => {
        from.current = latest;
        setDisplay(latest);
      },
    });
    return () => controls.stop();
  }, [value, reduceMotion]);

  return <span className={className}>{format(display)}</span>;
}

function useSvgId(prefix) {
  return `${prefix}-${useId().replace(/[^a-zA-Z0-9_-]/g, "")}`;
}

function Sparkline({ data, color, className }) {
  const gradientId = useSvgId("spark");
  const reduceMotion = useReducedMotion();
  return (
    <div className={cn("w-full", className)} aria-hidden="true">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 4, right: 2, bottom: 2, left: 2 }}>
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={color} stopOpacity={0.45} />
              <stop offset="100%" stopColor={color} stopOpacity={0} />
            </linearGradient>
          </defs>
          <Area
            type="monotone"
            dataKey="v"
            stroke={color}
            strokeWidth={2}
            fill={`url(#${gradientId})`}
            dot={false}
            isAnimationActive={!reduceMotion}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Navigace                                                            */
/* ------------------------------------------------------------------ */

function Brand() {
  return (
    <div className="flex items-center gap-3">
      <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-pink-500 to-purple-600 shadow-lg shadow-pink-500/20">
        <Gem className="h-5 w-5 text-white" aria-hidden="true" />
      </span>
      <div>
        <p className="text-sm font-bold tracking-wide text-white">AI Model Studio</p>
        <p className="text-xs text-pink-400/90 font-mono">admin · 18+</p>
      </div>
    </div>
  );
}

function NavList({ onNavigate }) {
  return (
    <nav aria-label="Hlavní menu">
      <ul className="space-y-1">
        {NAV_ITEMS.map(({ label, icon: Icon, active }) => (
          <li key={label}>
            <a
              href="#"
              onClick={(event) => {
                event.preventDefault();
                onNavigate?.();
              }}
              aria-current={active ? "page" : undefined}
              className={cn(
                "group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
                active
                  ? "bg-gradient-to-r from-pink-500/15 to-purple-600/10 text-white ring-1 ring-pink-500/20"
                  : "text-slate-400 hover:bg-white/5 hover:text-slate-100",
              )}
            >
              <Icon className={cn("h-4 w-4", active ? "text-pink-400" : "text-slate-500 group-hover:text-slate-300")} aria-hidden="true" />
              {label}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  );
}

function SyncStatus() {
  return (
    <div className="rounded-xl border border-white/5 bg-slate-950/50 p-4">
      <div className="flex items-center gap-2">
        <span className="relative flex h-2 w-2">
          <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-60 motion-safe:animate-ping" />
          <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
        </span>
        <p className="text-xs font-semibold text-slate-200">Fanvue API</p>
      </div>
      <p className="mt-1 text-xs text-slate-400">Synchronizováno před 14 min, cron každou hodinu.</p>
      <p className="mt-2 text-xs text-pink-400/90 font-mono">OnlyFans · Patreon · Loyalfans: CSV</p>
    </div>
  );
}

function Sidebar() {
  return (
    <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col gap-8 border-r border-purple-500/10 bg-slate-950/60 px-4 py-6 backdrop-blur-xl lg:flex">
      <div className="px-2">
        <Brand />
      </div>
      <NavList />
      <div className="mt-auto">
        <SyncStatus />
      </div>
    </aside>
  );
}

function MobileNav({ open, onOpen, onClose }) {
  return (
    <>
      <div className="flex items-center justify-between lg:hidden">
        <Brand />
        <button
          type="button"
          onClick={onOpen}
          aria-label="Otevřít menu"
          className="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60"
        >
          <Menu className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>
      <AnimatePresence>
        {open && (
          <motion.div className="fixed inset-0 z-50 lg:hidden" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
            <button type="button" aria-label="Zavřít menu" onClick={onClose} className="absolute inset-0 bg-slate-950/70 backdrop-blur-sm" />
            <motion.div
              role="dialog"
              aria-modal="true"
              aria-label="Menu"
              className="absolute inset-y-0 left-0 flex w-72 max-w-full flex-col gap-8 border-r border-purple-500/10 bg-slate-950 px-4 py-6"
              initial={{ x: -40, opacity: 0 }}
              animate={{ x: 0, opacity: 1 }}
              exit={{ x: -40, opacity: 0 }}
              transition={{ duration: 0.25 }}
            >
              <div className="flex items-center justify-between px-2">
                <Brand />
                <button
                  type="button"
                  onClick={onClose}
                  aria-label="Zavřít menu"
                  className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-white/5 hover:text-white"
                >
                  <X className="h-5 w-5" aria-hidden="true" />
                </button>
              </div>
              <NavList onNavigate={onClose} />
              <div className="mt-auto">
                <SyncStatus />
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}

/* ------------------------------------------------------------------ */
/* Hlavička: živé tržby, období, filtry                                */
/* ------------------------------------------------------------------ */

function PeriodSwitch({ value, onChange }) {
  const layoutId = useSvgId("period");
  return (
    <div role="group" aria-label="Období" className="inline-flex rounded-xl border border-white/5 bg-slate-950/60 p-1">
      {PERIODS.map((period) => {
        const active = period.id === value;
        return (
          <button
            key={period.id}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(period.id)}
            className={cn(
              "relative rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
              active ? "text-white" : "text-slate-400 hover:text-slate-100",
            )}
          >
            {active && (
              <motion.span
                layoutId={layoutId}
                className="absolute inset-0 rounded-lg bg-gradient-to-r from-pink-500 to-rose-600 shadow-lg shadow-pink-500/25"
                transition={{ type: "spring", bounce: 0.2, duration: 0.5 }}
              />
            )}
            <span className="relative">{period.label}</span>
          </button>
        );
      })}
    </div>
  );
}

function FilterChip({ active, onClick, children, title }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      title={title}
      className={cn(
        "inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
        active
          ? "border-pink-500/40 bg-pink-500/15 text-pink-50 shadow-md shadow-pink-500/10"
          : "border-white/10 bg-white/5 text-slate-300 hover:border-purple-400/30 hover:bg-purple-500/10 hover:text-white",
      )}
    >
      {children}
    </button>
  );
}

function MiniAvatar({ model }) {
  return (
    <span
      className={cn(
        "flex h-5 w-5 items-center justify-center rounded-full bg-gradient-to-br text-xs font-bold text-white",
        model.avatar,
      )}
      aria-hidden="true"
    >
      {model.name[0]}
    </span>
  );
}

function FilterBar({ selectedModels, selectedPlatforms, onToggleModel, onTogglePlatform, onClearModels, onClearPlatforms, onReset }) {
  const hasFilters = selectedModels.size > 0 || selectedPlatforms.size > 0;
  return (
    <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:gap-8">
      <div className="flex flex-wrap items-center gap-2">
        <Eyebrow className="mr-1 w-20 xl:w-auto">Modelky</Eyebrow>
        <FilterChip active={selectedModels.size === 0} onClick={onClearModels}>
          Všechny
        </FilterChip>
        {MODELS.map((model) => (
          <FilterChip key={model.id} active={selectedModels.has(model.id)} onClick={() => onToggleModel(model.id)}>
            <MiniAvatar model={model} />
            {model.name}
          </FilterChip>
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Eyebrow className="mr-1 w-20 xl:w-auto">Platformy</Eyebrow>
        <FilterChip active={selectedPlatforms.size === 0} onClick={onClearPlatforms}>
          Všechny
        </FilterChip>
        {PLATFORMS.map((platform) => (
          <FilterChip
            key={platform.id}
            active={selectedPlatforms.has(platform.id)}
            onClick={() => onTogglePlatform(platform.id)}
            title={POLICIES[platform.policy].note}
          >
            <span className={cn("h-2 w-2 rounded-full", platform.dot)} aria-hidden="true" />
            {platform.name}
            {platform.policy === "banned" && <ShieldAlert className="h-3.5 w-3.5 text-rose-400" aria-label="AI persona zakázána" />}
          </FilterChip>
        ))}
      </div>
      {hasFilters && (
        <button
          type="button"
          onClick={onReset}
          className="inline-flex items-center gap-1.5 self-start rounded-full px-3 py-1.5 text-xs font-medium text-pink-300 hover:bg-pink-500/10 xl:ml-auto xl:self-auto"
        >
          <X className="h-3.5 w-3.5" aria-hidden="true" />
          Zrušit filtry
        </button>
      )}
    </div>
  );
}

function LiveToast({ event }) {
  return (
    <div aria-live="polite" className="h-8">
      <AnimatePresence mode="popLayout">
        {event && (
          <motion.div
            key={event.id}
            initial={{ opacity: 0, y: -8, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 8 }}
            transition={{ duration: 0.3 }}
            className="inline-flex items-center gap-2 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1.5 text-xs text-emerald-200"
          >
            <Coins className="h-3.5 w-3.5 text-emerald-400" aria-hidden="true" />
            <span className="font-semibold tabular-nums text-emerald-300">+{formatCzk(event.amount)}</span>
            <span className="text-slate-300">
              {TX_KINDS[event.kind].label} · {MODEL_BY_ID[event.modelId].name} · {PLATFORM_BY_ID[event.platformId].name}
            </span>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

function RevenueHeader({ period, totals, previous, live, onToggleLive, lastEvent, onPeriodChange, filterBar }) {
  const net = totals.gross - totals.fees;
  return (
    <Card className="p-6 lg:p-8" glow="pink">
      <div aria-hidden="true" className={GLOWS.violet} />
      <div className="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-3">
            <Eyebrow className="flex items-center gap-2">
              <Radio className={cn("h-3.5 w-3.5", live ? "text-pink-400 motion-safe:animate-pulse" : "text-slate-500")} aria-hidden="true" />
              Hrubé tržby · {PERIOD_BY_ID[period].title}
            </Eyebrow>
            <Badge className="border-purple-500/20 bg-purple-500/10 text-purple-300" icon={Sparkles}>
              Ukázková data
            </Badge>
          </div>
          <p className="mt-3 text-4xl font-extrabold tracking-tight tabular-nums text-transparent bg-clip-text bg-gradient-to-r from-white via-slate-200 to-slate-400 lg:text-5xl">
            <AnimatedNumber value={totals.gross} />
          </p>
          <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-slate-400">
            <DeltaPill value={growth(totals.gross, previous.gross)} />
            <span>{PERIOD_BY_ID[period].compare}</span>
            <span className="hidden text-slate-600 sm:inline" aria-hidden="true">
              |
            </span>
            <span>
              čistě <span className="font-semibold tabular-nums text-slate-200">{formatCzk(net)}</span> po poplatcích platforem
            </span>
          </div>
        </div>

        <div className="flex flex-col items-start gap-3 xl:items-end">
          <div className="flex flex-wrap items-center gap-3">
            <LiveToast event={live ? lastEvent : null} />
            <button
              type="button"
              onClick={onToggleLive}
              aria-pressed={live}
              className={cn(
                "inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
                live
                  ? "border-pink-500/30 bg-pink-500/10 text-pink-200 hover:bg-pink-500/20"
                  : "border-white/10 bg-white/5 text-slate-300 hover:bg-white/10",
              )}
            >
              {live ? <Pause className="h-3.5 w-3.5" aria-hidden="true" /> : <Play className="h-3.5 w-3.5" aria-hidden="true" />}
              {live ? "Živě" : "Pozastaveno"}
            </button>
          </div>
          <PeriodSwitch value={period} onChange={onPeriodChange} />
        </div>
      </div>

      <div className="mt-6 border-t border-white/5 pt-5">{filterBar}</div>
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* KPI                                                                 */
/* ------------------------------------------------------------------ */

function KpiTile({ label, icon: Icon, value, delta, invert, neutral, note, series, color }) {
  return (
    <Card className="h-full p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <Eyebrow>{label}</Eyebrow>
          <p className="mt-2 text-2xl font-bold tracking-tight tabular-nums text-white">{formatCzk(value)}</p>
        </div>
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/5 text-slate-300 ring-1 ring-white/10">
          <Icon className="h-4 w-4" aria-hidden="true" />
        </span>
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <DeltaPill value={delta} invert={invert} neutral={neutral} />
        <span className="text-xs text-slate-500">{note}</span>
      </div>
      <Sparkline data={series} color={color} className="mt-3 h-12" />
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* Revenue & Analytics Hub                                             */
/* ------------------------------------------------------------------ */

function RevenueTooltip({ active, payload, hiddenTypes }) {
  if (!active || !payload?.length) return null;
  const point = payload[0].payload;
  if (point.future) return null;
  const types = REVENUE_TYPES.filter((t) => !hiddenTypes.has(t.id));
  const total = types.reduce((sum, t) => sum + point[t.id], 0);
  const platforms = PLATFORMS.filter((p) => point.platforms[p.id] > 0);
  const platformTotal = platforms.reduce((sum, p) => sum + point.platforms[p.id], 0);

  return (
    <div className="w-64 rounded-xl border border-purple-500/20 bg-slate-950/95 p-4 shadow-2xl shadow-black/60 backdrop-blur-xl">
      <p className="text-xs text-pink-400/90 font-mono">{point.tooltipLabel}</p>
      <p className="mt-1 text-xl font-bold tabular-nums text-white">{formatCzk(total)}</p>
      <ul className="mt-3 space-y-1.5">
        {types.map((type) => (
          <li key={type.id} className="flex items-center justify-between gap-3 text-xs">
            <span className="flex items-center gap-2 text-slate-300">
              <span className={cn("h-2 w-2 rounded-full", type.dot)} aria-hidden="true" />
              {type.label}
            </span>
            <span className="font-semibold tabular-nums text-slate-100">{formatCzk(point[type.id])}</span>
          </li>
        ))}
      </ul>
      {platforms.length > 0 && (
        <>
          <p className="mt-4 text-xs font-medium uppercase tracking-wider text-slate-500">Podle platformy</p>
          <ul className="mt-2 space-y-2">
            {platforms.map((platform) => {
              const amount = point.platforms[platform.id];
              return (
                <li key={platform.id}>
                  <div className="flex items-center justify-between gap-3 text-xs">
                    <span className="flex items-center gap-2 text-slate-300">
                      <span className={cn("h-2 w-2 rounded-full", platform.dot)} aria-hidden="true" />
                      {platform.name}
                    </span>
                    <span className="tabular-nums text-slate-100">
                      {formatCzk(amount)} <span className="text-slate-500">· {formatShare(amount, platformTotal)}</span>
                    </span>
                  </div>
                  <div className="mt-1 h-1 rounded-full bg-white/5">
                    <div
                      className="h-1 rounded-full"
                      style={{ width: `${platformTotal > 0 ? (amount / platformTotal) * 100 : 0}%`, backgroundColor: platform.color }}
                    />
                  </div>
                </li>
              );
            })}
          </ul>
          {hiddenTypes.size > 0 && <p className="mt-2 text-xs text-slate-500">Platformy za všechny typy plateb.</p>}
        </>
      )}
    </div>
  );
}

function RevenueHub({ period, chartData, totals, hiddenTypes, onToggleType }) {
  const reduceMotion = useReducedMotion();
  const gradientPrefix = useSvgId("revenue");
  const visibleTotal = REVENUE_TYPES.filter((t) => !hiddenTypes.has(t.id)).reduce((sum, t) => sum + totals.byType[t.id], 0);

  return (
    <Card className="h-full p-5 sm:p-6" glow="violet">
      <SectionTitle eyebrow="Revenue & Analytics Hub" title={`Vývoj tržeb · ${PERIOD_BY_ID[period].title}`}>
        <div className="text-right">
          <p className="text-2xl font-bold tabular-nums text-white">{formatCzk(visibleTotal)}</p>
          <p className="text-xs text-slate-500">zobrazené typy plateb</p>
        </div>
      </SectionTitle>

      <div className="mt-5 flex flex-wrap gap-2" role="group" aria-label="Typy plateb v grafu">
        {REVENUE_TYPES.map((type) => {
          const visible = !hiddenTypes.has(type.id);
          const Icon = type.icon;
          return (
            <button
              key={type.id}
              type="button"
              aria-pressed={visible}
              onClick={() => onToggleType(type.id)}
              className={cn(
                "inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
                visible ? "border-white/10 bg-white/5 hover:bg-white/10" : "border-white/5 bg-transparent opacity-50 hover:opacity-80",
              )}
            >
              <span className={cn("flex h-7 w-7 items-center justify-center rounded-lg", visible ? type.dot : "bg-slate-700")}>
                <Icon className="h-3.5 w-3.5 text-white" aria-hidden="true" />
              </span>
              <span>
                <span className="block text-xs font-medium text-slate-400">{type.label}</span>
                <span className="block text-sm font-semibold tabular-nums text-slate-100">{formatCzk(totals.byType[type.id])}</span>
              </span>
            </button>
          );
        })}
      </div>

      <div className="mt-6 h-72 sm:h-80 xl:h-96">
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={chartData} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
            <defs>
              {REVENUE_TYPES.map((type) => (
                <linearGradient key={type.id} id={`${gradientPrefix}-${type.id}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={type.color} stopOpacity={0.6} />
                  <stop offset="100%" stopColor={type.color} stopOpacity={0.08} />
                </linearGradient>
              ))}
            </defs>
            <CartesianGrid vertical={false} stroke="rgba(148, 163, 184, 0.08)" strokeDasharray="3 6" />
            <XAxis
              dataKey="label"
              tick={{ fill: "#64748b", fontSize: 11 }}
              axisLine={false}
              tickLine={false}
              minTickGap={24}
              interval="preserveStartEnd"
            />
            <YAxis
              width={52}
              tick={{ fill: "#64748b", fontSize: 11 }}
              axisLine={false}
              tickLine={false}
              tickFormatter={(value) => COMPACT.format(value)}
            />
            <Tooltip
              content={<RevenueTooltip hiddenTypes={hiddenTypes} />}
              cursor={{ stroke: "rgba(236, 72, 153, 0.4)", strokeWidth: 1 }}
            />
            {REVENUE_TYPES.map((type) => (
              <Area
                key={type.id}
                type="monotone"
                dataKey={type.id}
                name={type.label}
                stackId="revenue"
                hide={hiddenTypes.has(type.id)}
                stroke={type.color}
                strokeWidth={2}
                fill={`url(#${gradientPrefix}-${type.id})`}
                activeDot={{ r: 4, stroke: "#020617", strokeWidth: 2 }}
                isAnimationActive={!reduceMotion}
                animationDuration={900}
              />
            ))}
          </AreaChart>
        </ResponsiveContainer>
      </div>

      <p className="mt-3 text-xs text-slate-500">Custom videa se doručují placenou zprávou, proto se počítají do PPV.</p>

      <details className="group mt-3">
        <summary className="cursor-pointer text-xs font-medium text-pink-300 hover:text-pink-200">Zobrazit jako tabulku</summary>
        <div className="mt-3 max-h-64 overflow-auto rounded-xl border border-white/5">
          <table className="w-full text-left text-xs">
            <thead className="sticky top-0 bg-slate-950 text-slate-400">
              <tr>
                <th className="px-3 py-2 font-medium">Období</th>
                {REVENUE_TYPES.map((type) => (
                  <th key={type.id} className="px-3 py-2 text-right font-medium">
                    {type.label}
                  </th>
                ))}
                <th className="px-3 py-2 text-right font-medium">Celkem</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5 text-slate-200">
              {chartData
                .filter((row) => !row.future)
                .map((row) => (
                  <tr key={row.tooltipLabel}>
                    <td className="px-3 py-1.5 text-slate-400">{row.tooltipLabel}</td>
                    {REVENUE_TYPES.map((type) => (
                      <td key={type.id} className="px-3 py-1.5 text-right tabular-nums">
                        {formatCzk(row[type.id])}
                      </td>
                    ))}
                    <td className="px-3 py-1.5 text-right font-semibold tabular-nums">{formatCzk(row.total)}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </details>
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* Distribuce platforem                                                */
/* ------------------------------------------------------------------ */

function PlatformTooltip({ active, payload, total }) {
  if (!active || !payload?.length) return null;
  const row = payload[0].payload;
  return (
    <div className="rounded-xl border border-purple-500/20 bg-slate-950/95 px-3 py-2 shadow-2xl shadow-black/60">
      <p className="text-xs font-semibold text-slate-100">{row.name}</p>
      <p className="text-xs tabular-nums text-slate-300">
        {formatCzk(row.value)} · {formatShare(row.value, total)}
      </p>
    </div>
  );
}

function PlatformBreakdown({ totals }) {
  const reduceMotion = useReducedMotion();
  const [hovered, setHovered] = useState(null);
  const rows = PLATFORMS.map((platform) => ({
    ...platform,
    value: totals.byPlatform[platform.id],
    net: totals.byPlatform[platform.id] * (1 - platform.fee),
  })).filter((row) => row.value > 0);
  const total = rows.reduce((sum, row) => sum + row.value, 0);

  return (
    <Card className="h-full p-5 sm:p-6" glow="rose">
      <SectionTitle eyebrow="Distribuce" title="Podíl platforem na tržbách" />
      {rows.length === 0 ? (
        <p className="mt-8 text-sm text-slate-400">Pro vybrané filtry nejsou žádné tržby.</p>
      ) : (
        <>
          <div className="relative mx-auto mt-4 h-56 max-w-xs">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={rows}
                  dataKey="value"
                  nameKey="name"
                  innerRadius="68%"
                  outerRadius="92%"
                  paddingAngle={rows.length > 1 ? 2 : 0}
                  cornerRadius={4}
                  stroke="none"
                  isAnimationActive={!reduceMotion}
                  onMouseEnter={(_, index) => setHovered(rows[index]?.id ?? null)}
                  onMouseLeave={() => setHovered(null)}
                >
                  {rows.map((row) => (
                    <Cell key={row.id} fill={row.color} fillOpacity={hovered === null || hovered === row.id ? 1 : 0.35} />
                  ))}
                </Pie>
                <Tooltip content={<PlatformTooltip total={total} />} />
              </PieChart>
            </ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
              <p className="text-xs font-medium uppercase tracking-wider text-slate-500">Hrubě</p>
              <p className="text-xl font-bold tabular-nums text-white">{COMPACT.format(total)} Kč</p>
            </div>
          </div>

          <ul className="mt-4 space-y-2">
            {rows.map((row) => (
              <li
                key={row.id}
                onMouseEnter={() => setHovered(row.id)}
                onMouseLeave={() => setHovered(null)}
                className={cn(
                  "rounded-xl border px-3 py-2.5 transition-colors",
                  hovered === row.id ? "border-white/10 bg-white/5" : "border-transparent",
                )}
              >
                <div className="flex items-center justify-between gap-3">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className={cn("h-2.5 w-2.5 shrink-0 rounded-full", row.dot)} aria-hidden="true" />
                    <span className="text-sm font-semibold text-slate-100">{row.name}</span>
                    <span className="text-xs tabular-nums text-slate-500">{formatShare(row.value, total)}</span>
                  </span>
                  <span className="text-sm font-semibold tabular-nums text-slate-100">{formatCzk(row.value)}</span>
                </div>
                <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 pl-4">
                  <PolicyBadge policy={row.policy} />
                  <span className="text-xs tabular-nums text-slate-500">
                    čistě {formatCzk(row.net)} · poplatek {SHARE.format(row.fee * 100)} %
                  </span>
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* Karty modelek                                                       */
/* ------------------------------------------------------------------ */

function ModelAvatar({ model }) {
  const status = MODEL_STATUS[model.status];
  return (
    <div className="relative shrink-0">
      <div className="rounded-2xl bg-gradient-to-br from-pink-500 via-purple-500 to-indigo-500 p-0.5 shadow-lg shadow-pink-500/10">
        <div
          className={cn(
            "flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br text-lg font-extrabold tracking-wide text-white",
            model.avatar,
          )}
        >
          {model.initials}
        </div>
      </div>
      <span className="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-slate-950">
        {status.ping && <span className={cn("absolute h-3 w-3 rounded-full opacity-60 motion-safe:animate-ping", status.ping)} />}
        <span className={cn("relative h-2.5 w-2.5 rounded-full", status.dot)} />
      </span>
    </div>
  );
}

function ModelCard({ model, stats, previousGross, series, periodLabel, selected, dimmed, onSelect, delay }) {
  const status = MODEL_STATUS[model.status];
  const platforms = PLATFORMS.filter((p) => stats.byPlatform[p.id] > 0);
  const topType = REVENUE_TYPES.reduce((best, type) => (stats.byType[type.id] > stats.byType[best.id] ? type : best), REVENUE_TYPES[0]);
  const TopIcon = topType.icon;

  return (
    <Reveal delay={delay} className="h-full">
      <motion.button
        type="button"
        onClick={onSelect}
        aria-pressed={selected}
        aria-label={`${model.name}: ${selected ? "zrušit filtr" : "filtrovat přehled"}`}
        whileHover={{ y: -4 }}
        transition={{ type: "spring", stiffness: 300, damping: 24 }}
        className={cn(
          "group relative h-full w-full overflow-hidden rounded-2xl border bg-slate-900/60 p-5 text-left shadow-2xl shadow-purple-950/20 backdrop-blur-xl transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink-500/60",
          selected ? "border-pink-500/40 ring-1 ring-pink-500/30" : "border-purple-500/10 hover:border-purple-400/30",
          dimmed && "opacity-50 hover:opacity-90",
        )}
      >
        <div aria-hidden="true" className="absolute -top-10 -left-10 h-40 w-40 rounded-full bg-pink-500/10 blur-3xl pointer-events-none opacity-0 transition-opacity group-hover:opacity-100" />
        <div className="relative flex h-full flex-col">
          <div className="flex items-start gap-4">
            <ModelAvatar model={model} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-lg font-semibold tracking-wide text-slate-100">{model.name}</p>
              <p className="truncate text-xs text-slate-400">
                {model.niche} · <span className="text-pink-400/90 font-mono">@{model.handle}</span>
              </p>
              <Badge className={cn("mt-2", status.pill)} icon={status.icon}>
                {status.label}
              </Badge>
            </div>
          </div>

          <div className="mt-5 flex items-end justify-between gap-3">
            <div>
              <Eyebrow>Tržby · {periodLabel}</Eyebrow>
              <p className="mt-1 text-3xl font-extrabold tracking-tight tabular-nums text-white">{formatCzk(stats.gross)}</p>
            </div>
            <DeltaPill value={growth(stats.gross, previousGross)} />
          </div>

          <Sparkline data={series} color="#ec4899" className="mt-3 h-14" />

          <div className="mt-4">
            <div className="flex items-center justify-between">
              <Eyebrow>Podle platforem</Eyebrow>
              {platforms.some((p) => p.policy === "banned") && (
                <ShieldAlert className="h-3.5 w-3.5 text-rose-400" aria-label="Běží i na platformě, která AI persony zakazuje" />
              )}
            </div>
            {platforms.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500">Na vybraných platformách bez tržeb.</p>
            ) : (
              <>
                <div className="mt-2 flex h-2 gap-0.5 overflow-hidden rounded-full bg-white/5">
                  {platforms.map((platform) => (
                    <motion.div
                      key={platform.id}
                      className="h-full first:rounded-l-full last:rounded-r-full"
                      style={{ backgroundColor: platform.color }}
                      initial={{ width: 0 }}
                      animate={{ width: `${(stats.byPlatform[platform.id] / stats.gross) * 100}%` }}
                      transition={{ duration: 0.8, delay: delay + 0.2, ease: "easeOut" }}
                    />
                  ))}
                </div>
                <ul className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                  {platforms.map((platform) => (
                    <li key={platform.id} className="flex items-center justify-between gap-2 text-xs">
                      <span className="flex items-center gap-1.5 text-slate-400">
                        <span className={cn("h-1.5 w-1.5 rounded-full", platform.dot)} aria-hidden="true" />
                        {platform.name}
                      </span>
                      <span className="tabular-nums text-slate-200">{formatShare(stats.byPlatform[platform.id], stats.gross)}</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>

          <div className="mt-auto pt-4">
            <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-slate-950/50 p-3">
              <span className={cn("flex h-8 w-8 shrink-0 items-center justify-center rounded-lg", topType.dot)}>
                <TopIcon className="h-4 w-4 text-white" aria-hidden="true" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-xs text-slate-500">Nejziskovější kanál</p>
                <p className="truncate text-sm font-semibold text-slate-100">{stats.gross > 0 ? topType.label : "—"}</p>
              </div>
              <span className="text-sm font-bold tabular-nums text-slate-200">
                {stats.gross > 0 ? formatShare(stats.byType[topType.id], stats.gross) : ""}
              </span>
            </div>
            <p className="mt-3 flex items-center gap-1.5 text-xs text-pink-400/90 font-mono">
              <Bot className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate">{model.automation}</span>
            </p>
          </div>
        </div>
      </motion.button>
    </Reveal>
  );
}

/* ------------------------------------------------------------------ */
/* Transakce a měsíční cíl                                             */
/* ------------------------------------------------------------------ */

function TransactionsTable({ transactions, nowMs, live }) {
  return (
    <Card className="h-full">
      <div className="p-5 sm:p-6">
        <SectionTitle eyebrow="Transakce" title="Poslední vysoké platby">
          <p className="flex items-center gap-2 text-xs text-slate-400">
            <span className={cn("h-2 w-2 rounded-full", live ? "bg-emerald-400 motion-safe:animate-pulse" : "bg-slate-600")} aria-hidden="true" />
            od {formatCzk(HIGH_TX_MIN)} · tipy, custom videa, PPV
          </p>
        </SectionTitle>
      </div>
      {transactions.length === 0 ? (
        <p className="px-6 pb-6 text-sm text-slate-400">Pro vybrané filtry tu nejsou žádné platby.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-y border-white/5 bg-slate-950/40 text-xs uppercase tracking-wider text-slate-500">
                <th scope="col" className="px-6 py-3 font-medium">Fanoušek</th>
                <th scope="col" className="px-3 py-3 font-medium">Modelka</th>
                <th scope="col" className="px-3 py-3 font-medium">Platforma</th>
                <th scope="col" className="px-3 py-3 font-medium">Typ</th>
                <th scope="col" className="px-3 py-3 text-right font-medium">Částka</th>
                <th scope="col" className="px-6 py-3 text-right font-medium">Kdy</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5">
              <AnimatePresence initial={false}>
                {transactions.map((tx) => {
                  const model = MODEL_BY_ID[tx.modelId];
                  const kind = TX_KINDS[tx.kind];
                  const KindIcon = kind.icon;
                  return (
                    <motion.tr
                      key={tx.id}
                      layout
                      initial={{ opacity: 0, backgroundColor: "rgba(236, 72, 153, 0.12)" }}
                      animate={{ opacity: 1, backgroundColor: "rgba(236, 72, 153, 0)" }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 1.2 }}
                      className="hover:bg-white/5"
                    >
                      <td className="whitespace-nowrap px-6 py-3">
                        <span className="font-medium text-slate-100">{tx.fan}</span>
                        {tx.topFan && (
                          <Badge className="ml-2 border-amber-500/20 bg-amber-500/10 text-amber-300" icon={Crown}>
                            top
                          </Badge>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-3">
                        <span className="flex items-center gap-2 text-slate-300">
                          <MiniAvatar model={model} />
                          {model.name}
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <PlatformBadge platformId={tx.platformId} />
                      </td>
                      <td className="whitespace-nowrap px-3 py-3">
                        <span className="inline-flex items-center gap-1.5 text-slate-300">
                          <KindIcon className="h-3.5 w-3.5 text-slate-500" aria-hidden="true" />
                          {kind.label}
                        </span>
                      </td>
                      <td className="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums text-emerald-400">
                        +{formatCzk(tx.amount)}
                      </td>
                      <td className="whitespace-nowrap px-6 py-3 text-right text-xs text-slate-500">
                        {tx.live && <span className="mr-2 text-pink-400/90 font-mono">nové</span>}
                        {relativeTime(tx.ts, nowMs)}
                      </td>
                    </motion.tr>
                  );
                })}
              </AnimatePresence>
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

function GoalRing({ progress }) {
  const gradientId = useSvgId("goal");
  const reduceMotion = useReducedMotion();
  const radius = 52;
  const circumference = 2 * Math.PI * radius;
  return (
    <svg viewBox="0 0 128 128" className="h-36 w-36 -rotate-90" aria-hidden="true">
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#ec4899" />
          <stop offset="100%" stopColor="#7c3aed" />
        </linearGradient>
      </defs>
      <circle cx="64" cy="64" r={radius} fill="none" stroke="rgba(148, 163, 184, 0.12)" strokeWidth="10" />
      <motion.circle
        cx="64"
        cy="64"
        r={radius}
        fill="none"
        stroke={`url(#${gradientId})`}
        strokeWidth="10"
        strokeLinecap="round"
        strokeDasharray={circumference}
        initial={{ strokeDashoffset: circumference }}
        animate={{ strokeDashoffset: circumference * (1 - progress) }}
        transition={{ duration: reduceMotion ? 0 : 1.2, ease: "easeOut" }}
      />
    </svg>
  );
}

function GoalCard({ goal }) {
  const onTrack = goal.projected >= MONTHLY_GOAL;
  return (
    <Card className="h-full p-5 sm:p-6" glow="pink">
      <SectionTitle eyebrow={`Měsíční cíl · ${goal.month}`} title="Čistý zisk celého studia" />
      <div className="mt-5 flex flex-col items-center gap-6 sm:flex-row xl:flex-col">
        <div className="relative shrink-0">
          <GoalRing progress={goal.progress} />
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <p className="text-3xl font-extrabold tabular-nums text-white">{SHARE.format(goal.progress * 100)} %</p>
            <p className="text-xs text-slate-500">z cíle</p>
          </div>
        </div>
        <dl className="w-full space-y-3 text-sm">
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-slate-400">Zisk zatím</dt>
            <dd className="font-semibold tabular-nums text-white">
              <AnimatedNumber value={goal.profit} />
            </dd>
          </div>
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-slate-400">Cíl</dt>
            <dd className="tabular-nums text-slate-200">{formatCzk(MONTHLY_GOAL)}</dd>
          </div>
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-slate-400">Odhad za měsíc</dt>
            <dd className="font-semibold tabular-nums text-slate-100">{formatCzk(goal.projected)}</dd>
          </div>
          <div className="flex items-baseline justify-between gap-3 border-t border-white/5 pt-3">
            <dt className="text-slate-400">Aktuální tempo</dt>
            <dd className="tabular-nums text-slate-200">{formatCzk(goal.pace)} / den</dd>
          </div>
          <div className="flex items-baseline justify-between gap-3">
            <dt className="text-slate-400">Potřebné tempo</dt>
            <dd className={cn("font-semibold tabular-nums", onTrack ? "text-emerald-400" : "text-amber-300")}>{formatCzk(goal.needed)} / den</dd>
          </div>
        </dl>
      </div>
      <div
        className={cn(
          "mt-5 flex items-start gap-2 rounded-xl border p-3 text-xs",
          onTrack ? "border-emerald-500/20 bg-emerald-500/10 text-emerald-200" : "border-amber-500/20 bg-amber-500/10 text-amber-200",
        )}
      >
        {onTrack ? <TrendingUp className="h-4 w-4 shrink-0" aria-hidden="true" /> : <Target className="h-4 w-4 shrink-0" aria-hidden="true" />}
        <p>
          {onTrack
            ? `Při současném tempu cíl překročíš o ${formatCzk(goal.projected - MONTHLY_GOAL)}.`
            : `Při současném tempu chybí ${formatCzk(MONTHLY_GOAL - goal.projected)}. Do konce měsíce zbývá ${goal.remainingDays} dní.`}
        </p>
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* Stránka                                                             */
/* ------------------------------------------------------------------ */

function toggleInSet(set, value) {
  const next = new Set(set);
  if (next.has(value)) next.delete(value);
  else next.add(value);
  return next;
}

export default function AdminDashboard() {
  const [now, setNow] = useState(() => new Date());
  const [period, setPeriod] = useState("30d");
  const [selectedModels, setSelectedModels] = useState(() => new Set());
  const [selectedPlatforms, setSelectedPlatforms] = useState(() => new Set());
  const [hiddenTypes, setHiddenTypes] = useState(() => new Set());
  const [live, setLive] = useState(true);
  const [liveEvents, setLiveEvents] = useState([]);
  const [navOpen, setNavOpen] = useState(false);

  const todayKey = dateKey(now);
  const data = useMemo(() => buildDataset(startOfDay(now)), [todayKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const seededTransactions = useMemo(() => buildRecentTransactions(Date.now()), []);

  // Hodiny pro relativní časy a podíl dne.
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 30000);
    return () => clearInterval(timer);
  }, []);

  // Simulace živých plateb.
  useEffect(() => {
    if (!live) return undefined;
    let timer;
    const schedule = () => {
      timer = setTimeout(() => {
        setLiveEvents((events) => [createLiveEvent(Date.now()), ...events].slice(0, 200));
        schedule();
      }, 3500 + Math.random() * 3500);
    };
    schedule();
    return () => clearTimeout(timer);
  }, [live]);

  const todayStart = startOfDay(now).getTime();
  const todaysEvents = useMemo(() => liveEvents.filter((e) => e.ts >= todayStart), [liveEvents, todayStart]);
  const filter = useMemo(() => createFilter(selectedModels, selectedPlatforms), [selectedModels, selectedPlatforms]);
  const cardFilter = useMemo(() => createFilter(new Set(), selectedPlatforms), [selectedPlatforms]);

  const main = useMemo(
    () => aggregate({ data, periodId: period, now, filter, events: todaysEvents }),
    [data, period, now, filter, todaysEvents],
  );
  const cards = useMemo(
    () => aggregate({ data, periodId: period, now, filter: cardFilter, events: todaysEvents }),
    [data, period, now, cardFilter, todaysEvents],
  );
  const goal = useMemo(() => monthlyGoal(data, now, todaysEvents), [data, now, todaysEvents]);

  const pastBuckets = main.buckets.filter((b) => !b.future);
  const chartData = main.buckets.map((bucket) => {
    const row = { label: bucket.label, tooltipLabel: bucket.tooltipLabel, future: bucket.future, platforms: bucket.totals.byPlatform };
    let total = 0;
    REVENUE_TYPES.forEach((type) => {
      row[type.id] = bucket.future ? null : bucket.totals.byType[type.id];
      total += bucket.totals.byType[type.id];
    });
    row.total = bucket.future ? null : total;
    return row;
  });

  const { current, previous } = main;
  const net = current.gross - current.fees;
  const prevNet = previous.gross - previous.fees;
  const profit = net - current.costs;
  const prevProfit = prevNet - previous.costs;
  const roi = current.costs > 0 ? profit / current.costs : null;
  const series = (pick) => pastBuckets.map((b) => ({ v: pick(b.totals) }));

  const kpis = [
    {
      label: "Čistý příjem",
      icon: Wallet,
      value: net,
      delta: growth(net, prevNet),
      note: "po poplatcích",
      series: series((t) => t.gross - t.fees),
      color: "#ec4899",
    },
    {
      label: "Poplatky platforem",
      icon: Layers,
      value: current.fees,
      delta: growth(current.fees, previous.fees),
      neutral: true,
      note: `${SHARE.format(current.gross > 0 ? (current.fees / current.gross) * 100 : 0)} % z hrubých tržeb`,
      series: series((t) => t.fees),
      color: "#8b5cf6",
    },
    {
      label: "Náklady",
      icon: Receipt,
      value: current.costs,
      delta: growth(current.costs, previous.costs),
      invert: true,
      note: selectedPlatforms.size > 0 ? "celé modelky, nedělí se podle platforem" : "AI nástroje, výroba, promo",
      series: series((t) => t.costs),
      color: "#94a3b8",
    },
    {
      label: "Zisk",
      icon: TrendingUp,
      value: profit,
      delta: prevProfit > 0 ? growth(profit, prevProfit) : null,
      note: roi === null ? "bez nákladů" : `ROI ${SHARE.format(roi * 100)} %`,
      series: series((t) => t.gross - t.fees - t.costs),
      color: "#10b981",
    },
  ];

  const transactions = useMemo(
    () =>
      [...liveEvents, ...seededTransactions]
        .filter((tx) => tx.amount >= HIGH_TX_MIN && filter.model(tx.modelId) && filter.platform(tx.platformId))
        .slice(0, 8),
    [liveEvents, seededTransactions, filter],
  );

  const lastEvent = liveEvents[0] ?? null;
  const periodLabel = PERIOD_BY_ID[period].label;

  return (
    <MotionConfig reducedMotion="user">
      <div className="relative isolate min-h-screen overflow-x-hidden bg-slate-950 text-slate-100 antialiased" style={{ fontFamily: FONT_STACK }}>
        <style>{FONT_IMPORT}</style>

        {/* Atmosféra: vrstvený gradient a rozostřené svatozáře */}
        <div aria-hidden="true" className="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-br from-slate-950 via-purple-950/40 to-slate-950" />
        <div aria-hidden="true" className="pointer-events-none fixed -top-40 left-1/3 -z-10 h-96 w-96 rounded-full bg-pink-600/10 blur-3xl" />
        <div aria-hidden="true" className="pointer-events-none fixed -bottom-40 -right-20 -z-10 h-96 w-96 rounded-full bg-purple-700/15 blur-3xl" />

        <div className="flex">
          <Sidebar />

          <main className="min-w-0 flex-1 space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <MobileNav open={navOpen} onOpen={() => setNavOpen(true)} onClose={() => setNavOpen(false)} />

            <Reveal className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <h1 className="text-2xl font-bold tracking-tight text-white">Přehled</h1>
                <p className="mt-1 text-sm text-slate-400 first-letter:uppercase">{LONG_DATE.format(now)}</p>
              </div>
              <p className="flex items-center gap-2 text-xs text-slate-400">
                <RefreshCw className="h-3.5 w-3.5 text-slate-500" aria-hidden="true" />
                Kurzy ČNB ke dni platby · částky v Kč
              </p>
            </Reveal>

            <Reveal delay={0.05}>
              <RevenueHeader
                period={period}
                totals={current}
                previous={previous}
                live={live}
                onToggleLive={() => setLive((value) => !value)}
                lastEvent={lastEvent}
                onPeriodChange={setPeriod}
                filterBar={
                  <FilterBar
                    selectedModels={selectedModels}
                    selectedPlatforms={selectedPlatforms}
                    onToggleModel={(id) => setSelectedModels((set) => toggleInSet(set, id))}
                    onTogglePlatform={(id) => setSelectedPlatforms((set) => toggleInSet(set, id))}
                    onClearModels={() => setSelectedModels(new Set())}
                    onClearPlatforms={() => setSelectedPlatforms(new Set())}
                    onReset={() => {
                      setSelectedModels(new Set());
                      setSelectedPlatforms(new Set());
                    }}
                  />
                }
              />
            </Reveal>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {kpis.map((kpi, index) => (
                <Reveal key={kpi.label} delay={0.1 + index * 0.06} className="h-full">
                  <KpiTile {...kpi} />
                </Reveal>
              ))}
            </div>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
              <Reveal delay={0.3} className="xl:col-span-2">
                <RevenueHub
                  period={period}
                  chartData={chartData}
                  totals={current}
                  hiddenTypes={hiddenTypes}
                  onToggleType={(id) => setHiddenTypes((set) => (set.size === REVENUE_TYPES.length - 1 && !set.has(id) ? set : toggleInSet(set, id)))}
                />
              </Reveal>
              <Reveal delay={0.36}>
                <PlatformBreakdown totals={current} />
              </Reveal>
            </div>

            <section aria-labelledby="models-title" className="space-y-4">
              <Reveal delay={0.4} className="flex flex-wrap items-end justify-between gap-3">
                <div>
                  <Eyebrow>Model Performance</Eyebrow>
                  <h2 id="models-title" className="mt-1 text-lg font-semibold tracking-wide text-slate-100">
                    Výkon AI modelek
                  </h2>
                </div>
                <p className="text-xs text-slate-500">Klikni na kartu a přehled se vyfiltruje na modelku.</p>
              </Reveal>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-4">
                {MODELS.map((model, index) => (
                  <ModelCard
                    key={model.id}
                    model={model}
                    stats={cards.current.byModel[model.id]}
                    previousGross={cards.previous.byModel[model.id].gross}
                    series={cards.buckets.filter((b) => !b.future).map((b) => ({ v: b.totals.byModel[model.id].gross }))}
                    periodLabel={periodLabel}
                    selected={selectedModels.has(model.id)}
                    dimmed={selectedModels.size > 0 && !selectedModels.has(model.id)}
                    onSelect={() => setSelectedModels((set) => toggleInSet(set, model.id))}
                    delay={0.45 + index * 0.07}
                  />
                ))}
              </div>
            </section>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
              <Reveal delay={0.6} className="min-w-0 xl:col-span-2">
                <TransactionsTable transactions={transactions} nowMs={Math.max(now.getTime(), lastEvent?.ts ?? 0)} live={live} />
              </Reveal>
              <Reveal delay={0.66}>
                <GoalCard goal={goal} />
              </Reveal>
            </div>

            <p className="pb-4 text-center text-xs text-slate-600">
              AI Model Studio · všechny modelky jsou AI a na platformách musí být označené jako AI.
            </p>
          </main>
        </div>
      </div>
    </MotionConfig>
  );
}
