var SeekmodoSuggest=(function(exports){'use strict';var v=class extends Error{status;body;tool;constructor(r,e,t,n){super(r),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=n;}},q=class extends v{constructor(r,e,t){super(`Seekmodo auth failed (HTTP ${r})`,r,e,t),this.name="SeekmodoAuthError";}},re=class extends v{code;bucket;limit;used;constructor(r,e){super("Seekmodo over quota (HTTP 402)",402,r,e),this.name="SeekmodoQuotaError";let t=r??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},ne=class extends v{constructor(r,e,t){super(`Seekmodo server error (HTTP ${r})`,r,e,t),this.name="SeekmodoServerError";}},oe=class extends v{constructor(r,e,t){super(`Seekmodo request rejected (HTTP ${r})`,r,e,t),this.name="SeekmodoRequestError";}},U=class extends v{constructor(r,e){super(`Seekmodo network failure${r instanceof Error?`: ${r.message}`:""}`,0,r,e),this.name="SeekmodoNetworkError";}};function k(r,e){if(r instanceof U)return k(r.body);if(r instanceof TypeError){let t=r.message.toLowerCase();return t.includes("failed to fetch")||t.includes("networkerror")||t.includes("network request failed")||t.includes("load failed")}if(r instanceof Error){let t=r.message.toLowerCase();return t.includes("cors")||t.includes("access-control-allow-origin")||t.includes("cross-origin")}return  false}var se="https://gateway.seekmodo.com",ie=8e3,ae=class{config;cachedToken=null;constructor(r){if(!r.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof r.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:r.tenantId,getToken:r.getToken,baseUrl:(r.baseUrl??se).replace(/\/+$/,""),fetch:r.fetch??globalThis.fetch.bind(globalThis),timeoutMs:r.timeoutMs??ie,signal:r.signal,onError:r.onError,getRegion:r.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(r,e,t={}){try{return await this.callOnce(r,e,t,!1)}catch(n){if(n instanceof q){this.clearTokenCache();try{return await this.callOnce(r,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:r}),o}}throw this.config.onError?.(n,{tool:r}),n}}async callOnce(r,e,t,n){let o=await this.resolveToken(n),i=`${this.config.baseUrl}/v1/${encodeURIComponent(r)}`,a=new AbortController,c=t.timeoutMs??this.config.timeoutMs,p=setTimeout(()=>a.abort(),c),d=()=>a.abort();this.config.signal?.addEventListener("abort",d,{once:true}),t.signal?.addEventListener("abort",d,{once:true});let l={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let g=await this.config.getRegion();typeof g=="string"&&g.length>0&&(l["Seekmodo-Region"]=g);}catch{}let u;try{u=await this.config.fetch(i,{method:"POST",headers:l,body:JSON.stringify(e),signal:a.signal});}catch(g){throw new U(g,r)}finally{clearTimeout(p),this.config.signal?.removeEventListener("abort",d),t.signal?.removeEventListener("abort",d);}let h=await u.text(),m=h?de(h):null;if(u.status===401||u.status===403)throw new q(u.status,m,r);if(u.status===402)throw new re(m,r);if(u.status>=500)throw new ne(u.status,m,r);if(!u.ok)throw new oe(u.status,m,r);return m}async resolveToken(r){let e=Date.now();if(!r&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function de(r){try{return JSON.parse(r)}catch{return r}}var $=class{transport;recommend;bundle;constructor(r){this.transport=new ae(r),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(r,e){return this.transport.call("search",{...r},e??{})}suggest(r,e){return this.transport.call("suggest",{...r},e??{})}searchByImage(r,e){return this.transport.call("search.byImage",{...r},e??{})}chat(r,e){return this.transport.call("chat",{...r},e??{})}event(r,e){return this.transport.call("events",{...r},e??{})}};var E=null,f=null;function S(r){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${r}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function K(){return E!==null||(E=le()),E}async function le(){let r=S("seekmodo:tenant");if(!r)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=S("seekmodo:token"),t=S("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(f={token:e,expiresAt:Date.now()+3e4});let n=S("seekmodo:gateway")??void 0;return new $({tenantId:r,baseUrl:n,getRegion:()=>pe(),getToken:async()=>{let o=Date.now();if(f&&f.expiresAt-1e4>o)return {token:f.token,expiresAt:f.expiresAt};if(!t){if(f)return {token:f.token,expiresAt:f.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let i=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!i.ok)throw new Error(`seekmodo:refresh route returned HTTP ${i.status}`);let a=await i.json();if(!a.token||typeof a.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return f={token:a.token,expiresAt:a.expires_at*1e3},{token:f.token,expiresAt:f.expiresAt}}})}var ce="seekmodo_region";function ue(r){if(typeof r!="string")return null;let e=r.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function pe(){if(typeof document>"u")return null;let r=document.cookie??"";if(r.length===0)return null;let e=ce.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(r);if(!t)return null;try{return ue(decodeURIComponent(t[1]))}catch{return null}}var L=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}async getClient(){return K()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function s(r,e,t){let n=document.createElement(r);if(e){for(let[o,i]of Object.entries(e))if(!(i==null||i===false))if(o==="class")n.className=String(i);else if(o==="part")n.setAttribute("part",String(i));else if(o==="text")n.textContent=String(i);else if(o==="html")n.innerHTML=String(i);else if(o==="attrs"&&typeof i=="object"&&i!==null)for(let[a,c]of Object.entries(i))n.setAttribute(a,c);else n.setAttribute(o,String(i));}return n}function N(r,e){let t=null;return (...n)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>r(...n),e);}}function w(r,e,t){r.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var x="Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";function Q(r,e,t){let n=document.createElement("style");n.textContent=e;let o=s("div",{class:"wrap seekmodo-cors-blocked",part:"wrap cors-blocked",attrs:{role:"status"}});o.append(s("div",{class:"cors-notice",part:"cors-notice",text:t?.message??x})),r.replaceChildren(n,o);}var F="seekmodo-cors-notice";function V(r,e){if(!r||typeof document>"u")return;let t=r.closest(".search-form")??r.parentNode;if(!t)return;t.style.position=t.style.position||"relative";let n=t.querySelector(`.${F}`);if(!n){n=document.createElement("div"),n.className=F,n.setAttribute("role","status"),n.style.cssText=["position:absolute","top:100%","left:0","right:0","z-index:10050","display:none","background:#fff8e6","border:1px solid #f0c040","border-top:none","padding:8px 12px","font-size:13px","line-height:1.4","color:#5c4a00","box-shadow:0 4px 12px rgba(0,0,0,.08)"].join(";"),t.appendChild(n);let o=()=>{if(!n)return;let i=(r.value||"").trim();n.style.display=i.length>=2?"block":"none";};r.addEventListener("input",o),r.addEventListener("focus",o);}n.textContent=e??x;}function W(){typeof window>"u"||(window.seekmodoShowCorsNotice=V,window.seekmodoScriptLoadFailed=(r,e)=>{let t=r??document.querySelectorAll('input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]');for(let n=0;n<t.length;n++){let o=t[n];o instanceof HTMLInputElement&&V(o,e);}});}var z="split-rail",he=15;function _(r,e){let n={"split-rail":5,"command-bar":5,"cinema-grid":6,magazine:6,classic:5}[r]??5,o=Math.max(e,n*3);return Math.min(he,o)}var G=`
  .wrap.wide { padding: 0; overflow-x: hidden; overflow-y: auto; }
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
  .rail {
    border-right: 1px solid var(--_border); background: var(--_row-hover);
    padding: 0.5rem 0.35rem; overflow-y: auto; align-self: stretch;
  }
  .rail .row { padding: 0.4rem 0.55rem; font-size: 0.8125rem; }
  .canvas {
    padding: 0.65rem 0.75rem 0.75rem; min-width: 0;
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
    border-radius: calc(var(--_radius) - 0.2rem); background: var(--_row-active);
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
  @media (max-width: 900px) {
    .split-body { grid-template-columns: 1fr; }
    .rail { border-right: none; border-bottom: 1px solid var(--_border); max-height: 180px; }
    .product-grid.cols-5, .product-grid.cols-6 { grid-template-columns: repeat(3, 1fr); }
    .hero-row { grid-template-columns: 1fr; }
  }
`,me="https://seekmodo.com/email-assets/seekmodo-lockup.png";function Y(r,e,t){let n={block:"products",data:e,value:String(e.name??e.title??""),id:e.id!==void 0?String(e.id):void 0},o=r.rows.length;return r.rows.push(n),o}function ge(r){let e=s("div",{class:"thumb-frame"}),t=r.image_url??r.image;return t?e.append(s("img",{class:"thumb",part:"thumb",attrs:{src:t,alt:"",loading:"lazy",decoding:"async"}})):e.append(s("div",{class:"thumb-empty",part:"thumb thumb--empty"})),e}function fe(r){let e=r.image_url??r.image;return e?s("img",{class:"thumb",part:"thumb",attrs:{src:e,alt:"",loading:"lazy",decoding:"async"}}):s("div",{class:"thumb-empty",part:"thumb thumb--empty"})}function J(r,e,t="card-price"){if(r.price===void 0||r.price===null)return null;let n=s("div",{class:t,part:"price"});return r.on_sale&&typeof r.sale_price=="number"?(n.append(s("del",{text:e.formatPrice(r.price,r.currency)})),n.append(document.createTextNode(e.formatPrice(r.sale_price,r.currency)))):n.append(document.createTextNode(e.formatPrice(r.price,r.currency))),n}function X(r,e,t){r.classList.add("row"),r.setAttribute("data-seekmodo-surface","suggest"),r.setAttribute("data-seekmodo-block","products"),r.setAttribute("data-seekmodo-pos",String(t));let n=e.rows[t];n?.id&&r.setAttribute("data-seekmodo-id",n.id),r.addEventListener("click",()=>e.onRowClick(t));}function be(r,e,t,n=false){let o=Y(r,e),i=s("button",{class:"product-card",part:"row",type:"button"});i.append(ge(e)),i.append(s("span",{class:"card-title",part:"name",text:String(e.name??e.title??"")}));let a=J(e,r,"card-price");return a&&i.append(a),X(i,r,o),i}function we(r,e,t,n){let o=Y(r,e),i=s("button",{class:"hero-card",part:"row",type:"button"});n&&i.append(s("span",{class:"hero-badge",text:n})),i.append(fe(e));let a=s("div",{class:"hero-info"});a.append(s("span",{class:"card-title",part:"name",text:String(e.name??e.title??"")}));let c=J(e,r);return c&&a.append(c),i.append(a),X(i,r,o),i}function M(r){let e=r.res.meta?.total??0,t=s("div",{class:"meta-bar",part:"meta-bar"}),n=s("div");n.append(s("span",{class:"count",text:`${e} results for `})),n.append(s("span",{class:"query",text:`"${r.lastQuery}"`})),t.append(n);let o=s("a",{class:"view-all view-all-cta",part:"view-all",attrs:{href:r.viewAllHref},text:r.label("view_all").replace("{total}",String(e))});return o.addEventListener("click",i=>{i.preventDefault(),r.onViewAll();}),t.append(o),t}function ve(r){let e=r.res.did_you_mean;if(!e)return null;let t=s("div",{class:"did-you-mean-bar",part:"did-you-mean"});t.append(document.createTextNode(`Showing results for "${r.lastQuery}". Search instead for `));let n=s("button",{class:"swap",type:"button",text:e}),o=r.rows.length;return r.rows.push({block:"did_you_mean",data:{value:e},value:e}),n.addEventListener("click",()=>r.onRowClick(o)),t.append(n),t.append(document.createTextNode("?")),t}function I(r,e){let t=s("div",{class:"chip-row filter-bar",part:"filter-bar"});return t.append(s("span",{class:"filter-label",text:"Category"})),e.forEach((n,o)=>{let i=s("button",{class:`chip${o===0?" active":""}`,type:"button",text:`${n.name}${typeof n.count=="number"?` ${n.count}`:""}`}),a=r.rows.length;r.rows.push({block:"categories",data:n,value:String(n.name??"")}),i.addEventListener("click",()=>r.onRowClick(a)),t.append(i);}),t}function j(r,e,t="Try"){let n=s("div",{class:"chip-row",part:"filter-bar"});return n.append(s("span",{class:"filter-label",text:t})),e.forEach(o=>{let i=s("button",{class:"chip",type:"button",text:o.keyword}),a=r.rows.length;r.rows.push({block:"keywords",data:o,value:o.keyword}),i.addEventListener("click",()=>r.onRowClick(a)),n.append(i);}),n}function P(r,e,t,n,o){let i=r.rows.length;r.rows.push({block:e,data:t,value:n});let a=s("button",{class:"row",part:"row",type:"button"});return a.append(s("div",{class:"name",part:"name",text:n})),o&&a.append(s("span",{class:"badge",part:"badge",text:o})),a.setAttribute("data-seekmodo-surface","suggest"),a.setAttribute("data-seekmodo-block",e),a.setAttribute("data-seekmodo-pos",String(i)),a.addEventListener("click",()=>r.onRowClick(i)),a}function D(r,e){if(e.length===0)return null;let t=s("div",{class:"rail-section"});return t.append(s("div",{class:"group-title",part:"group-title",text:r})),e.forEach(n=>t.append(n)),t}function ye(r){if(!r.showBranding)return null;let e=s("a",{class:"brand-footer",part:"brand-footer",attrs:{href:r.brandUrl,target:"_blank",rel:"noopener noreferrer"}});return e.append(s("span",{class:"brand-by",text:"Powered by "})),e.append(s("img",{class:"brand-logo",part:"brand-logo",attrs:{src:r.brandLogoUrl||me,alt:"Seekmodo",height:"16"}})),e}function y(r,e,t){let n=s("div",{class:`product-grid cols-${t}`,part:"product-grid"});return e.forEach((o,i)=>n.append(be(r,o,i,true))),n}function Z(r,e){let t=s("div",{class:"wrap wide",part:"wrap"});t.append(s("slot",{attrs:{name:"header"}}));let n=(e.res.products??[]).slice(0,_(r,e.limit)),o=(e.res.keywords??[]).slice(0,e.limit),i=(e.res.categories??[]).slice(0,e.limit),a=(e.res.recent??[]).slice(0,5);(e.res.trending??[]).slice(0,5);if(r==="split-rail"){t.append(M(e));let d=s("div",{class:"split-body"}),l=s("aside",{class:"rail",part:"rail"}),u=o.map(b=>P(e,"keywords",b,b.keyword)),h=D(e.label("keywords"),u);h&&l.append(h);let m=a.map(b=>P(e,"recent",b,b.keyword)),g=D(e.label("recent"),m);g&&l.append(g);let H=i.map(b=>P(e,"categories",b,String(b.name),typeof b.count=="number"?String(b.count):void 0)),B=D(e.label("categories"),H);B&&l.append(B),d.append(l);let O=s("div",{class:"canvas"});O.append(y(e,n,5)),d.append(O),t.append(d);}else if(r==="cinema-grid"){let d=ve(e);d&&t.append(d),t.append(M(e)),i.length&&t.append(I(e,i)),o.length&&t.append(j(e,o));let l=s("div",{class:"canvas"});l.append(y(e,n,6)),t.append(l);}else if(r==="command-bar"){let d=e.res.meta?.total??0,l=s("div",{class:"command-header",part:"meta-bar"});l.append(s("div",{class:"query-display",text:`"${e.lastQuery}"`})),l.append(s("span",{class:"result-pill",text:`${d} products`}));let u=s("a",{class:"view-all-link",part:"view-all",attrs:{href:e.viewAllHref},text:"View all \u2192"});if(u.addEventListener("click",m=>{m.preventDefault(),e.onViewAll();}),l.append(u),t.append(l),e.res.did_you_mean){let m=s("div",{class:"chip-row"});m.append(s("span",{class:"filter-label",text:"Did you mean"}));let g=s("button",{class:"chip",type:"button",text:e.res.did_you_mean}),H=e.rows.length;e.rows.push({block:"did_you_mean",data:{value:e.res.did_you_mean},value:e.res.did_you_mean}),g.addEventListener("click",()=>e.onRowClick(H)),m.append(g),t.append(m);}o.length&&t.append(j(e,o,"Related")),i.length&&t.append(I(e,i));let h=s("div",{class:"canvas"});h.append(y(e,n,5)),t.append(h);}else if(r==="magazine"){t.append(M(e)),i.length&&t.append(I(e,i));let d=s("div",{class:"canvas"}),l=n.slice(0,3),u=n.slice(3);if(l.length){d.append(s("div",{class:"group-title",part:"group-title",text:"Best matches"}));let h=s("div",{class:"hero-row",part:"hero-row"});l.forEach((m,g)=>{h.append(we(e,m,g,g===0?"Top match":void 0));}),d.append(h);}u.length?(d.append(s("div",{class:"group-title",part:"group-title",text:"More results"})),d.append(y(e,u,6))):l.length||d.append(y(e,n,6)),t.append(d);}let p=ye(e);return p&&t.append(p),t.append(s("slot",{attrs:{name:"footer"}})),t}function A(r){return r!=="classic"&&r!==""}var ee=["recent","did_you_mean","keywords","trending","products","categories"],ke={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing.",cors_blocked:x},te=`
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
  ${G}
`,R=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let n=this.map.keys().next().value;if(n===void 0)break;this.map.delete(n);}}clear(){this.map.clear();}},C=class extends L{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","limit","cache-size","view-all-href","lang","anchor","anchor-offset","anchor-min-width","layout","show-branding","brand-url","brand-logo-url","suppress-legacy"]}current=null;loading=false;corsBlocked=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;fetchToken=0;inflight=null;cache=new R(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorApplied=false;suppressedLegacyEls=new WeakSet;legacySuppressionRetryHandler=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),this.scheduleLegacySuppressionRetries(),this.scheduleRender();}disconnectedCallback(){this.unscheduleLegacySuppressionRetries(),this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"||e==="layout"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;this.debouncedAt===e&&this.debounced||(this.debouncedAt=e,this.debounced=N(t=>{this.fetch(t);},e));}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new R(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let n=document.getElementById(e);if(n){this.subscribed=n,n.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let n=document.getElementById(t);n instanceof HTMLInputElement&&(this.inputEl=n,n.addEventListener("input",this.onPlainInput),n.addEventListener("focus",this.onPlainFocus),n.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}bindAnchorListeners(){this.anchorScrollHandler=()=>this.applyAnchor(),this.anchorResizeHandler=()=>this.applyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let n=this.inputEl??this.subscribed;n&&(t===n||n.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let n=t.getBoundingClientRect();if(n.width<=0&&n.height<=0){this.style.visibility="hidden";return}let o=parseInt(this.getAttribute("anchor-offset")??"4",10),i=Number.isFinite(o)?o:4,a=this.getAttribute("anchor-min-width"),c=A(this.layoutMode()),p=a===null?c?960:480:Math.max(0,parseInt(a,10)||0),d=typeof window<"u"&&window.innerWidth>0?window.innerWidth:Math.max(n.width,p),l=Math.min(d*.96,1440),u=c?Math.max(n.width,Math.min(Math.max(p,n.width),l)):Math.max(n.width,p),h=c?l:Math.max(0,d-n.left-8),m=c?Math.min(u,h):Math.max(n.width,Math.min(u,h)),g=c?Math.max(8,(d-m)/2):n.left;this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${n.bottom+i}px`,this.style.left=`${g}px`,this.style.width=`${m}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true;}clearAnchor(){this.anchorApplied&&(this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(o=>o.trim()).filter(Boolean),n=this.inputEl;if(n)for(let o of t)o==="jquery-ui"?this.suppressJqueryUiAutocomplete(n):o==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(n);}suppressJqueryUiAutocomplete(e){let n=window.jQuery;if(!n||!n.ui||!n.ui.autocomplete)return;let o=n(e);if(o.data("ui-autocomplete")){try{o.autocomplete("close");}catch{}try{o.autocomplete("destroy");}catch{}}let i=o.attr("aria-owns");if(i){let a=document.getElementById(i);a&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));}document.querySelectorAll("ul.ui-autocomplete").forEach(a=>{let c=a.getAttribute("id");if(!c)return;document.querySelector(`[aria-owns="${CSS.escape(c)}"]`)===e&&(a.classList.add("seekmodo-suggest-legacy-suppressed"),a.style.display="none",this.suppressedLegacyEls.add(a));});}scheduleLegacySuppressionRetries(){this.unscheduleLegacySuppressionRetries();let e=()=>{this.applyLegacySuppression();};this.legacySuppressionRetryHandler=e,setTimeout(e,0),setTimeout(e,50),document.readyState==="loading"&&document.addEventListener("DOMContentLoaded",e,{once:true}),window.addEventListener("load",e,{once:true});}unscheduleLegacySuppressionRetries(){this.legacySuppressionRetryHandler=null;}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(n=>{n.style.display="none",this.suppressedLegacyEls.add(n);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),n=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<n){this.lastQuery=t,this.current=null,this.loading=false,this.corsBlocked=false,this.inflight?.abort(),this.scheduleRender();return}this.lastQuery=t,this.corsBlocked=false;let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}cacheKey(e){return e.toLowerCase()}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let n=++this.fetchToken;try{let o=await this.getClient(),i=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.layoutMode(),c=A(a)?_(a,i):i,p=this.getSessionId(),d={q:e,limit:c};p&&(d.session_id=p);let l=await o.suggest(d);if(n!==this.fetchToken||t.signal.aborted)return;this.current=l,this.loading=!1,this.cache.set(this.cacheKey(e),l),this.emitOpen(e),this.isEmpty(l)&&w(this,"seekmodo-suggest:empty",{q:e}),this.scheduleRender();}catch(o){if(n!==this.fetchToken||t.signal.aborted)return;this.corsBlocked=k(o),this.current=null,this.loading=false,this.corsBlocked?(w(this,"seekmodo-suggest:cors-blocked",{q:e}),console.warn("[seekmodo-suggest] blocked by CORS or network policy",o)):console.warn("[seekmodo-suggest] fetch failed",o),this.scheduleRender();}}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}blocks(){let e=this.getAttribute("blocks");if(!e)return ee;let t=e.split(",").map(n=>n.trim()).filter(n=>["recent","trending","did_you_mean","keywords","products","categories"].includes(n));return t.length>0?t:ee}label(e){return ke[e]??e}layoutMode(){let e=(this.getAttribute("layout")??z).trim();return e==="classic"||e==="cinema-grid"||e==="command-bar"||e==="magazine"||e==="split-rail"?e:z}showBrandingFlag(){let e=(this.getAttribute("show-branding")??"true").trim().toLowerCase();return e!=="false"&&e!=="0"&&e!=="no"}dismiss(){this.current===null&&!this.loading||(w(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.scheduleRender());}emitOpen(e){w(this,"seekmodo-suggest:open",{q:e});}navigateViewAll(){let e=this.current?.meta?.total??0;w(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:e});let t=this.getAttribute("view-all-href")??"/search?q={q}";window.location.assign(t.replace("{q}",encodeURIComponent(this.lastQuery)));}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,n)=>{n===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];t&&w(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id,position:e+1});}render(){let e=document.createElement("style");if(e.textContent=te,this.loading&&this.current===null&&!this.corsBlocked){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.corsBlocked){Q(this.root,te,{message:this.label("cors_blocked")}),this.rows=[],this.active=-1,this.applyAnchor();return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(this.current)){let p=s("slot",{attrs:{name:"empty"}}),d=s("div",{class:"empty",text:this.label("empty")});p.append(d);let l=s("div",{class:"wrap",part:"wrap"});l.append(p),this.root.replaceChildren(e,l),this.rows=[],this.active=-1;return}let t=s("div",{class:"wrap",part:"wrap"});t.append(s("slot",{attrs:{name:"header"}}));let n=[],o=parseInt(this.getAttribute("limit")??"5",10)||5,i=this.layoutMode();if(A(i)){let d=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(this.lastQuery)),l=Z(i,{res:this.current,lastQuery:this.lastQuery,limit:_(i,o),label:u=>this.label(u),rows:n,onRowClick:u=>this.activateRow(u),onViewAll:()=>this.navigateViewAll(),viewAllHref:d,showBranding:this.showBrandingFlag(),brandUrl:this.getAttribute("brand-url")??"https://seekmodo.com",brandLogoUrl:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",formatPrice:T});this.rows=n,this.active=-1,this.root.replaceChildren(e,l),this.applyAnchor();return}let a=this.blocks();for(let p of a){let d=this.renderBlock(p,this.current,o,n);d&&t.append(d);}this.rows=n,this.active=-1;let c=this.current.meta?.total??0;if(c>0&&this.lastQuery.length>0){let d=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(this.lastQuery)),l=s("a",{class:"view-all",part:"view-all",attrs:{href:d},text:this.label("view_all").replace("{total}",String(c))});l.addEventListener("click",u=>{u.preventDefault(),this.navigateViewAll();}),t.append(l);}if(t.append(s("slot",{attrs:{name:"footer"}})),this.showBrandingFlag()){let p=s("a",{class:"brand-footer",part:"brand-footer",attrs:{href:this.getAttribute("brand-url")??"https://seekmodo.com",target:"_blank",rel:"noopener noreferrer"}});p.append(s("span",{class:"brand-by",text:"Powered by "})),p.append(s("img",{class:"brand-logo",part:"brand-logo",attrs:{src:this.getAttribute("brand-logo-url")??"https://seekmodo.com/email-assets/seekmodo-lockup.png",alt:"Seekmodo",height:"16"}})),t.append(p);}this.root.replaceChildren(e,t),this.applyAnchor();}renderSkeleton(){let e=s("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let n=s("div",{class:"row",part:"row skeleton"});n.append(s("div",{class:"thumb",part:"thumb"}));let o=s("div",{class:"name"});o.append(s("span",{class:"name-title"})),o.append(s("span",{class:"name-meta"})),n.append(o),e.append(n);}return e}renderBlock(e,t,n,o){if(e==="did_you_mean"){let c=t.did_you_mean;if(!c)return null;let p=s("div",{class:"group",part:"group did-you-mean"});p.append(s("slot",{attrs:{name:"did_you_mean"}}));let d=s("div",{class:"did-you-mean"});d.append(document.createTextNode(this.label("did_you_mean")+" "));let l=s("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:c});return l.addEventListener("click",()=>{w(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:c},q:this.lastQuery,value:c});}),d.append(l),p.append(d),p}let i=this.blockData(e,t,n);if(i.length===0)return null;let a=s("div",{class:"group",part:"group",attrs:{"data-block":e}});return a.append(s("slot",{attrs:{name:e}})),a.append(s("div",{class:"group-title",part:"group-title",text:this.label(e)})),i.forEach((c,p)=>{let d={block:e,data:c,value:this.rowValue(e,c),id:this.rowId(e,c)};o.push(d);let l=o.length-1,u=window.seekmodoSuggest?.renderRow?.(d.data,e),h;u instanceof HTMLElement?(h=u,h.classList.add("row")):typeof u=="string"&&u.length>0?(h=s("button",{class:"row",part:"row",type:"button"}),h.innerHTML=u):h=this.renderRowDefault(e,c,p),h.setAttribute("data-seekmodo-surface","suggest"),h.setAttribute("data-seekmodo-block",e),h.setAttribute("data-seekmodo-pos",String(l)),d.id&&h.setAttribute("data-seekmodo-id",d.id),h.addEventListener("click",()=>this.activateRow(l)),a.append(h);}),a}blockData(e,t,n){switch(e){case "recent":return (t.recent??[]).slice(0,n);case "trending":return (t.trending??[]).slice(0,n);case "keywords":return (t.keywords??[]).slice(0,n);case "products":return (t.products??[]).slice(0,n);case "categories":return (t.categories??[]).slice(0,n);default:return []}}rowValue(e,t){let n=t;return e==="recent"||e==="trending"||e==="keywords"?String(n.keyword??""):e==="products"?String(n.name??n.title??""):e==="categories"?String(n.name??""):""}rowId(e,t){if(e!=="products")return;let n=t.id;return n!==void 0?String(n):void 0}renderRowDefault(e,t,n){let o=s("button",{class:"row",part:"row",type:"button"});if(e==="products"){let i=t,a=i.image_url??i.image;a?o.append(s("img",{class:"thumb",part:"thumb",attrs:{src:a,alt:"",loading:"lazy",decoding:"async"}})):o.append(s("div",{class:"thumb",part:"thumb"}));let c=s("div",{class:"name",part:"name"});c.append(s("span",{class:"name-title",text:i.name??""}));let p=[i.brand?String(i.brand):"",i.sku??i.model??i.ez_number??""].filter(Boolean);p.length>0&&c.append(s("span",{class:"name-meta",text:p.join(" \xB7 ")})),o.append(c);let d=this.renderPrice(i);return d&&o.append(d),o}if(e==="categories"){let i=t,a=s("div",{class:"name",part:"name",text:i.name});return o.append(a),typeof i.count=="number"&&i.count>0&&o.append(s("span",{class:"badge",part:"badge",text:String(i.count)})),o}if(e==="recent"||e==="trending"||e==="keywords"){let i=t,a=s("div",{class:"name",part:"name",text:String(i.keyword)});return o.append(a),e==="trending"&&typeof i.search_count=="number"&&o.append(s("span",{class:"badge",part:"badge",text:String(i.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=s("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(t.append(s("del",{text:T(e.price,e.currency)})),t.append(document.createTextNode(T(e.sale_price,e.currency)))):t.append(document.createTextNode(T(e.price,e.currency))),t}};function T(r,e){try{return new Intl.NumberFormat(void 0,{style:"currency",currency:e??"USD",maximumFractionDigits:2}).format(r)}catch{return String(r)}}W();typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",C);
exports.SeekmodoSuggest=C;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map