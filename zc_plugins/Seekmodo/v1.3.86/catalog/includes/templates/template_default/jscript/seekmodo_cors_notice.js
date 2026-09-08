/*!
 * Seekmodo — CORS-block notice helper (load before suggest bundle).
 * When mcp.seekmodo.com scripts or gateway fetches are blocked, show
 * an inline message where suggestions would appear.
 */
(function (w) {
  "use strict";
  var FALLBACK =
    "Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.";

  function resolveMsg(customMsg) {
    if (customMsg) return customMsg;
    try {
      var labels = w.SeekmodoSuggestLabels;
      if (labels && typeof labels.cors_blocked === "string" && labels.cors_blocked) {
        return labels.cors_blocked;
      }
    } catch (e) {}
    return FALLBACK;
  }

  function showNotice(input, customMsg) {
    if (!input) return;
    var host = input.closest ? input.closest(".search-form") : null;
    if (!host) host = input.parentNode;
    if (!host) return;
    host.style.position = host.style.position || "relative";
    var cls = "seekmodo-cors-notice";
    var notice = host.querySelector("." + cls);
    if (!notice) {
      notice = document.createElement("div");
      notice.className = cls;
      notice.setAttribute("role", "status");
      notice.style.cssText =
        "position:absolute;top:100%;left:0;right:0;z-index:10050;display:none;" +
        "background:#fff8e6;border:1px solid #f0c040;border-top:none;padding:8px 12px;" +
        "font-size:13px;line-height:1.4;color:#5c4a00;box-shadow:0 4px 12px rgba(0,0,0,.08);";
      host.appendChild(notice);
      function toggle() {
        var q = (input.value || "").trim();
        notice.style.display = q.length >= 2 ? "block" : "none";
      }
      input.addEventListener("input", toggle);
      input.addEventListener("focus", toggle);
    }
    notice.textContent = resolveMsg(customMsg);
  }

  w.seekmodoShowCorsNotice = showNotice;
  w.seekmodoScriptLoadFailed = function (inputs, customMsg) {
    var list =
      inputs ||
      document.querySelectorAll(
        'input[data-seekmodo-suggest],input[data-seekmodo-typeahead],input[name="s"],input[name="keyword"],input[name="search_query"],input[name="q"],input[type="search"]'
      );
    for (var i = 0; i < list.length; i++) {
      if (list[i] && list[i].tagName === "INPUT") showNotice(list[i], customMsg);
    }
  };
})(window);
