/**
 * Storefront chat launcher: icon color, flair, and once-per-session auto-open.
 * Vendored into connectors. No framework, no network.
 */

const SESSION_KEY = "seekmodo.chat.autoOpened";
const VISITOR_KEY = "seekmodo.chat.visitor";

const DAYS = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

function applyAppearance(button, flair, launcher) {
  if (!button) return;
  const color = launcher && launcher.icon_color ? String(launcher.icon_color) : "";
  if (color) {
    button.style.background = color;
    button.style.backgroundImage = "none";
  }
  const text = launcher && launcher.flair && launcher.flair.text ? String(launcher.flair.text).trim() : "";
  if (!flair) return;
  if (!text) {
    flair.hidden = true;
    flair.textContent = "";
    return;
  }
  flair.hidden = false;
  flair.textContent = text;
  placeFlair(button, flair);
  if (color) {
    flair.style.background = color;
    flair.style.color = "#fff";
  }
}

function createFlair(button) {
  const flair = document.createElement("button");
  flair.type = "button";
  flair.className = "seekmodo-chat-flair";
  flair.hidden = true;
  flair.setAttribute("data-seekmodo-chat-flair", "1");
  const parent = typeof document !== "undefined" ? document.body : null;
  if (parent) parent.appendChild(flair);
  if (button && typeof window !== "undefined") {
    const place = () => placeFlair(button, flair);
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
  }
  return flair;
}

function placeFlair(button, flair) {
  if (!button || !flair || flair.hidden || typeof window === "undefined") return;
  const rect = button.getBoundingClientRect();
  flair.style.position = "fixed";
  flair.style.left = (rect.left + rect.width / 2) + "px";
  flair.style.top = "auto";
  flair.style.bottom = (window.innerHeight - rect.top + 8) + "px";
  flair.style.transform = "translateX(-50%)";
  flair.style.zIndex = "100000";
}

function ensureFlairStyles() {
  if (typeof document === "undefined") return;
  if (document.querySelector("style[data-seekmodo-chat-flair]")) return;
  const style = document.createElement("style");
  style.setAttribute("data-seekmodo-chat-flair", "1");
  style.textContent = [
    ".seekmodo-chat-flair{position:fixed;left:50%;transform:translateX(-50%);",
    "max-width:160px;padding:4px 8px;border:0;border-radius:999px;background:#10B981;color:#fff;",
    "font:600 12px/1.2 Inter,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;",
    "white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;",
    "box-shadow:0 4px 12px rgba(17,24,39,.18);}",
  ].join("");
  document.head.appendChild(style);
}

/**
 * First enabled automation whose rules match.
 * Returns { automation, waitMs } when only seconds_on_page is still pending.
 * Returns null when nothing matches and nothing is pending.
 */
function evaluate(launcher, ctx, now) {
  const automations = launcher && Array.isArray(launcher.automations) ? launcher.automations : [];
  const clock = now instanceof Date ? now : new Date();
  let pending = null;
  for (const automation of automations) {
    if (!automation || automation.enabled === false) continue;
    const result = evalNode(automation.when, launcher, ctx || {}, clock);
    if (result.ok) return { automation, waitMs: 0 };
    if (result.waitMs > 0 && (pending === null || result.waitMs < pending.waitMs)) {
      pending = { automation, waitMs: result.waitMs };
    }
  }
  return pending;
}

function alreadyOpened(storage) {
  try {
    return !!(storage && storage.getItem(SESSION_KEY));
  } catch (e) {
    return false;
  }
}

function markOpened(storage, id) {
  try {
    if (storage) storage.setItem(SESSION_KEY, String(id || "1"));
  } catch (e) { /* private mode */ }
}

function visitorKind(storage) {
  try {
    if (!storage) return "new";
    if (storage.getItem(VISITOR_KEY)) return "returning";
    storage.setItem(VISITOR_KEY, String(Date.now()));
    return "new";
  } catch (e) {
    return "new";
  }
}

