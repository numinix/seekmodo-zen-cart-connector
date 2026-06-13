var SeekmodoSuggest=(function(exports){'use strict';var y=class extends Error{status;body;tool;constructor(r,e,t,n){super(r),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=n;}},L=class extends y{constructor(r,e,t){super(`Seekmodo auth failed (HTTP ${r})`,r,e,t),this.name="SeekmodoAuthError";}},H=class extends y{code;bucket;limit;used;constructor(r,e){super("Seekmodo over quota (HTTP 402)",402,r,e),this.name="SeekmodoQuotaError";let t=r??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},C=class extends y{constructor(r,e,t){super(`Seekmodo server error (HTTP ${r})`,r,e,t),this.name="SeekmodoServerError";}},I=class extends y{constructor(r,e,t){super(`Seekmodo request rejected (HTTP ${r})`,r,e,t),this.name="SeekmodoRequestError";}},M=class extends y{constructor(r,e){super(`Seekmodo network failure${r instanceof Error?`: ${r.message}`:""}`,0,r,e),this.name="SeekmodoNetworkError";}},D="https://gateway.seekmodo.com",P=8e3,z=class{config;cachedToken=null;constructor(r){if(!r.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof r.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:r.tenantId,getToken:r.getToken,baseUrl:(r.baseUrl??D).replace(/\/+$/,""),fetch:r.fetch??globalThis.fetch.bind(globalThis),timeoutMs:r.timeoutMs??P,signal:r.signal,onError:r.onError,getRegion:r.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(r,e,t={}){try{return await this.callOnce(r,e,t,!1)}catch(n){if(n instanceof L){this.clearTokenCache();try{return await this.callOnce(r,e,t,!0)}catch(s){throw this.config.onError?.(s,{tool:r}),s}}throw this.config.onError?.(n,{tool:r}),n}}async callOnce(r,e,t,n){let s=await this.resolveToken(n),o=`${this.config.baseUrl}/v1/${encodeURIComponent(r)}`,i=new AbortController,l=t.timeoutMs??this.config.timeoutMs,c=setTimeout(()=>i.abort(),l),d=()=>i.abort();this.config.signal?.addEventListener("abort",d,{once:true}),t.signal?.addEventListener("abort",d,{once:true});let m={"Content-Type":"application/json",Authorization:`Bearer ${s}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let v=await this.config.getRegion();typeof v=="string"&&v.length>0&&(m["Seekmodo-Region"]=v);}catch{}let u;try{u=await this.config.fetch(o,{method:"POST",headers:m,body:JSON.stringify(e),signal:i.signal});}catch(v){throw new M(v,r)}finally{clearTimeout(c),this.config.signal?.removeEventListener("abort",d),t.signal?.removeEventListener("abort",d);}let h=await u.text(),f=h?K(h):null;if(u.status===401||u.status===403)throw new L(u.status,f,r);if(u.status===402)throw new H(f,r);if(u.status>=500)throw new C(u.status,f,r);if(!u.ok)throw new I(u.status,f,r);return f}async resolveToken(r){let e=Date.now();if(!r&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function K(r){try{return JSON.parse(r)}catch{return r}}var A=class{transport;recommend;bundle;constructor(r){this.transport=new z(r),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(r,e){return this.transport.call("search",{...r},e??{})}suggest(r,e){return this.transport.call("suggest",{...r},e??{})}searchByImage(r,e){return this.transport.call("search.byImage",{...r},e??{})}chat(r,e){return this.transport.call("chat",{...r},e??{})}event(r,e){return this.transport.call("events",{...r},e??{})}};var w=null,p=null;function b(r){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${r}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function T(){return w!==null||(w=q()),w}async function q(){let r=b("seekmodo:tenant");if(!r)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=b("seekmodo:token"),t=b("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(p={token:e,expiresAt:Date.now()+3e4});let n=b("seekmodo:gateway")??void 0;return new A({tenantId:r,baseUrl:n,getRegion:()=>$(),getToken:async()=>{let s=Date.now();if(p&&p.expiresAt-1e4>s)return {token:p.token,expiresAt:p.expiresAt};if(!t){if(p)return {token:p.token,expiresAt:p.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let o=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!o.ok)throw new Error(`seekmodo:refresh route returned HTTP ${o.status}`);let i=await o.json();if(!i.token||typeof i.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return p={token:i.token,expiresAt:i.expires_at*1e3},{token:p.token,expiresAt:p.expiresAt}}})}var B="seekmodo_region";function O(r){if(typeof r!="string")return null;let e=r.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function $(){if(typeof document>"u")return null;let r=document.cookie??"";if(r.length===0)return null;let e=B.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(r);if(!t)return null;try{return O(decodeURIComponent(t[1]))}catch{return null}}var k=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}async getClient(){return T()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function a(r,e,t){let n=document.createElement(r);if(e){for(let[s,o]of Object.entries(e))if(!(o==null||o===false))if(s==="class")n.className=String(o);else if(s==="part")n.setAttribute("part",String(o));else if(s==="text")n.textContent=String(o);else if(s==="html")n.innerHTML=String(o);else if(s==="attrs"&&typeof o=="object"&&o!==null)for(let[i,l]of Object.entries(o))n.setAttribute(i,l);else n.setAttribute(s,String(o));}return n}function _(r,e){let t=null;return (...n)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>r(...n),e);}}function g(r,e,t){r.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var R=["recent","did_you_mean","keywords","trending","products","categories"],U={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing."},V=`
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
    object-fit: cover;
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
    border-top: 1px solid var(--_border);
    text-align: center;
    font-weight: 600;
    padding: 0.55rem 0.75rem;
  }
  .empty {
    padding: 0.75rem;
    color: var(--_group-color);
    text-align: center;
    font-size: 0.9em;
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
`,E=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let n=this.map.keys().next().value;if(n===void 0)break;this.map.delete(n);}}clear(){this.map.clear();}},S=class extends k{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","limit","cache-size","view-all-href","lang","anchor","anchor-offset","anchor-min-width","suppress-legacy"]}current=null;loading=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;fetchToken=0;inflight=null;cache=new E(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;anchorScrollHandler=null;anchorResizeHandler=null;anchorFocusHandler=null;anchorApplied=false;suppressedLegacyEls=new WeakSet;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.bindAnchorListeners(),this.applyAnchor(),this.applyLegacySuppression(),setTimeout(()=>this.applyLegacySuppression(),0),this.scheduleRender();}disconnectedCallback(){this.unsubscribe(),this.unbindGlobalListeners(),this.unbindAnchorListeners(),this.restoreLegacyOnDetach(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe(),this.applyAnchor(),this.applyLegacySuppression()):e==="debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():e==="anchor"||e==="anchor-offset"||e==="anchor-min-width"?this.applyAnchor():e==="suppress-legacy"?(this.restoreLegacyOnDetach(),this.applyLegacySuppression()):this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;this.debouncedAt===e&&this.debounced||(this.debouncedAt=e,this.debounced=_(t=>{this.fetch(t);},e));}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new E(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let n=document.getElementById(e);if(n){this.subscribed=n,n.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let n=document.getElementById(t);n instanceof HTMLInputElement&&(this.inputEl=n,n.addEventListener("input",this.onPlainInput),n.addEventListener("focus",this.onPlainFocus),n.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}bindAnchorListeners(){this.anchorScrollHandler=()=>this.applyAnchor(),this.anchorResizeHandler=()=>this.applyAnchor(),this.anchorFocusHandler=e=>{let t=e.target;if(!(t instanceof Element))return;let n=this.inputEl??this.subscribed;n&&(t===n||n.contains(t))&&(this.applyAnchor(),this.applyLegacySuppression());},window.addEventListener("scroll",this.anchorScrollHandler,{passive:true}),window.addEventListener("resize",this.anchorResizeHandler),window.addEventListener("orientationchange",this.anchorResizeHandler),document.addEventListener("focusin",this.anchorFocusHandler),window.visualViewport?.addEventListener("resize",this.anchorResizeHandler);}unbindAnchorListeners(){this.anchorScrollHandler&&(window.removeEventListener("scroll",this.anchorScrollHandler),this.anchorScrollHandler=null),this.anchorResizeHandler&&(window.removeEventListener("resize",this.anchorResizeHandler),window.removeEventListener("orientationchange",this.anchorResizeHandler),window.visualViewport?.removeEventListener("resize",this.anchorResizeHandler),this.anchorResizeHandler=null),this.anchorFocusHandler&&(document.removeEventListener("focusin",this.anchorFocusHandler),this.anchorFocusHandler=null);}applyAnchor(){if(typeof window>"u")return;let e=(this.getAttribute("anchor")??"auto").trim();if(e==="none"||e===""){this.clearAnchor();return}let t=null;if(e==="auto")t=this.inputEl??this.subscribed;else try{t=document.querySelector(e);}catch{t=null;}if(!t){this.clearAnchor();return}let n=t.getBoundingClientRect();if(n.width<=0&&n.height<=0){this.style.visibility="hidden";return}let s=parseInt(this.getAttribute("anchor-offset")??"4",10),o=Number.isFinite(s)?s:4,i=this.getAttribute("anchor-min-width"),l=i===null?320:Math.max(0,parseInt(i,10)||0),c=Math.max(n.width,l);this.style.position="fixed",this.style.zIndex=this.style.zIndex||"10000",this.style.top=`${n.bottom+o}px`,this.style.left=`${n.left}px`,this.style.width=`${c}px`,this.style.visibility="",this.style.display=this.style.display||"block",this.anchorApplied=true;}clearAnchor(){this.anchorApplied&&(this.style.position="",this.style.top="",this.style.left="",this.style.width="",this.style.visibility="",this.style.zIndex="",this.anchorApplied=false);}applyLegacySuppression(){let e=this.getAttribute("suppress-legacy");if(!e)return;let t=e.split(",").map(s=>s.trim()).filter(Boolean),n=this.inputEl;if(n)for(let s of t)s==="jquery-ui"?this.suppressJqueryUiAutocomplete(n):s==="seekmodo-typeahead"&&this.suppressLegacyTypeahead(n);}suppressJqueryUiAutocomplete(e){let n=window.jQuery;if(!n||!n.ui||!n.ui.autocomplete)return;let s=n(e);if(s.data("ui-autocomplete"))try{s.autocomplete("destroy");}catch{}let o=s.attr("aria-owns");if(o){let i=document.getElementById(o);i&&(i.classList.add("seekmodo-suggest-legacy-suppressed"),i.style.display="none",this.suppressedLegacyEls.add(i));}document.querySelectorAll("ul.ui-autocomplete").forEach(i=>{let l=i.getAttribute("id");if(!l)return;document.querySelector(`[aria-owns="${CSS.escape(l)}"]`)===e&&(i.classList.add("seekmodo-suggest-legacy-suppressed"),i.style.display="none",this.suppressedLegacyEls.add(i));});}suppressLegacyTypeahead(e){let t=e.id;t&&document.querySelectorAll(`seekmodo-typeahead[input="${CSS.escape(t)}"]`).forEach(n=>{n.style.display="none",this.suppressedLegacyEls.add(n);});}restoreLegacyOnDetach(){let e=[];document.querySelectorAll(".seekmodo-suggest-legacy-suppressed").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.classList.remove("seekmodo-suggest-legacy-suppressed"),t.style.display="",e.push(t));}),document.querySelectorAll("seekmodo-typeahead").forEach(t=>{this.suppressedLegacyEls.has(t)&&(t.style.display="",e.push(t));});for(let t of e)this.suppressedLegacyEls.delete(t);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),n=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<n){this.lastQuery=t,this.current=null,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.lastQuery=t;let s=this.cache.get(this.cacheKey(t));if(s){this.current=s,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}cacheKey(e){return e.toLowerCase()}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let n=++this.fetchToken;try{let s=await this.getClient(),o=parseInt(this.getAttribute("limit")??"5",10)||5,i=this.getSessionId(),l={q:e,limit:o};i&&(l.session_id=i);let c=await s.suggest(l);if(n!==this.fetchToken||t.signal.aborted)return;this.current=c,this.loading=!1,this.cache.set(this.cacheKey(e),c),this.emitOpen(e),this.isEmpty(c)&&g(this,"seekmodo-suggest:empty",{q:e}),this.scheduleRender();}catch(s){if(n!==this.fetchToken||t.signal.aborted)return;this.current=null,this.loading=false,console.warn("[seekmodo-suggest] fetch failed",s),this.scheduleRender();}}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}blocks(){let e=this.getAttribute("blocks");if(!e)return R;let t=e.split(",").map(n=>n.trim()).filter(n=>["recent","trending","did_you_mean","keywords","products","categories"].includes(n));return t.length>0?t:R}label(e){return U[e]??e}dismiss(){this.current===null&&!this.loading||(g(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.scheduleRender());}emitOpen(e){g(this,"seekmodo-suggest:open",{q:e});}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,n)=>{n===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];t&&g(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id});}render(){let e=document.createElement("style");if(e.textContent=V,this.loading&&this.current===null){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(this.current)){let l=a("slot",{attrs:{name:"empty"}}),c=a("div",{class:"empty",text:this.label("empty")});l.append(c);let d=a("div",{class:"wrap",part:"wrap"});d.append(l),this.root.replaceChildren(e,d),this.rows=[],this.active=-1;return}let t=a("div",{class:"wrap",part:"wrap"});t.append(a("slot",{attrs:{name:"header"}}));let n=[],s=parseInt(this.getAttribute("limit")??"5",10)||5,o=this.blocks();for(let l of o){let c=this.renderBlock(l,this.current,s,n);c&&t.append(c);}this.rows=n,this.active=-1;let i=this.current.meta?.total??0;if(i>0&&this.lastQuery.length>0){let c=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(this.lastQuery)),d=a("a",{class:"view-all",part:"view-all",attrs:{href:c},text:this.label("view_all").replace("{total}",String(i))});d.addEventListener("click",()=>{g(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:i});}),t.append(d);}t.append(a("slot",{attrs:{name:"footer"}})),this.root.replaceChildren(e,t),this.applyAnchor();}renderSkeleton(){let e=a("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let n=a("div",{class:"row",part:"row skeleton"});n.append(a("div",{class:"thumb",part:"thumb"}));let s=a("div",{class:"name"});s.append(a("span",{class:"name-title"})),s.append(a("span",{class:"name-meta"})),n.append(s),e.append(n);}return e}renderBlock(e,t,n,s){if(e==="did_you_mean"){let l=t.did_you_mean;if(!l)return null;let c=a("div",{class:"group",part:"group did-you-mean"});c.append(a("slot",{attrs:{name:"did_you_mean"}}));let d=a("div",{class:"did-you-mean"});d.append(document.createTextNode(this.label("did_you_mean")+" "));let m=a("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:l});return m.addEventListener("click",()=>{g(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:l},q:this.lastQuery,value:l});}),d.append(m),c.append(d),c}let o=this.blockData(e,t,n);if(o.length===0)return null;let i=a("div",{class:"group",part:"group",attrs:{"data-block":e}});return i.append(a("slot",{attrs:{name:e}})),i.append(a("div",{class:"group-title",part:"group-title",text:this.label(e)})),o.forEach((l,c)=>{let d={block:e,data:l,value:this.rowValue(e,l),id:this.rowId(e,l)};s.push(d);let m=s.length-1,u=window.seekmodoSuggest?.renderRow?.(d.data,e),h;u instanceof HTMLElement?(h=u,h.classList.add("row")):typeof u=="string"&&u.length>0?(h=a("button",{class:"row",part:"row",type:"button"}),h.innerHTML=u):h=this.renderRowDefault(e,l,c),h.setAttribute("data-seekmodo-surface","suggest"),h.setAttribute("data-seekmodo-block",e),h.setAttribute("data-seekmodo-pos",String(m)),d.id&&h.setAttribute("data-seekmodo-id",d.id),h.addEventListener("click",()=>this.activateRow(m)),i.append(h);}),i}blockData(e,t,n){switch(e){case "recent":return (t.recent??[]).slice(0,n);case "trending":return (t.trending??[]).slice(0,n);case "keywords":return (t.keywords??[]).slice(0,n);case "products":return (t.products??[]).slice(0,n);case "categories":return (t.categories??[]).slice(0,n);default:return []}}rowValue(e,t){let n=t;return e==="recent"||e==="trending"||e==="keywords"?String(n.keyword??""):e==="products"?String(n.name??n.title??""):e==="categories"?String(n.name??""):""}rowId(e,t){if(e!=="products")return;let n=t.id;return n!==void 0?String(n):void 0}renderRowDefault(e,t,n){let s=a("button",{class:"row",part:"row",type:"button"});if(e==="products"){let o=t,i=o.image_url??o.image;i?s.append(a("img",{class:"thumb",part:"thumb",attrs:{src:i,alt:"",loading:"lazy",decoding:"async"}})):s.append(a("div",{class:"thumb",part:"thumb"}));let l=a("div",{class:"name",part:"name"});l.append(a("span",{class:"name-title",text:o.name??""}));let c=[o.brand?String(o.brand):"",o.sku??o.model??o.ez_number??""].filter(Boolean);c.length>0&&l.append(a("span",{class:"name-meta",text:c.join(" \xB7 ")})),s.append(l);let d=this.renderPrice(o);return d&&s.append(d),s}if(e==="categories"){let o=t,i=a("div",{class:"name",part:"name",text:o.name});return s.append(i),typeof o.count=="number"&&o.count>0&&s.append(a("span",{class:"badge",part:"badge",text:String(o.count)})),s}if(e==="recent"||e==="trending"||e==="keywords"){let o=t,i=a("div",{class:"name",part:"name",text:String(o.keyword)});return s.append(i),e==="trending"&&typeof o.search_count=="number"&&s.append(a("span",{class:"badge",part:"badge",text:String(o.search_count)})),s}return s}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=a("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(t.append(a("del",{text:x(e.price,e.currency)})),t.append(document.createTextNode(x(e.sale_price,e.currency)))):t.append(document.createTextNode(x(e.price,e.currency))),t}};function x(r,e){try{return new Intl.NumberFormat(void 0,{style:"currency",currency:e??"USD",maximumFractionDigits:2}).format(r)}catch{return String(r)}}typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",S);exports.SeekmodoSuggest=S;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map