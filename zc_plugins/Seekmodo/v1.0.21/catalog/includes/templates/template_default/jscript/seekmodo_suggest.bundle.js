var SeekmodoSuggest=(function(exports){'use strict';var k=class extends Error{status;body;tool;constructor(n,e,t,r){super(n),this.name="SeekmodoError",this.status=e,this.body=t,this.tool=r;}},_=class extends k{constructor(n,e,t){super(`Seekmodo auth failed (HTTP ${n})`,n,e,t),this.name="SeekmodoAuthError";}},L=class extends k{code;bucket;limit;used;constructor(n,e){super("Seekmodo over quota (HTTP 402)",402,n,e),this.name="SeekmodoQuotaError";let t=n??{};this.code=t.code??"over_quota",this.bucket=t.bucket,this.limit=t.limit,this.used=t.used;}},I=class extends k{constructor(n,e,t){super(`Seekmodo server error (HTTP ${n})`,n,e,t),this.name="SeekmodoServerError";}},H=class extends k{constructor(n,e,t){super(`Seekmodo request rejected (HTTP ${n})`,n,e,t),this.name="SeekmodoRequestError";}},M=class extends k{constructor(n,e){super(`Seekmodo network failure${n instanceof Error?`: ${n.message}`:""}`,0,n,e),this.name="SeekmodoNetworkError";}},P="https://gateway.seekmodo.com",D=8e3,K=class{config;cachedToken=null;constructor(n){if(!n.tenantId)throw new Error("Seekmodo SDK: tenantId is required");if(typeof n.getToken!="function")throw new Error("Seekmodo SDK: getToken callback is required");this.config={tenantId:n.tenantId,getToken:n.getToken,baseUrl:(n.baseUrl??P).replace(/\/+$/,""),fetch:n.fetch??globalThis.fetch.bind(globalThis),timeoutMs:n.timeoutMs??D,signal:n.signal,onError:n.onError,getRegion:n.getRegion};}clearTokenCache(){this.cachedToken=null;}async call(n,e,t={}){try{return await this.callOnce(n,e,t,!1)}catch(r){if(r instanceof _){this.clearTokenCache();try{return await this.callOnce(n,e,t,!0)}catch(o){throw this.config.onError?.(o,{tool:n}),o}}throw this.config.onError?.(r,{tool:n}),r}}async callOnce(n,e,t,r){let o=await this.resolveToken(r),s=`${this.config.baseUrl}/v1/${encodeURIComponent(n)}`,a=new AbortController,l=t.timeoutMs??this.config.timeoutMs,d=setTimeout(()=>a.abort(),l),c=()=>a.abort();this.config.signal?.addEventListener("abort",c,{once:true}),t.signal?.addEventListener("abort",c,{once:true});let p={"Content-Type":"application/json",Authorization:`Bearer ${o}`,"X-Seekmodo-Tenant":this.config.tenantId,"X-Seekmodo-SDK":"@seekmodo/sdk@0.1.0"};if(this.config.getRegion)try{let v=await this.config.getRegion();typeof v=="string"&&v.length>0&&(p["Seekmodo-Region"]=v);}catch{}let u;try{u=await this.config.fetch(s,{method:"POST",headers:p,body:JSON.stringify(e),signal:a.signal});}catch(v){throw new M(v,n)}finally{clearTimeout(d),this.config.signal?.removeEventListener("abort",c),t.signal?.removeEventListener("abort",c);}let h=await u.text(),f=h?B(h):null;if(u.status===401||u.status===403)throw new _(u.status,f,n);if(u.status===402)throw new L(f,n);if(u.status>=500)throw new I(u.status,f,n);if(!u.ok)throw new H(u.status,f,n);return f}async resolveToken(n){let e=Date.now();if(!n&&this.cachedToken&&this.cachedToken.expiresAt-1e4>e)return this.cachedToken.token;let t=await this.config.getToken();if(typeof t=="string")return this.cachedToken={token:t,expiresAt:e+6e4},t;if(t&&typeof t=="object"&&typeof t.token=="string"&&typeof t.expiresAt=="number")return this.cachedToken={token:t.token,expiresAt:t.expiresAt},t.token;throw new Error("Seekmodo SDK: getToken must return a string or { token, expiresAt }")}};function B(n){try{return JSON.parse(n)}catch{return n}}var T=class{transport;recommend;bundle;constructor(n){this.transport=new K(n),this.recommend={related:(e,t)=>this.transport.call("recommend.related",{...e},t??{}),alsoBought:(e,t)=>this.transport.call("recommend.also_bought",{...e},t??{}),alsoViewed:(e,t)=>this.transport.call("recommend.also_viewed",{...e},t??{}),trending:(e,t)=>this.transport.call("recommend.trending",{...e},t??{})},this.bundle={suggest:(e,t)=>this.transport.call("bundle.suggest",{...e},t??{})};}search(n,e){return this.transport.call("search",{...n},e??{})}suggest(n,e){return this.transport.call("suggest",{...n},e??{})}searchByImage(n,e){return this.transport.call("search.byImage",{...n},e??{})}chat(n,e){return this.transport.call("chat",{...n},e??{})}event(n,e){return this.transport.call("events",{...n},e??{})}};var b=null,m=null;function w(n){if(typeof document>"u")return null;let t=document.head?.querySelector(`meta[name="${n}"]`)?.getAttribute("content");return t&&t.length>0?t:null}function R(){return b!==null||(b=O()),b}async function O(){let n=w("seekmodo:tenant");if(!n)throw new Error('@seekmodo/web-components: <meta name="seekmodo:tenant"> is required');let e=w("seekmodo:token"),t=w("seekmodo:refresh");if(!e&&!t)throw new Error('@seekmodo/web-components: either <meta name="seekmodo:token"> or <meta name="seekmodo:refresh"> must be set');e&&(m={token:e,expiresAt:Date.now()+3e4});let r=w("seekmodo:gateway")??void 0;return new T({tenantId:n,baseUrl:r,getRegion:()=>z(),getToken:async()=>{let o=Date.now();if(m&&m.expiresAt-1e4>o)return {token:m.token,expiresAt:m.expiresAt};if(!t){if(m)return {token:m.token,expiresAt:m.expiresAt};throw new Error("seekmodo:refresh meta missing; no way to refresh token")}let s=await fetch(t,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"}});if(!s.ok)throw new Error(`seekmodo:refresh route returned HTTP ${s.status}`);let a=await s.json();if(!a.token||typeof a.expires_at!="number")throw new Error("seekmodo:refresh route returned a malformed envelope");return m={token:a.token,expiresAt:a.expires_at*1e3},{token:m.token,expiresAt:m.expiresAt}}})}var $="seekmodo_region";function q(n){if(typeof n!="string")return null;let e=n.trim().toLowerCase();return /^[a-z0-9][a-z0-9_-]{1,63}$/.test(e)?e:null}function z(){if(typeof document>"u")return null;let n=document.cookie??"";if(n.length===0)return null;let e=$.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"),t=new RegExp(`(?:^|; )${e}=([^;]+)`).exec(n);if(!t)return null;try{return q(decodeURIComponent(t[1]))}catch{return null}}var y=class extends HTMLElement{root;rafId=null;constructor(){super(),this.root=this.attachShadow({mode:"open"});}scheduleRender(){this.rafId===null&&(this.rafId=requestAnimationFrame(()=>{this.rafId=null;try{this.render();}catch(e){console.warn("[seekmodo] render failure",e);try{this.renderError("internal_error");}catch{this.root.innerHTML="";}}}));}async getClient(){return R()}renderError(e){this.root.innerHTML="";}disconnectedCallback(){this.rafId!==null&&(cancelAnimationFrame(this.rafId),this.rafId=null);}};function i(n,e,t){let r=document.createElement(n);if(e){for(let[o,s]of Object.entries(e))if(!(s==null||s===false))if(o==="class")r.className=String(s);else if(o==="part")r.setAttribute("part",String(s));else if(o==="text")r.textContent=String(s);else if(o==="html")r.innerHTML=String(s);else if(o==="attrs"&&typeof s=="object"&&s!==null)for(let[a,l]of Object.entries(s))r.setAttribute(a,l);else r.setAttribute(o,String(s));}return r}function A(n,e){let t=null;return (...r)=>{t!==null&&clearTimeout(t),t=setTimeout(()=>n(...r),e);}}function g(n,e,t){n.dispatchEvent(new CustomEvent(e,{detail:t,bubbles:true,composed:true}));}var C=["recent","did_you_mean","keywords","trending","products","categories"],N={recent:"Recently searched",trending:"Trending",keywords:"Suggestions",products:"Products",categories:"Categories",did_you_mean:"Did you mean",view_all:"View all {total} results",empty:"No matches yet \u2014 keep typing."},U=`
  :host {
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
`,E=class{constructor(e){this.cap=e;}cap;map=new Map;get(e){let t=this.map.get(e);if(t!==void 0)return this.map.delete(e),this.map.set(e,t),t}set(e,t){for(this.map.has(e)&&this.map.delete(e),this.map.set(e,t);this.map.size>this.cap;){let r=this.map.keys().next().value;if(r===void 0)break;this.map.delete(r);}}clear(){this.map.clear();}},S=class extends y{static get observedAttributes(){return ["source","input","blocks","min-length","debounce-ms","limit","cache-size","view-all-href","lang"]}current=null;loading=false;lastQuery="";subscribed=null;inputEl=null;debounced=null;debouncedAt=0;fetchToken=0;inflight=null;cache=new E(32);rows=[];active=-1;bodyClickHandler=null;keyHandler=null;regionChangeHandler=null;connectedCallback(){this.resyncDebounce(),this.resyncCache(),this.subscribe(),this.bindGlobalListeners(),this.scheduleRender();}disconnectedCallback(){this.unsubscribe(),this.unbindGlobalListeners(),this.inflight?.abort(),super.disconnectedCallback();}attributeChangedCallback(e){e==="source"||e==="input"?(this.unsubscribe(),this.subscribe()):e==="debounce-ms"?this.resyncDebounce():e==="cache-size"?this.resyncCache():this.scheduleRender();}resyncDebounce(){let e=parseInt(this.getAttribute("debounce-ms")??"150",10)||150;this.debouncedAt===e&&this.debounced||(this.debouncedAt=e,this.debounced=A(t=>{this.fetch(t);},e));}resyncCache(){let e=Math.max(1,parseInt(this.getAttribute("cache-size")??"32",10)||32),t=new E(e);this.cache=t;}subscribe(){let e=this.getAttribute("source");if(e){let r=document.getElementById(e);if(r){this.subscribed=r,r.addEventListener("seekmodo:input",this.onSeekmodoInput);return}}let t=this.getAttribute("input");if(t){let r=document.getElementById(t);r instanceof HTMLInputElement&&(this.inputEl=r,r.addEventListener("input",this.onPlainInput),r.addEventListener("focus",this.onPlainFocus),r.addEventListener("blur",this.onPlainBlur));}}unsubscribe(){this.subscribed&&(this.subscribed.removeEventListener("seekmodo:input",this.onSeekmodoInput),this.subscribed=null),this.inputEl&&(this.inputEl.removeEventListener("input",this.onPlainInput),this.inputEl.removeEventListener("focus",this.onPlainFocus),this.inputEl.removeEventListener("blur",this.onPlainBlur),this.inputEl=null);}bindGlobalListeners(){this.bodyClickHandler=e=>{let t=e.composedPath();t.includes(this)||this.inputEl&&t.includes(this.inputEl)||this.subscribed&&t.includes(this.subscribed)||this.dismiss();},document.addEventListener("click",this.bodyClickHandler),this.keyHandler=e=>this.onKeyDown(e),document.addEventListener("keydown",this.keyHandler),this.regionChangeHandler=()=>{this.cache.clear(),this.current=null,this.scheduleRender();},document.addEventListener("seekmodo:region-change",this.regionChangeHandler);}unbindGlobalListeners(){this.bodyClickHandler&&(document.removeEventListener("click",this.bodyClickHandler),this.bodyClickHandler=null),this.keyHandler&&(document.removeEventListener("keydown",this.keyHandler),this.keyHandler=null),this.regionChangeHandler&&(document.removeEventListener("seekmodo:region-change",this.regionChangeHandler),this.regionChangeHandler=null);}onSeekmodoInput=e=>{let t=e.detail?.query??"";this.handleQuery(t);};onPlainInput=e=>{let t=e.target.value??"";this.handleQuery(t);};onPlainFocus=()=>{this.current&&this.rows.length>0&&this.scheduleRender();};onPlainBlur=()=>{};handleQuery(e){let t=e.trim(),r=parseInt(this.getAttribute("min-length")??"2",10)||2;if(t.length<r){this.lastQuery=t,this.current=null,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.lastQuery=t;let o=this.cache.get(this.cacheKey(t));if(o){this.current=o,this.loading=false,this.inflight?.abort(),this.scheduleRender();return}this.loading=true,this.scheduleRender(),this.debounced?.(t);}cacheKey(e){return e.toLowerCase()}async fetch(e){this.inflight?.abort();let t=new AbortController;this.inflight=t;let r=++this.fetchToken;try{let o=await this.getClient(),s=parseInt(this.getAttribute("limit")??"5",10)||5,a=this.getSessionId(),l={q:e,limit:s};a&&(l.session_id=a);let d=await o.suggest(l);if(r!==this.fetchToken||t.signal.aborted)return;this.current=d,this.loading=!1,this.cache.set(this.cacheKey(e),d),this.emitOpen(e),this.isEmpty(d)&&g(this,"seekmodo-suggest:empty",{q:e}),this.scheduleRender();}catch(o){if(r!==this.fetchToken||t.signal.aborted)return;this.current=null,this.loading=false,console.warn("[seekmodo-suggest] fetch failed",o),this.scheduleRender();}}getSessionId(){if(typeof document>"u")return null;let e=document.cookie.match(/(?:^|; )seekmodo_session=([^;]+)/);return e?decodeURIComponent(e[1]):null}isEmpty(e){return (e.keywords?.length??0)===0&&(e.products?.length??0)===0&&(e.categories?.length??0)===0&&(e.recent?.length??0)===0&&(e.trending?.length??0)===0&&!e.did_you_mean}blocks(){let e=this.getAttribute("blocks");if(!e)return C;let t=e.split(",").map(r=>r.trim()).filter(r=>["recent","trending","did_you_mean","keywords","products","categories"].includes(r));return t.length>0?t:C}label(e){return N[e]??e}dismiss(){this.current===null&&!this.loading||(g(this,"seekmodo-suggest:dismiss",{q:this.lastQuery}),this.current=null,this.loading=false,this.scheduleRender());}emitOpen(e){g(this,"seekmodo-suggest:open",{q:e});}onKeyDown(e){let t=this.shadowRoot?.activeElement??document.activeElement;!(this.inputEl&&t===this.inputEl||this.subscribed&&t===this.subscribed||this.subscribed&&this.subscribed.contains(t))&&!this.contains(t)||this.rows.length===0&&e.key!=="Escape"||(e.key==="ArrowDown"?(e.preventDefault(),this.active=(this.active+1)%this.rows.length,this.applyActive()):e.key==="ArrowUp"?(e.preventDefault(),this.active=(this.active-1+this.rows.length)%this.rows.length,this.applyActive()):e.key==="Enter"&&this.active>=0?(e.preventDefault(),this.activateRow(this.active)):e.key==="Escape"&&(e.preventDefault(),this.dismiss()));}applyActive(){this.root.querySelectorAll(".row").forEach((t,r)=>{r===this.active?(t.classList.add("active"),t.setAttribute("part","row row-active"),t.scrollIntoView({block:"nearest"})):(t.classList.remove("active"),t.setAttribute("part","row"));});}activateRow(e){let t=this.rows[e];t&&g(this,"seekmodo-suggest:row-click",{block:t.block,row:t.data,q:this.lastQuery,value:t.value,id:t.id});}render(){let e=document.createElement("style");if(e.textContent=U,this.loading&&this.current===null){this.root.replaceChildren(e,this.renderSkeleton()),this.rows=[],this.active=-1;return}if(this.current===null){this.root.replaceChildren(e),this.rows=[],this.active=-1;return}if(this.isEmpty(this.current)){let l=i("slot",{attrs:{name:"empty"}}),d=i("div",{class:"empty",text:this.label("empty")});l.append(d);let c=i("div",{class:"wrap",part:"wrap"});c.append(l),this.root.replaceChildren(e,c),this.rows=[],this.active=-1;return}let t=i("div",{class:"wrap",part:"wrap"});t.append(i("slot",{attrs:{name:"header"}}));let r=[],o=parseInt(this.getAttribute("limit")??"5",10)||5,s=this.blocks();for(let l of s){let d=this.renderBlock(l,this.current,o,r);d&&t.append(d);}this.rows=r,this.active=-1;let a=this.current.meta?.total??0;if(a>0&&this.lastQuery.length>0){let d=(this.getAttribute("view-all-href")??"/search?q={q}").replace("{q}",encodeURIComponent(this.lastQuery)),c=i("a",{class:"view-all",part:"view-all",attrs:{href:d},text:this.label("view_all").replace("{total}",String(a))});c.addEventListener("click",()=>{g(this,"seekmodo-suggest:view-all",{q:this.lastQuery,total:a});}),t.append(c);}t.append(i("slot",{attrs:{name:"footer"}})),this.root.replaceChildren(e,t);}renderSkeleton(){let e=i("div",{class:"wrap skeleton",part:"wrap skeleton"});for(let t=0;t<3;t++){let r=i("div",{class:"row",part:"row skeleton"});r.append(i("div",{class:"thumb",part:"thumb"}));let o=i("div",{class:"name"});o.append(i("span",{class:"name-title"})),o.append(i("span",{class:"name-meta"})),r.append(o),e.append(r);}return e}renderBlock(e,t,r,o){if(e==="did_you_mean"){let l=t.did_you_mean;if(!l)return null;let d=i("div",{class:"group",part:"group did-you-mean"});d.append(i("slot",{attrs:{name:"did_you_mean"}}));let c=i("div",{class:"did-you-mean"});c.append(document.createTextNode(this.label("did_you_mean")+" "));let p=i("button",{class:"swap",type:"button",attrs:{"data-seekmodo-surface":"suggest","data-seekmodo-block":"did_you_mean"},text:l});return p.addEventListener("click",()=>{g(this,"seekmodo-suggest:row-click",{block:"did_you_mean",row:{value:l},q:this.lastQuery,value:l});}),c.append(p),d.append(c),d}let s=this.blockData(e,t,r);if(s.length===0)return null;let a=i("div",{class:"group",part:"group",attrs:{"data-block":e}});return a.append(i("slot",{attrs:{name:e}})),a.append(i("div",{class:"group-title",part:"group-title",text:this.label(e)})),s.forEach((l,d)=>{let c={block:e,data:l,value:this.rowValue(e,l),id:this.rowId(e,l)};o.push(c);let p=o.length-1,u=window.seekmodoSuggest?.renderRow?.(c.data,e),h;u instanceof HTMLElement?(h=u,h.classList.add("row")):typeof u=="string"&&u.length>0?(h=i("button",{class:"row",part:"row",type:"button"}),h.innerHTML=u):h=this.renderRowDefault(e,l,d),h.setAttribute("data-seekmodo-surface","suggest"),h.setAttribute("data-seekmodo-block",e),h.setAttribute("data-seekmodo-pos",String(p)),c.id&&h.setAttribute("data-seekmodo-id",c.id),h.addEventListener("click",()=>this.activateRow(p)),a.append(h);}),a}blockData(e,t,r){switch(e){case "recent":return (t.recent??[]).slice(0,r);case "trending":return (t.trending??[]).slice(0,r);case "keywords":return (t.keywords??[]).slice(0,r);case "products":return (t.products??[]).slice(0,r);case "categories":return (t.categories??[]).slice(0,r);default:return []}}rowValue(e,t){let r=t;return e==="recent"||e==="trending"||e==="keywords"?String(r.keyword??""):e==="products"?String(r.name??r.title??""):e==="categories"?String(r.name??""):""}rowId(e,t){if(e!=="products")return;let r=t.id;return r!==void 0?String(r):void 0}renderRowDefault(e,t,r){let o=i("button",{class:"row",part:"row",type:"button"});if(e==="products"){let s=t,a=s.image_url??s.image;a?o.append(i("img",{class:"thumb",part:"thumb",attrs:{src:a,alt:"",loading:"lazy",decoding:"async"}})):o.append(i("div",{class:"thumb",part:"thumb"}));let l=i("div",{class:"name",part:"name"});l.append(i("span",{class:"name-title",text:s.name??""}));let d=[s.brand?String(s.brand):"",s.sku??s.model??s.ez_number??""].filter(Boolean);d.length>0&&l.append(i("span",{class:"name-meta",text:d.join(" \xB7 ")})),o.append(l);let c=this.renderPrice(s);return c&&o.append(c),o}if(e==="categories"){let s=t,a=i("div",{class:"name",part:"name",text:s.name});return o.append(a),typeof s.count=="number"&&s.count>0&&o.append(i("span",{class:"badge",part:"badge",text:String(s.count)})),o}if(e==="recent"||e==="trending"||e==="keywords"){let s=t,a=i("div",{class:"name",part:"name",text:String(s.keyword)});return o.append(a),e==="trending"&&typeof s.search_count=="number"&&o.append(i("span",{class:"badge",part:"badge",text:String(s.search_count)})),o}return o}renderPrice(e){if(e.price===void 0||e.price===null)return null;let t=i("div",{class:"price",part:"price"});return e.on_sale&&typeof e.sale_price=="number"?(t.append(i("del",{text:x(e.price,e.currency)})),t.append(document.createTextNode(x(e.sale_price,e.currency)))):t.append(document.createTextNode(x(e.price,e.currency))),t}};function x(n,e){try{return new Intl.NumberFormat(void 0,{style:"currency",currency:e??"USD",maximumFractionDigits:2}).format(n)}catch{return String(n)}}typeof customElements<"u"&&!customElements.get("seekmodo-suggest")&&customElements.define("seekmodo-suggest",S);exports.SeekmodoSuggest=S;return exports;})({});//# sourceMappingURL=suggest.global.js.map
//# sourceMappingURL=suggest.global.js.map