var SeekmodoSuggest=(function(exports){'use strict';var A=class extends Error{status;body;tool;constructor(n,e,t,r){super(n),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=r;}},te=class extends A{constructor(n,e,t){super(`Seekmodo auth failed (HTTP ${n})`,n,e,t),this.name="SeekmodoAuthError";}},G=class extends A{code;bucket;limit;used;constructor(n,e){super("Seekmodo over quota (HTTP 402)",402,n,e),this.name="SeekmodoQuotaError";let t=n??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},De=class extends A{constructor(n,e,t){super(`Seekmodo server error (HTTP ${n})`,n,e,t),this.name="SeekmodoServerError";}},Oe=class extends A{constructor(n,e,t){super(`Seekmodo request rejected (HTTP ${n})`,n,e,t),this.name="SeekmodoRequestError";}},re=class extends A{constructor(n,e){super(`Seekmodo network failure${n instanceof Error?`: ${n.message}`:""}`,0,n,e),this.name="SeekmodoNetworkError";}};function I(n,e){if(n instanceof re)return I(n.body);if(n instanceof TypeError){let t=n.message.toLowerCase();return t.includes("failed to fetch")||t.includes("networkerror")||t.includes("network request failed")||t.includes("load failed")}if(n instanceof Error){let t=n.message.toLowerCase();return t.includes("cors")||t.includes("access-control-allow-origin")||t.includes("cross-origin")}return  false}var $e="https://gateway.seekmodo.com",je=8e3,Ke=class{config;cachedToken=null;constructor(n){if(!n.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof n.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:n.tenantId,getToken:n.getToken,baseUrl:(n.baseUrl??$e).replace(/\/+$/,""),fetch:n.fetch??globalThis.fetch.bind(globalThis),timeoutMs:n.timeoutMs??je,signal:n.signal,onError:n.onError,getRegion:n.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(n,e,t={}){try{return await this.callOnce(n,e,t,!1)}catch(r){if(r instanceof te){this.clearTokenCache();try{return await this.callOnce(n,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:n}),o}}throw this.config.onError?.(r,{tool:n}),r}}async callOnce(n,e,t,r){let o=await this.resolveToken(r),i=`${this.config.baseUrl}/v1/${encodeURIComponent(n)}`,s=new AbortController,l=t.timeoutMs??this.config.timeoutMs,d=setTimeout(()=>s.abort(),l),c=()=>s.abort();this.config.signal?.addEventListener("abort",c,{once:true}),t.signal?.addEventListener("abort",c,{once:true});let m={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let f=await this.config.getRegion();typeof f=="string"&&f.length>0&&(m["Seekmodo-Region"]=f);}catch{}let u;try{u=await this.config.fetch(i,{method:"POST",headers:m,body:JSON.stringify(e),signal:s.signal});}catch(f){throw new re(f,n)}finally{clearTimeout(d),this.config.signal?.removeEventListener("abort",c),t.signal?.removeEventListener("abort",c);}let p=await u.text(),h=p?Ue(p):null;if(u.status===401||u.status===403)throw new te(u.status,h,n);if(u.status===402)throw new G(h,n);if(u.status>=500)throw new De(u.status,h,n);if(!u.ok)throw new Oe(u.status,h,n);return h}async resolveToken(n){let e=Date.now();if(!n&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function Ue(n){try{return JSON.parse(n)}catch{return n}}var ne=class{transport;recommend;bundle;constructor(n){this.transport=new Ke(n),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(n,e){return this.transport.call("search",{...n},e??{})}suggest(n,e){return this.transport.call("suggest",{...n},e??{})}searchByImage(n,e){return this.transport.call("search.byImage",{...n},e??{})}chat(n,e){return this.transport.call("chat",{...n},e??{})}ask(n,e){return this.transport.call("ask",{...n},e??{})}event(n,e){return this.transport.call("events",{...n},e??{})}};var H=null,w=null;function B(n){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${n}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function oe(){return H!==null||(H=qe()),H}async function qe(){let n=B("seekmodo:tenant");if(!n)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=B("seekmodo:token"),t=B("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(w={token:e,expiresAt:Date.now()+3e4});let r=B("seekmodo:gateway")??void 0;return new ne({tenantId:n,baseUrl:r,getRegion:()=>Qe(),getToken:async()=>{let o=Date.now();if(w&&w.expiresAt-1e4>o)return {token:w.token,expiresAt:w.expiresAt};if(!t){if(w)return {token:w.token,expiresAt:w.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let i=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!i.ok)throw new Error(`seekmodo:refresh route returned HTTP ${i.status}`);let s=await i.json();if(!s.token||typeof s.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return w={token:s.token,expiresAt:s.expires_at*1e3},{token:w.token,expiresAt:w.expiresAt}}})}var Ne="seekmodo_region";function Ve(n){if(typeof n!="string")return null;let e=n.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function Qe(){if(typeof document>"u")return null;let n=document.cookie??"";if(n.length===0)return null;let e=Ne.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(n);if(!t)return null;try{return Ve(decodeURIComponent(t[1]))}catch{return null}}var F=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render(),this.afterRender();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}afterRender(){}async getClient(){return oe()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function a(n,e,t){let r=document.createElement(n);if(e){for(let[o,i]of Object.entries(e))if(!(i==null||i===false))if(o==="class")r.className=String(i);else if(o==="part")r.setAttribute("part",String(i));else if(o==="text")r.textContent=String(i);else if(o==="html")r.innerHTML=String(i);else if(o==="attrs"&&typeof i=="object"&&i!==null)for(let[s,l]of Object.entries(i))r.setAttribute(s,l);else r.setAttribute(o,String(i));}return r}function D(n,e){let t=null;return (...r)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>n(...r),e);}}function y(n,e,t){n.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var O="Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";function ae(n,e,t){let r=document.createElement("style");r.textContent=e;let o=a("div",{class:"wrap seekmodo-cors-blocked",part:"wrap cors-blocked",attrs:{role:"status"}});o.append(a("div",{class:"cors-notice",part:"cors-notice",text:t?.message??O})),n.replaceChildren(r,o);}var ie="seekmodo-cors-notice";function se(n,e){if(!n||typeof document>"u")return;let t=n.closest(".search-form")??n.parentNode;if(!t)return;t.style.position=t.style.position||"relative";let r=t.querySelector(`.${ie}`);if(!r){r=document.createElement("div"),r.className=ie,r.setAttribute("role","status"),r.style.cssText=["position:absolute","top:100%","left:0","right:0","z-index:10050","display:none","background:#fff8e6","border:1px solid #f0c040","border-top:none","padding:8px 12px","font-size:13px","line-height:1.4","color:#5c4a00","box-shadow:0 4px 12px rgba(0,0,0,.08)"].join(";"),t.appendChild(r);let o=()=>{if(!r)return;let i=(n.value||"").trim();r.style.display=i.length>=2?"block":"none";};n.addEventListener("input",o),n.addEventListener("focus",o);}r.textContent=e??O;}function le(){typeof window>"u"||(window.seekmodoShowCorsNotice=se,window.seekmodoScriptLoadFailed=(n,e)=>{let t=n??document.querySelectorAll('input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]');for(let r=0;r<t.length;r++){let o=t[r];o instanceof HTMLInputElement&&se(o,e);}});}function de(n,e){if(String(e?.vertical??"").toLowerCase()==="content")return  false;let r=String(e?.collection??"");if(/_posts$/i.test(r))return  false;let o=n??[];return o.some(s=>{let l=s.price,d=s.sale_price;return typeof l=="number"&&Number.isFinite(l)||typeof d=="number"&&Number.isFinite(d)})?true:!(o.length>0&&o.every(s=>typeof s.post_type=="string"&&String(s.post_type).trim()!=="")||o.length>0||/_posts$/i.test(r))}function ce(n){return n.trim().toUpperCase()==="GBP"?[{min:0,max:15},{min:15,max:25},{min:25,max:50},{min:50,max:null}]:[{min:0,max:20},{min:20,max:50},{min:50,max:100},{min:100,max:null}]}function $(n){return `${n.min}:${n.max??""}`}function j(n,e){return !n||!e?n===e:n.min===e.min&&n.max===e.max}function ue(n){return n.max===null?`price:>=${n.min}`:`price:>=${n.min} && price:<=${n.max}`}function pe(n,e){let t=(n??"").trim();return t?`(${t}) && (${e})`:e}function ge(n,e){return n.max===null?`${e(n.min)}+`:`${e(n.min)} \u2013 ${e(n.max)}`}function he(n,e){try{let t=typeof window<"u"?window.location.origin:"http://localhost",r=new URL(n,t),o=e??[...r.searchParams.keys()];for(let i of o){let s=r.searchParams.get(i);(s===null||s==="")&&r.searchParams.delete(i);}return /^https?:\/\//i.test(n)?r.toString():`${r.pathname}${r.search}${r.hash}`}catch{return n}}function T(n){for(let e of ["image_url","image","thumbnail_url"]){let t=n[e];if(typeof t=="string"&&t.trim()!=="")return t.trim()}}function me(n,e,t){try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(n,r);return o.searchParams.set(e,t),/^https?:\/\//i.test(n)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=n.includes("?")?"&":"?";return `${n}${r}${encodeURIComponent(e)}=${encodeURIComponent(t)}`}}function Ge(n,e,t){let r=(n??"").toLowerCase(),o=(e??"").toLowerCase();if(o.startsWith("http"))try{o=new URL(o).pathname;}catch{o="";}return r==="page"&&/\/tools\/[^/]+\/?$/.test(o)?t?.("tool")??"Tool":r==="page"&&/\/tools\/?$/.test(o)?t?.("tools")??"Tools":r==="page"?t?.("page")??"Page":r==="post"?t?.("article")??"Article":""}function S(n,e){let t=typeof n.post_type=="string"?n.post_type.toLowerCase():"",r=typeof n.url=="string"?n.url:typeof n.permalink=="string"?n.permalink:"";return {postType:t,label:Ge(t,r,e)}}var X="split-rail",We=15;function R(n,e){let r={"split-rail":5,"command-bar":5,"cinema-grid":6,magazine:6,classic:5}[n]??5,o=Math.max(e,r*3);return Math.min(We,o)}var we=`
  .wrap.wide { padding: 0; overflow-x: hidden; overflow-y: auto; }
  .wrap.wide.split-rail-panel {
    overflow: hidden; display: flex; flex-direction: column;
    max-height: var(--_max-height);
  }
  .meta-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.75rem; padding: 0.65rem 1rem; border-bottom: 1px solid var(--_border);
    background: var(--_meta-bg); color: var(--_meta-color); font-size: 0.8125rem;
  }
  .meta-bar .query { color: var(--_accent); font-weight: 600; }
  .meta-bar .count { color: var(--_meta-count-color); }
  .meta-bar .view-all,
  .meta-bar .view-all-cta {
    border-top: none; text-align: right; font-weight: 600;
    background: var(--_cta-bg); color: var(--_cta-color);
    border-radius: var(--_cta-radius); padding: var(--_cta-padding);
    text-decoration: var(--_cta-decoration);
    border: var(--_cta-border-width) solid var(--_cta-border-color);
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
    border-right: 1px solid var(--_border); background: var(--_rail-bg);
    padding: 0.5rem 0.35rem; align-self: stretch;
    min-height: 0; overflow-y: auto; overscroll-behavior: contain;
  }
  .rail .rail-section {
    padding: 0.15rem 0 0.35rem;
  }
  .rail .rail-section + .rail-section {
    margin-top: 0.4rem;
    padding-top: 0.55rem;
    border-top: 1px solid var(--_border);
  }
  .rail .rail-section .group-title {
    padding: 0.1rem 0.55rem 0.35rem;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.08em;
  }
  .rail .row { padding: 0.4rem 0.55rem; font-size: 0.8125rem; }
  .rail .row.is-selected,
  .rail .row[aria-pressed="true"] {
    background: var(--_bg);
    font-weight: 600;
    box-shadow: inset 3px 0 0 var(--_accent, #2563eb);
  }
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
    background: var(--_header-bg); color: var(--_header-color);
  }
  .command-header .query-display { font-size: 1rem; font-weight: 600; flex: 1; }
  .command-header .result-pill {
    padding: 0.25rem 0.6rem; background: rgba(255,255,255,0.15);
    border-radius: 999px; font-size: 0.75rem;
  }
  .command-header .view-all-link {
    color: var(--_header-color); font-size: 0.75rem; font-weight: 600; text-decoration: none;
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
  .hero-card:hover, .hero-card.active { border-color: var(--_accent); }
  .hero-card .thumb, .hero-card .thumb-empty {
    width: 90px; height: 90px; object-fit: contain;
    border-radius: calc(var(--_radius) - 0.2rem); background: var(--_row-active);
  }
  .hero-badge {
    display: inline-block; width: fit-content; padding: 0.1rem 0.4rem;
    font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
    background: var(--_accent); color: var(--_accent-contrast); border-radius: 3px; margin-bottom: 0.2rem;
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
    background: var(--_dym-bg); border-bottom: 1px solid var(--_dym-border);
  }
  .did-you-mean-bar .swap {
    border: var(--_dym-swap-border-width) solid var(--_dym-swap-border-color);
    background: var(--_dym-swap-bg); font: inherit; font-weight: 600;
    color: var(--seekmodo-suggest-dym-swap-color, var(--_accent));
    border-radius: var(--_dym-swap-radius);
    text-decoration: var(--_dym-swap-decoration); cursor: pointer;
    padding: var(--_dym-swap-padding);
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
      outline: 2px solid var(--_accent); outline-offset: -2px;
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
`,fe=.15,be=.85,Ye=.28,ye="seekmodo:split-rail-mobile-ratio-v3",Je="(max-width: 900px)",Xe=1-Ye;function Ze(){return a("div",{class:"split-divider",part:"split-divider",html:'<svg class="split-divider-icon" viewBox="0 0 36 12" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="1" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="5" width="24" height="2" rx="1" fill="currentColor"/><rect x="6" y="9" width="24" height="2" rx="1" fill="currentColor"/></svg>'})}function ke(n){let e=n.querySelector(".split-divider");if(!e)return ()=>{};let t=window.matchMedia(Je),r=Xe;try{let h=sessionStorage.getItem(ye);if(h){let f=parseFloat(h);f>=fe&&f<=be&&(r=f);}}catch{}let o=h=>{r=Math.min(be,Math.max(fe,h)),n.style.setProperty("--split-rail-bottom-grow",String(r)),n.style.setProperty("--split-rail-top-grow",String(1-r));},i=()=>{n.style.removeProperty("--split-rail-top-grow"),n.style.removeProperty("--split-rail-bottom-grow");},s=()=>{t.matches?o(r):i();};s();let l=false,d=0,c=r,m=h=>{!t.matches||h.button!==0||(l=true,d=h.clientY,c=r,e.classList.add("is-dragging"),e.setPointerCapture(h.pointerId),h.preventDefault());},u=h=>{if(!l)return;let g=n.getBoundingClientRect().height-e.offsetHeight;g<=0||o(c+(h.clientY-d)/g);},p=h=>{if(l){l=false,e.classList.remove("is-dragging");try{e.releasePointerCapture(h.pointerId);}catch{}try{sessionStorage.setItem(ye,String(r));}catch{}}};return e.setAttribute("role","separator"),e.setAttribute("aria-orientation","horizontal"),e.setAttribute("aria-label","Resize product and suggestion panels"),e.setAttribute("tabindex","0"),e.addEventListener("pointerdown",m),e.addEventListener("pointermove",u),e.addEventListener("pointerup",p),e.addEventListener("pointercancel",p),t.addEventListener("change",s),()=>{e.removeEventListener("pointerdown",m),e.removeEventListener("pointermove",u),e.removeEventListener("pointerup",p),e.removeEventListener("pointercancel",p),t.removeEventListener("change",s),e.classList.remove("is-dragging"),i();}}var et="https://seekmodo.com/email-assets/seekmodo-lockup.png";function _e(n,e){return n.resolveThumbSrc?n.resolveThumbSrc(e):e}function Se(n){return String(n.name??n.title??"").trim()}function Re(n,e,t){e.productTitleTooltip&&t&&n.setAttribute("title",t);}function xe(n,e){return n.productTitleTooltip&&e?e:""}function Ee(n,e,t){let r={block:"products",data:e,value:String(e.name??e.title??""),id:e.id!==void 0?String(e.id):void 0},o=n.rows.length;return n.rows.push(r),o}function tt(n,e,t){let r=a("div",{class:"thumb-frame",part:"thumb-frame"}),{postType:o}=S(n,e.label);o&&r.setAttribute("data-post-type",o);let i=T(n);if(i)r.append(a("img",{class:"thumb",part:"thumb",attrs:{src:_e(e,i),"data-src":i,alt:xe(e,t),loading:"eager",decoding:"async"}}));else {let s=a("div",{class:"thumb-empty",part:"thumb thumb--empty",text:Le(o)});o&&s.setAttribute("data-content-type",o),r.append(s);}return r}function Le(n){return n==="page"?"P":n==="post"?"A":"\xB7"}function rt(n,e,t){let{postType:r}=S(n,e.label),o=T(n);if(o)return a("img",{class:"thumb",part:"thumb",attrs:{src:_e(e,o),"data-src":o,alt:xe(e,t),loading:"eager",decoding:"async"}});let i=a("div",{class:"thumb-empty",part:"thumb thumb--empty",text:Le(r)});return r&&i.setAttribute("data-content-type",r),i}function Ae(n,e,t="card-price"){if(n.price===void 0||n.price===null)return null;let r=a("div",{class:t,part:"price"});return n.on_sale&&typeof n.sale_price=="number"?(r.append(a("del",{text:e.formatPrice(n.price,n.currency)})),r.append(document.createTextNode(e.formatPrice(n.sale_price,n.currency)))):r.append(document.createTextNode(e.formatPrice(n.price,n.currency))),r}function Te(n,e,t){n.classList.add("row"),n.setAttribute("data-seekmodo-surface","suggest"),n.setAttribute("data-seekmodo-block","products"),n.setAttribute("data-seekmodo-pos",String(t));let r=e.rows[t];r?.id&&n.setAttribute("data-seekmodo-id",r.id),n.addEventListener("click",()=>e.onRowClick(t));}function nt(n,e,t,r=false){let o=Ee(n,e),i=Se(e),{postType:s,label:l}=S(e,n.label),d=a("button",{class:"product-card",part:"row",type:"button"});s&&d.setAttribute("data-post-type",s),d.append(tt(e,n,i));let c=a("span",{class:"card-title",part:"name",text:i});Re(c,n,i),d.append(c),l&&d.append(a("span",{class:"card-meta",part:"card-meta",text:l}));let m=Ae(e,n,"card-price");return m&&d.append(m),Te(d,n,o),d}function ot(n,e,t,r){let o=Ee(n,e),i=Se(e),s=a("button",{class:"hero-card",part:"row",type:"button"});r&&s.append(a("span",{class:"hero-badge",text:r})),s.append(rt(e,n,i));let l=a("div",{class:"hero-info"}),d=a("span",{class:"card-title",part:"name",text:i});Re(d,n,i),l.append(d);let c=Ae(e,n);return c&&l.append(c),s.append(l),Te(s,n,o),s}function Y(n){let e=n.res.meta?.total??0,t=a("div",{class:"meta-bar",part:"meta-bar"}),r=a("div"),o=n.label("results_for").replace(/\{total\}/g,String(e));r.append(a("span",{class:"count",text:o})),r.append(a("span",{class:"query",text:`"${n.lastQuery}"`})),t.append(r);let i=a("a",{class:"view-all view-all-cta",part:"view-all",attrs:{href:n.viewAllHref},text:n.label("view_all").replace("{total}",String(e))});return i.addEventListener("click",s=>{s.preventDefault(),n.onViewAll();}),t.append(i),t}function it(n){let e=n.res.did_you_mean;if(!e)return null;let t=a("div",{class:"did-you-mean-bar",part:"did-you-mean"});t.append(document.createTextNode(n.label("showing_results_for").replace(/\{query\}/g,n.lastQuery)));let r=a("button",{class:"swap",type:"button",text:e}),o=n.rows.length;return n.rows.push({block:"did_you_mean",data:{value:e},value:e}),r.addEventListener("click",()=>n.onRowClick(o)),t.append(r),t.append(document.createTextNode("?")),t}function J(n,e){let t=a("div",{class:"chip-row filter-bar",part:"filter-bar"});return t.append(a("span",{class:"filter-label",text:n.label("category_filter")})),e.forEach((r,o)=>{let i=a("button",{class:`chip${o===0?" active":""}`,type:"button",text:`${r.name}${typeof r.count=="number"?` ${r.count}`:""}`}),s=n.rows.length;n.rows.push({block:"categories",data:r,value:String(r.name??"")}),i.addEventListener("click",()=>n.onRowClick(s)),t.append(i);}),t}function ve(n,e,t="Try"){let r=a("div",{class:"chip-row",part:"filter-bar"});return r.append(a("span",{class:"filter-label",text:t})),e.forEach(o=>{let i=a("button",{class:"chip",type:"button",text:o.keyword}),s=n.rows.length;n.rows.push({block:"keywords",data:o,value:o.keyword}),i.addEventListener("click",()=>n.onRowClick(s)),r.append(i);}),r}function P(n,e,t,r,o,i=false){let s=n.rows.length;n.rows.push({block:e,data:t,value:r});let l=a("button",{class:i?"row is-selected":"row",part:i?"row row-active":"row",type:"button"});return l.append(a("div",{class:"name",part:"name",text:r})),o&&l.append(a("span",{class:"badge",part:"badge",text:o})),l.setAttribute("data-seekmodo-surface","suggest"),l.setAttribute("data-seekmodo-block",e),l.setAttribute("data-seekmodo-pos",String(s)),e==="price_range"&&l.setAttribute("aria-pressed",i?"true":"false"),l.addEventListener("click",()=>n.onRowClick(s)),l}function C(n,e){if(e.length===0)return null;let t=a("div",{class:"rail-section"});return t.append(a("div",{class:"group-title",part:"group-title",text:n})),e.forEach(r=>t.append(r)),t}function st(n){if(!n.showBranding)return null;let e=a("a",{class:"brand-footer",part:"brand-footer",attrs:{href:n.brandUrl,target:"_blank",rel:"noopener noreferrer"}});return e.append(a("span",{class:"brand-by",text:n.label("powered_by")})),e.append(a("img",{class:"brand-logo",part:"brand-logo",attrs:{src:n.brandLogoUrl||et,alt:"Seekmodo",height:"16"}})),e}function M(n,e,t){let r=a("div",{class:`product-grid cols-${t}`,part:"product-grid"});return e.forEach((o,i)=>r.append(nt(n,o,i,true))),r}function Pe(n,e){let t=["wrap","wide"];n==="split-rail"&&t.push("split-rail-panel"),e.splitMobileResize&&t.push("split-rail-mobile"),e.productTitleTooltip&&t.push("product-title-tooltip");let r=a("div",{class:t.join(" "),part:"wrap"});r.append(a("slot",{attrs:{name:"header"}}));let o=(e.res.products??[]).slice(0,R(n,e.limit)),i=(e.res.keywords??[]).slice(0,4),s=(e.res.categories??[]).slice(0,4),l=(e.res.redirects??[]).slice(0,4),d=(e.res.recent??[]).slice(0,5);(e.res.trending??[]).slice(0,5);if(n==="split-rail"){(!e.productsPending||(e.res.meta?.total??0)>0)&&r.append(Y(e));let u=e.splitMobileResize?"split-body split-body--mobile-resize":"split-body",p=a("div",{class:u}),h=a("aside",{class:"rail",part:"rail"}),f=i.map(b=>P(e,"keywords",b,b.keyword)),g=C(e.label("keywords"),f);g&&h.append(g);let v=l.map(b=>P(e,"redirects",b,String(b.label||b.matched_term||b.target_url))),_=C(e.label("redirects"),v);_&&h.append(_);let N=s.map(b=>P(e,"categories",b,String(b.name),typeof b.count=="number"?String(b.count):void 0)),z=C(e.label("categories"),N);if(z&&h.append(z),de(e.res.products??[],e.res.meta)){let b=ce(e.currency??"USD").map(L=>{let Be=j(e.activePriceBand,L);return P(e,"price_range",{min:L.min,max:L.max,key:$(L)},ge(L,Fe=>e.formatPrice(Fe,e.currency)),void 0,Be)}),ee=C(e.label("price_range"),b);ee&&h.append(ee);}let V=d.map(b=>P(e,"recent",b,b.keyword)),E=C(e.label("recent"),V);E&&h.append(E);let Q=a("div",{class:"canvas"});e.productsPending&&o.length===0?Q.append(a("div",{class:"products-pending",part:"products-pending",text:e.label("products_pending")})):Q.append(M(e,o,5)),p.append(Q),e.splitMobileResize&&p.append(Ze()),p.append(h),r.append(p);}else if(n==="cinema-grid"){let u=it(e);u&&r.append(u),r.append(Y(e)),s.length&&r.append(J(e,s)),i.length&&r.append(ve(e,i,e.label("keywords")));let p=a("div",{class:"canvas"});p.append(M(e,o,6)),r.append(p);}else if(n==="command-bar"){let u=e.res.meta?.total??0,p=a("div",{class:"command-header",part:"meta-bar"});p.append(a("div",{class:"query-display",text:`"${e.lastQuery}"`})),p.append(a("span",{class:"result-pill",text:e.label("products_count").replace(/\{count\}/g,String(u))}));let h=a("a",{class:"view-all-link",part:"view-all",attrs:{href:e.viewAllHref},text:e.label("view_all_short")});if(h.addEventListener("click",g=>{g.preventDefault(),e.onViewAll();}),p.append(h),r.append(p),e.res.did_you_mean){let g=a("div",{class:"chip-row"});g.append(a("span",{class:"filter-label",text:e.label("did_you_mean")}));let v=a("button",{class:"chip",type:"button",text:e.res.did_you_mean}),_=e.rows.length;e.rows.push({block:"did_you_mean",data:{value:e.res.did_you_mean},value:e.res.did_you_mean}),v.addEventListener("click",()=>e.onRowClick(_)),g.append(v),r.append(g);}i.length&&r.append(ve(e,i,e.label("keywords"))),s.length&&r.append(J(e,s));let f=a("div",{class:"canvas"});f.append(M(e,o,5)),r.append(f);}else if(n==="magazine"){r.append(Y(e)),s.length&&r.append(J(e,s));let u=a("div",{class:"canvas"}),p=o.slice(0,3),h=o.slice(3);if(p.length){u.append(a("div",{class:"group-title",part:"group-title",text:e.label("best_matches")}));let f=a("div",{class:"hero-row",part:"hero-row"});p.forEach((g,v)=>{f.append(ot(e,g,v,v===0?e.label("top_match"):void 0));}),u.append(f);}h.length?(u.append(a("div",{class:"group-title",part:"group-title",text:e.label("more_results")})),u.append(M(e,h,6))):p.length||u.append(M(e,o,6)),r.append(u);}let m=st(e);return m&&r.append(m),r.append(a("slot",{attrs:{name:"footer"}})),r}function x(n){return n!=="classic"&&n!==""}function at(n){if(n==null)return null;let e=String(n).trim().toLowerCase();if(!e)return null;let t={deutsch:"de",german:"de",english:"en",spanish:"es",espanol:"es",franc\u00E9s:"fr",francais:"fr",french:"fr",portuguese:"pt",italian:"it",dutch:"nl",japanese:"ja",chinese:"zh",vietnamese:"vi",turkish:"tr",korean:"ko",polish:"pl",arabic:"ar"};if(t[e])return t[e];let r=e.replace(/[_-].*$/,"");return /^[a-z]{2,3}$/.test(r)?r:null}var k="Search suggestions could not load (CORS). Ask your store admin to allow this domain on the Seekmodo gateway.",lt={en:{},de:{recent:"Zuletzt gesucht",trending:"Trends",keywords:"Vorschl\xE4ge",products:"Produkte",categories:"Kategorien",redirects:"Weiterleitungen",price_range:"Preisbereich",did_you_mean:"Meinten Sie",view_all:"Alle {total} Ergebnisse anzeigen",view_all_short:"Alle anzeigen \u2192",results_for:"{total} Ergebnisse f\xFCr ",showing_results_for:"Ergebnisse f\xFCr \u201E{query}\u201C. Stattdessen suchen nach ",products_count:"{count} Produkte",products_pending:"Passende Produkte erscheinen, wenn Sie mit dem Tippen pausieren\u2026",empty:"Noch keine Treffer \u2014 weiter tippen.",powered_by:"Unterst\xFCtzt von ",best_matches:"Beste Treffer",more_results:"Weitere Ergebnisse",top_match:"Top-Treffer",category_filter:"Kategorie",related:"Verwandt",try:"Versuchen",page:"Seite",article:"Artikel",tool:"Tool",tools:"Tools",cors_blocked:"Suchvorschl\xE4ge konnten nicht geladen werden, weil diese Website Seekmodo nicht erreichen darf (CORS). Bitten Sie Ihren Shop-Administrator, diese Domain am Seekmodo-Gateway freizugeben oder den Same-Origin-Suggest-Proxy des Connectors zu aktivieren."},es:{recent:"Buscadas recientemente",trending:"Tendencias",keywords:"Sugerencias",products:"Productos",categories:"Categor\xEDas",redirects:"Redirecciones",price_range:"Rango de precios",did_you_mean:"Quiso decir",view_all:"Ver los {total} resultados",view_all_short:"Ver todos \u2192",results_for:"{total} resultados para ",showing_results_for:'Mostrando resultados para "{query}". Buscar en su lugar ',products_count:"{count} productos",products_pending:"Los productos coincidentes aparecen cuando deja de escribir\u2026",empty:"A\xFAn no hay coincidencias \u2014 sigue escribiendo.",powered_by:"Con tecnolog\xEDa de ",best_matches:"Mejores coincidencias",more_results:"M\xE1s resultados",top_match:"Mejor coincidencia",category_filter:"Categor\xEDa",related:"Relacionado",try:"Probar",page:"P\xE1gina",article:"Art\xEDculo",tool:"Herramienta",tools:"Herramientas",cors_blocked:"Las sugerencias de b\xFAsqueda no se pudieron cargar porque este sitio no puede alcanzar Seekmodo (CORS). Pida al administrador de la tienda que autorice este dominio en la puerta de enlace de Seekmodo, o active el proxy de sugerencias same-origin del conector."},fr:{recent:"Recherches r\xE9centes",trending:"Tendances",keywords:"Propositions",products:"Produits",categories:"Cat\xE9gories",redirects:"Redirections",price_range:"Fourchette de prix",did_you_mean:"Vouliez-vous dire",view_all:"Voir les {total} r\xE9sultats",view_all_short:"Tout voir \u2192",results_for:"{total} r\xE9sultats pour ",showing_results_for:"R\xE9sultats pour \xAB {query} \xBB. Rechercher plut\xF4t ",products_count:"{count} produits",products_pending:"Les produits correspondants apparaissent lorsque vous arr\xEAtez de taper\u2026",empty:"Aucune correspondance \u2014 continuez \xE0 saisir.",powered_by:"Propuls\xE9 par ",best_matches:"Meilleurs r\xE9sultats",more_results:"Plus de r\xE9sultats",top_match:"Meilleure correspondance",category_filter:"Cat\xE9gorie",related:"Associ\xE9",try:"Essayer",page:"Page",article:"Article",tool:"Outil",tools:"Outils",cors_blocked:"Les suggestions de recherche n'ont pas pu \xEAtre charg\xE9es car ce site est bloqu\xE9 pour atteindre Seekmodo (CORS). Demandez \xE0 l'administrateur de la boutique d'autoriser ce domaine sur la passerelle Seekmodo, ou d'activer le proxy de suggestions same-origin du connecteur."},pt:{recent:"Pesquisas recentes",trending:"Em alta",keywords:"Sugest\xF5es",products:"Produtos",categories:"Categorias",redirects:"Redirecionamentos",price_range:"Faixa de pre\xE7o",did_you_mean:"Voc\xEA quis dizer",view_all:"Ver todos os {total} resultados",view_all_short:"Ver todos \u2192",results_for:"{total} resultados para ",showing_results_for:'Mostrando resultados para "{query}". Pesquisar em vez disso ',products_count:"{count} produtos",products_pending:"Os produtos correspondentes aparecem quando voc\xEA pausa a digita\xE7\xE3o\u2026",empty:"Ainda sem correspond\xEAncias \u2014 continue digitando.",powered_by:"Desenvolvido por ",best_matches:"Melhores resultados",more_results:"Mais resultados",top_match:"Melhor correspond\xEAncia",category_filter:"Categoria",related:"Relacionado",try:"Tentar",page:"P\xE1gina",article:"Artigo",tool:"Ferramenta",tools:"Ferramentas",cors_blocked:k},it:{recent:"Ricerche recenti",trending:"Di tendenza",keywords:"Suggerimenti",products:"Prodotti",categories:"Categorie",redirects:"Reindirizzamenti",price_range:"Fascia di prezzo",did_you_mean:"Forse cercavi",view_all:"Vedi tutti i {total} risultati",view_all_short:"Vedi tutti \u2192",results_for:"{total} risultati per ",showing_results_for:'Risultati per "{query}". Cerca invece ',products_count:"{count} prodotti",products_pending:"I prodotti corrispondenti compaiono quando interrompi la digitazione\u2026",empty:"Nessuna corrispondenza \u2014 continua a digitare.",powered_by:"Offerto da ",best_matches:"Migliori risultati",more_results:"Altri risultati",top_match:"Miglior risultato",category_filter:"Categoria",related:"Correlati",try:"Prova",page:"Pagina",article:"Articolo",tool:"Strumento",tools:"Strumenti",cors_blocked:k},nl:{recent:"Recent gezocht",trending:"Trending",keywords:"Suggesties",products:"Producten",categories:"Categorie\xEBn",redirects:"Doorverwijzingen",price_range:"Prijsklasse",did_you_mean:"Bedoelde u",view_all:"Bekijk alle {total} resultaten",view_all_short:"Alles bekijken \u2192",results_for:"{total} resultaten voor ",showing_results_for:'Resultaten voor "{query}". Zoek in plaats daarvan naar ',products_count:"{count} producten",products_pending:"Overeenkomende producten verschijnen wanneer u stopt met typen\u2026",empty:"Nog geen treffers \u2014 blijf typen.",powered_by:"Mogelijk gemaakt door ",best_matches:"Beste matches",more_results:"Meer resultaten",top_match:"Beste match",category_filter:"Categorie",related:"Gerelateerd",try:"Probeer",page:"Pagina",article:"Artikel",tool:"Tool",tools:"Tools",cors_blocked:k},ja:{recent:"\u6700\u8FD1\u306E\u691C\u7D22",trending:"\u30C8\u30EC\u30F3\u30C9",keywords:"\u5019\u88DC",products:"\u5546\u54C1",categories:"\u30AB\u30C6\u30B4\u30EA\u30FC",redirects:"\u79FB\u52D5",price_range:"\u4FA1\u683C\u5E2F",did_you_mean:"\u3082\u3057\u304B\u3057\u3066",view_all:"\u5168{total}\u4EF6\u306E\u7D50\u679C\u3092\u8868\u793A",view_all_short:"\u3059\u3079\u3066\u8868\u793A \u2192",results_for:"{total}\u4EF6\u306E\u7D50\u679C\uFF1A",showing_results_for:"\u300C{query}\u300D\u306E\u7D50\u679C\u3002\u4EE3\u308F\u308A\u306B\u691C\u7D22\uFF1A",products_count:"{count}\u4EF6\u306E\u5546\u54C1",products_pending:"\u5165\u529B\u3092\u4E00\u6642\u505C\u6B62\u3059\u308B\u3068\u4E00\u81F4\u3059\u308B\u5546\u54C1\u304C\u8868\u793A\u3055\u308C\u307E\u3059\u2026",empty:"\u307E\u3060\u4E00\u81F4\u304C\u3042\u308A\u307E\u305B\u3093 \u2014 \u5165\u529B\u3092\u7D9A\u3051\u3066\u304F\u3060\u3055\u3044\u3002",powered_by:"\u63D0\u4F9B\uFF1A",best_matches:"\u30D9\u30B9\u30C8\u30DE\u30C3\u30C1",more_results:"\u305D\u306E\u4ED6\u306E\u7D50\u679C",top_match:"\u30C8\u30C3\u30D7\u30DE\u30C3\u30C1",category_filter:"\u30AB\u30C6\u30B4\u30EA\u30FC",related:"\u95A2\u9023",try:"\u8A66\u3059",page:"\u30DA\u30FC\u30B8",article:"\u8A18\u4E8B",tool:"\u30C4\u30FC\u30EB",tools:"\u30C4\u30FC\u30EB",cors_blocked:k},zh:{recent:"\u6700\u8FD1\u641C\u7D22",trending:"\u70ED\u95E8",keywords:"\u5EFA\u8BAE",products:"\u4EA7\u54C1",categories:"\u5206\u7C7B",redirects:"\u8DF3\u8F6C",price_range:"\u4EF7\u683C\u533A\u95F4",did_you_mean:"\u60A8\u662F\u4E0D\u662F\u8981\u627E",view_all:"\u67E5\u770B\u5168\u90E8 {total} \u6761\u7ED3\u679C",view_all_short:"\u67E5\u770B\u5168\u90E8 \u2192",results_for:"{total} \u6761\u7ED3\u679C\uFF1A",showing_results_for:"\u663E\u793A\u201C{query}\u201D\u7684\u7ED3\u679C\u3002\u6539\u4E3A\u641C\u7D22 ",products_count:"{count} \u4EF6\u4EA7\u54C1",products_pending:"\u6682\u505C\u8F93\u5165\u540E\u5C06\u663E\u793A\u5339\u914D\u7684\u4EA7\u54C1\u2026",empty:"\u6682\u65E0\u5339\u914D \u2014 \u8BF7\u7EE7\u7EED\u8F93\u5165\u3002",powered_by:"\u6280\u672F\u652F\u6301\uFF1A",best_matches:"\u6700\u4F73\u5339\u914D",more_results:"\u66F4\u591A\u7ED3\u679C",top_match:"\u6700\u4F73\u7ED3\u679C",category_filter:"\u5206\u7C7B",related:"\u76F8\u5173",try:"\u8BD5\u8BD5",page:"\u9875\u9762",article:"\u6587\u7AE0",tool:"\u5DE5\u5177",tools:"\u5DE5\u5177",cors_blocked:k},vi:{recent:"T\xECm ki\u1EBFm g\u1EA7n \u0111\xE2y",trending:"Xu h\u01B0\u1EDBng",keywords:"G\u1EE3i \xFD",products:"S\u1EA3n ph\u1EA9m",categories:"Danh m\u1EE5c",redirects:"Chuy\u1EC3n h\u01B0\u1EDBng",price_range:"Kho\u1EA3ng gi\xE1",did_you_mean:"B\u1EA1n c\xF3 mu\u1ED1n t\xECm",view_all:"Xem t\u1EA5t c\u1EA3 {total} k\u1EBFt qu\u1EA3",view_all_short:"Xem t\u1EA5t c\u1EA3 \u2192",results_for:"{total} k\u1EBFt qu\u1EA3 cho ",showing_results_for:'Hi\u1EC3n th\u1ECB k\u1EBFt qu\u1EA3 cho "{query}". T\xECm ki\u1EBFm thay th\u1EBF ',products_count:"{count} s\u1EA3n ph\u1EA9m",products_pending:"S\u1EA3n ph\u1EA9m kh\u1EDBp s\u1EBD xu\u1EA5t hi\u1EC7n khi b\u1EA1n t\u1EA1m d\u1EEBng g\xF5\u2026",empty:"Ch\u01B0a c\xF3 k\u1EBFt qu\u1EA3 \u2014 h\xE3y ti\u1EBFp t\u1EE5c g\xF5.",powered_by:"Cung c\u1EA5p b\u1EDFi ",best_matches:"Kh\u1EDBp nh\u1EA5t",more_results:"Th\xEAm k\u1EBFt qu\u1EA3",top_match:"K\u1EBFt qu\u1EA3 h\xE0ng \u0111\u1EA7u",category_filter:"Danh m\u1EE5c",related:"Li\xEAn quan",try:"Th\u1EED",page:"Trang",article:"B\xE0i vi\u1EBFt",tool:"C\xF4ng c\u1EE5",tools:"C\xF4ng c\u1EE5",cors_blocked:k},tr:{recent:"Son aramalar",trending:"Trendler",keywords:"\xD6neriler",products:"\xDCr\xFCnler",categories:"Kategoriler",redirects:"Y\xF6nlendirmeler",price_range:"Fiyat aral\u0131\u011F\u0131",did_you_mean:"Bunu mu demek istediniz",view_all:"T\xFCm {total} sonucu g\xF6r\xFCnt\xFCle",view_all_short:"T\xFCm\xFCn\xFC g\xF6r \u2192",results_for:"{total} sonu\xE7: ",showing_results_for:'"{query}" i\xE7in sonu\xE7lar. Bunun yerine ara ',products_count:"{count} \xFCr\xFCn",products_pending:"Yazmay\u0131 duraklatt\u0131\u011F\u0131n\u0131zda e\u015Fle\u015Fen \xFCr\xFCnler g\xF6r\xFCn\xFCr\u2026",empty:"Hen\xFCz e\u015Fle\u015Fme yok \u2014 yazmaya devam edin.",powered_by:"Destekleyen: ",best_matches:"En iyi e\u015Fle\u015Fmeler",more_results:"Daha fazla sonu\xE7",top_match:"En iyi e\u015Fle\u015Fme",category_filter:"Kategori",related:"\u0130lgili",try:"Dene",page:"Sayfa",article:"Makale",tool:"Ara\xE7",tools:"Ara\xE7lar",cors_blocked:k},ko:{recent:"\uCD5C\uADFC \uAC80\uC0C9",trending:"\uC778\uAE30",keywords:"\uCD94\uCC9C",products:"\uC0C1\uD488",categories:"\uCE74\uD14C\uACE0\uB9AC",redirects:"\uC774\uB3D9",price_range:"\uAC00\uACA9\uB300",did_you_mean:"\uC774\uAC83\uC744 \uCC3E\uC73C\uC168\uB098\uC694",view_all:"\uC804\uCCB4 {total}\uAC1C \uACB0\uACFC \uBCF4\uAE30",view_all_short:"\uBAA8\uB450 \uBCF4\uAE30 \u2192",results_for:"{total}\uAC1C \uACB0\uACFC: ",showing_results_for:'"{query}" \uACB0\uACFC. \uB300\uC2E0 \uAC80\uC0C9 ',products_count:"{count}\uAC1C \uC0C1\uD488",products_pending:"\uC785\uB825\uC744 \uC7A0\uC2DC \uBA48\uCD94\uBA74 \uC77C\uCE58\uD558\uB294 \uC0C1\uD488\uC774 \uD45C\uC2DC\uB429\uB2C8\uB2E4\u2026",empty:"\uC544\uC9C1 \uC77C\uCE58 \uD56D\uBAA9\uC774 \uC5C6\uC2B5\uB2C8\uB2E4 \u2014 \uACC4\uC18D \uC785\uB825\uD558\uC138\uC694.",powered_by:"\uC81C\uACF5: ",best_matches:"\uCD5C\uC801 \uC77C\uCE58",more_results:"\uB354 \uB9CE\uC740 \uACB0\uACFC",top_match:"\uCD5C\uACE0 \uC77C\uCE58",category_filter:"\uCE74\uD14C\uACE0\uB9AC",related:"\uAD00\uB828",try:"\uC2DC\uB3C4",page:"\uD398\uC774\uC9C0",article:"\uAE30\uC0AC",tool:"\uB3C4\uAD6C",tools:"\uB3C4\uAD6C",cors_blocked:k},pl:{recent:"Ostatnio wyszukiwane",trending:"Popularne",keywords:"Sugestie",products:"Produkty",categories:"Kategorie",redirects:"Przekierowania",price_range:"Zakres cen",did_you_mean:"Czy chodzi\u0142o Ci o",view_all:"Zobacz wszystkie {total} wynik\xF3w",view_all_short:"Zobacz wszystkie \u2192",results_for:"{total} wynik\xF3w dla ",showing_results_for:"Wyniki dla \u201E{query}\u201D. Wyszukaj zamiast tego ",products_count:"{count} produkt\xF3w",products_pending:"Pasuj\u0105ce produkty pojawi\u0105 si\u0119, gdy przerwiesz pisanie\u2026",empty:"Brak dopasowa\u0144 \u2014 kontynuuj pisanie.",powered_by:"Obs\u0142ugiwane przez ",best_matches:"Najlepsze dopasowania",more_results:"Wi\u0119cej wynik\xF3w",top_match:"Najlepsze dopasowanie",category_filter:"Kategoria",related:"Powi\u0105zane",try:"Spr\xF3buj",page:"Strona",article:"Artyku\u0142",tool:"Narz\u0119dzie",tools:"Narz\u0119dzia",cors_blocked:k},ar:{recent:"\u0639\u0645\u0644\u064A\u0627\u062A \u0627\u0644\u0628\u062D\u062B \u0627\u0644\u0623\u062E\u064A\u0631\u0629",trending:"\u0627\u0644\u0631\u0627\u0626\u062C",keywords:"\u0627\u0642\u062A\u0631\u0627\u062D\u0627\u062A",products:"\u0627\u0644\u0645\u0646\u062A\u062C\u0627\u062A",categories:"\u0627\u0644\u0641\u0626\u0627\u062A",redirects:"\u0625\u0639\u0627\u062F\u0629 \u0627\u0644\u062A\u0648\u062C\u064A\u0647",price_range:"\u0646\u0637\u0627\u0642 \u0627\u0644\u0633\u0639\u0631",did_you_mean:"\u0647\u0644 \u062A\u0642\u0635\u062F",view_all:"\u0639\u0631\u0636 \u062C\u0645\u064A\u0639 \u0627\u0644\u0646\u062A\u0627\u0626\u062C \u0627\u0644\u0628\u0627\u0644\u063A \u0639\u062F\u062F\u0647\u0627 {total}",view_all_short:"\u0639\u0631\u0636 \u0627\u0644\u0643\u0644 \u2190",results_for:"{total} \u0646\u062A\u064A\u062C\u0629 \u0644\u0640 ",showing_results_for:'\u0646\u062A\u0627\u0626\u062C \u0644\u0640 "{query}". \u0627\u0628\u062D\u062B \u0628\u062F\u0644\u0627\u064B \u0645\u0646 \u0630\u0644\u0643 \u0639\u0646 ',products_count:"{count} \u0645\u0646\u062A\u062C",products_pending:"\u062A\u0638\u0647\u0631 \u0627\u0644\u0645\u0646\u062A\u062C\u0627\u062A \u0627\u0644\u0645\u0637\u0627\u0628\u0642\u0629 \u0639\u0646\u062F \u0627\u0644\u062A\u0648\u0642\u0641 \u0639\u0646 \u0627\u0644\u0643\u062A\u0627\u0628\u0629\u2026",empty:"\u0644\u0627 \u062A\u0648\u062C\u062F \u0646\u062A\u0627\u0626\u062C \u0628\u0639\u062F \u2014 \u0648\u0627\u0635\u0644 \u0627\u0644\u0643\u062A\u0627\u0628\u0629.",powered_by:"\u0645\u062F\u0639\u0648\u0645 \u0645\u0646 ",best_matches:"\u0623\u0641\u0636\u0644 \u0627\u0644\u062A\u0637\u0627\u0628\u0642\u0627\u062A",more_results:"\u0627\u0644\u0645\u0632\u064A\u062F \u0645\u0646 \u0627\u0644\u0646\u062A\u0627\u0626\u062C",top_match:"\u0623\u0641\u0636\u0644 \u062A\u0637\u0627\u0628\u0642",category_filter:"\u0627\u0644\u0641\u0626\u0629",related:"\u0630\u0627\u062A \u0635\u0644\u0629",try:"\u062C\u0631\u0651\u0628",page:"\u0635\u0641\u062D\u0629",article:"\u0645\u0642\u0627\u0644",tool:"\u0623\u062F\u0627\u0629",tools:"\u0623\u062F\u0648\u0627\u062A",cors_blocked:k}};function Ce(n){let e=at(n);return !e||e==="en"?{}:lt[e]??{}}var Me={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",redirects:"Go to",price_range:"Price range",did_you_mean:"Did you mean",view_all:"View all {total} results",view_all_short:"View all \u2192",results_for:"{total} results for ",showing_results_for:'Showing results for "{query}". Search instead for ',products_count:"{count} products",products_pending:"Matching products appear when you pause typing\u2026",empty:"No matches yet \u2014 keep typing.",powered_by:"Powered by ",best_matches:"Best matches",more_results:"More results",top_match:"Top match",category_filter:"Category",related:"Related",try:"Try",page:"Page",article:"Article",tool:"Tool",tools:"Tools",cors_blocked:O};function dt(n){if(!n||!String(n).trim())return null;try{let e=JSON.parse(String(n));if(!e||typeof e!="object")return null;let t={};for(let[r,o]of Object.entries(e))typeof r=="string"&&typeof o=="string"&&o!==""&&(t[r]=o);return Object.keys(t).length>0?t:null}catch{return null}}function ct(n){if(!n||typeof n!="object")return null;let e={};for(let[t,r]of Object.entries(n))typeof t=="string"&&typeof r=="string"&&r!==""&&(e[t]=r);return Object.keys(e).length>0?e:null}function ut(...n){let e={};for(let t of n)if(t)for(let[r,o]of Object.entries(t))typeof r=="string"&&typeof o=="string"&&o!==""&&(e[r]=o);return e}function Z(n,e,t){let r=dt(n),o=ct(e),i=Ce(t);return ut(i,o,r)}function ze(n,e,t=Me){let r=e?.[n];return typeof r=="string"&&r!==""?r:t[n]??n}var Ie=["recent","did_you_mean","redirects","keywords","trending","products","categories"],He=`
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
    /* SM-812 accent tokens \u2014 see JSDoc above. Defaults preserve the
       original hardcoded blue byte-for-byte. */
    --_accent: var(--seekmodo-suggest-accent, #2563eb);
    --_accent-contrast: var(--seekmodo-suggest-accent-contrast, #ffffff);
    --_header-bg: var(--seekmodo-suggest-header-bg,
      linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%));
    --_header-color: var(--seekmodo-suggest-header-color, #ffffff);
    --_dym-bg: var(--seekmodo-suggest-did-you-mean-bg, #eff6ff);
    --_dym-border: var(--seekmodo-suggest-did-you-mean-border, #bfdbfe);
    --_badge-bg: var(--seekmodo-suggest-badge-bg, var(--_row-active));
    --_badge-color: var(--seekmodo-suggest-badge-color, var(--_color));
    /* Header/CTA/row-accent tokens \u2014 see JSDoc above. Every default
       is a zero-sized/transparent no-op so existing tenants are
       unaffected until they set these explicitly. */
    --_meta-bg: var(--seekmodo-suggest-meta-bg, var(--_row-hover));
    --_meta-color: var(--seekmodo-suggest-meta-color, var(--_color));
    --_meta-count-color: var(--seekmodo-suggest-meta-count-color, var(--_group-color));
    --_cta-bg: var(--seekmodo-suggest-cta-bg, transparent);
    --_cta-color: var(--seekmodo-suggest-cta-color, var(--_accent));
    --_cta-radius: var(--seekmodo-suggest-cta-radius, 0px);
    --_cta-padding: var(--seekmodo-suggest-cta-padding, 0px);
    --_cta-decoration: var(--seekmodo-suggest-cta-decoration, underline);
    --_cta-border-width: var(--seekmodo-suggest-cta-border-width, 0px);
    --_cta-border-color: var(--seekmodo-suggest-cta-border-color, transparent);
    --_dym-swap-bg: var(--seekmodo-suggest-dym-swap-bg, transparent);
    --_dym-swap-radius: var(--seekmodo-suggest-dym-swap-radius, 0px);
    --_dym-swap-padding: var(--seekmodo-suggest-dym-swap-padding, 0px);
    --_dym-swap-decoration: var(--seekmodo-suggest-dym-swap-decoration, underline);
    --_dym-swap-border-width: var(--seekmodo-suggest-dym-swap-border-width, 0px);
    --_dym-swap-border-color: var(--seekmodo-suggest-dym-swap-border-color, transparent);
    --_row-accent: var(--seekmodo-suggest-row-accent, transparent);
    --_row-accent-width: var(--seekmodo-suggest-row-accent-width, 0px);
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
    border-left: var(--_row-accent-width) solid transparent;
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
    border-left-color: var(--_row-accent);
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
    border: var(--_dym-swap-border-width) solid var(--_dym-swap-border-color);
    background: var(--_dym-swap-bg);
    border-radius: var(--_dym-swap-radius);
    padding: var(--_dym-swap-padding);
    color: var(--seekmodo-suggest-dym-swap-color, var(--_color));
    font: inherit;
    font-weight: 600;
    text-decoration: var(--_dym-swap-decoration);
    cursor: pointer;
  }
  .badge {
    display: inline-block;
    font-size: 0.7em;
    padding: 0.05em 0.4em;
    border-radius: 999px;
    background: var(--_badge-bg);
    color: var(--_badge-color);
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
  ${we}
`,U=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let r=this.map.keys().next().value;if(r===void 0)break;this.map.delete(r);}}clear(){this.map.clear();}},q=class extends F{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","product-debounce-ms","limit","cache-size","view-all-href","lang","labels","anchor","anchor-offset","anchor-min-width","layout","split-mobile-resize","product-title-tooltip","typeahead-fallback-url","prefer-local","serp-passthrough","img-ver","vehicle-id","vehicle-filter","currency","show-branding","brand-url","brand-logo-url","suppress-legacy"]}current=null;loading=false;corsBlocked=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;debouncedPrefix=null;debouncedProducts=null;prefixDebouncedAt=0;productDebouncedAt=0;productsPending=false;prefixRailQuery="";pendingRenderedQ=null;fetchToken=0;prefixFetchToken=0;productFetchToken=0;inflight=null;prefixInflight=null;productInflight=null;cache=new U(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorResizeRaf=null;lastAnchorKey="";anchorApplied=false;suppressedLegacyEls=new WeakSet;legacySuppressionRetryHandler=null;splitResizeCleanup=null;activePriceBand=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),this.scheduleLegacySuppressionRetries(),this.scheduleRender();}disconnectedCallback(){this.unscheduleLegacySuppressionRetries(),this.unbindSplitMobileResize(),this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"||e==="product-debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"||e==="layout"||e==="split-mobile-resize"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):e==="vehicle-id"||e==="vehicle-filter"||e==="serp-passthrough"||e==="currency"?(this.cache.clear(),this.current=null,this.lastQuery.trim().length>=(parseInt(this.getAttribute("min-length")??"2",10)||2)?this.fetch(this.lastQuery):this.scheduleRender()):this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;if((this.debouncedAt!==e||!this.debounced)&&(this.debouncedAt=e,this.debounced=D(r=>{this.fetch(r);},e)),!this.twoPhaseEnabled()){this.debouncedPrefix=null,this.debouncedProducts=null;return}let t=this.productDebounceMs();(this.prefixDebouncedAt!==e||!this.debouncedPrefix)&&(this.prefixDebouncedAt=e,this.debouncedPrefix=D(r=>{this.fetchPrefix(r);},e)),(this.productDebouncedAt!==t||!this.debouncedProducts)&&(this.productDebouncedAt=t,this.debouncedProducts=D(r=>{this.fetchProducts(r);},t));}debounceMs(){return parseInt(this.getAttribute("debounce-ms")??"150",10)||150}productDebounceMs(){return parseInt(this.getAttribute("product-debounce-ms")??"0",10)||0}twoPhaseEnabled(){return this.productDebounceMs()>this.debounceMs()}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new U(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let r=document.getElementById(e);if(r){this.subscribed=r,r.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let r=document.getElementById(t);r instanceof HTMLInputElement&&(this.inputEl=r,r.addEventListener("input",this.onPlainInput),r.addEventListener("focus",this.onPlainFocus),r.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}bindAnchorListeners(){this.anchorScrollHandler=()=>this.scheduleApplyAnchor(),this.anchorResizeHandler=()=>this.scheduleApplyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let r=this.inputEl??this.subscribed;r&&(t===r||r.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorResizeRaf!==null&&(cancelAnimationFrame(this.anchorResizeRaf),this.anchorResizeRaf=null),this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}scheduleApplyAnchor(){typeof window>"u"||this.anchorResizeRaf===null&&(this.anchorResizeRaf=requestAnimationFrame(()=>{this.anchorResizeRaf=null,this.applyAnchor();}));}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let r=t.getBoundingClientRect();if(r.width<=0&&r.height<=0){this.style.visibility="hidden";return}let o=parseInt(this.getAttribute("anchor-offset")??"4",10),i=Number.isFinite(o)?o:4,s=this.getAttribute("anchor-min-width"),l=x(this.layoutMode()),d=s===null?l?960:480:Math.max(0,parseInt(s,10)||0),c=typeof window<"u"&&window.innerWidth>0?window.innerWidth:Math.max(r.width,d),m=Math.min(c*.96,1440),u=l?Math.max(r.width,Math.min(Math.max(d,r.width),m)):Math.max(r.width,d),p=l?m:Math.max(0,c-r.left-8),h=l?Math.min(u,p):Math.max(r.width,Math.min(u,p)),f=l?Math.max(8,(c-h)/2):r.left,g=[f,h,r.bottom,i,l?1:0].join("|");if(this.anchorApplied&&g===this.lastAnchorKey){this.style.visibility==="hidden"&&(this.style.visibility="");return}this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${r.bottom+i}px`,this.style.left=`${f}px`,this.style.width=`${h}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true,this.lastAnchorKey=g;}clearAnchor(){this.anchorApplied&&(this.lastAnchorKey="",this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(o=>o.trim()).filter(Boolean),r=this.inputEl;if(r)for(let o of t)o==="jquery-ui"?this.suppressJqueryUiAutocomplete(r):o==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(r);}suppressJqueryUiAutocomplete(e){let r=window.jQuery;if(!r||!r.ui||!r.ui.autocomplete)return;let o=r(e);if(o.data("ui-autocomplete")){try{o.autocomplete("close");}catch{}try{o.autocomplete("destroy");}catch{}}let i=o.attr("aria-owns");if(i){let s=document.getElementById(i);s&&(s.classList.add("seekmodo-suggest-legacy-suppressed"),s.style.display="none",this.suppressedLegacyEls.add(s));}document.querySelectorAll("ul.ui-autocomplete").forEach(s=>{let l=s.getAttribute("id");if(!l)return;document.querySelector(`[aria-owns="${CSS.escape(l)}"]`)===e&&(s.classList.add("seekmodo-suggest-legacy-suppressed"),s.style.display="none",this.suppressedLegacyEls.add(s));});}scheduleLegacySuppressionRetries(){this.unscheduleLegacySuppressionRetries();let e=()=>{this.applyLegacySuppression();};this.legacySuppressionRetryHandler=e,setTimeout(e,0),setTimeout(e,50),document.readyState==="loading"&&document.addEventListener("DOMContentLoaded",e,{once:true}),window.addEventListener("load",e,{once:true});}unscheduleLegacySuppressionRetries(){this.legacySuppressionRetryHandler=null;}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(r=>{r.style.display="none",this.suppressedLegacyEls.add(r);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),r=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<r){this.lastQuery=t,this.current=null,this.loading=false,this.productsPending=false,this.corsBlocked=false,this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort(),this.scheduleRender();return}if(this.lastQuery=t,this.corsBlocked=false,this.twoPhaseEnabled()){this.handleQueryTwoPhase(t);return}let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.productsPending=false,this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort(),this.queueRenderedEvent(t,o),this.emitOpen(t),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}handleQueryTwoPhase(e){let t=this.cache.get(this.cacheKey(e,"full"));if(t){this.current=t,this.loading=false,this.productsPending=false,this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort(),this.queueRenderedEvent(e,t),this.emitOpen(e),this.scheduleRender();return}this.productsPending=true,this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort();let r=this.cache.get(this.cacheKey(e,"prefix"));if(r){this.current=this.stripProducts(r),this.loading=false,this.scheduleRender(),this.debouncedProducts?.(e);return}this.loading=true,this.scheduleRender(),this.debouncedPrefix?.(e),this.debouncedProducts?.(e);}mergePrefixTextRails(e,t){let r=(l,d)=>l&&l.length>0?[...l]:[...d??[]],o=r(t.keywords,e.keywords),i=r(t.recent,e.recent),s=r(t.trending,e.trending);return {...t,keywords:o,recent:i,trending:s,meta:{...t.meta??{},counts:{...t.meta?.counts??{},keywords:o.length,recent:i.length,trending:s.length}}}}stripProducts(e){return {...e,products:[],categories:[],meta:{...e.meta??{},total:0,counts:{...e.meta?.counts??{},products:0,categories:0}}}}cacheKey(e,t="full"){let r=this.getSerpPassthrough(),o=this.getVehicleFilterArgs(),i=r?JSON.stringify(r):"",s=Object.keys(o).length>0?JSON.stringify(o):"",l=this.getVehicleId(),d=l!==null?`v${l}`:s,c=this.resolvePriceCurrency(),m=this.activePriceBand?$(this.activePriceBand):"";return `${t==="prefix"?"p":"f"}\0${e.toLowerCase()}\0${d}\0${i}\0${c}\0${m}`}resolvePriceCurrency(e){let t=this.getAttribute("currency")?.trim();if(t)return t.toUpperCase();if(e)return e.toUpperCase();let r=this.current?.meta?.region;if(r&&typeof r=="object"&&!Array.isArray(r)){let s=r.currency;if(typeof s=="string"&&s.trim()!=="")return s.trim().toUpperCase()}let i=this.getSerpPassthrough()?.shopper_context;if(i&&typeof i=="object"&&!Array.isArray(i)){let s=i.currency;if(typeof s=="string"&&s.trim()!=="")return s.trim().toUpperCase()}return "USD"}resolvePriceLocale(){let e=this.current?.meta?.region;if(e&&typeof e=="object"&&!Array.isArray(e)){let t=e.locale;if(typeof t=="string"&&t.trim()!=="")return t.trim()}}getVehicleId(){let e=this.getAttribute("vehicle-id");if(!e)return null;let t=parseInt(e,10);return Number.isFinite(t)&&t>0?t:null}getVehicleFilterArgs(){let e={},t=this.getAttribute("vehicle-filter");if(t)try{let i=JSON.parse(t);i&&typeof i=="object"&&!Array.isArray(i)&&Object.assign(e,i);}catch{}let r=this.getSerpPassthrough();if(r)for(let i of ["filter_by","vehicle_filter_mode","vehicle_hard_filter","vehicle_id","shopper_context"])r[i]!==void 0&&r[i]!==null&&e[i]===void 0&&(e[i]=r[i]);let o=this.getVehicleId();return o!==null&&e.vehicle_id===void 0&&(e.vehicle_id=o),e}getSerpPassthrough(){let e=this.getAttribute("serp-passthrough");if(!e)return null;try{let t=JSON.parse(e);if(t&&typeof t=="object"&&!Array.isArray(t))return t}catch{}return null}showProductLoadingSkeleton(e){return e.trim()!==""}deferStorefrontThumbs(){return this.thumbVer()!==""}thumbVer(){return (this.getAttribute("img-ver")??"").trim()}thumbSrc(e){let t=this.thumbVer();if(!t||!e)return e;try{let r=typeof window<"u"?window.location.origin:"http://localhost",o=new URL(e,r);return o.searchParams.set("_smv",t),/^https?:\/\//i.test(e)?o.toString():`${o.pathname}${o.search}${o.hash}`}catch{let r=e.includes("?")?"&":"?";return `${e}${r}_smv=${encodeURIComponent(t)}`}}async fetchPrefix(e){this.prefixInflight?.abort();let t=new AbortController;this.prefixInflight=t;let r=++this.prefixFetchToken;if(!this.preferLocal())try{let o=await this.getClient(),i=parseInt(this.getAttribute("limit")??"5",10)||5,s=this.buildSuggestArgs(e,i),l=await o.suggest({...s,complete:!1,include_products:!1});if(r!==this.prefixFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;l=this.stripProducts(l),this.cache.set(this.cacheKey(e,"prefix"),l),await this.applySuggestResponse(e,i,l,t,r,!1,"prefix");}catch(o){if(r!==this.prefixFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;this.handleSuggestFetchError(e,o,"prefix");}}async fetchProducts(e){this.productInflight?.abort();let t=new AbortController;this.productInflight=t;let r=++this.productFetchToken;if(this.preferLocal()){await this.applyLocalFallbackResponse(e,t,r,"products");return}try{let o=await this.getClient(),i=parseInt(this.getAttribute("limit")??"5",10)||5,s=this.layoutMode(),l=x(s)?R(s,i):i,d=this.buildSuggestArgs(e,l);if(this.showProductLoadingSkeleton(e)&&(this.loading=!0,this.scheduleRender(),r!==this.productFetchToken||t.signal.aborted))return;let c=this.prefixRailQuery===e.trim(),m=await o.suggest({...d,include_products:!0,include_keywords:!c,include_recent:!c,include_trending:!c});if(r!==this.productFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;await this.applySuggestResponse(e,l,m,t,r,!0,"products");}catch(o){if(r!==this.productFetchToken||t.signal.aborted||e.trim()!==this.lastQuery)return;this.productsPending=false,this.handleSuggestFetchError(e,o,"products");}}async fetch(e){this.inflight?.abort(),this.prefixInflight?.abort(),this.productInflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.fetchToken;if(this.preferLocal()){await this.applyLocalFallbackResponse(e,t,r,"full");return}try{let o=await this.getClient(),i=parseInt(this.getAttribute("limit")??"5",10)||5,s=this.layoutMode(),l=x(s)?R(s,i):i,d=this.buildSuggestArgs(e,l);if(this.showProductLoadingSkeleton(e)&&(this.loading=!0,this.scheduleRender(),r!==this.fetchToken||t.signal.aborted))return;let c=await o.suggest({...d,include_products:!0});if(r!==this.fetchToken||t.signal.aborted)return;await this.applySuggestResponse(e,l,c,t,r,!0);}catch(o){if(r!==this.fetchToken||t.signal.aborted)return;this.handleSuggestFetchError(e,o,"full");}}async handleSuggestFetchError(e,t,r){if(this.corsBlocked=I(t),r==="prefix"&&this.current&&e.trim()===this.lastQuery&&(this.current.products?.length??0)>0||(this.current=null),this.loading=false,this.corsBlocked){y(this,"seekmodo-suggest:cors-blocked",{q:e,input:this.inputEl}),console.warn("[seekmodo-suggest] blocked by CORS or network policy",t),this.scheduleRender();return}if(t instanceof G||t instanceof Error&&t.name==="SeekmodoQuotaError"){if(r!=="prefix"){let s=parseInt(this.getAttribute("limit")??"5",10)||5;this.inflight?.abort();let l=new AbortController;this.inflight=l;let d={products:[],keywords:[],categories:[],recent:[],trending:[],redirects:[]};try{let c=await this.mergeTypeaheadFallback(e,s,d,l,!0);if(l.signal.aborted||e.trim()!==this.lastQuery)return;if(c&&(c.products?.length??0)>0){this.current=c,this.loading=!1,this.emitOpen(e),this.queueRenderedEvent(e,c),y(this,"seekmodo-suggest:render",{q:e,products:c.products?.length??0}),y(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl,reason:"quota"}),console.warn("[seekmodo-suggest] quota / trial entitlement",t),this.scheduleRender();return}}catch{}if(l.signal.aborted||e.trim()!==this.lastQuery)return;y(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl,reason:"quota"});}console.warn("[seekmodo-suggest] quota / trial entitlement",t),this.scheduleRender();return}console.warn(`[seekmodo-suggest] ${r} fetch failed`,t),this.scheduleRender();}buildSuggestArgs(e,t){let r=this.getSessionId(),o={q:e,limit:t};r&&(o.session_id=r);let i=this.getVehicleFilterArgs();for(let[l,d]of Object.entries(i))if(d!=null){if(l==="vehicle_id"){let c=typeof d=="number"?d:parseInt(String(d),10);Number.isFinite(c)&&(o.vehicle_id=c);continue}if(l==="filter_by"&&typeof d=="string"){o.filter_by=d;continue}if(l==="vehicle_filter_mode"&&typeof d=="string"){o.vehicle_filter_mode=d;continue}if(l==="vehicle_hard_filter"){o.vehicle_hard_filter=d!==false&&d!=="false"&&d!==0&&d!=="0";continue}l==="shopper_context"&&d&&typeof d=="object"&&!Array.isArray(d)&&(o.shopper_context=d);}let s=this.getSerpPassthrough();if(s&&(o.serp_passthrough={...s}),this.activePriceBand){let l=ue(this.activePriceBand),d=typeof o.filter_by=="string"?o.filter_by:void 0,c=pe(d,l);o.filter_by=c,o.serp_passthrough={...o.serp_passthrough??{},filter_by:c};}return o}async applySuggestResponse(e,t,r,o,i,s,l="full"){let d=l==="full"||l==="products",c=d?await this.mergeTypeaheadFallback(e,t,r,o):null;if(c&&(r=c),l==="prefix"&&(this.prefixRailQuery=e.trim(),!!this.current&&e.trim()===this.lastQuery&&(this.current.products?.length??0)>0&&!this.productsPending&&this.current?r=this.mergePrefixTextRails(r,this.current):r=this.stripProducts(r)),l==="products"&&this.current&&this.prefixRailQuery===e.trim()&&(r=this.mergePrefixTextRails(this.current,r)),this.current=r,this.loading=!s,d&&(this.productsPending=!s),s){let m=l==="prefix"?"prefix":"full";this.cache.set(this.cacheKey(e,m),r),d&&(this.productsPending=false);}if(s&&r.redirect?.target_url){window.location.assign(r.redirect.target_url);return}s&&(this.emitOpen(e),this.isEmpty(r)&&y(this,"seekmodo-suggest:empty",{q:e,input:this.inputEl})),s&&d&&(r.products?.length??0)>0&&(this.queueRenderedEvent(e,r),y(this,"seekmodo-suggest:render",{q:e,products:r.products?.length??0})),this.scheduleRender();}queueRenderedEvent(e,t){(t.products?.length??0)>0&&(this.pendingRenderedQ=e);}afterRender(){let e=this.pendingRenderedQ;if(!e)return;this.pendingRenderedQ=null;let t=this.current?.products?.length??0;t>0&&y(this,"seekmodo-suggest:rendered",{q:e,products:t});}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}currentSearchEventId(){let e=this.current?.meta?.search_event_id;if(typeof e=="number"&&Number.isFinite(e)&&e>0)return Math.trunc(e);if(typeof e=="string"&&e!==""){let t=parseInt(e,10);if(Number.isFinite(t)&&t>0)return t}}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.redirects?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&(e.redirects?.length??0)===0&&!e.did_you_mean}typeaheadFallbackUrl(){let e=(this.getAttribute("typeahead-fallback-url")??"").trim();return e.length>0?e:null}preferLocal(){let e=(this.getAttribute("prefer-local")??"").trim().toLowerCase();return e==="1"||e==="true"||e==="yes"||e==="on"}async applyLocalFallbackResponse(e,t,r,o="full"){let i=parseInt(this.getAttribute("limit")??"5",10)||5,s=this.layoutMode(),l=x(s)?R(s,i):i;if(this.showProductLoadingSkeleton(e)&&(this.loading=true,this.scheduleRender(),t.signal.aborted))return;let d={products:[],keywords:[],categories:[],recent:[],trending:[],redirects:[]},c=await this.mergeTypeaheadFallback(e,l,d,t,true)??d;t.signal.aborted||e.trim()!==this.lastQuery||o==="products"&&r!==this.productFetchToken||o==="full"&&r!==this.fetchToken||await this.applySuggestResponse(e,l,c,t,r,true,o);}async mergeTypeaheadFallback(e,t,r,o,i=false){let s=this.typeaheadFallbackUrl();if(!s||typeof fetch!="function"||(r.products?.length??0)>0||!i&&this.deferStorefrontThumbs())return null;try{let l=s.includes("?")?"&":"?",d=await fetch(`${s}${l}q=${encodeURIComponent(e)}&max=${encodeURIComponent(String(t))}`,{credentials:"same-origin",signal:o.signal});if(!d.ok)return null;let c=await d.json(),m=Array.isArray(c?.rows)?c.rows:Array.isArray(c?.products)?c.products:[];if(m.length===0)return null;let u=m.slice(0,t).map((h,f)=>{let g=h,v=g.id!==void 0&&g.id!==null?g.id:g.products_id!==void 0&&g.products_id!==null?g.products_id:f,_=String(v),N=typeof g.name=="string"&&g.name!==""?g.name:typeof g.title=="string"&&g.title!==""?g.title:typeof g.value=="string"?g.value:"",z=typeof g.url=="string"&&g.url!==""?g.url:typeof g.permalink=="string"?g.permalink:void 0,V=typeof g.image_url=="string"&&g.image_url!==""?g.image_url:typeof g.thumbnail_url=="string"?g.thumbnail_url:void 0,E=typeof g.price=="number"&&Number.isFinite(g.price)?g.price:void 0;return {id:_,name:N,url:z,image_url:V,...E!==void 0?{price:E}:{},post_type:typeof g.post_type=="string"?g.post_type:void 0,excerpt:typeof g.excerpt=="string"?g.excerpt:void 0}}),p={...r.meta??{},typeahead_fallback:!0};return p.total=Math.max(p.total??0,u.length),p.counts={...p.counts??{},products:u.length},{...r,products:u,meta:p}}catch{return null}}blocks(){let e=this.getAttribute("blocks");if(!e)return Ie;let t=e.split(",").map(r=>r.trim()).filter(r=>["recent","trending","did_you_mean","redirects","keywords","products","categories","price_range"].includes(r));return t.length>0?t:Ie}label(e){return ze(e,this.labelOverrides())}labelOverrides(){try{let e=typeof window<"u"?window.SeekmodoSuggestLabels:void 0,t=globalThis.SeekmodoSuggestLabels;return Z(this.getAttribute("labels"),e??t,this.getAttribute("lang"))}catch{return Z(this.getAttribute("labels"),null,this.getAttribute("lang"))}}layoutMode(){let e=(this.getAttribute("layout")??X).trim();return e==="classic"||e==="cinema-grid"||e==="command-bar"||e==="magazine"||e==="split-rail"?e:X}showBrandingFlag(){let e=(this.getAttribute("show-branding")??"true").trim().toLowerCase();return e!=="false"&&e!=="0"&&e!=="no"}splitMobileResizeEnabled(){let e=(this.getAttribute("split-mobile-resize")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}productTitleTooltipEnabled(){let e=(this.getAttribute("product-title-tooltip")??"").trim().toLowerCase();return e==="true"||e==="1"||e==="yes"||e==="on"}unbindSplitMobileResize(){this.splitResizeCleanup?.(),this.splitResizeCleanup=null;}bindSplitMobileResizeIfNeeded(e,t){if(this.unbindSplitMobileResize(),e!=="split-rail"||!this.splitMobileResizeEnabled())return;let r=t.querySelector(".split-body");r instanceof HTMLElement&&(this.splitResizeCleanup=ke(r));}dismiss(){this.current===null&&!this.loading||(y(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.productsPending=false,this.activePriceBand=null,this.scheduleRender());}emitOpen(e){y(this,"seekmodo-suggest:open",{q:e});}buildViewAllHref(e){let t=this.getAttribute("view-all-href")??"/search?q={q}",r=this.activePriceBand,o=r?String(r.min):"",i=r&&r.max!==null?String(r.max):"",s=t.replace("{q}",encodeURIComponent(e)).replace("{price_from}",encodeURIComponent(o)).replace("{price_to}",encodeURIComponent(i));return s=he(s,["pfrom","pto","price_from","price_to","min_price","max_price"]),me(s,"seekmodo_skip_category_redirect","1")}navigateViewAll(){let e=this.current?.meta?.total??0,t=this.activePriceBand;y(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:e,price_from:t?t.min:null,price_to:t&&t.max!==null?t.max:null}),window.location.assign(this.buildViewAllHref(this.lastQuery));}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,r)=>{r===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];if(!t)return;let r=this.currentSearchEventId();if(y(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id,position:e+1,...r!==void 0?{search_event_id:r}:{}}),t.block==="price_range"){this.togglePriceBand(t.data);return}if(t.block==="redirects"){let o=String(t.data.target_url??t.data.url??"");o&&window.location.assign(o);return}}togglePriceBand(e){let t=Number(e.min);if(!Number.isFinite(t))return;let r=e.max,o=r==null||r===""?null:Number(r),i={min:t,max:o!==null&&Number.isFinite(o)?o:null};this.activePriceBand=j(this.activePriceBand,i)?null:i;let s=this.lastQuery.trim();s.length>0?this.fetch(s):this.scheduleRender();}render(){this.unbindSplitMobileResize();let e=document.createElement("style");if(e.textContent=He,this.loading&&this.current===null&&!this.corsBlocked){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.corsBlocked){ae(this.root,He,{message:this.label("cors_blocked")}),this.rows=[],this.active=-1,this.applyAnchor();return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}let t=this.displayResponse();if(!t){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(t)){let m=a("slot",{attrs:{name:"empty"}}),u=a("div",{class:"empty",text:this.label("empty")});m.append(u);let p=a("div",{class:"wrap",part:"wrap"});p.append(m),this.root.replaceChildren(e,p),this.rows=[],this.active=-1;return}let r=this.productTitleTooltipEnabled()?"wrap product-title-tooltip":"wrap",o=a("div",{class:r,part:"wrap"});o.append(a("slot",{attrs:{name:"header"}}));let i=[],s=parseInt(this.getAttribute("limit")??"5",10)||5,l=this.layoutMode();if(x(l)){let m=this.buildViewAllHref(this.lastQuery),u=(h,f)=>K(h,this.resolvePriceCurrency(f),this.resolvePriceLocale()),p=Pe(l,{res:t,lastQuery:this.lastQuery,limit:R(l,s),label:h=>this.label(h),rows:i,onRowClick:h=>this.activateRow(h),onViewAll:()=>this.navigateViewAll(),viewAllHref:m,showBranding:this.showBrandingFlag(),brandUrl:this.getAttribute("brand-url")??"https://seekmodo.com",brandLogoUrl:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",formatPrice:u,splitMobileResize:this.splitMobileResizeEnabled(),productTitleTooltip:this.productTitleTooltipEnabled(),resolveThumbSrc:h=>this.thumbSrc(h),productsPending:this.productsPending&&this.twoPhaseEnabled(),currency:this.resolvePriceCurrency(),activePriceBand:this.activePriceBand});this.rows=i,this.active=-1,this.root.replaceChildren(e,p),this.bindSplitMobileResizeIfNeeded(l,p),this.applyAnchor();return}let d=this.blocks();for(let m of d){let u=this.renderBlock(m,t,s,i);u&&o.append(u);}this.rows=i,this.active=-1;let c=t.meta?.total??0;if(c>0&&this.lastQuery.length>0){let m=this.buildViewAllHref(this.lastQuery),u=a("a",{class:"view-all",part:"view-all",attrs:{href:m},text:this.label("view_all").replace("{total}",String(c))});u.addEventListener("click",p=>{p.preventDefault(),this.navigateViewAll();}),o.append(u);}if(o.append(a("slot",{attrs:{name:"footer"}})),this.showBrandingFlag()){let m=a("a",{class:"brand-footer",part:"brand-footer",attrs:{href:this.getAttribute("brand-url")??"https://seekmodo.com",target:"_blank",rel:"noopener noreferrer"}});m.append(a("span",{class:"brand-by",text:this.label("powered_by")})),m.append(a("img",{class:"brand-logo",part:"brand-logo",attrs:{src:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",alt:"Seekmodo",height:"16"}})),o.append(m);}this.root.replaceChildren(e,o),this.applyAnchor();}displayResponse(){return this.current?this.productsPending&&this.twoPhaseEnabled()?this.stripProducts(this.current):this.current:null}renderSkeleton(){let e=a("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let r=a("div",{class:"row",part:"row skeleton"});r.append(a("div",{class:"thumb",part:"thumb"}));let o=a("div",{class:"name"});o.append(a("span",{class:"name-title"})),o.append(a("span",{class:"name-meta"})),r.append(o),e.append(r);}return e}renderBlock(e,t,r,o){if(e==="did_you_mean"){let l=t.did_you_mean;if(!l)return null;let d=a("div",{class:"group",part:"group did-you-mean"});d.append(a("slot",{attrs:{name:"did_you_mean"}}));let c=a("div",{class:"did-you-mean"});c.append(document.createTextNode(this.label("did_you_mean")+" "));let m=a("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:l});return m.addEventListener("click",()=>{let u=this.currentSearchEventId();y(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:l},q:this.lastQuery,value:l,...u!==void 0?{search_event_id:u}:{}});}),c.append(m),d.append(c),d}let i=this.blockData(e,t,r);if(i.length===0)return null;let s=a("div",{class:"group",part:"group",attrs:{"data-block":e}});return s.append(a("slot",{attrs:{name:e}})),s.append(a("div",{class:"group-title",part:"group-title",text:this.label(e)})),i.forEach((l,d)=>{let c={block:e,data:l,value:this.rowValue(e,l),id:this.rowId(e,l)};o.push(c);let m=o.length-1,u=window.seekmodoSuggest?.renderRow?.(c.data,e),p;u instanceof HTMLElement?(p=u,p.classList.add("row")):typeof u=="string"&&u.length>0?(p=a("button",{class:"row",part:"row",type:"button"}),p.innerHTML=u):p=this.renderRowDefault(e,l,d),p.setAttribute("data-seekmodo-surface","suggest"),p.setAttribute("data-seekmodo-block",e),p.setAttribute("data-seekmodo-pos",String(m)),c.id&&p.setAttribute("data-seekmodo-id",c.id),p.addEventListener("click",()=>this.activateRow(m)),s.append(p);}),s}blockData(e,t,r){switch(e){case "recent":return (t.recent??[]).slice(0,r);case "trending":return (t.trending??[]).slice(0,r);case "keywords":return (t.keywords??[]).slice(0,r);case "products":return (t.products??[]).slice(0,r);case "categories":return (t.categories??[]).slice(0,r);case "redirects":return (t.redirects??[]).slice(0,r);default:return []}}rowValue(e,t){let r=t;return e==="recent"||e==="trending"||e==="keywords"?String(r.keyword??""):e==="products"?String(r.name??r.title??""):e==="categories"?String(r.name??""):e==="redirects"?String(r.label||r.matched_term||r.target_url||""):""}rowId(e,t){if(e!=="products")return;let r=t.id;return r!==void 0?String(r):void 0}renderRowDefault(e,t,r){let o=a("button",{class:"row",part:"row",type:"button"});if(e==="products"){let i=t,{postType:s,label:l}=S(i,g=>this.label(g)),d=T(i),c=String(i.name??i.title??"").trim(),m=this.productTitleTooltipEnabled()&&c?c:"";if(s&&o.setAttribute("data-post-type",s),d){let g=this.thumbSrc(d);o.append(a("img",{class:"thumb",part:"thumb",attrs:{src:g,"data-src":d,alt:m,loading:"eager",decoding:"async"}}));}else {let v=a("div",{class:"thumb thumb-empty",part:"thumb thumb--empty",text:s==="page"?"P":s==="post"?"A":"\xB7"});s&&v.setAttribute("data-content-type",s),o.append(v);}let u=a("div",{class:"name",part:"name"}),p=a("span",{class:"name-title",text:c});this.productTitleTooltipEnabled()&&c&&p.setAttribute("title",c),u.append(p);let h=[l,i.brand?String(i.brand):"",i.sku??i.model??i.ez_number??""].filter(Boolean);h.length>0&&u.append(a("span",{class:"name-meta",text:h.join(" \xB7 ")})),o.append(u);let f=this.renderPrice(i);return f&&o.append(f),o}if(e==="categories"){let i=t,s=a("div",{class:"name",part:"name",text:i.name});return o.append(s),typeof i.count=="number"&&i.count>0&&o.append(a("span",{class:"badge",part:"badge",text:String(i.count)})),o}if(e==="redirects"){let i=t;return o.append(a("div",{class:"name",part:"name",text:String(i.label||i.matched_term||i.target_url||"")})),o}if(e==="recent"||e==="trending"||e==="keywords"){let i=t,s=a("div",{class:"name",part:"name",text:String(i.keyword)});return o.append(s),e==="trending"&&typeof i.search_count=="number"&&o.append(a("span",{class:"badge",part:"badge",text:String(i.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=this.resolvePriceCurrency(typeof e.currency=="string"?e.currency:void 0),r=this.resolvePriceLocale(),o=a("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(o.append(a("del",{text:K(e.price,t,r)})),o.append(document.createTextNode(K(e.sale_price,t,r)))):o.append(document.createTextNode(K(e.price,t,r))),o}};function K(n,e,t){try{return new Intl.NumberFormat(t,{style:"currency",currency:e,maximumFractionDigits:2}).format(n)}catch{return `${n.toFixed(2)} ${e}`}}le();typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",q);
exports.SeekmodoSuggest=q;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map