function evalNode(node, launcher, ctx, now) {
  if (!node || typeof node !== "object") return { ok: false, waitMs: 0 };
  if (node.op === "and" || node.op === "or") {
    const rules = Array.isArray(node.rules) ? node.rules : [];
    if (node.op === "and") {
      let wait = 0;
      for (const child of rules) {
        const result = evalNode(child, launcher, ctx, now);
        if (!result.ok && result.waitMs <= 0) return { ok: false, waitMs: 0 };
        if (!result.ok) wait = Math.max(wait, result.waitMs);
      }
      return wait > 0 ? { ok: false, waitMs: wait } : { ok: true, waitMs: 0 };
    }
    let bestWait = 0;
    for (const child of rules) {
      const result = evalNode(child, launcher, ctx, now);
      if (result.ok) return { ok: true, waitMs: 0 };
      if (result.waitMs > 0 && (bestWait === 0 || result.waitMs < bestWait)) bestWait = result.waitMs;
    }
    return { ok: false, waitMs: bestWait };
  }
  return evalCondition(node, launcher, ctx, now);
}

function evalCondition(node, launcher, ctx, now) {
  switch (node.type) {
    case "time":
      return { ok: timeMatches(node, launcher, now), waitMs: 0 };
    case "path":
      return { ok: pathMatches(node, ctx.path || ""), waitMs: 0 };
    case "page_type":
      return { ok: Array.isArray(node.is) && node.is.indexOf(ctx.pageType || "other") !== -1, waitMs: 0 };
    case "query":
      return { ok: queryMatches(node, ctx.query || {}), waitMs: 0 };
    case "device":
      return { ok: node.is === (ctx.device || "desktop"), waitMs: 0 };
    case "visitor":
      return { ok: node.is === (ctx.visitor || "new"), waitMs: 0 };
    case "seconds_on_page": {
      const have = Number(ctx.secondsOnPage || 0);
      const need = Number(node.gte || 0);
      if (have >= need) return { ok: true, waitMs: 0 };
      return { ok: false, waitMs: Math.max(0, Math.round((need - have) * 1000)) };
    }
    default:
      return { ok: false, waitMs: 0 };
  }
}

function timeMatches(node, launcher, now) {
  const tz = launcher && launcher.timezone ? launcher.timezone : "UTC";
  let parts;
  try {
    parts = new Intl.DateTimeFormat("en-US", {
      timeZone: tz,
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
      weekday: "short",
    }).formatToParts(now);
  } catch (e) {
    return false;
  }
  const bag = {};
  for (const part of parts) bag[part.type] = part.value;
  const minutes = Number(bag.hour) * 60 + Number(bag.minute);
  const start = clockToMinutes(node.start);
  const end = clockToMinutes(node.end);
  if (start === null || end === null) return false;
  const inWindow = start === end ? true : (start < end ? minutes >= start && minutes < end : minutes >= start || minutes < end);
  if (!inWindow) return false;
  if (!Array.isArray(node.days) || node.days.length === 0) return true;
  const weekday = String(bag.weekday || "").slice(0, 3).toLowerCase();
  const key = DAYS.indexOf(weekday) === -1 ? weekday : DAYS[DAYS.indexOf(weekday)];
  return node.days.map((d) => String(d).toLowerCase()).indexOf(key) !== -1;
}

function clockToMinutes(value) {
  const match = /^(\d{2}):(\d{2})$/.exec(String(value || ""));
  if (!match) return null;
  return Number(match[1]) * 60 + Number(match[2]);
}

function pathMatches(node, path) {
  const value = String(node.value || "");
  const hay = String(path || "");
  if (node.match === "equals") return hay === value;
  if (node.match === "prefix") return hay.indexOf(value) === 0;
  return hay.indexOf(value) !== -1;
}

function queryMatches(node, query) {
  if (!Object.prototype.hasOwnProperty.call(query, node.name)) return false;
  if (node.value === undefined || node.value === "") return true;
  return String(query[node.name]) === String(node.value);
}

if (typeof globalThis !== "undefined") {
  globalThis.SeekmodoChatLauncher = {
    SESSION_KEY,
    VISITOR_KEY,
    applyAppearance,
    createFlair,
    placeFlair,
    ensureFlairStyles,
    evaluate,
    alreadyOpened,
    markOpened,
    visitorKind,
  };
}
