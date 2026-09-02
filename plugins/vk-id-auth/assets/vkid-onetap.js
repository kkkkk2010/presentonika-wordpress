(async function () {
  const TAG = "[VKID]";
  const log = (...a) => console.log(TAG, ...a);
  const err = (...a) => console.error(TAG, ...a);

  const cfg = window.VKID_CFG;
  if (!cfg) return err("Missing VKID_CFG");

  const container = document.getElementById(cfg.containerId);
  if (!container) return err("Container not found:", cfg.containerId);

  function VKIDGlobal() {
    return window.VKIDSDK || window.VKID || null;
  }

  function loadScript(src) {
    log("Loading SDK:", src);
    return new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = src;
      s.async = true;
      s.onload = () => { log("SDK loaded"); resolve(); };
      s.onerror = () => reject(new Error("SDK load failed: " + src));
      document.head.appendChild(s);
    });
  }

  async function prepareState() {
    const url = cfg.ajaxUrl + "?action=vkid_prepare_state";
    log("prepareState:", url);
    const res = await fetch(url, { credentials: "same-origin" });
    const text = await res.text();
    log("prepareState status:", res.status, "body:", text);
    const json = JSON.parse(text);
    if (!json.success || !json.data || !json.data.state) throw new Error("prepareState failed");
    return json.data.state;
  }

  try {
    const state = await prepareState();
    log("state OK:", state);

    if (!VKIDGlobal()) await loadScript(cfg.sdkUrl);
    const VKID = VKIDGlobal();
    if (!VKID) throw new Error("VKID global not found");

    VKID.Config.init({
      app: cfg.app,
      redirectUrl: cfg.redirectUrl,
      state: state,
      scope: cfg.scope,
      mode: VKID.ConfigAuthMode.Redirect
    });

    log("Render OneTap (redirect after user click inside widget)");
    const oneTap = new VKID.OneTap();
    const widget = oneTap.render({ container });

    if (VKID.WidgetEvents && widget && widget.on) {
      widget.on(VKID.WidgetEvents.ERROR, (e) => err("OneTap ERROR:", e));
      widget.on(VKID.WidgetEvents.SUCCESS, (e) => log("OneTap SUCCESS:", e));
      widget.on(VKID.WidgetEvents.CLOSE, (e) => log("OneTap CLOSE:", e));
    }
  } catch (e) {
    err("Init failed:", e);
  }
})();