var SeekmodoSuggest=(function(exports){'use strict';var E=class extends Error{status;body;tool;constructor(n,e,t,r){super(n),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=r;}},Q=class extends E{constructor(n,e,t){super(`Seekmodo auth failed (HTTP ${n})`,n,e,t),this.name="SeekmodoAuthError";}},ke=class extends E{code;bucket;limit;used;constructor(n,e){super("Seekmodo over quota (HTTP 402)",402,n,e),this.name="SeekmodoQuotaError";let t=n??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},Se=class extends E{constructor(n,e,t){super(`Seekmodo server error (HTTP ${n})`,n,e,t),this.name="SeekmodoServerError";}},Ee=class extends E{constructor(n,e,t){super(`Seekmodo request rejected (HTTP ${n})`,n,e,t),this.name="SeekmodoRequestError";}},q=class extends E{constructor(n,e){super(`Seekmodo network failure${n instanceof Error?`: ${n.message}`:""}`,0,n,e),this.name="SeekmodoNetworkError";}};function A(n,e){if(n instanceof q)return A(n.body);if(n instanceof TypeError){let t=n.message.toLowerCase();return t.includes("failed to fetch")||t.includes("networkerror")||t.includes("network request failed")||t.includes("load failed")}if(n instanceof Error){let t=n.message.toLowerCase();return t.includes("cors")||t.includes("access-control-allow-origin")||t.includes("cross-origin")}return  false}var _e="https://gateway.seekmodo.com",xe=8e3,Le=class{config;cachedToken=null;constructor(n){if(!n.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof n.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:n.tenantId,getToken:n.getToken,baseUrl:(n.baseUrl??_e).replace(/\/+$/,""),fetch:n.fetch??globalThis.fetch.bind(globalThis),timeoutMs:n.timeoutMs??xe,signal:n.signal,onError:n.onError,getRegion:n.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(n,e,t={}){try{return await this.callOnce(n,e,t,!1)}catch(r){if(r instanceof Q){this.clearTokenCache();try{return await this.callOnce(n,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:n}),o}}throw this.config.onError?.(r,{tool:n}),r}}async callOnce(n,e,t,r){let o=await this.resolveToken(r),i=`${this.config.baseUrl}/v1/${encodeURIComponent(n)}`,s=new AbortController,l=t.timeoutMs??this.config.timeoutMs,d=setTimeout(()=>s.abort(),l),c=()=>s.abort();this.config.signal?.addEventListener("abort",c,{once:true}),t.signal?.addEventListener("abort",c,{once:true});let m={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let h=await this.config.getRegion();typeof h=="string"&&h.length>0&&(m["Seekmodo-Region"]=h);}catch{}let u;try{u=await this.config.fetch(i,{method:"POST",headers:m,body:JSON.stringify(e),signal:s.signal});}catch(h){throw new q(h,n)}finally{clearTimeout(d),this.config.signal?.removeEventListener("abort",c),t.signal?.removeEventListener("abort",c);}let p=await u.text(),g=p?Re(p):null;if(u.status===401||u.status===403)throw new Q(u.status,g,n);if(u.status===402)throw new ke(g,n);if(u.status>=500)throw new Se(u.status,g,n);if(!u.ok)throw new Ee(u.status,g,n);return g}async resolveToken(n){let e=Date.now();if(!n&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function Re(n){try{return JSON.parse(n)}catch{return n}}var G=class{transport;recommend;bundle;constructor(n){this.transport=new Le(n),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(n,e){return this.transport.call("search",{...n},e??{})}suggest(n,e){return this.transport.call("suggest",{...n},e??{})}searchByImage(n,e){return this.transport.call("search.byImage",{...n},e??{})}chat(n,e){return this.transport.call("chat",{...n},e??{})}ask(n,e){return this.transport.call("ask",{...n},e??{})}event(n,e){return this.transport.call("events",{...n},e??{})}};var T=null,v=null;function _(n){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${n}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function W(){return _("seekmodo:suggest-proxy")}function B(){return W()!==null}var Ae={keywords:[],products:[],categories:[],recent:[],trending:[],did_you_mean:null,meta:{}};async function Y(n,e){let t=W();if(!t)throw new Error("@seekmodo/web-components: seekmodo:suggest-proxy meta missing");let r=true;try{typeof window<"u"&&window.location?.origin&&(r=new URL(t,window.location.href).origin===window.location.origin);}catch{r=true;}let o=new AbortController,i=e?.timeoutMs??8e3,s=setTimeout(()=>o.abort(),i),l=()=>o.abort();e?.signal?.addEventListener("abort",l,{once:true});try{let d=await fetch(t,{method:"POST",credentials:r?"same-origin":"omit",headers:{"Content-Type":"application/json",Accept:"application/json"},body:JSON.stringify(n),signal:o.signal});if(!d.ok)throw new Error(`seekmodo:suggest-proxy returned HTTP ${d.status}`);let c=await d.json();return !c||typeof c!="object"?{...Ae}:{keywords:Array.isArray(c.keywords)?c.keywords:[],products:Array.isArray(c.products)?c.products:[],categories:Array.isArray(c.categories)?c.categories:[],recent:Array.isArray(c.recent)?c.recent:[],trending:Array.isArray(c.trending)?c.trending:[],did_you_mean:typeof c.did_you_mean=="string"||c.did_you_mean===null?c.did_you_mean:null,redirect:c.redirect,meta:c.meta&&typeof c.meta=="object"?c.meta:{}}}finally{clearTimeout(s),e?.signal?.removeEventListener("abort",l);}}function J(){return T!==null||(T=Te()),T}async function Te(){let n=_("seekmodo:tenant");if(!n)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=_("seekmodo:token"),t=_("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(v={token:e,expiresAt:Date.now()+3e4});let r=_("seekmodo:gateway")??void 0;return new G({tenantId:n,baseUrl:r,getRegion:()=>Me(),getToken:async()=>{let o=Date.now();if(v&&v.expiresAt-1e4>o)return {token:v.token,expiresAt:v.expiresAt};if(!t){if(v)return {token:v.token,expiresAt:v.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let i=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!i.ok)throw new Error(`seekmodo:refresh route returned HTTP ${i.status}`);let s=await i.json();if(!s.token||typeof s.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return v={token:s.token,expiresAt:s.expires_at*1e3},{token:v.token,expiresAt:v.expiresAt}}})}var Ce="seekmodo_region";function Pe(n){if(typeof n!="string")return null;let e=n.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function Me(){if(typeof document>"u")return null;let n=document.cookie??"";if(n.length===0)return null;let e=Ce.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(n);if(!t)return null;try{return Pe(decodeURIComponent(t[1]))}catch{return null}}var C=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render(),this.afterRender();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}afterRender(){}async getClient(){return J()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function a(n,e,t){let r=document.createElement(n);if(e){for(let[o,i]of Object.entries(e))if(!(i==null||i===false))if(o==="class")r.className=String(i);else if(o==="part")r.setAttribute("part",String(i));else if(o==="text")r.textContent=String(i);else if(o==="html")r.innerHTML=String(i);else if(o==="attrs"&&typeof i=="object"&&i!==null)for(let[s,l]of Object.entries(i))r.setAttribute(s,l);else r.setAttribute(o,String(i));}return r}function P(n,e){let t=null;return (...r)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>n(...r),e);}}function w(n,e,t){n.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var M="Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";function ee(n,e,t){let r=document.createElement("style");r.textContent=e;let o=a("div",{class:"wrap seekmodo-cors-blocked",part:"wrap cors-blocked",attrs:{role:"status"}});o.append(a("div",{class:"cors-notice",part:"cors-notice",text:t?.message??M})),n.replaceChildren(r,o);}var X="seekmodo-cors-notice";function Z(n,e){if(!n||typeof document>"u")return;let t=n.closest(".search-form")??n.parentNode;if(!t)return;t.style.position=t.style.position||"relative";let r=t.querySelector(`.${X}`);if(!r){r=document.createElement("div"),r.className=X,r.setAttribute("role","status"),r.style.cssText=["position:absolute","top:100%","left:0","right:0","z-index:10050","display:none","background:#fff8e6","border:1px solid #f0c040","border-top:none","padding:8px 12px","font-size:13px","line-height:1.4","color:#5c4a00","box-shadow:0 4px 12px rgba(0,0,0,.08)"].join(";"),t.appendChild(r);let o=()=>{if(!r)return;let i=(n.value||"").trim();r.style.display=i.length>=2?"block":"none";};n.addEventListener("input",o),n.addEventListener("focus",o);}r.textContent=e??M;}function te(){typeof window>"u"||(window.seekmodoShowCorsNotice=Z,window.seekmodoScriptLoadFailed=(n,e)=>{let t=n??document.querySelectorAll('input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]');for(let r=0;r<t.length;r++){let o=t[r];o instanceof HTMLInputElement&&Z(o,e);}});}function re(n,e,t){try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(n,r);return o.searchParams.set(e,t),/^https?:\/\//i.test(n)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=n.includes("?")?"&":"?";return `${n}${r}${encodeURIComponent(e)}=${encodeURIComponent(t)}`}}function He(n,e){let t=(n??"").toLowerCase(),r=(e??"").toLowerCase();if(r.startsWith("http"))try{r=new URL(r).pathname;}catch{r="";}return t==="page"&&/\/tools\/[^/]+\/?$/.test(r)?"Tool":t==="page"&&/\/tools\/?$/.test(r)?"Tools":t==="page"?"Page":t==="post"?"Article":""}function S(n){let e=typeof n.post_type=="string"?n.post_type.toLowerCase():"",t=typeof n.url=="string"?n.url:typeof n.permalink=="string"?n.permalink:"";return {postType:e,label:He(e,t)}}var N="split-rail",Ie=15;function L(n,e){let r={"split-rail":5,"command-bar":5,"cinema-grid":6,magazine:6,classic:5}[n]??5,o=Math.max(e,r*3);return Math.min(Ie,o)}var ae=`
  .wrap.wide { padding: 0; overflow-x: hidden; overflow-y: auto; }
  .wrap.wide.split-rail-panel {
    overflow: hidden; display: flex; flex-direction: column;
    max-height: var(--_max-height);
  }
  .meta-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.75rem; padding: 0.65rem 1rem; border-bottom: 1px solid var(--_border);
    background: var(--_row-hover); font-size: 0.8125rem;
  }
  .meta-bar .query { color: #2563eb; font-weight: 600; }
  .meta-bar .count { color: var(--_group-color); }
  .meta-bar .view-all,
  .meta-bar .view-all-cta {
    border-top: none; padding: 0; text-align: right;
    color: #2563eb; text-decoration: underline; font-weight: 600;
  }
  .filter-bar, .chip-row {
    display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem;
    padding: 0.55rem 1rem; border-bottom: 1px solid var(--_border);
  }
  .filter-label {
    font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: var(--_group-color); margin-right: 0.15rem;
  }
  .chip {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.28rem 0.6rem; font-size: 0.75rem; border: 1px solid var(--_border);
    border-radius: 999px; background: var(--_bg); cursor: pointer;
  }
  .chip .badge { font-size: 0.65rem; opacity: 0.7; font-variant-numeric: tabular-nums; }
  .split-body {
    display: grid; grid-template-columns: 220px minmax(0, 1fr);
    align-items: stretch; min-width: 0;
  }
  /* DOM order is canvas \u2192 divider \u2192 rail for mobile stacking; pin
     rail left / products right on desktop via explicit placement. */
  .split-body .rail { grid-column: 1; grid-row: 1; }
  .split-body .canvas { grid-column: 2; grid-row: 1; }
  .split-body .split-divider { display: none; }
  .wrap.wide.split-rail-panel .split-body {
    flex: 1 1 auto; min-height: 0; overflow: hidden;
  }
  .rail {
    border-right: 1px solid var(--_border); background: var(--_row-hover);
    padding: 0.5rem 0.35rem; align-self: stretch;
    min-height: 0; overflow-y: auto; overscroll-behavior: contain;
  }
  .rail .row { padding: 0.4rem 0.55rem; font-size: 0.8125rem; }
  .canvas {
    padding: 0.65rem 0.75rem 0.75rem; min-width: 0;
    min-height: 0; overflow-y: auto; overscroll-behavior: contain;
    container-type: inline-size; container-name: suggest-canvas;
  }
  .product-grid {
    display: grid; gap: 0.35rem; min-width: 0;
  }
  .product-grid.cols-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
  .product-grid.cols-6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  @container suggest-canvas (max-width: 520px) {
    .product-grid.cols-5 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .product-grid.cols-6 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @container suggest-canvas (max-width: 680px) {
    .product-grid.cols-5 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .product-grid.cols-6 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  }
  .product-card {
    display: flex; flex-direction: column; gap: 0.35rem; padding: 0.45rem;
    border-radius: calc(var(--_radius) - 0.125rem); text-decoration: none;
    color: inherit; background: none; border: none; font: inherit; text-align: left;
    cursor: pointer; width: 100%;
  }
  .product-card:hover, .product-card.active { background: var(--_row-hover); }
  .thumb-frame {
    width: 100%; aspect-ratio: 1; display: flex; align-items: center;
    justify-content: center; overflow: hidden;
    border-radius: calc(var(--_radius) - 0.2rem); background: transparent;
  }
  .product-card .thumb-frame .thumb {
    max-width: 100%; max-height: 100%; width: auto; height: auto;
    object-fit: contain; object-position: center; display: block;
    border-radius: 0; background: transparent;
  }
  .product-card .thumb-frame .thumb-empty {
    width: 100%; height: 100%; aspect-ratio: unset;
    border-radius: 0;
    background: var(--_row-hover);
    border: 1px solid var(--_border);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; font-weight: 700; color: var(--_group-color);
    line-height: 1;
  }
  .product-card .card-meta {
    display: block;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--_group-color);
    margin-top: 0.15rem;
  }
  .product-card .card-title {
    font-size: 0.75rem; line-height: 1.3; display: -webkit-box;
    -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  .hero-card .card-title {
    font-size: 0.8125rem; line-height: 1.35; display: -webkit-box;
    -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  @container suggest-canvas (max-width: 560px) {
    .wrap.wide.product-title-tooltip .product-card .card-title,
    .wrap.wide.product-title-tooltip .hero-card .card-title {
      display: block; -webkit-line-clamp: unset; -webkit-box-orient: unset;
      overflow: visible; white-space: normal; word-break: break-word;
    }
    .wrap.wide.product-title-tooltip .product-grid.cols-5,
    .wrap.wide.product-title-tooltip .product-grid.cols-6 {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  .product-card .card-price {
    font-size: 0.8125rem; font-weight: 600; font-variant-numeric: tabular-nums;
  }
  .product-card .card-price del {
    color: var(--_group-color); font-weight: 400; margin-right: 0.3rem; font-size: 0.85em;
  }
  .command-header {
    display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); color: #fff;
  }
  .command-header .query-display { font-size: 1rem; font-weight: 600; flex: 1; }
  .command-header .result-pill {
    padding: 0.25rem 0.6rem; background: rgba(255,255,255,0.15);
    border-radius: 999px; font-size: 0.75rem;
  }
  .command-header .view-all-link {
    color: #fff; font-size: 0.75rem; font-weight: 600; text-decoration: none;
    padding: 0.3rem 0.65rem; background: rgba(255,255,255,0.2); border-radius: 999px;
  }
  .hero-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem;
    padding: 0.65rem 0; border-bottom: 1px solid var(--_border); margin-bottom: 0.5rem;
  }
  .hero-card {
    display: grid; grid-template-columns: 90px 1fr; gap: 0.65rem;
    padding: 0.65rem; border: 1px solid var(--_border);
    border-radius: calc(var(--_radius) - 0.125rem); background: var(--_row-hover);
    text-decoration: none; color: inherit; cursor: pointer; width: 100%;
    font: inherit; text-align: left;
  }
  .hero-card:hover, .hero-card.active { border-color: #2563eb; }
  .hero-card .thumb, .hero-card .thumb-empty {
    width: 90px; height: 90px; object-fit: contain;
    border-radius: calc(var(--_radius) - 0.2rem); background: var(--_row-active);
  }
  .hero-badge {
    display: inline-block; width: fit-content; padding: 0.1rem 0.4rem;
    font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
    background: #2563eb; color: #fff; border-radius: 3px; margin-bottom: 0.2rem;
  }
  .brand-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 0.35rem;
    padding: 0.45rem 1rem; font-size: 0.65rem; color: var(--_group-color);
    border-top: 1px solid var(--_border); background: var(--_row-hover);
    text-decoration: none;
  }
  .brand-footer:hover { color: var(--_color); }
  .brand-by { white-space: nowrap; }
  .brand-logo { height: 16px; width: auto; display: block; }
  .did-you-mean-bar {
    padding: 0.5rem 1rem; font-size: 0.8125rem;
    background: #eff6ff; border-bottom: 1px solid #bfdbfe;
  }
  .did-you-mean-bar .swap {
    border: none; background: none; font: inherit; font-weight: 600;
    color: #2563eb; text-decoration: underline; cursor: pointer; padding: 0;
  }
  .split-divider { display: none; }
  .split-divider-icon {
    width: 2.75rem; height: 1rem; color: var(--_group-color);
    pointer-events: none; opacity: 0.9;
  }
  .products-pending {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 6rem;
    padding: 1rem;
    color: var(--_group-color);
    font-size: 0.875rem;
    text-align: center;
  }
  @media (max-width: 900px) {
    /* Stack products above suggestions so product hits stay above the
       mobile keyboard; desktop split-rail keeps rail left via grid. */
    .split-body {
      display: flex; flex-direction: column;
      grid-template-columns: unset; grid-template-rows: unset;
    }
    .split-body .rail,
    .split-body .canvas {
      grid-column: unset; grid-row: unset;
    }
    .split-body .canvas {
      flex: 1 1 auto;
      min-height: 4.5rem;
      min-width: 0;
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    .split-body .rail {
      flex: 0 1 auto;
      border-right: none;
      border-top: 1px solid var(--_border);
      border-bottom: none;
      overflow-y: auto;
      overscroll-behavior: contain;
    }
    .wrap.wide.split-rail-panel.split-rail-mobile {
      height: min(var(--_max-height), 70vh);
      min-height: 16rem;
    }
    /* Static mobile stack (no drag handle): cap suggestion rail height. */
    .split-body:not(.split-body--mobile-resize) .rail {
      max-height: 7.5rem;
    }
    .split-body--mobile-resize .rail {
      flex: var(--split-rail-top-grow, 0.28) 1 0;
      min-height: 3.25rem;
      max-height: none;
    }
    .split-body--mobile-resize .split-divider {
      display: flex; align-items: center; justify-content: center;
      flex: 0 0 auto; height: 1.75rem; margin: 0; padding: 0;
      border-top: 1px solid var(--_border);
      border-bottom: 1px solid var(--_border);
      background: linear-gradient(180deg, var(--_row-hover) 0%, var(--_row-active) 100%);
      cursor: ns-resize; touch-action: none;
      user-select: none; -webkit-user-select: none;
      z-index: 2;
    }
    .split-body--mobile-resize .split-divider:focus-visible {
      outline: 2px solid #2563eb; outline-offset: -2px;
    }
    .split-body--mobile-resize .split-divider.is-dragging {
      background: var(--_row-active);
    }
    .split-body--mobile-resize .canvas {
      flex: var(--split-rail-bottom-grow, 0.72) 1 0;
    }
    .product-grid.cols-5, .product-grid.cols-6 { grid-template-columns: repeat(3, 1fr); }
    .hero-row { grid-template-columns: 1fr; }
    .wrap.wide.product-title-tooltip .product-card .card-title,
    .wrap.wide.product-title-tooltip .hero-card .card-title {
      display: block; -webkit-line-clamp: unset; -webkit-box-orient: unset;
      overflow: visible; white-space: normal; word-break: break-word;
    }
    .wrap.wide.product-title-tooltip .product-grid.cols-5,
    .wrap.wide.product-title-tooltip .product-grid.cols-6 {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .wrap.wide.product-title-tooltip .product-card {
      align-items: stretch;
    }
  }
`,ne=.15,oe=.85,ze=.28,ie="seekmodo:split-rail-mobile-ratio-v3",De="(max-width: 900px)",Fe=1-ze;function Oe(){return a("div",{class:"split-divider",part:"split-divider",html:'<svg class="split-divider-icon" viewBox="0 0 36 12" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="1" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="5" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="9" width="24" height="2" rx="1" fill="currentColor"/></svg>'})}function le(n){let e=n.querySelector(".split-divider");if(!e)return ()=>{};let t=window.matchMedia(De),r=Fe;try{let g=sessionStorage.getItem(ie);if(g){let h=parseFloat(g);h>=ne&&h<=oe&&(r=h);}}catch{}let o=g=>{r=Math.min(oe,Math.max(ne,g)),n.style.setProperty("--split-rail-bottom-grow",String(r)),n.style.setProperty("--split-rail-top-grow",String(1-r));},i=()=>{n.style.removeProperty("--split-rail-top-grow"),n.style.removeProperty("--split-rail-bottom-grow");},s=()=>{t.matches?o(r):i();};s();let l=false,d=0,c=r,m=g=>{!t.matches||g.button!==0||(l=true,d=g.clientY,c=r,e.classList.add("is-dragging"),e.setPointerCapture(g.pointerId),g.preventDefault());},u=g=>{if(!l)return;let f=n.getBoundingClientRect().height-e.offsetHeight;f<=0||o(c+(g.clientY-d)/f);},p=g=>{if(l){l=false,e.classList.remove("is-dragging");try{e.releasePointerCapture(g.pointerId);}catch{}try{sessionStorage.setItem(ie,String(r));}catch{}}};return e.setAttribute("role","separator"),e.setAttribute("aria-orientation","horizontal"),e.setAttribute("aria-label","Resize product and suggestion panels"),e.setAttribute("tabindex","0"),e.addEventListener("pointerdown",m),e.addEventListener("pointermove",u),e.addEventListener("pointerup",p),e.addEventListener("pointercancel",p),t.addEventListener("change",s),()=>{e.removeEventListener("pointerdown",m),e.removeEventListener("pointermove",u),e.removeEventListener("pointerup",p),e.removeEventListener("pointercancel",p),t.removeEventListener("change",s),e.classList.remove("is-dragging"),i();}}var $e="https://seekmodo.com/email-assets/seekmodo-lockup.png";function de(n,e){return n.resolveThumbSrc?n.resolveThumbSrc(e):e}function ce(n){return String(n.name??n.title??"").trim()}function ue(n,e,t){e.productTitleTooltip&&t&&n.setAttribute("title",t);}function pe(n,e){return n.productTitleTooltip&&e?e:""}function he(n,e,t){let r={block:"products",data:e,value:String(e.name??e.title??""),id:e.id!==void 0?String(e.id):void 0},o=n.rows.length;return n.rows.push(r),o}function Be(n,e,t){let r=a("div",{class:"thumb-frame",part:"thumb-frame"}),{postType:o}=S(n);o&&r.setAttribute("data-post-type",o);let i=n.image_url??n.image;if(i)r.append(a("img",{class:"thumb",part:"thumb",attrs:{src:de(e,i),"data-src":i,alt:pe(e,t),loading:"eager",decoding:"async"}}));else {let s=a("div",{class:"thumb-empty",part:"thumb thumb--empty",text:ge(o)});o&&s.setAttribute("data-content-type",o),r.append(s);}return r}function ge(n){return n==="page"?"P":n==="post"?"A":"\xB7"}function Ue(n,e,t){let{postType:r}=S(n),o=n.image_url??n.image;if(o)return a("img",{class:"thumb",part:"thumb",attrs:{src:de(e,o),"data-src":o,alt:pe(e,t),loading:"eager",decoding:"async"}});let i=a("div",{class:"thumb-empty",part:"thumb thumb--empty",text:ge(r)});return r&&i.setAttribute("data-content-type",r),i}function me(n,e,t="card-price"){if(n.price===void 0||n.price===null)return null;let r=a("div",{class:t,part:"price"});return n.on_sale&&typeof n.sale_price=="number"?(r.append(a("del",{text:e.formatPrice(n.price,n.currency)})),r.append(document.createTextNode(e.formatPrice(n.sale_price,n.currency)))):r.append(document.createTextNode(e.formatPrice(n.price,n.currency))),r}function fe(n,e,t){n.classList.add("row"),n.setAttribute("data-seekmodo-surface","suggest"),n.setAttribute("data-seekmodo-block","products"),n.setAttribute("data-seekmodo-pos",String(t));let r=e.rows[t];r?.id&&n.setAttribute("data-seekmodo-id",r.id),n.addEventListener("click",()=>e.onRowClick(t));}function Ke(n,e,t,r=false){let o=he(n,e),i=ce(e),{postType:s,label:l}=S(e),d=a("button",{class:"product-card",part:"row",type:"button"});s&&d.setAttribute("data-post-type",s),d.append(Be(e,n,i));let c=a("span",{class:"card-title",part:"name",text:i});ue(c,n,i),d.append(c),l&&d.append(a("span",{class:"card-meta",part:"card-meta",text:l}));let m=me(e,n,"card-price");return m&&d.append(m),fe(d,n,o),d}function Ne(n,e,t,r){let o=he(n,e),i=ce(e),s=a("button",{class:"hero-card",part:"row",type:"button"});r&&s.append(a("span",{class:"hero-badge",text:r})),s.append(Ue(e,n,i));let l=a("div",{class:"hero-info"}),d=a("span",{class:"card-title",part:"name",text:i});ue(d,n,i),l.append(d);let c=me(e,n);return c&&l.append(c),s.append(l),fe(s,n,o),s}function U(n){let e=n.res.meta?.total??0,t=a("div",{class:"meta-bar",part:"meta-bar"}),r=a("div");r.append(a("span",{class:"count",text:`${e} results for `})),r.append(a("span",{class:"query",text:`"${n.lastQuery}"`})),t.append(r);let o=a("a",{class:"view-all view-all-cta",part:"view-all",attrs:{href:n.viewAllHref},text:n.label("view_all").replace("{total}",String(e))});return o.addEventListener("click",i=>{i.preventDefault(),n.onViewAll();}),t.append(o),t}function je(n){let e=n.res.did_you_mean;if(!e)return null;let t=a("div",{class:"did-you-mean-bar",part:"did-you-mean"});t.append(document.createTextNode(`Showing results for "${n.lastQuery}". Search instead for `));let r=a("button",{class:"swap",type:"button",text:e}),o=n.rows.length;return n.rows.push({block:"did_you_mean",data:{value:e},value:e}),r.addEventListener("click",()=>n.onRowClick(o)),t.append(r),t.append(document.createTextNode("?")),t}function K(n,e){let t=a("div",{class:"chip-row filter-bar",part:"filter-bar"});return t.append(a("span",{class:"filter-label",text:"Category"})),e.forEach((r,o)=>{let i=a("button",{class:`chip${o===0?" active":""}`,type:"button",text:`${r.name}${typeof r.count=="number"?` ${r.count}`:""}`}),s=n.rows.length;n.rows.push({block:"categories",data:r,value:String(r.name??"")}),i.addEventListener("click",()=>n.onRowClick(s)),t.append(i);}),t}function se(n,e,t="Try"){let r=a("div",{class:"chip-row",part:"filter-bar"});return r.append(a("span",{class:"filter-label",text:t})),e.forEach(o=>{let i=a("button",{class:"chip",type:"button",text:o.keyword}),s=n.rows.length;n.rows.push({block:"keywords",data:o,value:o.keyword}),i.addEventListener("click",()=>n.onRowClick(s)),r.append(i);}),r}function H(n,e,t,r,o){let i=n.rows.length;n.rows.push({block:e,data:t,value:r});let s=a("button",{class:"row",part:"row",type:"button"});return s.append(a("div",{class:"name",part:"name",text:r})),o&&s.append(a("span",{class:"badge",part:"badge",text:o})),s.setAttribute("data-seekmodo-surface","suggest"),s.setAttribute("data-seekmodo-block",e),s.setAttribute("data-seekmodo-pos",String(i)),s.addEventListener("click",()=>n.onRowClick(i)),s}function I(n,e){if(e.length===0)return null;let t=a("div",{class:"rail-section"});return t.append(a("div",{class:"group-title",part:"group-title",text:n})),e.forEach(r=>t.append(r)),t}function Ve(n){if(!n.showBranding)return null;let e=a("a",{class:"brand-footer",part:"brand-footer",attrs:{href:n.brandUrl,target:"_blank",rel:"noopener noreferrer"}});return e.append(a("span",{class:"brand-by",text:"Powered by "})),e.append(a("img",{class:"brand-logo",part:"brand-logo",attrs:{src:n.brandLogoUrl||$e,alt:"Seekmodo",height:"16"}})),e}function x(n,e,t){let r=a("div",{class:`product-grid cols-${t}`,part:"product-grid"});return e.forEach((o,i)=>r.append(Ke(n,o,i,true))),r}function be(n,e){let t=["wrap","wide"];n==="split-rail"&&t.push("split-rail-panel"),e.splitMobileResize&&t.push("split-rail-mobile"),e.productTitleTooltip&&t.push("product-title-tooltip");let r=a("div",{class:t.join(" "),part:"wrap"});r.append(a("slot",{attrs:{name:"header"}}));let o=(e.res.products??[]).slice(0,L(n,e.limit)),i=(e.res.keywords??[]).slice(0,e.limit),s=(e.res.categories??[]).slice(0,e.limit),l=(e.res.redirects??[]).slice(0,e.limit),d=(e.res.recent??[]).slice(0,5);(e.res.trending??[]).slice(0,5);if(n==="split-rail"){(!e.productsPending||(e.res.meta?.total??0)>0)&&r.append(U(e));let u=e.splitMobileResize?"split-body split-body--mobile-resize":"split-body",p=a("div",{class:u}),g=a("aside",{class:"rail",part:"rail"}),h=i.map(b=>H(e,"keywords",b,b.keyword)),f=I(e.label("keywords"),h);f&&g.append(f);let y=l.map(b=>H(e,"redirects",b,String(b.label||b.matched_term||b.target_url))),k=I(e.label("redirects"),y);k&&g.append(k);let O=s.map(b=>H(e,"categories",b,String(b.name),typeof b.count=="number"?String(b.count):void 0)),j=I(e.label("categories"),O);j&&g.append(j);let we=d.map(b=>H(e,"recent",b,b.keyword)),V=I(e.label("recent"),we);V&&g.append(V);let $=a("div",{class:"canvas"});e.productsPending&&o.length===0?$.append(a("div",{class:"products-pending",part:"products-pending",text:"Matching products appear when you pause typing\u2026"})):$.append(x(e,o,5)),p.append($),e.splitMobileResize&&p.append(Oe()),p.append(g),r.append(p);}else if(n==="cinema-grid"){let u=je(e);u&&r.append(u),r.append(U(e)),s.length&&r.append(K(e,s)),i.length&&r.append(se(e,i));let p=a("div",{class:"canvas"});p.append(x(e,o,6)),r.append(p);}else if(n==="command-bar"){let u=e.res.meta?.total??0,p=a("div",{class:"command-header",part:"meta-bar"});p.append(a("div",{class:"query-display",text:`"${e.lastQuery}"`})),p.append(a("span",{class:"result-pill",text:`${u} products`}));let g=a("a",{class:"view-all-link",part:"view-all",attrs:{href:e.viewAllHref},text:"View all \u2192"});if(g.addEventListener("click",f=>{f.preventDefault(),e.onViewAll();}),p.append(g),r.append(p),e.res.did_you_mean){let f=a("div",{class:"chip-row"});f.append(a("span",{class:"filter-label",text:"Did you mean"}));let y=a("button",{class:"chip",type:"button",text:e.res.did_you_mean}),k=e.rows.length;e.rows.push({block:"did_you_mean",data:{value:e.res.did_you_mean},value:e.res.did_you_mean}),y.addEventListener("click",()=>e.onRowClick(k)),f.append(y),r.append(f);}i.length&&r.append(se(e,i,"Related")),s.length&&r.append(K(e,s));let h=a("div",{class:"canvas"});h.append(x(e,o,5)),r.append(h);}else if(n==="magazine"){r.append(U(e)),s.length&&r.append(K(e,s));let u=a("div",{class:"canvas"}),p=o.slice(0,3),g=o.slice(3);if(p.length){u.append(a("div",{class:"group-title",part:"group-title",text:"Best matches"}));let h=a("div",{class:"hero-row",part:"hero-row"});p.forEach((f,y)=>{h.append(Ne(e,f,y,y===0?"Top match":void 0));}),u.append(h);}g.length?(u.append(a("div",{class:"group-title",part:"group-title",text:"More results"})),u.append(x(e,g,6))):p.length||u.append(x(e,o,6)),r.append(u);}let m=Ve(e);return m&&r.append(m),r.append(a("slot",{attrs:{name:"footer"}})),r}function R(n){return n!=="classic"&&n!==""}var ye=["recent","did_you_mean","keywords","trending","products","categories"],Qe={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing.",cors_blocked:M},ve=`
  :host {
    /* When anchor-mode is "auto" (default) applyAnchor() flips
       these to position:fixed via inline style so the dropdown
       overlays the input regardless of the host's DOM position.
       When anchor-mode is "none" the shadow stylesheet wins and
       the dropdown renders in-DOM at its host's position. */
    display: block;
    position: relative;
    font-family: inherit;
    --_bg: var(--seekmodo-suggest-bg, #ffffff);
    --_color: var(--seekmodo-suggest-color, #18181b);
    --_border: var(--seekmodo-suggest-border, #d4d4d8);
    --_radius: var(--seekmodo-suggest-radius, 0.5rem);
    --_shadow: var(--seekmodo-suggest-shadow, 0 10px 24px rgba(0, 0, 0, 0.08));
    --_row-padding: var(--seekmodo-suggest-row-padding, 0.5rem 0.75rem);
    --_row-hover: var(--seekmodo-suggest-row-hover, #f4f4f5);
    --_row-active: var(--seekmodo-suggest-row-active, #e4e4e7);
    --_thumb: var(--seekmodo-suggest-thumb-size, 36px);
    --_group-color: var(--seekmodo-suggest-group-color, #71717a);
    --_group-size: var(--seekmodo-suggest-group-size, 0.7rem);
    --_max-height: var(--seekmodo-suggest-max-height, 70vh);
    --_width: var(--seekmodo-suggest-width, 100%);
  }
  .wrap {
    background: var(--_bg);
    color: var(--_color);
    border: 1px solid var(--_border);
    border-radius: var(--_radius);
    box-shadow: var(--_shadow);
    padding: 0.25rem 0;
    max-height: var(--_max-height);
    width: var(--_width);
    overflow-y: auto;
    overscroll-behavior: contain;
  }
  .hidden { display: none; }
  .group-title {
    padding: 0.4rem 0.75rem 0.25rem;
    font-size: var(--_group-size);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--_group-color);
    font-weight: 600;
  }
  .row {
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    padding: var(--_row-padding);
    cursor: pointer;
    font: inherit;
    color: inherit;
    display: flex;
    gap: 0.6rem;
    align-items: center;
  }
  .row:hover, .row.active {
    background: var(--_row-hover);
    outline: none;
  }
  .row:active {
    background: var(--_row-active);
  }
  .thumb {
    width: var(--_thumb);
    height: var(--_thumb);
    object-fit: contain;
    border-radius: calc(var(--_radius) - 0.125rem);
    flex-shrink: 0;
    background: var(--_row-hover);
  }
  .thumb.thumb-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--_group-color);
    border: 1px solid var(--_border);
  }
  .name { flex: 1; min-width: 0; }
  .name-title {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .name-meta {
    display: block;
    font-size: 0.8em;
    color: var(--_group-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .price {
    margin-left: auto;
    font-variant-numeric: tabular-nums;
  }
  .price del { color: var(--_group-color); margin-right: 0.4rem; font-weight: normal; }
  .view-all {
    text-align: center;
    font-weight: 600;
    padding: 0.55rem 0.75rem;
  }
  .wrap:not(.wide) .view-all {
    border-top: 1px solid var(--_border);
  }
  .empty {
    padding: 0.75rem;
    color: var(--_group-color);
    text-align: center;
    font-size: 0.9em;
  }
  .cors-notice {
    padding: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.45;
    color: #5c4a00;
    background: #fff8e6;
  }
  .did-you-mean {
    padding: 0.45rem 0.75rem;
    font-size: 0.9em;
  }
  .did-you-mean .swap {
    border: none;
    background: none;
    padding: 0;
    color: var(--_color);
    font: inherit;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
  }
  .badge {
    display: inline-block;
    font-size: 0.7em;
    padding: 0.05em 0.4em;
    border-radius: 999px;
    background: var(--_row-active);
    color: var(--_color);
    margin-left: 0.4rem;
    font-variant-numeric: tabular-nums;
  }
  /* Skeleton loader \u2014 three placeholder rows while in-flight on a
     cold cache. Masks the network latency on the first keystroke. */
  .skeleton .row {
    pointer-events: none;
  }
  .skeleton .thumb,
  .skeleton .name-title,
  .skeleton .name-meta {
    background: linear-gradient(90deg,
      var(--_row-hover) 0%,
      var(--_row-active) 50%,
      var(--_row-hover) 100%);
    background-size: 200% 100%;
    animation: seekmodo-suggest-shimmer 1.2s ease-in-out infinite;
    border-radius: 0.25rem;
    color: transparent;
  }
  .skeleton .name-title { height: 0.95em; width: 70%; }
  .skeleton .name-meta { height: 0.75em; width: 40%; margin-top: 0.3em; }
  @keyframes seekmodo-suggest-shimmer {
    0%   { background-position:  200% 0; }
    100% { background-position: -200% 0; }
  }
  @media (prefers-reduced-motion: reduce) {
    .skeleton .thumb,
    .skeleton .name-title,
    .skeleton .name-meta { animation: none; }
  }
  @media (max-width: 900px) {
    .wrap.product-title-tooltip .name-title {
      white-space: normal;
      overflow: visible;
      text-overflow: unset;
      word-break: break-word;
    }
    .wrap.product-title-tooltip .row {
      align-items: flex-start;
    }
  }
  ${ae}
`,D=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let r=this.map.keys().next().value;if(r===void 0)break;this.map.delete(r);}}clear(){this.map.clear();}},F=class extends C{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","product-debounce-ms","limit","cache-size","view-all-href","lang","anchor","anchor-offset","anchor-min-width","layout","split-mobile-resize","product-title-tooltip","typeahead-fallback-url","serp-passthrough","img-ver","vehicle-id","vehicle-filter","currency","show-branding","brand-url","brand-logo-url","suppress-legacy"]}current=null;loading=false;corsBlocked=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;debouncedPrefix=null;debouncedProducts=null;prefixDebouncedAt=0;productDebouncedAt=0;productsPending=false;pendingRenderedQ=null;fetchToken=0;prefixFetchToken=0;productFetchToken=0;inflight=null;cache=new D(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorResizeRaf=null;lastAnchorKey="";anchorApplied=false;suppressedLegacyEls=new WeakSet;legacySuppressionRetryHandler=null;splitResizeCleanup=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),this.scheduleLegacySuppressionRetries(),this.scheduleRender();}disconnectedCallback(){this.unscheduleLegacySuppressionRetries(),this.unbindSplitMobileResize(),this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"||e==="product-debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"||e==="layout"||e==="split-mobile-resize"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):e==="vehicle-id"||e==="vehicle-filter"||e==="serp-passthrough"||e==="currency"?(this.cache.clear(),this.current=null,this.lastQuery.trim().length>=(parseInt(this.getAttribute("min-length")??"2",10)||2)?this.fetch(this.lastQuery):this.scheduleRender()):this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;if((this.debouncedAt!==e||!this.debounced)&&(this.debouncedAt=e,this.debounced=P(r=>{this.fetch(r);},e)),!this.twoPhaseEnabled()){this.debouncedPrefix=null,this.debouncedProducts=null;return}let t=this.productDebounceMs();(this.prefixDebouncedAt!==e||!this.debouncedPrefix)&&(this.prefixDebouncedAt=e,this.debouncedPrefix=P(r=>{this.fetchPrefix(r);},e)),(this.productDebouncedAt!==t||!this.debouncedProducts)&&(this.productDebouncedAt=t,this.debouncedProducts=P(r=>{this.fetchProducts(r);},t));}debounceMs(){return parseInt(this.getAttribute("debounce-ms")??"150",10)||150}productDebounceMs(){return parseInt(this.getAttribute("product-debounce-ms")??"0",10)||0}twoPhaseEnabled(){return this.productDebounceMs()>this.debounceMs()}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new D(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let r=document.getElementById(e);if(r){this.subscribed=r,r.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let r=document.getElementById(t);r instanceof HTMLInputElement&&(this.inputEl=r,r.addEventListener("input",this.onPlainInput),r.addEventListener("focus",this.onPlainFocus),r.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}bindAnchorListeners(){this.anchorScrollHandler=()=>this.scheduleApplyAnchor(),this.anchorResizeHandler=()=>this.scheduleApplyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let r=this.inputEl??this.subscribed;r&&(t===r||r.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorResizeRaf!==null&&(cancelAnimationFrame(this.anchorResizeRaf),this.anchorResizeRaf=null),this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}scheduleApplyAnchor(){typeof window>"u"||this.anchorResizeRaf===null&&(this.anchorResizeRaf=requestAnimationFrame(()=>{this.anchorResizeRaf=null,this.applyAnchor();}));}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let r=t.getBoundingClientRect();if(r.width<=0&&r.height<=0){this.style.visibility="hidden";return}let o=parseInt(this.getAttribute("anchor-offset")??"4",10),i=Number.isFinite(o)?o:4,s=this.getAttribute("anchor-min-width"),l=R(this.layoutMode()),d=s===null?l?960:480:Math.max(0,parseInt(s,10)||0),c=typeof window<"u"&&window.innerWidth>0?window.innerWidth:Math.max(r.width,d),m=Math.min(c*.96,1440),u=l?Math.max(r.width,Math.min(Math.max(d,r.width),m)):Math.max(r.width,d),p=l?m:Math.max(0,c-r.left-8),g=l?Math.min(u,p):Math.max(r.width,Math.min(u,p)),h=l?Math.max(8,(c-g)/2):r.left,f=[h,g,r.bottom,i,l?1:0].join("|");if(this.anchorApplied&&f===this.lastAnchorKey){this.style.visibility==="hidden"&&(this.style.visibility="");return}this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${r.bottom+i}px`,this.style.left=`${h}px`,this.style.width=`${g}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true,this.lastAnchorKey=f;}clearAnchor(){this.anchorApplied&&(this.lastAnchorKey="",this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(o=>o.trim()).filter(Boolean),r=this.inputEl;if(r)for(let o of t)o==="jquery-ui"?this.suppressJqueryUiAutocomplete(r):o==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(r);}suppressJqueryUiAutocomplete(e){let r=window.jQuery;if(!r||!r.ui||!r.ui.autocomplete)return;let o=r(e);if(o.data("ui-autocomplete")){try{o.autocomplete("close");}catch{}try{o.autocomplete("destroy");}catch{}}let i=o.attr("aria-owns");if(i){let s=document.getElementById(i);s&&(s.classList.add("seekmodo-suggest-legacy-suppressed"),s.style.display="none",this.suppressedLegacyEls.add(s));}document.querySelectorAll("ul.ui-autocomplete").forEach(s=>{let l=s.getAttribute("id");if(!l)return;document.querySelector(`[aria-owns="${CSS.escape(l)}"]`)===e&&(s.classList.add("seekmodo-suggest-legacy-suppressed"),s.style.display="none",this.suppressedLegacyEls.add(s));});}scheduleLegacySuppressionRetries(){this.unscheduleLegacySuppressionRetries();let e=()=>{this.applyLegacySuppression();};this.legacySuppressionRetryHandler=e,setTimeout(e,0),setTimeout(e,50),document.readyState==="loading"&&document.addEventListener("DOMContentLoaded",e,{once:true}),window.addEventListener("load",e,{once:true});}unscheduleLegacySuppressionRetries(){this.legacySuppressionRetryHandler=null;}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(r=>{r.style.display="none",this.suppressedLegacyEls.add(r);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),r=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<r){this.lastQuery=t,this.current=null,this.loading=false,this.productsPending=false,this.corsBlocked=false,this.inflight?.abort(),this.scheduleRender();return}if(this.lastQuery=t,this.corsBlocked=false,this.twoPhaseEnabled()){this.handleQueryTwoPhase(t);return}let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.productsPending=false,this.inflight?.abort(),this.queueRenderedEvent(t,o),this.emitOpen(t),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}handleQueryTwoPhase(e){let t=this.cache.get(this.cacheKey(e,"full"));if(t){this.current=t,this.loading=false,this.productsPending=false,this.inflight?.abort(),this.queueRenderedEvent(e,t),this.emitOpen(e),this.scheduleRender();return}this.productsPending=true,this.inflight?.abort();let r=this.cache.get(this.cacheKey(e,"prefix"));if(r){this.current=this.stripProducts(r),this.loading=false,this.scheduleRender(),this.debouncedProducts?.(e);return}this.loading=true,this.scheduleRender(),this.debouncedPrefix?.(e),this.debouncedProducts?.(e);}stripProducts(e){return {...e,products:[],categories:[],meta:{...e.meta??{},total:0,counts:{...e.meta?.counts??{},products:0,categories:0}}}}cacheKey(e,t="full"){let r=this.getSerpPassthrough(),o=this.getVehicleFilterArgs(),i=r?JSON.stringify(r):"",s=Object.keys(o).length>0?JSON.stringify(o):"",l=this.getVehicleId(),d=l!==null?`v${l}`:s,c=this.resolvePriceCurrency();return `${t==="prefix"?"p":"f"}\0${e.toLowerCase()}\0${d}\0${i}\0${c}`}resolvePriceCurrency(e){let t=this.getAttribute("currency")?.trim();if(t)return t.toUpperCase();if(e)return e.toUpperCase();let r=this.current?.meta?.region;if(r&&typeof r=="object"&&!Array.isArray(r)){let s=r.currency;if(typeof s=="string"&&s.trim()!=="")return s.trim().toUpperCase()}let i=this.getSerpPassthrough()?.shopper_context;if(i&&typeof i=="object"&&!Array.isArray(i)){let s=i.currency;if(typeof s=="string"&&s.trim()!=="")return s.trim().toUpperCase()}return "USD"}resolvePriceLocale(){let e=this.current?.meta?.region;if(e&&typeof e=="object"&&!Array.isArray(e)){let t=e.locale;if(typeof t=="string"&&t.trim()!=="")return t.trim()}}getVehicleId(){let e=this.getAttribute("vehicle-id");if(!e)return null;let t=parseInt(e,10);return Number.isFinite(t)&&t>0?t:null}getVehicleFilterArgs(){let e={},t=this.getAttribute("vehicle-filter");if(t)try{let i=JSON.parse(t);i&&typeof i=="object"&&!Array.isArray(i)&&Object.assign(e,i);}catch{}let r=this.getSerpPassthrough();if(r)for(let i of ["filter_by","vehicle_filter_mode","vehicle_hard_filter","vehicle_id","shopper_context"])r[i]!==void 0&&r[i]!==null&&e[i]===void 0&&(e[i]=r[i]);let o=this.getVehicleId();return o!==null&&e.vehicle_id===void 0&&(e.vehicle_id=o),e}getSerpPassthrough(){let e=this.getAttribute("serp-passthrough");if(!e)return null;try{let t=JSON.parse(e);if(t&&typeof t=="object"&&!Array.isArray(t))return t}catch{}return null}showProductLoadingSkeleton(e){return e.trim()!==""}deferStorefrontThumbs(){return this.thumbVer()!==""}thumbVer(){return (this.getAttribute("img-ver")??"").trim()}thumbSrc(e){let t=this.thumbVer();if(!t||!e)return e;try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(e,r);return o.searchParams.set("_smv",t),/^https?:\/\//i.test(e)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=e.includes("?")?"&":"?";return `${e}${r}_smv=${encodeURIComponent(t)}`}}async runSuggest(e,t){return B()?Y(e,{signal:t}):await(await this.getClient()).suggest(e,{signal:t})}markSuggestFetchError(e,t){if(B()){this.corsBlocked=false,console.warn("[seekmodo-suggest] proxy fetch failed",e);return}this.corsBlocked=A(e),this.corsBlocked?(w(this,"seekmodo-suggest:cors-blocked",{q:t}),console.warn("[seekmodo-suggest] blocked by CORS or network policy",e)):console.warn("[seekmodo-suggest] fetch failed",e);}async fetchPrefix(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.prefixFetchToken;try{let o=parseInt(this.getAttribute("limit")??"5",10)||5,i=this.buildSuggestArgs(e,o),s=await this.runSuggest({...i,complete:!1,include_products:!1},t.signal);if(r!==this.prefixFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;s=this.stripProducts(s),this.cache.set(this.cacheKey(e,"prefix"),s),await this.applySuggestResponse(e,o,s,t,r,!1,"prefix");}catch(o){if(r!==this.prefixFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;this.markSuggestFetchError(o,e),this.corsBlocked&&(this.current=null,this.loading=false),this.scheduleRender();}}async fetchProducts(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.productFetchToken;try{let o=parseInt(this.getAttribute("limit")??"5",10)||5,i=this.layoutMode(),s=R(i)?L(i,o):o,l=this.buildSuggestArgs(e,s);if(this.showProductLoadingSkeleton(e)&&(this.loading=!0,this.scheduleRender(),r!==this.productFetchToken||t.signal.aborted))return;let d=await this.runSuggest({...l,include_products:!0},t.signal);if(r!==this.productFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;await this.applySuggestResponse(e,s,d,t,r,!0,"full");}catch(o){if(r!==this.productFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;this.markSuggestFetchError(o,e),this.current=null,this.loading=false,this.productsPending=false,this.scheduleRender();}}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.fetchToken;try{let o=parseInt(this.getAttribute("limit")??"5",10)||5,i=this.layoutMode(),s=R(i)?L(i,o):o,l=this.buildSuggestArgs(e,s);if(this.showProductLoadingSkeleton(e)&&(this.loading=!0,this.scheduleRender(),r!==this.fetchToken||t.signal.aborted))return;let d=await this.runSuggest({...l,include_products:!0},t.signal);if(r!==this.fetchToken||t.signal.aborted)return;await this.applySuggestResponse(e,s,d,t,r,!0);}catch(o){if(r!==this.fetchToken||t.signal.aborted)return;this.markSuggestFetchError(o,e),this.current=null,this.loading=false,this.scheduleRender();}}buildSuggestArgs(e,t){let r=this.getSessionId(),o={q:e,limit:t};r&&(o.session_id=r);let i=this.getVehicleFilterArgs();for(let[l,d]of Object.entries(i))if(d!=null){if(l==="vehicle_id"){let c=typeof d=="number"?d:parseInt(String(d),10);Number.isFinite(c)&&(o.vehicle_id=c);continue}if(l==="filter_by"&&typeof d=="string"){o.filter_by=d;continue}if(l==="vehicle_filter_mode"&&typeof d=="string"){o.vehicle_filter_mode=d;continue}if(l==="vehicle_hard_filter"){o.vehicle_hard_filter=d!==false&&d!=="false"&&d!==0&&d!=="0";continue}l==="shopper_context"&&d&&typeof d=="object"&&!Array.isArray(d)&&(o.shopper_context=d);}let s=this.getSerpPassthrough();return s&&(o.serp_passthrough=s),o}async applySuggestResponse(e,t,r,o,i,s,l="full"){let d=l==="full"?await this.mergeTypeaheadFallback(e,t,r,o):null;if(d&&(r=d),l==="prefix"&&(r=this.stripProducts(r)),this.current=r,this.loading=!s,l==="full"&&(this.productsPending=!s),s&&(this.cache.set(this.cacheKey(e,l),r),l==="full"&&(this.productsPending=false)),s&&r.redirect?.target_url){window.location.assign(r.redirect.target_url);return}s&&(this.emitOpen(e),this.isEmpty(r)&&w(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl})),s&&l==="full"&&(r.products?.length??0)>0&&(this.queueRenderedEvent(e,r),w(this,"seekmodo-suggest:render",{q:e,products:r.products?.length??0})),this.scheduleRender();}queueRenderedEvent(e,t){(t.products?.length??0)>0&&(this.pendingRenderedQ=e);}afterRender(){let e=this.pendingRenderedQ;if(!e)return;this.pendingRenderedQ=null;let t=this.current?.products?.length??0;t>0&&w(this,"seekmodo-suggest:rendered",{q:e,products:t});}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}currentSearchEventId(){let e=this.current?.meta?.search_event_id;if(typeof e=="number"&&Number.isFinite(e)&&e>0)return Math.trunc(e);if(typeof e=="string"&&e!==""){let t=parseInt(e,10);if(Number.isFinite(t)&&t>0)return t}}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}typeaheadFallbackUrl(){let e=(this.getAttribute("typeahead-fallback-url")??"").trim();return e.length>0?e:null}async mergeTypeaheadFallback(e,t,r,o){let i=this.typeaheadFallbackUrl();if(!i||typeof fetch!="function"||(r.products?.length??0)>0||this.deferStorefrontThumbs())return null;try{let s=i.includes("?")?"&":"?",l=await fetch(`${i}${s}q=${encodeURIComponent(e)}&max=${encodeURIComponent(String(t))}`,{credentials:"same-origin",signal:o.signal});if(!l.ok)return null;let d=await l.json(),c=Array.isArray(d?.rows)?d.rows:[];if(c.length===0)return null;let m=c.slice(0,t).map((p,g)=>{let h=p,f=h.id!==void 0&&h.id!==null?String(h.id):String(g),y=typeof h.name=="string"&&h.name!==""?h.name:typeof h.title=="string"?h.title:"",k=typeof h.url=="string"&&h.url!==""?h.url:typeof h.permalink=="string"?h.permalink:void 0,O=typeof h.image_url=="string"&&h.image_url!==""?h.image_url:typeof h.thumbnail_url=="string"?h.thumbnail_url:void 0;return {id:f,name:y,url:k,image_url:O,post_type:typeof h.post_type=="string"?h.post_type:void 0,excerpt:typeof h.excerpt=="string"?h.excerpt:void 0}}),u={...r.meta??{},typeahead_fallback:!0};return u.total=Math.max(u.total??0,m.length),u.counts={...u.counts??{},products:m.length},{...r,products:m,meta:u}}catch{return null}}blocks(){let e=this.getAttribute("blocks");if(!e)return ye;let t=e.split(",").map(r=>r.trim()).filter(r=>["recent","trending","did_you_mean","keywords","products","categories"].includes(r));return t.length>0?t:ye}label(e){return Qe[e]??e}layoutMode(){let e=(this.getAttribute("layout")??N).trim();return e==="classic"||e==="cinema-grid"||e==="command-bar"||e==="magazine"||e==="split-rail"?e:N}showBrandingFlag(){let e=(this.getAttribute("show-branding")??"true").trim().toLowerCase();return e!=="false"&&e!=="0"&&e!=="no"}splitMobileResizeEnabled(){let e=(this.getAttribute("split-mobile-resize")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}productTitleTooltipEnabled(){let e=(this.getAttribute("product-title-tooltip")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}unbindSplitMobileResize(){this.splitResizeCleanup?.(),this.splitResizeCleanup=null;}bindSplitMobileResizeIfNeeded(e,t){if(this.unbindSplitMobileResize(),e!=="split-rail"||!this.splitMobileResizeEnabled())return;let r=t.querySelector(".split-body");r instanceof HTMLElement&&(this.splitResizeCleanup=le(r));}dismiss(){this.current===null&&!this.loading||(w(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.productsPending=false,this.scheduleRender());}emitOpen(e){w(this,"seekmodo-suggest:open",{q:e});}buildViewAllHref(e){let r=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(e));return re(r,"seekmodo_skip_category_redirect","1")}navigateViewAll(){let e=this.current?.meta?.total??0;w(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:e}),window.location.assign(this.buildViewAllHref(this.lastQuery));}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,r)=>{r===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];if(!t)return;let r=this.currentSearchEventId();w(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id,position:e+1,...r!==void 0?{search_event_id:r}:{}});}render(){this.unbindSplitMobileResize();let e=document.createElement("style");if(e.textContent=ve,this.loading&&this.current===null&&!this.corsBlocked){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.corsBlocked){ee(this.root,ve,{message:this.label("cors_blocked")}),this.rows=[],this.active=-1,this.applyAnchor();return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}let t=this.displayResponse();if(!t){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(t)){let m=a("slot",{attrs:{name:"empty"}}),u=a("div",{class:"empty",text:this.label("empty")});m.append(u);let p=a("div",{class:"wrap",part:"wrap"});p.append(m),this.root.replaceChildren(e,p),this.rows=[],this.active=-1;return}let r=this.productTitleTooltipEnabled()?"wrap product-title-tooltip":"wrap",o=a("div",{class:r,part:"wrap"});o.append(a("slot",{attrs:{name:"header"}}));let i=[],s=parseInt(this.getAttribute("limit")??"5",10)||5,l=this.layoutMode();if(R(l)){let m=this.buildViewAllHref(this.lastQuery),u=(g,h)=>z(g,this.resolvePriceCurrency(h),this.resolvePriceLocale()),p=be(l,{res:t,lastQuery:this.lastQuery,limit:L(l,s),label:g=>this.label(g),rows:i,onRowClick:g=>this.activateRow(g),onViewAll:()=>this.navigateViewAll(),viewAllHref:m,showBranding:this.showBrandingFlag(),brandUrl:this.getAttribute("brand-url")??"https://seekmodo.com",brandLogoUrl:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",formatPrice:u,splitMobileResize:this.splitMobileResizeEnabled(),productTitleTooltip:this.productTitleTooltipEnabled(),resolveThumbSrc:g=>this.thumbSrc(g),productsPending:this.productsPending&&this.twoPhaseEnabled()});this.rows=i,this.active=-1,this.root.replaceChildren(e,p),this.bindSplitMobileResizeIfNeeded(l,p),this.applyAnchor();return}let d=this.blocks();for(let m of d){let u=this.renderBlock(m,t,s,i);u&&o.append(u);}this.rows=i,this.active=-1;let c=t.meta?.total??0;if(c>0&&this.lastQuery.length>0){let m=this.buildViewAllHref(this.lastQuery),u=a("a",{class:"view-all",part:"view-all",attrs:{href:m},text:this.label("view_all").replace("{total}",String(c))});u.addEventListener("click",p=>{p.preventDefault(),this.navigateViewAll();}),o.append(u);}if(o.append(a("slot",{attrs:{name:"footer"}})),this.showBrandingFlag()){let m=a("a",{class:"brand-footer",part:"brand-footer",attrs:{href:this.getAttribute("brand-url")??"https://seekmodo.com",target:"_blank",rel:"noopener noreferrer"}});m.append(a("span",{class:"brand-by",text:"Powered by "})),m.append(a("img",{class:"brand-logo",part:"brand-logo",attrs:{src:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",alt:"Seekmodo",height:"16"}})),o.append(m);}this.root.replaceChildren(e,o),this.applyAnchor();}displayResponse(){return this.current?this.productsPending&&this.twoPhaseEnabled()?this.stripProducts(this.current):this.current:null}renderSkeleton(){let e=a("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let r=a("div",{class:"row",part:"row skeleton"});r.append(a("div",{class:"thumb",part:"thumb"}));let o=a("div",{class:"name"});o.append(a("span",{class:"name-title"})),o.append(a("span",{class:"name-meta"})),r.append(o),e.append(r);}return e}renderBlock(e,t,r,o){if(e==="did_you_mean"){let l=t.did_you_mean;if(!l)return null;let d=a("div",{class:"group",part:"group did-you-mean"});d.append(a("slot",{attrs:{name:"did_you_mean"}}));let c=a("div",{class:"did-you-mean"});c.append(document.createTextNode(this.label("did_you_mean")+" "));let m=a("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:l});return m.addEventListener("click",()=>{let u=this.currentSearchEventId();w(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:l},q:this.lastQuery,value:l,...u!==void 0?{search_event_id:u}:{}});}),c.append(m),d.append(c),d}let i=this.blockData(e,t,r);if(i.length===0)return null;let s=a("div",{class:"group",part:"group",attrs:{"data-block":e}});return s.append(a("slot",{attrs:{name:e}})),s.append(a("div",{class:"group-title",part:"group-title",text:this.label(e)})),i.forEach((l,d)=>{let c={block:e,data:l,value:this.rowValue(e,l),id:this.rowId(e,l)};o.push(c);let m=o.length-1,u=window.seekmodoSuggest?.renderRow?.(c.data,e),p;u instanceof HTMLElement?(p=u,p.classList.add("row")):typeof u=="string"&&u.length>0?(p=a("button",{class:"row",part:"row",type:"button"}),p.innerHTML=u):p=this.renderRowDefault(e,l,d),p.setAttribute("data-seekmodo-surface","suggest"),p.setAttribute("data-seekmodo-block",e),p.setAttribute("data-seekmodo-pos",String(m)),c.id&&p.setAttribute("data-seekmodo-id",c.id),p.addEventListener("click",()=>this.activateRow(m)),s.append(p);}),s}blockData(e,t,r){switch(e){case "recent":return (t.recent??[]).slice(0,r);case "trending":return (t.trending??[]).slice(0,r);case "keywords":return (t.keywords??[]).slice(0,r);case "products":return (t.products??[]).slice(0,r);case "categories":return (t.categories??[]).slice(0,r);default:return []}}rowValue(e,t){let r=t;return e==="recent"||e==="trending"||e==="keywords"?String(r.keyword??""):e==="products"?String(r.name??r.title??""):e==="categories"?String(r.name??""):""}rowId(e,t){if(e!=="products")return;let r=t.id;return r!==void 0?String(r):void 0}renderRowDefault(e,t,r){let o=a("button",{class:"row",part:"row",type:"button"});if(e==="products"){let i=t,{postType:s,label:l}=S(i),d=i.image_url??i.image,c=String(i.name??i.title??"").trim(),m=this.productTitleTooltipEnabled()&&c?c:"";if(s&&o.setAttribute("data-post-type",s),d){let f=this.thumbSrc(d);o.append(a("img",{class:"thumb",part:"thumb",attrs:{src:f,"data-src":d,alt:m,loading:"eager",decoding:"async"}}));}else {let y=a("div",{class:"thumb thumb-empty",part:"thumb thumb--empty",text:s==="page"?"P":s==="post"?"A":"\xB7"});s&&y.setAttribute("data-content-type",s),o.append(y);}let u=a("div",{class:"name",part:"name"}),p=a("span",{class:"name-title",text:c});this.productTitleTooltipEnabled()&&c&&p.setAttribute("title",c),u.append(p);let g=[l,i.brand?String(i.brand):"",i.sku??i.model??i.ez_number??""].filter(Boolean);g.length>0&&u.append(a("span",{class:"name-meta",text:g.join(" \xB7 ")})),o.append(u);let h=this.renderPrice(i);return h&&o.append(h),o}if(e==="categories"){let i=t,s=a("div",{class:"name",part:"name",text:i.name});return o.append(s),typeof i.count=="number"&&i.count>0&&o.append(a("span",{class:"badge",part:"badge",text:String(i.count)})),o}if(e==="recent"||e==="trending"||e==="keywords"){let i=t,s=a("div",{class:"name",part:"name",text:String(i.keyword)});return o.append(s),e==="trending"&&typeof i.search_count=="number"&&o.append(a("span",{class:"badge",part:"badge",text:String(i.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=this.resolvePriceCurrency(typeof e.currency=="string"?e.currency:void 0),r=this.resolvePriceLocale(),o=a("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(o.append(a("del",{text:z(e.price,t,r)})),o.append(document.createTextNode(z(e.sale_price,t,r)))):o.append(document.createTextNode(z(e.price,t,r))),o}};function z(n,e,t){try{return new Intl.NumberFormat(t,{style:"currency",currency:e,maximumFractionDigits:2}).format(n)}catch{return `${n.toFixed(2)} ${e}`}}te();typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",F);
exports.SeekmodoSuggest=F;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map