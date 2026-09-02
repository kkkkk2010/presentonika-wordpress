(async function () {
  function fail(msg, err) {
    console.error("[VKID OneTap]", msg, err || "");
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = () => reject(new Error("Failed to load: " + src));
      document.head.appendChild(s);
    });
  }

  async function ensureVKID(cfg) {
    if (window.VKID) return true;
    // грузим SDK сами (UMD)
    await loadScript(cfg.sdkUrl);

    // ждём появления VKID
    for (let i = 0; i < 40; i++) {
      if (window.VKID) return true;
      await new Promise(r => setTimeout(r, 50));
    }
    return false;
  }

  async function getState(cfg) {
    const res = await fetch(cfg.ajaxUrl + "?action=vkid_prepare_state", { credentials: "same-origin" });
    const data = await res.json();
    if (!data || !data.success || !data.data || !data.data.state) {
      throw new Error((data && data.data && data.data.message) ? data.data.message : "State fetch failed");
    }
    return data.data.state;
  }

  async function init() {
    const cfg = window.VKID_ONE_TAP_CFG;
    if (!cfg) return fail("Missing VKID_ONE_TAP_CFG");

    const container = document.getElementById(cfg.containerId);
    if (!container) return fail("Container not found: " + cfg.containerId);

    const ok = await ensureVKID(cfg);
    if (!ok) return fail("VKID SDK not loaded");

    try {
      const state = await getState(cfg);

      VKID.Config.init({
        app: cfg.app,
        redirectUrl: cfg.redirectUrl,
        state: state,
        scope: cfg.scope,
        mode: VKID.ConfigAuthMode.Redirect
      });

      const oneTap = new VKID.OneTap();
      oneTap.render({
        container: container,
        scheme: VKID.Scheme.LIGHT,
        lang: VKID.Languages.RUS
      }).on(VKID.WidgetEvents.ERROR, (e) => fail("Widget error", e));
    } catch (e) {
      fail("Init exception", e);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();