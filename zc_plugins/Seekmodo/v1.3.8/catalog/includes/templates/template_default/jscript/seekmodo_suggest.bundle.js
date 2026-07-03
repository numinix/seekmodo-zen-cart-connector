var SeekmodoSuggest=(function(exports){'use strict';var k=class extends Error{status;body;tool;constructor(n,e,t,r){super(n),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=r;}},N=class extends k{constructor(n,e,t){super(`Seekmodo auth failed (HTTP ${n})`,n,e,t),this.name="SeekmodoAuthError";}},me=class extends k{code;bucket;limit;used;constructor(n,e){super("Seekmodo over quota (HTTP 402)",402,n,e),this.name="SeekmodoQuotaError";let t=n??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},ge=class extends k{constructor(n,e,t){super(`Seekmodo server error (HTTP ${n})`,n,e,t),this.name="SeekmodoServerError";}},fe=class extends k{constructor(n,e,t){super(`Seekmodo request rejected (HTTP ${n})`,n,e,t),this.name="SeekmodoRequestError";}},K=class extends k{constructor(n,e){super(`Seekmodo network failure${n instanceof Error?`: ${n.message}`:""}`,0,n,e),this.name="SeekmodoNetworkError";}};function L(n,e){if(n instanceof K)return L(n.body);if(n instanceof TypeError){let t=n.message.toLowerCase();return t.includes("failed to fetch")||t.includes("networkerror")||t.includes("network request failed")||t.includes("load failed")}if(n instanceof Error){let t=n.message.toLowerCase();return t.includes("cors")||t.includes("access-control-allow-origin")||t.includes("cross-origin")}return  false}var be="https://gateway.seekmodo.com",ve=8e3,we=class{config;cachedToken=null;constructor(n){if(!n.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof n.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:n.tenantId,getToken:n.getToken,baseUrl:(n.baseUrl??be).replace(/\/+$/,""),fetch:n.fetch??globalThis.fetch.bind(globalThis),timeoutMs:n.timeoutMs??ve,signal:n.signal,onError:n.onError,getRegion:n.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(n,e,t={}){try{return await this.callOnce(n,e,t,!1)}catch(r){if(r instanceof N){this.clearTokenCache();try{return await this.callOnce(n,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:n}),o}}throw this.config.onError?.(r,{tool:n}),r}}async callOnce(n,e,t,r){let o=await this.resolveToken(r),i=`${this.config.baseUrl}/v1/${encodeURIComponent(n)}`,a=new AbortController,p=t.timeoutMs??this.config.timeoutMs,g=setTimeout(()=>a.abort(),p),d=()=>a.abort();this.config.signal?.addEventListener("abort",d,{once:true}),t.signal?.addEventListener("abort",d,{once:true});let h={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let c=await this.config.getRegion();typeof c=="string"&&c.length>0&&(h["Seekmodo-Region"]=c);}catch{}let l;try{l=await this.config.fetch(i,{method:"POST",headers:h,body:JSON.stringify(e),signal:a.signal});}catch(c){throw new K(c,n)}finally{clearTimeout(g),this.config.signal?.removeEventListener("abort",d),t.signal?.removeEventListener("abort",d);}let u=await l.text(),m=u?ye(u):null;if(l.status===401||l.status===403)throw new N(l.status,m,n);if(l.status===402)throw new me(m,n);if(l.status>=500)throw new ge(l.status,m,n);if(!l.ok)throw new fe(l.status,m,n);return m}async resolveToken(n){let e=Date.now();if(!n&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function ye(n){try{return JSON.parse(n)}catch{return n}}var q=class{transport;recommend;bundle;constructor(n){this.transport=new we(n),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(n,e){return this.transport.call("search",{...n},e??{})}suggest(n,e){return this.transport.call("suggest",{...n},e??{})}searchByImage(n,e){return this.transport.call("search.byImage",{...n},e??{})}chat(n,e){return this.transport.call("chat",{...n},e??{})}ask(n,e){return this.transport.call("ask",{...n},e??{})}catalogGet(n,e){return this.transport.call("catalog.get",{...n},e??{})}event(n,e){return this.transport.call("events",{...n},e??{})}};var _=null,v=null;function x(n){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${n}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function V(){return _!==null||(_=ke()),_}async function ke(){let n=x("seekmodo:tenant");if(!n)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=x("seekmodo:token"),t=x("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(v={token:e,expiresAt:Date.now()+3e4});let r=x("seekmodo:gateway")??void 0;return new q({tenantId:n,baseUrl:r,getRegion:()=>Le(),getToken:async()=>{let o=Date.now();if(v&&v.expiresAt-1e4>o)return {token:v.token,expiresAt:v.expiresAt};if(!t){if(v)return {token:v.token,expiresAt:v.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let i=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!i.ok)throw new Error(`seekmodo:refresh route returned HTTP ${i.status}`);let a=await i.json();if(!a.token||typeof a.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return v={token:a.token,expiresAt:a.expires_at*1e3},{token:v.token,expiresAt:v.expiresAt}}})}var Ee="seekmodo_region";function Se(n){if(typeof n!="string")return null;let e=n.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function Le(){if(typeof document>"u")return null;let n=document.cookie??"";if(n.length===0)return null;let e=Ee.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(n);if(!t)return null;try{return Se(decodeURIComponent(t[1]))}catch{return null}}var A=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}async getClient(){return V()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function s(n,e,t){let r=document.createElement(n);if(e){for(let[o,i]of Object.entries(e))if(!(i==null||i===false))if(o==="class")r.className=String(i);else if(o==="part")r.setAttribute("part",String(i));else if(o==="text")r.textContent=String(i);else if(o==="html")r.innerHTML=String(i);else if(o==="attrs"&&typeof i=="object"&&i!==null)for(let[a,p]of Object.entries(i))r.setAttribute(a,p);else r.setAttribute(o,String(i));}return r}function j(n,e){let t=null;return (...r)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>n(...r),e);}}function y(n,e,t){n.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var R="Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";function G(n,e,t){let r=document.createElement("style");r.textContent=e;let o=s("div",{class:"wrap seekmodo-cors-blocked",part:"wrap cors-blocked",attrs:{role:"status"}});o.append(s("div",{class:"cors-notice",part:"cors-notice",text:t?.message??R})),n.replaceChildren(r,o);}var Q="seekmodo-cors-notice";function W(n,e){if(!n||typeof document>"u")return;let t=n.closest(".search-form")??n.parentNode;if(!t)return;t.style.position=t.style.position||"relative";let r=t.querySelector(`.${Q}`);if(!r){r=document.createElement("div"),r.className=Q,r.setAttribute("role","status"),r.style.cssText=["position:absolute","top:100%","left:0","right:0","z-index:10050","display:none","background:#fff8e6","border:1px solid #f0c040","border-top:none","padding:8px 12px","font-size:13px","line-height:1.4","color:#5c4a00","box-shadow:0 4px 12px rgba(0,0,0,.08)"].join(";"),t.appendChild(r);let o=()=>{if(!r)return;let i=(n.value||"").trim();r.style.display=i.length>=2?"block":"none";};n.addEventListener("input",o),n.addEventListener("focus",o);}r.textContent=e??R;}function Y(){typeof window>"u"||(window.seekmodoShowCorsNotice=W,window.seekmodoScriptLoadFailed=(n,e)=>{let t=n??document.querySelectorAll('input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]');for(let r=0;r<t.length;r++){let o=t[r];o instanceof HTMLInputElement&&W(o,e);}});}function z(n){for(let e of ["image_url","image","thumbnail_url"]){let t=n[e];if(typeof t=="string"&&t.trim()!=="")return t.trim()}}function J(n,e,t){try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(n,r);return o.searchParams.set(e,t),/^https?:\/\//i.test(n)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=n.includes("?")?"&":"?";return `${n}${r}${encodeURIComponent(e)}=${encodeURIComponent(t)}`}}function _e(n,e){let t=(n??"").toLowerCase(),r=(e??"").toLowerCase();if(r.startsWith("http"))try{r=new URL(r).pathname;}catch{r="";}return t==="page"&&/\/tools\/[^/]+\/?$/.test(r)?"Tool":t==="page"&&/\/tools\/?$/.test(r)?"Tools":t==="page"?"Page":t==="post"?"Article":""}function X(n){let e=typeof n.post_type=="string"?n.post_type.toLowerCase():"",t=typeof n.url=="string"?n.url:typeof n.permalink=="string"?n.permalink:"";return {postType:e,label:_e(e,t)}}var $="split-rail",xe=15;function T(n,e){let r={"split-rail":5,"command-bar":5,"cinema-grid":6,magazine:6,classic:5}[n]??5,o=Math.max(e,r*3);return Math.min(xe,o)}var ne=`
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
    border-radius: 0; background: transparent;
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
`,Z=.15,ee=.85,Ae=.28,te="seekmodo:split-rail-mobile-ratio-v3",Re="(max-width: 900px)",Te=1-Ae;function Ce(){return s("div",{class:"split-divider",part:"split-divider",html:'<svg class="split-divider-icon" viewBox="0 0 36 12" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="1" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="5" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="9" width="24" height="2" rx="1" fill="currentColor"/></svg>'})}function oe(n){let e=n.querySelector(".split-divider");if(!e)return ()=>{};let t=window.matchMedia(Re),r=Te;try{let m=sessionStorage.getItem(te);if(m){let c=parseFloat(m);c>=Z&&c<=ee&&(r=c);}}catch{}let o=m=>{r=Math.min(ee,Math.max(Z,m)),n.style.setProperty("--split-rail-bottom-grow",String(r)),n.style.setProperty("--split-rail-top-grow",String(1-r));},i=()=>{n.style.removeProperty("--split-rail-top-grow"),n.style.removeProperty("--split-rail-bottom-grow");},a=()=>{t.matches?o(r):i();};a();let p=false,g=0,d=r,h=m=>{!t.matches||m.button!==0||(p=true,g=m.clientY,d=r,e.classList.add("is-dragging"),e.setPointerCapture(m.pointerId),m.preventDefault());},l=m=>{if(!p)return;let f=n.getBoundingClientRect().height-e.offsetHeight;f<=0||o(d+(m.clientY-g)/f);},u=m=>{if(p){p=false,e.classList.remove("is-dragging");try{e.releasePointerCapture(m.pointerId);}catch{}try{sessionStorage.setItem(te,String(r));}catch{}}};return e.setAttribute("role","separator"),e.setAttribute("aria-orientation","horizontal"),e.setAttribute("aria-label","Resize product and suggestion panels"),e.setAttribute("tabindex","0"),e.addEventListener("pointerdown",h),e.addEventListener("pointermove",l),e.addEventListener("pointerup",u),e.addEventListener("pointercancel",u),t.addEventListener("change",a),()=>{e.removeEventListener("pointerdown",h),e.removeEventListener("pointermove",l),e.removeEventListener("pointerup",u),e.removeEventListener("pointercancel",u),t.removeEventListener("change",a),e.classList.remove("is-dragging"),i();}}var He="https://seekmodo.com/email-assets/seekmodo-lockup.png";function ie(n){return String(n.name??n.title??"").trim()}function se(n,e,t){e.productTitleTooltip&&t&&n.setAttribute("title",t);}function ae(n,e){return n.productTitleTooltip&&e?e:""}function le(n,e,t){let r={block:"products",data:e,value:String(e.name??e.title??""),id:e.id!==void 0?String(e.id):void 0},o=n.rows.length;return n.rows.push(r),o}function Me(n,e,t){let r=s("div",{class:"thumb-frame",part:"thumb-frame"}),o=z(n);return o?r.append(s("img",{class:"thumb",part:"thumb",attrs:{src:o,"data-src":o,alt:ae(e,t),loading:"eager",decoding:"async"}})):r.append(s("div",{class:"thumb-empty",part:"thumb thumb--empty"})),r}function Ie(n,e,t){let r=z(n);return r?s("img",{class:"thumb",part:"thumb",attrs:{src:r,"data-src":r,alt:ae(e,t),loading:"eager",decoding:"async"}}):s("div",{class:"thumb-empty",part:"thumb thumb--empty"})}function de(n,e,t="card-price"){if(n.price===void 0||n.price===null)return null;let r=s("div",{class:t,part:"price"});return n.on_sale&&typeof n.sale_price=="number"?(r.append(s("del",{text:e.formatPrice(n.price,n.currency)})),r.append(document.createTextNode(e.formatPrice(n.sale_price,n.currency)))):r.append(document.createTextNode(e.formatPrice(n.price,n.currency))),r}function ce(n,e,t){n.classList.add("row"),n.setAttribute("data-seekmodo-surface","suggest"),n.setAttribute("data-seekmodo-block","products"),n.setAttribute("data-seekmodo-pos",String(t));let r=e.rows[t];r?.id&&n.setAttribute("data-seekmodo-id",r.id),n.addEventListener("click",()=>e.onRowClick(t));}function Pe(n,e,t,r=false){let o=le(n,e),i=ie(e),a=s("button",{class:"product-card",part:"row",type:"button"});a.append(Me(e,n,i));let p=s("span",{class:"card-title",part:"name",text:i});se(p,n,i),a.append(p);let g=de(e,n,"card-price");return g&&a.append(g),ce(a,n,o),a}function ze(n,e,t,r){let o=le(n,e),i=ie(e),a=s("button",{class:"hero-card",part:"row",type:"button"});r&&a.append(s("span",{class:"hero-badge",text:r})),a.append(Ie(e,n,i));let p=s("div",{class:"hero-info"}),g=s("span",{class:"card-title",part:"name",text:i});se(g,n,i),p.append(g);let d=de(e,n);return d&&p.append(d),a.append(p),ce(a,n,o),a}function D(n){let e=n.res.meta?.total??0,t=s("div",{class:"meta-bar",part:"meta-bar"}),r=s("div");r.append(s("span",{class:"count",text:`${e} results for `})),r.append(s("span",{class:"query",text:`"${n.lastQuery}"`})),t.append(r);let o=s("a",{class:"view-all view-all-cta",part:"view-all",attrs:{href:n.viewAllHref},text:n.label("view_all").replace("{total}",String(e))});return o.addEventListener("click",i=>{i.preventDefault(),n.onViewAll();}),t.append(o),t}function De(n){let e=n.res.did_you_mean;if(!e)return null;let t=s("div",{class:"did-you-mean-bar",part:"did-you-mean"});t.append(document.createTextNode(`Showing results for "${n.lastQuery}". Search instead for `));let r=s("button",{class:"swap",type:"button",text:e}),o=n.rows.length;return n.rows.push({block:"did_you_mean",data:{value:e},value:e}),r.addEventListener("click",()=>n.onRowClick(o)),t.append(r),t.append(document.createTextNode("?")),t}function O(n,e){let t=s("div",{class:"chip-row filter-bar",part:"filter-bar"});return t.append(s("span",{class:"filter-label",text:"Category"})),e.forEach((r,o)=>{let i=s("button",{class:`chip${o===0?" active":""}`,type:"button",text:`${r.name}${typeof r.count=="number"?` ${r.count}`:""}`}),a=n.rows.length;n.rows.push({block:"categories",data:r,value:String(r.name??"")}),i.addEventListener("click",()=>n.onRowClick(a)),t.append(i);}),t}function re(n,e,t="Try"){let r=s("div",{class:"chip-row",part:"filter-bar"});return r.append(s("span",{class:"filter-label",text:t})),e.forEach(o=>{let i=s("button",{class:"chip",type:"button",text:o.keyword}),a=n.rows.length;n.rows.push({block:"keywords",data:o,value:o.keyword}),i.addEventListener("click",()=>n.onRowClick(a)),r.append(i);}),r}function F(n,e,t,r,o){let i=n.rows.length;n.rows.push({block:e,data:t,value:r});let a=s("button",{class:"row",part:"row",type:"button"});return a.append(s("div",{class:"name",part:"name",text:r})),o&&a.append(s("span",{class:"badge",part:"badge",text:o})),a.setAttribute("data-seekmodo-surface","suggest"),a.setAttribute("data-seekmodo-block",e),a.setAttribute("data-seekmodo-pos",String(i)),a.addEventListener("click",()=>n.onRowClick(i)),a}function B(n,e){if(e.length===0)return null;let t=s("div",{class:"rail-section"});return t.append(s("div",{class:"group-title",part:"group-title",text:n})),e.forEach(r=>t.append(r)),t}function Oe(n){if(!n.showBranding)return null;let e=s("a",{class:"brand-footer",part:"brand-footer",attrs:{href:n.brandUrl,target:"_blank",rel:"noopener noreferrer"}});return e.append(s("span",{class:"brand-by",text:"Powered by "})),e.append(s("img",{class:"brand-logo",part:"brand-logo",attrs:{src:n.brandLogoUrl||He,alt:"Seekmodo",height:"16"}})),e}function E(n,e,t){let r=s("div",{class:`product-grid cols-${t}`,part:"product-grid"});return e.forEach((o,i)=>r.append(Pe(n,o,i,true))),r}function ue(n,e){let t=["wrap","wide"];n==="split-rail"&&t.push("split-rail-panel"),e.splitMobileResize&&t.push("split-rail-mobile"),e.productTitleTooltip&&t.push("product-title-tooltip");let r=s("div",{class:t.join(" "),part:"wrap"});r.append(s("slot",{attrs:{name:"header"}}));let o=(e.res.products??[]).slice(0,T(n,e.limit)),i=(e.res.keywords??[]).slice(0,e.limit),a=(e.res.categories??[]).slice(0,e.limit),p=(e.res.recent??[]).slice(0,5);(e.res.trending??[]).slice(0,5);if(n==="split-rail"){r.append(D(e));let h=e.splitMobileResize?"split-body split-body--mobile-resize":"split-body",l=s("div",{class:h}),u=s("aside",{class:"rail",part:"rail"}),m=i.map(w=>F(e,"keywords",w,w.keyword)),c=B(e.label("keywords"),m);c&&u.append(c);let f=p.map(w=>F(e,"recent",w,w.keyword)),b=B(e.label("recent"),f);b&&u.append(b);let P=a.map(w=>F(e,"categories",w,String(w.name),typeof w.count=="number"?String(w.count):void 0)),S=B(e.label("categories"),P);S&&u.append(S);let U=s("div",{class:"canvas"});U.append(E(e,o,5)),l.append(U),e.splitMobileResize&&l.append(Ce()),l.append(u),r.append(l);}else if(n==="cinema-grid"){let h=De(e);h&&r.append(h),r.append(D(e)),a.length&&r.append(O(e,a)),i.length&&r.append(re(e,i));let l=s("div",{class:"canvas"});l.append(E(e,o,6)),r.append(l);}else if(n==="command-bar"){let h=e.res.meta?.total??0,l=s("div",{class:"command-header",part:"meta-bar"});l.append(s("div",{class:"query-display",text:`"${e.lastQuery}"`})),l.append(s("span",{class:"result-pill",text:`${h} products`}));let u=s("a",{class:"view-all-link",part:"view-all",attrs:{href:e.viewAllHref},text:"View all \u2192"});if(u.addEventListener("click",c=>{c.preventDefault(),e.onViewAll();}),l.append(u),r.append(l),e.res.did_you_mean){let c=s("div",{class:"chip-row"});c.append(s("span",{class:"filter-label",text:"Did you mean"}));let f=s("button",{class:"chip",type:"button",text:e.res.did_you_mean}),b=e.rows.length;e.rows.push({block:"did_you_mean",data:{value:e.res.did_you_mean},value:e.res.did_you_mean}),f.addEventListener("click",()=>e.onRowClick(b)),c.append(f),r.append(c);}i.length&&r.append(re(e,i,"Related")),a.length&&r.append(O(e,a));let m=s("div",{class:"canvas"});m.append(E(e,o,5)),r.append(m);}else if(n==="magazine"){r.append(D(e)),a.length&&r.append(O(e,a));let h=s("div",{class:"canvas"}),l=o.slice(0,3),u=o.slice(3);if(l.length){h.append(s("div",{class:"group-title",part:"group-title",text:"Best matches"}));let m=s("div",{class:"hero-row",part:"hero-row"});l.forEach((c,f)=>{m.append(ze(e,c,f,f===0?"Top match":void 0));}),h.append(m);}u.length?(h.append(s("div",{class:"group-title",part:"group-title",text:"More results"})),h.append(E(e,u,6))):l.length||h.append(E(e,o,6)),r.append(h);}let d=Oe(e);return d&&r.append(d),r.append(s("slot",{attrs:{name:"footer"}})),r}function C(n){return n!=="classic"&&n!==""}var pe=["recent","did_you_mean","keywords","trending","products","redirects","categories"],Fe={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",redirects:"Go to",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing.",cors_blocked:R},he=`
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
  ${ne}
`,M=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let r=this.map.keys().next().value;if(r===void 0)break;this.map.delete(r);}}clear(){this.map.clear();}},I=class extends A{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","limit","cache-size","view-all-href","lang","anchor","anchor-offset","anchor-min-width","layout","split-mobile-resize","product-title-tooltip","typeahead-fallback-url","serp-passthrough","vehicle-id","vehicle-filter","currency","show-branding","brand-url","brand-logo-url","suppress-legacy"]}current=null;loading=false;corsBlocked=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;fetchToken=0;inflight=null;cache=new M(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;visibilityHandler=null;pageShowHandler=null;windowFocusHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorResizeRaf=null;lastAnchorKey="";anchorApplied=false;suppressedLegacyEls=new WeakSet;legacySuppressionRetryHandler=null;splitResizeCleanup=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),this.scheduleLegacySuppressionRetries(),this.scheduleRender();}disconnectedCallback(){this.unscheduleLegacySuppressionRetries(),this.unbindSplitMobileResize(),this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"||e==="layout"||e==="split-mobile-resize"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):e==="vehicle-id"||e==="vehicle-filter"||e==="serp-passthrough"||e==="currency"?(this.cache.clear(),this.current=null,this.lastQuery.trim().length>=(parseInt(this.getAttribute("min-length")??"2",10)||2)?this.fetch(this.lastQuery):this.scheduleRender()):this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;this.debouncedAt===e&&this.debounced||(this.debouncedAt=e,this.debounced=j(t=>{this.fetch(t);},e));}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new M(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let r=document.getElementById(e);if(r){this.subscribed=r,r.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let r=document.getElementById(t);r instanceof HTMLInputElement&&(this.inputEl=r,r.addEventListener("input",this.onPlainInput),r.addEventListener("focus",this.onPlainFocus),r.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler),this.visibilityHandler=()=>{document.hidden||this.current===null||this.reloadVisibleThumbs();},document.addEventListener("visibilitychange",this.visibilityHandler),this.pageShowHandler=e=>{this.current!==null&&e.persisted&&this.reloadVisibleThumbs();},window.addEventListener("pageshow",this.pageShowHandler),this.windowFocusHandler=()=>{this.current!==null&&this.reloadVisibleThumbs();},window.addEventListener("focus",this.windowFocusHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null),this.visibilityHandler&&(document.removeEventListener("visibilitychange",this.visibilityHandler),this.visibilityHandler=null),this.pageShowHandler&&(window.removeEventListener("pageshow",this.pageShowHandler),this.pageShowHandler=null),this.windowFocusHandler&&(window.removeEventListener("focus",this.windowFocusHandler),this.windowFocusHandler=null);}reloadVisibleThumbs(){this.root.querySelectorAll("img.thumb").forEach(e=>{if(e.complete&&e.naturalWidth>0)return;let t=e.getAttribute("src")||e.getAttribute("data-src");if(!t)return;let r=e.parentNode;if(!r)return;let o=e.cloneNode(false);o.removeAttribute("src"),o.setAttribute("data-src",t),o.setAttribute("src",t),r.replaceChild(o,e);});}bindAnchorListeners(){this.anchorScrollHandler=()=>this.scheduleApplyAnchor(),this.anchorResizeHandler=()=>this.scheduleApplyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let r=this.inputEl??this.subscribed;r&&(t===r||r.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorResizeRaf!==null&&(cancelAnimationFrame(this.anchorResizeRaf),this.anchorResizeRaf=null),this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}scheduleApplyAnchor(){typeof window>"u"||this.anchorResizeRaf===null&&(this.anchorResizeRaf=requestAnimationFrame(()=>{this.anchorResizeRaf=null,this.applyAnchor();}));}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let r=t.getBoundingClientRect();if(r.width<=0&&r.height<=0){this.style.visibility="hidden";return}let o=parseInt(this.getAttribute("anchor-offset")??"4",10),i=Number.isFinite(o)?o:4,a=this.getAttribute("anchor-min-width"),p=C(this.layoutMode()),g=a===null?p?960:480:Math.max(0,parseInt(a,10)||0),d=typeof window<"u"&&window.innerWidth>0?window.innerWidth:Math.max(r.width,g),h=Math.min(d*.96,1440),l=p?Math.max(r.width,Math.min(Math.max(g,r.width),h)):Math.max(r.width,g),u=p?h:Math.max(0,d-r.left-8),m=p?Math.min(l,u):Math.max(r.width,Math.min(l,u)),c=p?Math.max(8,(d-m)/2):r.left,f=[c,m,r.bottom,i,p?1:0].join("|");if(this.anchorApplied&&f===this.lastAnchorKey){this.style.visibility==="hidden"&&(this.style.visibility="");return}this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${r.bottom+i}px`,this.style.left=`${c}px`,this.style.width=`${m}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true,this.lastAnchorKey=f;}clearAnchor(){this.anchorApplied&&(this.lastAnchorKey="",this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(o=>o.trim()).filter(Boolean),r=this.inputEl;if(r)for(let o of t)o==="jquery-ui"?this.suppressJqueryUiAutocomplete(r):o==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(r);}suppressJqueryUiAutocomplete(e){let r=window.jQuery;if(!r||!r.ui||!r.ui.autocomplete)return;let o=r(e);if(o.data("ui-autocomplete")){try{o.autocomplete("close");}catch{}try{o.autocomplete("destroy");}catch{}}let i=o.attr("aria-owns");if(i){let a=document.getElementById(i);a&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));}document.querySelectorAll("ul.ui-autocomplete").forEach(a=>{let p=a.getAttribute("id");if(!p)return;document.querySelector(`[aria-owns="${CSS.escape(p)}"]`)===e&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));});}scheduleLegacySuppressionRetries(){this.unscheduleLegacySuppressionRetries();let e=()=>{this.applyLegacySuppression();};this.legacySuppressionRetryHandler=e,setTimeout(e,0),setTimeout(e,50),document.readyState==="loading"&&document.addEventListener("DOMContentLoaded",e,{once:true}),window.addEventListener("load",e,{once:true});}unscheduleLegacySuppressionRetries(){this.legacySuppressionRetryHandler=null;}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(r=>{r.style.display="none",this.suppressedLegacyEls.add(r);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),r=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<r){this.lastQuery=t,this.current=null,this.loading=false,this.corsBlocked=false,this.inflight?.abort(),this.scheduleRender();return}this.lastQuery=t,this.corsBlocked=false;let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}cacheKey(e){let t=this.getSerpPassthrough(),r=this.getVehicleFilterArgs(),o=t?JSON.stringify(t):"",i=Object.keys(r).length>0?JSON.stringify(r):"",a=this.getVehicleId(),p=a!==null?`v${a}`:i,g=this.resolvePriceCurrency();return `${e.toLowerCase()}\0${p}\0${o}\0${g}`}resolvePriceCurrency(e){let t=this.getAttribute("currency")?.trim();if(t)return t.toUpperCase();if(e)return e.toUpperCase();let r=this.current?.meta?.region;if(r&&typeof r=="object"&&!Array.isArray(r)){let a=r.currency;if(typeof a=="string"&&a.trim()!=="")return a.trim().toUpperCase()}let i=this.getSerpPassthrough()?.shopper_context;if(i&&typeof i=="object"&&!Array.isArray(i)){let a=i.currency;if(typeof a=="string"&&a.trim()!=="")return a.trim().toUpperCase()}return "USD"}resolvePriceLocale(){let e=this.current?.meta?.region;if(e&&typeof e=="object"&&!Array.isArray(e)){let t=e.locale;if(typeof t=="string"&&t.trim()!=="")return t.trim()}}getVehicleId(){let e=this.getAttribute("vehicle-id");if(!e)return null;let t=parseInt(e,10);return Number.isFinite(t)&&t>0?t:null}getVehicleFilterArgs(){let e={},t=this.getAttribute("vehicle-filter");if(t)try{let i=JSON.parse(t);i&&typeof i=="object"&&!Array.isArray(i)&&Object.assign(e,i);}catch{}let r=this.getSerpPassthrough();if(r)for(let i of ["filter_by","vehicle_filter_mode","vehicle_hard_filter","vehicle_id","shopper_context"])r[i]!==void 0&&r[i]!==null&&e[i]===void 0&&(e[i]=r[i]);let o=this.getVehicleId();return o!==null&&e.vehicle_id===void 0&&(e.vehicle_id=o),e}getSerpPassthrough(){let e=this.getAttribute("serp-passthrough");if(!e)return null;try{let t=JSON.parse(e);if(t&&typeof t=="object"&&!Array.isArray(t))return t}catch{}return null}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.fetchToken;try{let o=await this.getClient(),i=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.layoutMode(),p=C(a)?T(a,i):i,g=this.getSessionId(),d={q:e,limit:p,complete:!0};g&&(d.session_id=g);let h=this.getVehicleFilterArgs();for(let[c,f]of Object.entries(h))if(f!=null){if(c==="vehicle_id"){let b=typeof f=="number"?f:parseInt(String(f),10);Number.isFinite(b)&&(d.vehicle_id=b);continue}if(c==="filter_by"&&typeof f=="string"){d.filter_by=f;continue}if(c==="vehicle_filter_mode"&&typeof f=="string"){d.vehicle_filter_mode=f;continue}if(c==="vehicle_hard_filter"){d.vehicle_hard_filter=f!==!1&&f!=="false"&&f!==0&&f!=="0";continue}c==="shopper_context"&&f&&typeof f=="object"&&!Array.isArray(f)&&(d.shopper_context=f);}let l=this.getSerpPassthrough();l&&(d.serp_passthrough=l);let u=await o.suggest(d);if(r!==this.fetchToken||t.signal.aborted)return;let m=await this.mergeTypeaheadFallback(e,p,u,t);if(m&&(u=m),this.current=u,this.loading=!1,this.cache.set(this.cacheKey(e),u),u.redirect?.target_url){window.location.assign(u.redirect.target_url);return}this.emitOpen(e),this.isEmpty(u)&&y(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl}),this.scheduleRender();}catch(o){if(r!==this.fetchToken||t.signal.aborted)return;this.corsBlocked=L(o),this.current=null,this.loading=false,this.corsBlocked?(y(this,"seekmodo-suggest:cors-blocked",{q:e}),console.warn("[seekmodo-suggest] blocked by CORS or network policy",o)):console.warn("[seekmodo-suggest] fetch failed",o),this.scheduleRender();}}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}currentSearchEventId(){let e=this.current?.meta?.search_event_id;if(typeof e=="number"&&Number.isFinite(e)&&e>0)return Math.trunc(e);if(typeof e=="string"&&e!==""){let t=parseInt(e,10);if(Number.isFinite(t)&&t>0)return t}}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.redirects?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}typeaheadFallbackUrl(){let e=(this.getAttribute("typeahead-fallback-url")??"").trim();return e.length>0?e:null}async mergeTypeaheadFallback(e,t,r,o){let i=this.typeaheadFallbackUrl();if(!i||typeof fetch!="function")return null;try{let a=i.includes("?")?"&":"?",p=await fetch(`${i}${a}q=${encodeURIComponent(e)}&max=${encodeURIComponent(String(t))}`,{credentials:"same-origin",signal:o.signal});if(!p.ok)return null;let g=await p.json(),d=Array.isArray(g?.rows)?g.rows:[];if(d.length===0)return null;let h=d.slice(0,t).map((u,m)=>{let c=u,f=c.id!==void 0&&c.id!==null?String(c.id):String(m),b=typeof c.name=="string"&&c.name!==""?c.name:typeof c.title=="string"?c.title:"",P=typeof c.url=="string"&&c.url!==""?c.url:typeof c.permalink=="string"?c.permalink:void 0,S=typeof c.image_url=="string"&&c.image_url!==""?c.image_url:typeof c.thumbnail_url=="string"?c.thumbnail_url:void 0;return {id:f,name:b,url:P,image_url:S,post_type:typeof c.post_type=="string"?c.post_type:void 0,excerpt:typeof c.excerpt=="string"?c.excerpt:void 0}}),l={...r.meta??{},typeahead_fallback:!0};return l.total=Math.max(l.total??0,h.length),l.counts={...l.counts??{},products:h.length},{...r,products:h,meta:l}}catch{return null}}blocks(){let e=this.getAttribute("blocks");if(!e)return pe;let t=e.split(",").map(r=>r.trim()).filter(r=>["recent","trending","did_you_mean","keywords","products","redirects","categories"].includes(r));return t.length>0?t:pe}label(e){return Fe[e]??e}layoutMode(){let e=(this.getAttribute("layout")??$).trim();return e==="classic"||e==="cinema-grid"||e==="command-bar"||e==="magazine"||e==="split-rail"?e:$}showBrandingFlag(){let e=(this.getAttribute("show-branding")??"true").trim().toLowerCase();return e!=="false"&&e!=="0"&&e!=="no"}splitMobileResizeEnabled(){let e=(this.getAttribute("split-mobile-resize")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}productTitleTooltipEnabled(){let e=(this.getAttribute("product-title-tooltip")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}unbindSplitMobileResize(){this.splitResizeCleanup?.(),this.splitResizeCleanup=null;}bindSplitMobileResizeIfNeeded(e,t){if(this.unbindSplitMobileResize(),e!=="split-rail"||!this.splitMobileResizeEnabled())return;let r=t.querySelector(".split-body");r instanceof HTMLElement&&(this.splitResizeCleanup=oe(r));}dismiss(){this.current===null&&!this.loading||(y(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.scheduleRender());}emitOpen(e){y(this,"seekmodo-suggest:open",{q:e});}buildViewAllHref(e){let r=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(e));return J(r,"seekmodo_skip_category_redirect","1")}navigateViewAll(){let e=this.current?.meta?.total??0;y(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:e}),window.location.assign(this.buildViewAllHref(this.lastQuery));}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,r)=>{r===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];if(!t)return;let r=this.currentSearchEventId();if(y(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id,position:e+1,...r!==void 0?{search_event_id:r}:{}}),t.block==="redirects"){let o=String(t.data.target_url??"");o&&window.location.assign(o);}}render(){this.unbindSplitMobileResize();let e=document.createElement("style");if(e.textContent=he,this.loading&&this.current===null&&!this.corsBlocked){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.corsBlocked){G(this.root,he,{message:this.label("cors_blocked")}),this.rows=[],this.active=-1,this.applyAnchor();return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(this.current)){let d=s("slot",{attrs:{name:"empty"}}),h=s("div",{class:"empty",text:this.label("empty")});d.append(h);let l=s("div",{class:"wrap",part:"wrap"});l.append(d),this.root.replaceChildren(e,l),this.rows=[],this.active=-1;return}let t=this.productTitleTooltipEnabled()?"wrap product-title-tooltip":"wrap",r=s("div",{class:t,part:"wrap"});r.append(s("slot",{attrs:{name:"header"}}));let o=[],i=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.layoutMode();if(C(a)){let d=this.buildViewAllHref(this.lastQuery),h=(u,m)=>H(u,this.resolvePriceCurrency(m),this.resolvePriceLocale()),l=ue(a,{res:this.current,lastQuery:this.lastQuery,limit:T(a,i),label:u=>this.label(u),rows:o,onRowClick:u=>this.activateRow(u),onViewAll:()=>this.navigateViewAll(),viewAllHref:d,showBranding:this.showBrandingFlag(),brandUrl:this.getAttribute("brand-url")??"https://seekmodo.com",brandLogoUrl:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",formatPrice:h,splitMobileResize:this.splitMobileResizeEnabled(),productTitleTooltip:this.productTitleTooltipEnabled()});this.rows=o,this.active=-1,this.root.replaceChildren(e,l),this.bindSplitMobileResizeIfNeeded(a,l),this.applyAnchor();return}let p=this.blocks();for(let d of p){let h=this.renderBlock(d,this.current,i,o);h&&r.append(h);}this.rows=o,this.active=-1;let g=this.current.meta?.total??0;if(g>0&&this.lastQuery.length>0){let d=this.buildViewAllHref(this.lastQuery),h=s("a",{class:"view-all",part:"view-all",attrs:{href:d},text:this.label("view_all").replace("{total}",String(g))});h.addEventListener("click",l=>{l.preventDefault(),this.navigateViewAll();}),r.append(h);}if(r.append(s("slot",{attrs:{name:"footer"}})),this.showBrandingFlag()){let d=s("a",{class:"brand-footer",part:"brand-footer",attrs:{href:this.getAttribute("brand-url")??"https://seekmodo.com",target:"_blank",rel:"noopener noreferrer"}});d.append(s("span",{class:"brand-by",text:"Powered by "})),d.append(s("img",{class:"brand-logo",part:"brand-logo",attrs:{src:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",alt:"Seekmodo",height:"16"}})),r.append(d);}this.root.replaceChildren(e,r),this.applyAnchor();}renderSkeleton(){let e=s("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let r=s("div",{class:"row",part:"row skeleton"});r.append(s("div",{class:"thumb",part:"thumb"}));let o=s("div",{class:"name"});o.append(s("span",{class:"name-title"})),o.append(s("span",{class:"name-meta"})),r.append(o),e.append(r);}return e}renderBlock(e,t,r,o){if(e==="did_you_mean"){let p=t.did_you_mean;if(!p)return null;let g=s("div",{class:"group",part:"group did-you-mean"});g.append(s("slot",{attrs:{name:"did_you_mean"}}));let d=s("div",{class:"did-you-mean"});d.append(document.createTextNode(this.label("did_you_mean")+" "));let h=s("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:p});return h.addEventListener("click",()=>{let l=this.currentSearchEventId();y(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:p},q:this.lastQuery,value:p,...l!==void 0?{search_event_id:l}:{}});}),d.append(h),g.append(d),g}let i=this.blockData(e,t,r);if(i.length===0)return null;let a=s("div",{class:"group",part:"group",attrs:{"data-block":e}});return a.append(s("slot",{attrs:{name:e}})),a.append(s("div",{class:"group-title",part:"group-title",text:this.label(e)})),i.forEach((p,g)=>{let d={block:e,data:p,value:this.rowValue(e,p),id:this.rowId(e,p)};o.push(d);let h=o.length-1,l=window.seekmodoSuggest?.renderRow?.(d.data,e),u;l instanceof HTMLElement?(u=l,u.classList.add("row")):typeof l=="string"&&l.length>0?(u=s("button",{class:"row",part:"row",type:"button"}),u.innerHTML=l):u=this.renderRowDefault(e,p,g),u.setAttribute("data-seekmodo-surface","suggest"),u.setAttribute("data-seekmodo-block",e),u.setAttribute("data-seekmodo-pos",String(h)),d.id&&u.setAttribute("data-seekmodo-id",d.id),u.addEventListener("click",()=>this.activateRow(h)),a.append(u);}),a}blockData(e,t,r){switch(e){case "recent":return (t.recent??[]).slice(0,r);case "trending":return (t.trending??[]).slice(0,r);case "keywords":return (t.keywords??[]).slice(0,r);case "products":return (t.products??[]).slice(0,r);case "redirects":return (t.redirects??[]).slice(0,r);case "categories":return (t.categories??[]).slice(0,r);default:return []}}rowValue(e,t){let r=t;return e==="recent"||e==="trending"||e==="keywords"?String(r.keyword??""):e==="products"?String(r.name??r.title??""):e==="categories"?String(r.name??""):e==="redirects"?String(r.label??r.matched_term??""):""}rowId(e,t){if(e!=="products")return;let r=t.id;return r!==void 0?String(r):void 0}renderRowDefault(e,t,r){let o=s("button",{class:"row",part:"row",type:"button"});if(e==="products"){let i=t,{postType:a,label:p}=X(i),g=i.image_url??i.image,d=String(i.name??i.title??"").trim(),h=this.productTitleTooltipEnabled()&&d?d:"";if(a&&o.setAttribute("data-post-type",a),g)o.append(s("img",{class:"thumb",part:"thumb",attrs:{src:g,"data-src":g,alt:h,loading:"eager",decoding:"async"}}));else {let b=s("div",{class:"thumb thumb-empty",part:"thumb thumb--empty",text:a==="page"?"P":a==="post"?"A":"\xB7"});a&&b.setAttribute("data-content-type",a),o.append(b);}let l=s("div",{class:"name",part:"name"}),u=s("span",{class:"name-title",text:d});this.productTitleTooltipEnabled()&&d&&u.setAttribute("title",d),l.append(u);let m=[p,i.brand?String(i.brand):"",i.sku??i.model??i.ez_number??""].filter(Boolean);m.length>0&&l.append(s("span",{class:"name-meta",text:m.join(" \xB7 ")})),o.append(l);let c=this.renderPrice(i);return c&&o.append(c),o}if(e==="categories"){let i=t,a=s("div",{class:"name",part:"name",text:i.name});return o.append(a),typeof i.count=="number"&&i.count>0&&o.append(s("span",{class:"badge",part:"badge",text:String(i.count)})),o}if(e==="redirects"){let i=String(t.label??t.matched_term??"");return o.append(s("div",{class:"name",part:"name",text:i})),o.append(s("span",{class:"badge",part:"badge",text:"\u2192"})),o}if(e==="recent"||e==="trending"||e==="keywords"){let i=t,a=s("div",{class:"name",part:"name",text:String(i.keyword)});return o.append(a),e==="trending"&&typeof i.search_count=="number"&&o.append(s("span",{class:"badge",part:"badge",text:String(i.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=this.resolvePriceCurrency(typeof e.currency=="string"?e.currency:void 0),r=this.resolvePriceLocale(),o=s("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(o.append(s("del",{text:H(e.price,t,r)})),o.append(document.createTextNode(H(e.sale_price,t,r)))):o.append(document.createTextNode(H(e.price,t,r))),o}};function H(n,e,t){try{return new Intl.NumberFormat(t,{style:"currency",currency:e,maximumFractionDigits:2}).format(n)}catch{return `${n.toFixed(2)} ${e}`}}Y();typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",I);
exports.SeekmodoSuggest=I;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map