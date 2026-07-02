var SeekmodoSuggest=(function(exports){'use strict';var k=class extends Error{status;body;tool;constructor(n,e,t,r){super(n),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=r;}},K=class extends k{constructor(n,e,t){super(`Seekmodo auth failed (HTTP ${n})`,n,e,t),this.name="SeekmodoAuthError";}},he=class extends k{code;bucket;limit;used;constructor(n,e){super("Seekmodo over quota (HTTP 402)",402,n,e),this.name="SeekmodoQuotaError";let t=n??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},me=class extends k{constructor(n,e,t){super(`Seekmodo server error (HTTP ${n})`,n,e,t),this.name="SeekmodoServerError";}},ge=class extends k{constructor(n,e,t){super(`Seekmodo request rejected (HTTP ${n})`,n,e,t),this.name="SeekmodoRequestError";}},q=class extends k{constructor(n,e){super(`Seekmodo network failure${n instanceof Error?`: ${n.message}`:""}`,0,n,e),this.name="SeekmodoNetworkError";}};function x(n,e){if(n instanceof q)return x(n.body);if(n instanceof TypeError){let t=n.message.toLowerCase();return t.includes("failed to fetch")||t.includes("networkerror")||t.includes("network request failed")||t.includes("load failed")}if(n instanceof Error){let t=n.message.toLowerCase();return t.includes("cors")||t.includes("access-control-allow-origin")||t.includes("cross-origin")}return  false}var fe="https://gateway.seekmodo.com",be=8e3,ve=class{config;cachedToken=null;constructor(n){if(!n.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof n.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:n.tenantId,getToken:n.getToken,baseUrl:(n.baseUrl??fe).replace(/\/+$/,""),fetch:n.fetch??globalThis.fetch.bind(globalThis),timeoutMs:n.timeoutMs??be,signal:n.signal,onError:n.onError,getRegion:n.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(n,e,t={}){try{return await this.callOnce(n,e,t,!1)}catch(r){if(r instanceof K){this.clearTokenCache();try{return await this.callOnce(n,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:n}),o}}throw this.config.onError?.(r,{tool:n}),r}}async callOnce(n,e,t,r){let o=await this.resolveToken(r),s=`${this.config.baseUrl}/v1/${encodeURIComponent(n)}`,a=new AbortController,d=t.timeoutMs??this.config.timeoutMs,m=setTimeout(()=>a.abort(),d),c=()=>a.abort();this.config.signal?.addEventListener("abort",c,{once:true}),t.signal?.addEventListener("abort",c,{once:true});let u={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let p=await this.config.getRegion();typeof p=="string"&&p.length>0&&(u["Seekmodo-Region"]=p);}catch{}let l;try{l=await this.config.fetch(s,{method:"POST",headers:u,body:JSON.stringify(e),signal:a.signal});}catch(p){throw new q(p,n)}finally{clearTimeout(m),this.config.signal?.removeEventListener("abort",c),t.signal?.removeEventListener("abort",c);}let h=await l.text(),g=h?we(h):null;if(l.status===401||l.status===403)throw new K(l.status,g,n);if(l.status===402)throw new he(g,n);if(l.status>=500)throw new me(l.status,g,n);if(!l.ok)throw new ge(l.status,g,n);return g}async resolveToken(n){let e=Date.now();if(!n&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function we(n){try{return JSON.parse(n)}catch{return n}}var N=class{transport;recommend;bundle;constructor(n){this.transport=new ve(n),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(n,e){return this.transport.call("search",{...n},e??{})}suggest(n,e){return this.transport.call("suggest",{...n},e??{})}searchByImage(n,e){return this.transport.call("search.byImage",{...n},e??{})}chat(n,e){return this.transport.call("chat",{...n},e??{})}ask(n,e){return this.transport.call("ask",{...n},e??{})}catalogGet(n,e){return this.transport.call("catalog.get",{...n},e??{})}event(n,e){return this.transport.call("events",{...n},e??{})}};var _=null,b=null;function R(n){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${n}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function V(){return _!==null||(_=ye()),_}async function ye(){let n=R("seekmodo:tenant");if(!n)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=R("seekmodo:token"),t=R("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(b={token:e,expiresAt:Date.now()+3e4});let r=R("seekmodo:gateway")??void 0;return new N({tenantId:n,baseUrl:r,getRegion:()=>Se(),getToken:async()=>{let o=Date.now();if(b&&b.expiresAt-1e4>o)return {token:b.token,expiresAt:b.expiresAt};if(!t){if(b)return {token:b.token,expiresAt:b.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let s=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!s.ok)throw new Error(`seekmodo:refresh route returned HTTP ${s.status}`);let a=await s.json();if(!a.token||typeof a.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return b={token:a.token,expiresAt:a.expires_at*1e3},{token:b.token,expiresAt:b.expiresAt}}})}var ke="seekmodo_region";function Ee(n){if(typeof n!="string")return null;let e=n.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function Se(){if(typeof document>"u")return null;let n=document.cookie??"";if(n.length===0)return null;let e=ke.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(n);if(!t)return null;try{return Ee(decodeURIComponent(t[1]))}catch{return null}}var A=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}async getClient(){return V()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function i(n,e,t){let r=document.createElement(n);if(e){for(let[o,s]of Object.entries(e))if(!(s==null||s===false))if(o==="class")r.className=String(s);else if(o==="part")r.setAttribute("part",String(s));else if(o==="text")r.textContent=String(s);else if(o==="html")r.innerHTML=String(s);else if(o==="attrs"&&typeof s=="object"&&s!==null)for(let[a,d]of Object.entries(s))r.setAttribute(a,d);else r.setAttribute(o,String(s));}return r}function Q(n,e){let t=null;return (...r)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>n(...r),e);}}function w(n,e,t){n.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var T="Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";function W(n,e,t){let r=document.createElement("style");r.textContent=e;let o=i("div",{class:"wrap seekmodo-cors-blocked",part:"wrap cors-blocked",attrs:{role:"status"}});o.append(i("div",{class:"cors-notice",part:"cors-notice",text:t?.message??T})),n.replaceChildren(r,o);}var j="seekmodo-cors-notice";function G(n,e){if(!n||typeof document>"u")return;let t=n.closest(".search-form")??n.parentNode;if(!t)return;t.style.position=t.style.position||"relative";let r=t.querySelector(`.${j}`);if(!r){r=document.createElement("div"),r.className=j,r.setAttribute("role","status"),r.style.cssText=["position:absolute","top:100%","left:0","right:0","z-index:10050","display:none","background:#fff8e6","border:1px solid #f0c040","border-top:none","padding:8px 12px","font-size:13px","line-height:1.4","color:#5c4a00","box-shadow:0 4px 12px rgba(0,0,0,.08)"].join(";"),t.appendChild(r);let o=()=>{if(!r)return;let s=(n.value||"").trim();r.style.display=s.length>=2?"block":"none";};n.addEventListener("input",o),n.addEventListener("focus",o);}r.textContent=e??T;}function Y(){typeof window>"u"||(window.seekmodoShowCorsNotice=G,window.seekmodoScriptLoadFailed=(n,e)=>{let t=n??document.querySelectorAll('input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]');for(let r=0;r<t.length;r++){let o=t[r];o instanceof HTMLInputElement&&G(o,e);}});}function E(n){for(let e of ["image_url","image","thumbnail_url"]){let t=n[e];if(typeof t=="string"&&t.trim()!=="")return t.trim()}}function J(n,e,t){try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(n,r);return o.searchParams.set(e,t),/^https?:\/\//i.test(n)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=n.includes("?")?"&":"?";return `${n}${r}${encodeURIComponent(e)}=${encodeURIComponent(t)}`}}var U="split-rail",Le=15;function C(n,e){let r={"split-rail":5,"command-bar":5,"cinema-grid":6,magazine:6,classic:5}[n]??5,o=Math.max(e,r*3);return Math.min(Le,o)}var re=`
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
`,X=.15,Z=.85,xe=.28,ee="seekmodo:split-rail-mobile-ratio-v3",_e="(max-width: 900px)",Re=1-xe;function Ae(){return i("div",{class:"split-divider",part:"split-divider",html:'<svg class="split-divider-icon" viewBox="0 0 36 12" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="1" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="5" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="9" width="24" height="2" rx="1" fill="currentColor"/></svg>'})}function ne(n){let e=n.querySelector(".split-divider");if(!e)return ()=>{};let t=window.matchMedia(_e),r=Re;try{let g=sessionStorage.getItem(ee);if(g){let p=parseFloat(g);p>=X&&p<=Z&&(r=p);}}catch{}let o=g=>{r=Math.min(Z,Math.max(X,g)),n.style.setProperty("--split-rail-bottom-grow",String(r)),n.style.setProperty("--split-rail-top-grow",String(1-r));},s=()=>{n.style.removeProperty("--split-rail-top-grow"),n.style.removeProperty("--split-rail-bottom-grow");},a=()=>{t.matches?o(r):s();};a();let d=false,m=0,c=r,u=g=>{!t.matches||g.button!==0||(d=true,m=g.clientY,c=r,e.classList.add("is-dragging"),e.setPointerCapture(g.pointerId),g.preventDefault());},l=g=>{if(!d)return;let f=n.getBoundingClientRect().height-e.offsetHeight;f<=0||o(c+(g.clientY-m)/f);},h=g=>{if(d){d=false,e.classList.remove("is-dragging");try{e.releasePointerCapture(g.pointerId);}catch{}try{sessionStorage.setItem(ee,String(r));}catch{}}};return e.setAttribute("role","separator"),e.setAttribute("aria-orientation","horizontal"),e.setAttribute("aria-label","Resize product and suggestion panels"),e.setAttribute("tabindex","0"),e.addEventListener("pointerdown",u),e.addEventListener("pointermove",l),e.addEventListener("pointerup",h),e.addEventListener("pointercancel",h),t.addEventListener("change",a),()=>{e.removeEventListener("pointerdown",u),e.removeEventListener("pointermove",l),e.removeEventListener("pointerup",h),e.removeEventListener("pointercancel",h),t.removeEventListener("change",a),e.classList.remove("is-dragging"),s();}}var Te="https://seekmodo.com/email-assets/seekmodo-lockup.png";function oe(n){return String(n.name??n.title??"").trim()}function ie(n,e,t){e.productTitleTooltip&&t&&n.setAttribute("title",t);}function se(n,e){return n.productTitleTooltip&&e?e:""}function ae(n,e,t){let r={block:"products",data:e,value:String(e.name??e.title??""),id:e.id!==void 0?String(e.id):void 0},o=n.rows.length;return n.rows.push(r),o}function Ce(n,e,t){let r=i("div",{class:"thumb-frame",part:"thumb-frame"}),o=E(n);return o?r.append(i("img",{class:"thumb",part:"thumb",attrs:{src:o,alt:se(e,t),loading:"eager",decoding:"async"}})):r.append(i("div",{class:"thumb-empty",part:"thumb thumb--empty"})),r}function Me(n,e,t){let r=E(n);return r?i("img",{class:"thumb",part:"thumb",attrs:{src:r,alt:se(e,t),loading:"eager",decoding:"async"}}):i("div",{class:"thumb-empty",part:"thumb thumb--empty"})}function le(n,e,t="card-price"){if(n.price===void 0||n.price===null)return null;let r=i("div",{class:t,part:"price"});return n.on_sale&&typeof n.sale_price=="number"?(r.append(i("del",{text:e.formatPrice(n.price,n.currency)})),r.append(document.createTextNode(e.formatPrice(n.sale_price,n.currency)))):r.append(document.createTextNode(e.formatPrice(n.price,n.currency))),r}function de(n,e,t){n.classList.add("row"),n.setAttribute("data-seekmodo-surface","suggest"),n.setAttribute("data-seekmodo-block","products"),n.setAttribute("data-seekmodo-pos",String(t));let r=e.rows[t];r?.id&&n.setAttribute("data-seekmodo-id",r.id),n.addEventListener("click",()=>e.onRowClick(t));}function He(n,e,t,r=false){let o=ae(n,e),s=oe(e),a=i("button",{class:"product-card",part:"row",type:"button"});a.append(Ce(e,n,s));let d=i("span",{class:"card-title",part:"name",text:s});ie(d,n,s),a.append(d);let m=le(e,n,"card-price");return m&&a.append(m),de(a,n,o),a}function Ie(n,e,t,r){let o=ae(n,e),s=oe(e),a=i("button",{class:"hero-card",part:"row",type:"button"});r&&a.append(i("span",{class:"hero-badge",text:r})),a.append(Me(e,n,s));let d=i("div",{class:"hero-info"}),m=i("span",{class:"card-title",part:"name",text:s});ie(m,n,s),d.append(m);let c=le(e,n);return c&&d.append(c),a.append(d),de(a,n,o),a}function D(n){let e=n.res.meta?.total??0,t=i("div",{class:"meta-bar",part:"meta-bar"}),r=i("div");r.append(i("span",{class:"count",text:`${e} results for `})),r.append(i("span",{class:"query",text:`"${n.lastQuery}"`})),t.append(r);let o=i("a",{class:"view-all view-all-cta",part:"view-all",attrs:{href:n.viewAllHref},text:n.label("view_all").replace("{total}",String(e))});return o.addEventListener("click",s=>{s.preventDefault(),n.onViewAll();}),t.append(o),t}function ze(n){let e=n.res.did_you_mean;if(!e)return null;let t=i("div",{class:"did-you-mean-bar",part:"did-you-mean"});t.append(document.createTextNode(`Showing results for "${n.lastQuery}". Search instead for `));let r=i("button",{class:"swap",type:"button",text:e}),o=n.rows.length;return n.rows.push({block:"did_you_mean",data:{value:e},value:e}),r.addEventListener("click",()=>n.onRowClick(o)),t.append(r),t.append(document.createTextNode("?")),t}function O(n,e){let t=i("div",{class:"chip-row filter-bar",part:"filter-bar"});return t.append(i("span",{class:"filter-label",text:"Category"})),e.forEach((r,o)=>{let s=i("button",{class:`chip${o===0?" active":""}`,type:"button",text:`${r.name}${typeof r.count=="number"?` ${r.count}`:""}`}),a=n.rows.length;n.rows.push({block:"categories",data:r,value:String(r.name??"")}),s.addEventListener("click",()=>n.onRowClick(a)),t.append(s);}),t}function te(n,e,t="Try"){let r=i("div",{class:"chip-row",part:"filter-bar"});return r.append(i("span",{class:"filter-label",text:t})),e.forEach(o=>{let s=i("button",{class:"chip",type:"button",text:o.keyword}),a=n.rows.length;n.rows.push({block:"keywords",data:o,value:o.keyword}),s.addEventListener("click",()=>n.onRowClick(a)),r.append(s);}),r}function B(n,e,t,r,o){let s=n.rows.length;n.rows.push({block:e,data:t,value:r});let a=i("button",{class:"row",part:"row",type:"button"});return a.append(i("div",{class:"name",part:"name",text:r})),o&&a.append(i("span",{class:"badge",part:"badge",text:o})),a.setAttribute("data-seekmodo-surface","suggest"),a.setAttribute("data-seekmodo-block",e),a.setAttribute("data-seekmodo-pos",String(s)),a.addEventListener("click",()=>n.onRowClick(s)),a}function $(n,e){if(e.length===0)return null;let t=i("div",{class:"rail-section"});return t.append(i("div",{class:"group-title",part:"group-title",text:n})),e.forEach(r=>t.append(r)),t}function Pe(n){if(!n.showBranding)return null;let e=i("a",{class:"brand-footer",part:"brand-footer",attrs:{href:n.brandUrl,target:"_blank",rel:"noopener noreferrer"}});return e.append(i("span",{class:"brand-by",text:"Powered by "})),e.append(i("img",{class:"brand-logo",part:"brand-logo",attrs:{src:n.brandLogoUrl||Te,alt:"Seekmodo",height:"16"}})),e}function S(n,e,t){let r=i("div",{class:`product-grid cols-${t}`,part:"product-grid"});return e.forEach((o,s)=>r.append(He(n,o,s,true))),r}function ce(n,e){let t=["wrap","wide"];n==="split-rail"&&t.push("split-rail-panel"),e.splitMobileResize&&t.push("split-rail-mobile"),e.productTitleTooltip&&t.push("product-title-tooltip");let r=i("div",{class:t.join(" "),part:"wrap"});r.append(i("slot",{attrs:{name:"header"}}));let o=(e.res.products??[]).slice(0,C(n,e.limit)),s=(e.res.keywords??[]).slice(0,e.limit),a=(e.res.categories??[]).slice(0,e.limit),d=(e.res.recent??[]).slice(0,5);(e.res.trending??[]).slice(0,5);if(n==="split-rail"){r.append(D(e));let u=e.splitMobileResize?"split-body split-body--mobile-resize":"split-body",l=i("div",{class:u}),h=i("aside",{class:"rail",part:"rail"}),g=s.map(v=>B(e,"keywords",v,v.keyword)),p=$(e.label("keywords"),g);p&&h.append(p);let f=d.map(v=>B(e,"recent",v,v.keyword)),y=$(e.label("recent"),f);y&&h.append(y);let P=a.map(v=>B(e,"categories",v,String(v.name),typeof v.count=="number"?String(v.count):void 0)),L=$(e.label("categories"),P);L&&h.append(L);let F=i("div",{class:"canvas"});F.append(S(e,o,5)),l.append(F),e.splitMobileResize&&l.append(Ae()),l.append(h),r.append(l);}else if(n==="cinema-grid"){let u=ze(e);u&&r.append(u),r.append(D(e)),a.length&&r.append(O(e,a)),s.length&&r.append(te(e,s));let l=i("div",{class:"canvas"});l.append(S(e,o,6)),r.append(l);}else if(n==="command-bar"){let u=e.res.meta?.total??0,l=i("div",{class:"command-header",part:"meta-bar"});l.append(i("div",{class:"query-display",text:`"${e.lastQuery}"`})),l.append(i("span",{class:"result-pill",text:`${u} products`}));let h=i("a",{class:"view-all-link",part:"view-all",attrs:{href:e.viewAllHref},text:"View all \u2192"});if(h.addEventListener("click",p=>{p.preventDefault(),e.onViewAll();}),l.append(h),r.append(l),e.res.did_you_mean){let p=i("div",{class:"chip-row"});p.append(i("span",{class:"filter-label",text:"Did you mean"}));let f=i("button",{class:"chip",type:"button",text:e.res.did_you_mean}),y=e.rows.length;e.rows.push({block:"did_you_mean",data:{value:e.res.did_you_mean},value:e.res.did_you_mean}),f.addEventListener("click",()=>e.onRowClick(y)),p.append(f),r.append(p);}s.length&&r.append(te(e,s,"Related")),a.length&&r.append(O(e,a));let g=i("div",{class:"canvas"});g.append(S(e,o,5)),r.append(g);}else if(n==="magazine"){r.append(D(e)),a.length&&r.append(O(e,a));let u=i("div",{class:"canvas"}),l=o.slice(0,3),h=o.slice(3);if(l.length){u.append(i("div",{class:"group-title",part:"group-title",text:"Best matches"}));let g=i("div",{class:"hero-row",part:"hero-row"});l.forEach((p,f)=>{g.append(Ie(e,p,f,f===0?"Top match":void 0));}),u.append(g);}h.length?(u.append(i("div",{class:"group-title",part:"group-title",text:"More results"})),u.append(S(e,h,6))):l.length||u.append(S(e,o,6)),r.append(u);}let c=Pe(e);return c&&r.append(c),r.append(i("slot",{attrs:{name:"footer"}})),r}function M(n){return n!=="classic"&&n!==""}var ue=["recent","did_you_mean","keywords","trending","products","categories"],De={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing.",cors_blocked:T},pe=`
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
  ${re}
`,I=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let r=this.map.keys().next().value;if(r===void 0)break;this.map.delete(r);}}clear(){this.map.clear();}},z=class extends A{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","limit","cache-size","view-all-href","lang","anchor","anchor-offset","anchor-min-width","layout","split-mobile-resize","product-title-tooltip","typeahead-fallback-url","serp-passthrough","show-branding","brand-url","brand-logo-url","suppress-legacy"]}current=null;loading=false;corsBlocked=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;fetchToken=0;inflight=null;cache=new I(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorResizeRaf=null;lastAnchorKey="";anchorApplied=false;suppressedLegacyEls=new WeakSet;legacySuppressionRetryHandler=null;splitResizeCleanup=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),this.scheduleLegacySuppressionRetries(),this.scheduleRender();}disconnectedCallback(){this.unscheduleLegacySuppressionRetries(),this.unbindSplitMobileResize(),this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"||e==="layout"||e==="split-mobile-resize"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):e==="serp-passthrough"?this.refreshFromPassthroughChange():this.scheduleRender();}refreshFromPassthroughChange(){let e=this.lastQuery.trim();if(!e){this.scheduleRender();return}this.fetch(e);}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;this.debouncedAt===e&&this.debounced||(this.debouncedAt=e,this.debounced=Q(t=>{this.fetch(t);},e));}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new I(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let r=document.getElementById(e);if(r){this.subscribed=r,r.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let r=document.getElementById(t);r instanceof HTMLInputElement&&(this.inputEl=r,r.addEventListener("input",this.onPlainInput),r.addEventListener("focus",this.onPlainFocus),r.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}bindAnchorListeners(){this.anchorScrollHandler=()=>this.scheduleApplyAnchor(),this.anchorResizeHandler=()=>this.scheduleApplyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let r=this.inputEl??this.subscribed;r&&(t===r||r.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorResizeRaf!==null&&(cancelAnimationFrame(this.anchorResizeRaf),this.anchorResizeRaf=null),this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}scheduleApplyAnchor(){typeof window>"u"||this.anchorResizeRaf===null&&(this.anchorResizeRaf=requestAnimationFrame(()=>{this.anchorResizeRaf=null,this.applyAnchor();}));}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let r=t.getBoundingClientRect();if(r.width<=0&&r.height<=0){this.style.visibility="hidden";return}let o=parseInt(this.getAttribute("anchor-offset")??"4",10),s=Number.isFinite(o)?o:4,a=this.getAttribute("anchor-min-width"),d=M(this.layoutMode()),m=a===null?d?960:480:Math.max(0,parseInt(a,10)||0),c=typeof window<"u"&&window.innerWidth>0?window.innerWidth:Math.max(r.width,m),u=Math.min(c*.96,1440),l=d?Math.max(r.width,Math.min(Math.max(m,r.width),u)):Math.max(r.width,m),h=d?u:Math.max(0,c-r.left-8),g=d?Math.min(l,h):Math.max(r.width,Math.min(l,h)),p=d?Math.max(8,(c-g)/2):r.left,f=[p,g,r.bottom,s,d?1:0].join("|");if(this.anchorApplied&&f===this.lastAnchorKey){this.style.visibility==="hidden"&&(this.style.visibility="");return}this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${r.bottom+s}px`,this.style.left=`${p}px`,this.style.width=`${g}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true,this.lastAnchorKey=f;}clearAnchor(){this.anchorApplied&&(this.lastAnchorKey="",this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(o=>o.trim()).filter(Boolean),r=this.inputEl;if(r)for(let o of t)o==="jquery-ui"?this.suppressJqueryUiAutocomplete(r):o==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(r);}suppressJqueryUiAutocomplete(e){let r=window.jQuery;if(!r||!r.ui||!r.ui.autocomplete)return;let o=r(e);if(o.data("ui-autocomplete")){try{o.autocomplete("close");}catch{}try{o.autocomplete("destroy");}catch{}}let s=o.attr("aria-owns");if(s){let a=document.getElementById(s);a&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));}document.querySelectorAll("ul.ui-autocomplete").forEach(a=>{let d=a.getAttribute("id");if(!d)return;document.querySelector(`[aria-owns="${CSS.escape(d)}"]`)===e&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));});}scheduleLegacySuppressionRetries(){this.unscheduleLegacySuppressionRetries();let e=()=>{this.applyLegacySuppression();};this.legacySuppressionRetryHandler=e,setTimeout(e,0),setTimeout(e,50),document.readyState==="loading"&&document.addEventListener("DOMContentLoaded",e,{once:true}),window.addEventListener("load",e,{once:true});}unscheduleLegacySuppressionRetries(){this.legacySuppressionRetryHandler=null;}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(r=>{r.style.display="none",this.suppressedLegacyEls.add(r);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),r=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<r){this.lastQuery=t,this.current=null,this.loading=false,this.corsBlocked=false,this.inflight?.abort(),this.scheduleRender();return}this.lastQuery=t,this.corsBlocked=false;let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}cacheKey(e){let t=this.getSerpPassthrough(),r=t?JSON.stringify(t):"";return `${e.toLowerCase()}\0${r}`}getSerpPassthrough(){let e=this.getAttribute("serp-passthrough");if(!e)return null;try{let t=JSON.parse(e);if(t&&typeof t=="object"&&!Array.isArray(t))return t}catch{}return null}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.fetchToken;try{let o=await this.getClient(),s=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.layoutMode(),d=M(a)?C(a,s):s,m=this.getSessionId(),c={q:e,limit:d,complete:!0};m&&(c.session_id=m);let u=this.getSerpPassthrough();u&&(c.serp_passthrough=u);let l=await o.suggest(c);if(r!==this.fetchToken||t.signal.aborted)return;if(this.isEmpty(l)){let h=await this.mergeTypeaheadFallback(e,d,l,t);h&&(l=h);}this.current=l,this.loading=!1,this.cache.set(this.cacheKey(e),l),this.emitOpen(e),this.isEmpty(l)&&w(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl}),this.scheduleRender();}catch(o){if(r!==this.fetchToken||t.signal.aborted)return;this.corsBlocked=x(o),this.current=null,this.loading=false,this.corsBlocked?(w(this,"seekmodo-suggest:cors-blocked",{q:e}),console.warn("[seekmodo-suggest] blocked by CORS or network policy",o)):console.warn("[seekmodo-suggest] fetch failed",o),this.scheduleRender();}}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}currentSearchEventId(){let e=this.current?.meta?.search_event_id;if(typeof e=="number"&&Number.isFinite(e)&&e>0)return Math.trunc(e);if(typeof e=="string"&&e!==""){let t=parseInt(e,10);if(Number.isFinite(t)&&t>0)return t}}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}typeaheadFallbackUrl(){let e=(this.getAttribute("typeahead-fallback-url")??"").trim();return e.length>0?e:null}async mergeTypeaheadFallback(e,t,r,o){let s=this.typeaheadFallbackUrl();if(!s||typeof fetch!="function")return null;try{let a=s.includes("?")?"&":"?",d=await fetch(`${s}${a}q=${encodeURIComponent(e)}&max=${encodeURIComponent(String(t))}`,{credentials:"same-origin",signal:o.signal});if(!d.ok)return null;let m=await d.json(),c=Array.isArray(m?.rows)?m.rows:[];if(c.length===0)return null;let u=c.slice(0,t).map((h,g)=>{let p=h,f=p.id!==void 0&&p.id!==null?String(p.id):String(g),y=typeof p.name=="string"&&p.name!==""?p.name:typeof p.title=="string"?p.title:"",P=typeof p.url=="string"&&p.url!==""?p.url:typeof p.permalink=="string"?p.permalink:void 0,L=typeof p.image_url=="string"&&p.image_url!==""?p.image_url:typeof p.thumbnail_url=="string"?p.thumbnail_url:void 0;return {id:f,name:y,url:P,image_url:L,post_type:typeof p.post_type=="string"?p.post_type:void 0,excerpt:typeof p.excerpt=="string"?p.excerpt:void 0}}),l={...r.meta??{},typeahead_fallback:!0};return (l.total??0)<=0&&(l.total=u.length),l.counts={...l.counts??{},products:u.length},{...r,products:u,meta:l}}catch{return null}}blocks(){let e=this.getAttribute("blocks");if(!e)return ue;let t=e.split(",").map(r=>r.trim()).filter(r=>["recent","trending","did_you_mean","keywords","products","categories"].includes(r));return t.length>0?t:ue}label(e){return De[e]??e}layoutMode(){let e=(this.getAttribute("layout")??U).trim();return e==="classic"||e==="cinema-grid"||e==="command-bar"||e==="magazine"||e==="split-rail"?e:U}showBrandingFlag(){let e=(this.getAttribute("show-branding")??"true").trim().toLowerCase();return e!=="false"&&e!=="0"&&e!=="no"}splitMobileResizeEnabled(){let e=(this.getAttribute("split-mobile-resize")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}productTitleTooltipEnabled(){let e=(this.getAttribute("product-title-tooltip")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}unbindSplitMobileResize(){this.splitResizeCleanup?.(),this.splitResizeCleanup=null;}bindSplitMobileResizeIfNeeded(e,t){if(this.unbindSplitMobileResize(),e!=="split-rail"||!this.splitMobileResizeEnabled())return;let r=t.querySelector(".split-body");r instanceof HTMLElement&&(this.splitResizeCleanup=ne(r));}dismiss(){this.current===null&&!this.loading||(w(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.scheduleRender());}emitOpen(e){w(this,"seekmodo-suggest:open",{q:e});}buildViewAllHref(e){let r=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(e));return J(r,"seekmodo_skip_category_redirect","1")}navigateViewAll(){let e=this.current?.meta?.total??0;w(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:e}),window.location.assign(this.buildViewAllHref(this.lastQuery));}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,r)=>{r===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];if(!t)return;let r=this.currentSearchEventId();w(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id,position:e+1,...r!==void 0?{search_event_id:r}:{}});}render(){this.unbindSplitMobileResize();let e=document.createElement("style");if(e.textContent=pe,this.loading&&this.current===null&&!this.corsBlocked){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.corsBlocked){W(this.root,pe,{message:this.label("cors_blocked")}),this.rows=[],this.active=-1,this.applyAnchor();return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(this.current)){let c=i("slot",{attrs:{name:"empty"}}),u=i("div",{class:"empty",text:this.label("empty")});c.append(u);let l=i("div",{class:"wrap",part:"wrap"});l.append(c),this.root.replaceChildren(e,l),this.rows=[],this.active=-1;return}let t=this.productTitleTooltipEnabled()?"wrap product-title-tooltip":"wrap",r=i("div",{class:t,part:"wrap"});r.append(i("slot",{attrs:{name:"header"}}));let o=[],s=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.layoutMode();if(M(a)){let c=this.buildViewAllHref(this.lastQuery),u=ce(a,{res:this.current,lastQuery:this.lastQuery,limit:C(a,s),label:l=>this.label(l),rows:o,onRowClick:l=>this.activateRow(l),onViewAll:()=>this.navigateViewAll(),viewAllHref:c,showBranding:this.showBrandingFlag(),brandUrl:this.getAttribute("brand-url")??"https://seekmodo.com",brandLogoUrl:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",formatPrice:H,splitMobileResize:this.splitMobileResizeEnabled(),productTitleTooltip:this.productTitleTooltipEnabled()});this.rows=o,this.active=-1,this.root.replaceChildren(e,u),this.bindSplitMobileResizeIfNeeded(a,u),this.applyAnchor();return}let d=this.blocks();for(let c of d){let u=this.renderBlock(c,this.current,s,o);u&&r.append(u);}this.rows=o,this.active=-1;let m=this.current.meta?.total??0;if(m>0&&this.lastQuery.length>0){let c=this.buildViewAllHref(this.lastQuery),u=i("a",{class:"view-all",part:"view-all",attrs:{href:c},text:this.label("view_all").replace("{total}",String(m))});u.addEventListener("click",l=>{l.preventDefault(),this.navigateViewAll();}),r.append(u);}if(r.append(i("slot",{attrs:{name:"footer"}})),this.showBrandingFlag()){let c=i("a",{class:"brand-footer",part:"brand-footer",attrs:{href:this.getAttribute("brand-url")??"https://seekmodo.com",target:"_blank",rel:"noopener noreferrer"}});c.append(i("span",{class:"brand-by",text:"Powered by "})),c.append(i("img",{class:"brand-logo",part:"brand-logo",attrs:{src:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",alt:"Seekmodo",height:"16"}})),r.append(c);}this.root.replaceChildren(e,r),this.applyAnchor();}renderSkeleton(){let e=i("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let r=i("div",{class:"row",part:"row skeleton"});r.append(i("div",{class:"thumb",part:"thumb"}));let o=i("div",{class:"name"});o.append(i("span",{class:"name-title"})),o.append(i("span",{class:"name-meta"})),r.append(o),e.append(r);}return e}renderBlock(e,t,r,o){if(e==="did_you_mean"){let d=t.did_you_mean;if(!d)return null;let m=i("div",{class:"group",part:"group did-you-mean"});m.append(i("slot",{attrs:{name:"did_you_mean"}}));let c=i("div",{class:"did-you-mean"});c.append(document.createTextNode(this.label("did_you_mean")+" "));let u=i("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:d});return u.addEventListener("click",()=>{let l=this.currentSearchEventId();w(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:d},q:this.lastQuery,value:d,...l!==void 0?{search_event_id:l}:{}});}),c.append(u),m.append(c),m}let s=this.blockData(e,t,r);if(s.length===0)return null;let a=i("div",{class:"group",part:"group",attrs:{"data-block":e}});return a.append(i("slot",{attrs:{name:e}})),a.append(i("div",{class:"group-title",part:"group-title",text:this.label(e)})),s.forEach((d,m)=>{let c={block:e,data:d,value:this.rowValue(e,d),id:this.rowId(e,d)};o.push(c);let u=o.length-1,l=window.seekmodoSuggest?.renderRow?.(c.data,e),h;l instanceof HTMLElement?(h=l,h.classList.add("row")):typeof l=="string"&&l.length>0?(h=i("button",{class:"row",part:"row",type:"button"}),h.innerHTML=l):h=this.renderRowDefault(e,d,m),h.setAttribute("data-seekmodo-surface","suggest"),h.setAttribute("data-seekmodo-block",e),h.setAttribute("data-seekmodo-pos",String(u)),c.id&&h.setAttribute("data-seekmodo-id",c.id),h.addEventListener("click",()=>this.activateRow(u)),a.append(h);}),a}blockData(e,t,r){switch(e){case "recent":return (t.recent??[]).slice(0,r);case "trending":return (t.trending??[]).slice(0,r);case "keywords":return (t.keywords??[]).slice(0,r);case "products":return (t.products??[]).slice(0,r);case "categories":return (t.categories??[]).slice(0,r);default:return []}}rowValue(e,t){let r=t;return e==="recent"||e==="trending"||e==="keywords"?String(r.keyword??""):e==="products"?String(r.name??r.title??""):e==="categories"?String(r.name??""):""}rowId(e,t){if(e!=="products")return;let r=t.id;return r!==void 0?String(r):void 0}renderRowDefault(e,t,r){let o=i("button",{class:"row",part:"row",type:"button"});if(e==="products"){let s=t,a=E(s),d=String(s.name??s.title??"").trim(),m=this.productTitleTooltipEnabled()&&d?d:"";a?o.append(i("img",{class:"thumb",part:"thumb",attrs:{src:a,alt:m,loading:"eager",decoding:"async"}})):o.append(i("div",{class:"thumb",part:"thumb"}));let c=i("div",{class:"name",part:"name"}),u=i("span",{class:"name-title",text:d});this.productTitleTooltipEnabled()&&d&&u.setAttribute("title",d),c.append(u);let l=[s.brand?String(s.brand):"",s.sku??s.model??s.ez_number??""].filter(Boolean);l.length>0&&c.append(i("span",{class:"name-meta",text:l.join(" \xB7 ")})),o.append(c);let h=this.renderPrice(s);return h&&o.append(h),o}if(e==="categories"){let s=t,a=i("div",{class:"name",part:"name",text:s.name});return o.append(a),typeof s.count=="number"&&s.count>0&&o.append(i("span",{class:"badge",part:"badge",text:String(s.count)})),o}if(e==="recent"||e==="trending"||e==="keywords"){let s=t,a=i("div",{class:"name",part:"name",text:String(s.keyword)});return o.append(a),e==="trending"&&typeof s.search_count=="number"&&o.append(i("span",{class:"badge",part:"badge",text:String(s.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=i("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(t.append(i("del",{text:H(e.price,e.currency)})),t.append(document.createTextNode(H(e.sale_price,e.currency)))):t.append(document.createTextNode(H(e.price,e.currency))),t}};function H(n,e){try{return new Intl.NumberFormat(void 0,{style:"currency",currency:e??"USD",maximumFractionDigits:2}).format(n)}catch{return String(n)}}Y();typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",z);
exports.SeekmodoSuggest=z;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map