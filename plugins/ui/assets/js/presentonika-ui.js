(function(){
  "use strict";

  const cfg = (typeof window !== "undefined" && window.pnkUi) ? window.pnkUi : null;
  if (!cfg) return;

  const ajaxUrl = cfg.ajaxUrl;
  const nonce = cfg.nonce;
  const draftKey = "pnkDeckPlanDraft:v1";
  const debugPlan = Boolean(cfg.debugPlan);
  const deckPlanSlideTypes = [
    "cover",
    "goals",
    "hook",
    "context",
    "definition",
    "bullets",
    "comparison",
    "twoCol",
    "steps",
    "timeline",
    "examples",
    "quiz",
    "summary",
    "visual_explanation"
  ];
  const deckPlanSlideTypeLabels = {
    cover: "Обложка",
    goals: "Цели / маршрут",
    hook: "Проблема",
    context: "Контекст",
    definition: "Понятия",
    bullets: "Аргументы",
    comparison: "Сравнение",
    twoCol: "Две колонки",
    steps: "Шаги",
    timeline: "Хронология",
    examples: "Примеры",
    quiz: "Проверка",
    summary: "Итог",
    visual_explanation: "Визуальное объяснение"
  };
  const typingSeq = new WeakMap();
  let pendingGenerationRequestId = "";

  const getGenerationRequestId = () => {
    if (pendingGenerationRequestId) return pendingGenerationRequestId;
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      pendingGenerationRequestId = window.crypto.randomUUID();
    } else {
      pendingGenerationRequestId = "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
        const value = Math.floor(Math.random() * 16);
        return (char === "x" ? value : ((value & 3) | 8)).toString(16);
      });
    }
    return pendingGenerationRequestId;
  };

  function qs(sel, root){ return (root || document).querySelector(sel); }
  function qsa(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }
  function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

  async function typeText(el, text, speed){
    if (!el) return;
    const seq = (typingSeq.get(el) || 0) + 1;
    typingSeq.set(el, seq);
    const value = String(text || "");
    el.classList.add("pnk-typing");
    el.textContent = "";
    for (let i = 0; i < value.length; i += 1){
      if (typingSeq.get(el) !== seq) return;
      el.textContent += value[i];
      await sleep(speed || 14);
    }
    if (typingSeq.get(el) === seq) el.classList.remove("pnk-typing");
  }

  async function fetchJsonSafe(url, options){
    const r = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
    const txt = await r.text();
    try { return JSON.parse(txt); }
    catch(e){ throw new Error("Сервер вернул не JSON: " + txt.slice(0, 200)); }
  }

  async function postAjax(action, payload){
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", nonce);
    Object.entries(payload || {}).forEach(([k,v]) => fd.append(k, String(v)));
    return fetchJsonSafe(ajaxUrl, { method: "POST", body: fd });
  }

  function setStatus(elWrap, elText, text){
    if (!elWrap || !elText) return;
    elWrap.hidden = false;
    typeText(elText, text, 12);
  }

  function fillThemeSelect(selectEl, engine){
    if (!selectEl) return;
    const list = (cfg.themes && cfg.themes[engine]) ? cfg.themes[engine] : [];
    selectEl.innerHTML = "";
    list.forEach(opt => {
      const o = document.createElement("option");
      o.value = opt.value;
      o.textContent = opt.label || opt.value;
      selectEl.appendChild(o);
    });
    if (engine === "deepseek") selectEl.value = (list[0] && list[0].value) ? list[0].value : "teacher-dark";
    if (engine === "gamma") selectEl.value = (list[0] && list[0].value) ? list[0].value : "default";
  }

  function saveDraft(draft){
    try { window.sessionStorage.setItem(draftKey, JSON.stringify(draft)); }
    catch(e) { /* no-op */ }
  }

  function loadDraft(){
    try{
      const raw = window.sessionStorage.getItem(draftKey);
      return raw ? JSON.parse(raw) : null;
    } catch(e) {
      return null;
    }
  }

  function splitLines(value){
    return String(value || "")
      .split(/\r?\n/)
      .map(v => v.trim())
      .filter(Boolean);
  }

  function formatSlideCount(value){
    const count = Math.max(0, Number(value) || 0);
    const mod10 = count % 10;
    const mod100 = count % 100;
    const word = mod10 === 1 && mod100 !== 11
      ? "слайд"
      : (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? "слайда" : "слайдов");
    return String(count) + " " + word;
  }

  function option(value, label, selected){
    const o = document.createElement("option");
    o.value = value;
    o.textContent = label || value;
    if (selected) o.selected = true;
    return o;
  }

  function renderTextArea(className, value, rows){
    const el = document.createElement("textarea");
    el.className = className;
    el.rows = rows || 3;
    el.value = value || "";
    return el;
  }

  function renderInput(className, value){
    const el = document.createElement("input");
    el.className = className;
    el.type = "text";
    el.value = value || "";
    return el;
  }

  async function generateDeckPlanFromFormData(formData, statusWrap, statusText){
    setStatus(statusWrap, statusText, "Собираем структуру презентации…");
    const res = await postAjax("generate_deck_plan", {
      presentation_text: formData.presentation_text,
      theme: formData.theme || "teacher-dark",
      subject: formData.subject || "",
      grade: formData.grade || "",
      slide_count: formData.slide_count || "10",
      presentation_type: formData.presentation_type || "auto"
    });

    if (!res.success){
      const msg = (res.data && res.data.message) ? res.data.message : "Не удалось собрать план";
      throw new Error(msg);
    }

    return {
      form: formData,
      deckPlan: res.data.deckPlan,
      planForUi: res.data.planForUi,
      diagnostics: res.data.diagnostics || null,
      updatedAt: new Date().toISOString()
    };
  }

  function initForm(){
    const form = qs("#pnk-presentationForm");
    if (!form) return;

    const textarea = qs("#pnk-text", form);
    const counter = qs("#pnk-count", form);
    const themeSel = qs("#pnk-theme", form);
    const submitBtn = qs("#pnk-submit", form);
    const submitText = submitBtn ? qs(".pnk-btn__text", submitBtn) : null;
    const subjectInput = qs("#pnk-subject", form);
    const gradeInput = qs("#pnk-grade", form);
    const slideCountInput = qs("#pnk-slideCount", form);
    const presentationTypeInput = qs("#pnk-presentationType", form);
    const statusWrap = qs("#pnk-status", form);
    const statusText = qs("#pnk-statusText", form);
    const engineRadios = qsa("input[name=engine]", form);
    const summaryEngine = qs("#pnk-summaryEngine", form);
    const summaryTheme = qs("#pnk-summaryTheme", form);
    const summarySlides = qs("#pnk-summarySlides", form);
    const summaryType = qs("#pnk-summaryType", form);
    const themeSwatch = qs("#pnk-themeSwatch", form);

    const getEngine = () => {
      const checked = qs("input[name=engine]:checked", form);
      return checked ? checked.value : "deepseek";
    };

    const setSubmitText = (text) => {
      if (submitText) submitText.textContent = text;
      else if (submitBtn) submitBtn.textContent = text;
    };

    const formPayload = () => ({
      presentation_text: textarea ? String(textarea.value || "").trim() : "",
      theme: themeSel ? String(themeSel.value || "") : "",
      subject: subjectInput ? String(subjectInput.value || "").trim() : "",
      grade: gradeInput ? String(gradeInput.value || "").trim() : "",
      slide_count: slideCountInput ? String(slideCountInput.value || "10") : "10",
      presentation_type: presentationTypeInput ? String(presentationTypeInput.value || "auto") : "auto"
    });

    const selectedText = (select, fallback) => {
      if (!select || !select.options || select.selectedIndex < 0) return fallback;
      return String(select.options[select.selectedIndex].textContent || fallback).trim();
    };

    const updateSummary = () => {
      const engine = getEngine();
      if (summaryEngine) summaryEngine.textContent = engine === "gamma" ? "Gamma" : "DeepSeek";
      if (summaryTheme) summaryTheme.textContent = selectedText(themeSel, "Не выбрано");
      if (summarySlides) summarySlides.textContent = formatSlideCount((slideCountInput && slideCountInput.value) || 10);
      if (summaryType) summaryType.textContent = selectedText(presentationTypeInput, "Подобрать автоматически");
      if (themeSwatch) themeSwatch.setAttribute("data-theme", (themeSel && themeSel.value) || "teacher-dark");
    };

    async function submitGamma(payload){
      setStatus(statusWrap, statusText, "Ставим в очередь (Gamma)…");
      if (submitBtn) submitBtn.disabled = true;

      try{
        const fd = new FormData(form);
        fd.set("nonce", nonce);
        fd.append("action", "generate_presentation");
        fd.set("request_id", getGenerationRequestId());
        if (String(payload.theme || "").startsWith("teacher-") || !payload.theme) fd.set("theme", "default");

        const res = await fetchJsonSafe(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
        if (!res.success){
          const msg = (res.data && res.data.message) ? res.data.message : "Ошибка";
          setStatus(statusWrap, statusText, msg);
          if (res.data && res.data.redirect) setTimeout(() => { window.location.href = res.data.redirect; }, 800);
          else if (submitBtn) submitBtn.disabled = false;
          return;
        }
        pendingGenerationRequestId = "";
        const waitingUrl = cfg.waitingUrl || "/waiting/";
        window.location.href = waitingUrl + "?presentation_id=" + encodeURIComponent(String(res.data.presentation_id));
      }catch(err){
        console.error(err);
        setStatus(statusWrap, statusText, (err && err.message) ? err.message : "Ошибка соединения");
        if (submitBtn) submitBtn.disabled = false;
      }
    }

    fillThemeSelect(themeSel, getEngine());
    setSubmitText("Создать презентацию");
    updateSummary();

    engineRadios.forEach(r => {
      r.addEventListener("change", () => {
        fillThemeSelect(themeSel, getEngine());
        setSubmitText("Создать презентацию");
        updateSummary();
      });
    });

    [themeSel, slideCountInput, presentationTypeInput].forEach(input => {
      if (!input) return;
      input.addEventListener("change", updateSummary);
      input.addEventListener("input", updateSummary);
    });

    if (textarea && counter){
      const upd = () => { counter.textContent = String((textarea.value || "").length); };
      textarea.addEventListener("input", upd);
      upd();
    }

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const payload = formPayload();
      if (payload.presentation_text.length < 10){
        setStatus(statusWrap, statusText, "Текст слишком короткий (минимум 10 символов).");
        return;
      }

      if (getEngine() === "gamma"){
        await submitGamma(payload);
        return;
      }

      if (submitBtn) submitBtn.disabled = true;
      try{
        const deepseekPayload = Object.assign({}, payload);
        if (!String(deepseekPayload.theme || "").startsWith("teacher-")){
          const first = cfg.themes && cfg.themes.deepseek && cfg.themes.deepseek[0] ? cfg.themes.deepseek[0].value : "teacher-dark";
          deepseekPayload.theme = first || "teacher-dark";
        }
        const draft = await generateDeckPlanFromFormData(deepseekPayload, statusWrap, statusText);
        saveDraft(draft);
        setStatus(statusWrap, statusText, "План готов. Переходим к редактированию…");
        window.location.href = cfg.planUrl || "/plan/";
      }catch(err){
        console.error(err);
        setStatus(statusWrap, statusText, (err && err.message) ? err.message : "Ошибка сборки плана");
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  function initPlanPage(){
    const root = qs("#pnk-planPage");
    if (!root) return;

    const empty = qs("#pnk-planEmpty", root);
    const editor = qs("#pnk-planEditor", root);
    const planTyping = qs("#pnk-planTyping", root);
    const planSubtitle = qs("#pnk-planSubtitle", root);
    const planQuestion = qs("#pnk-planQuestion", root);
    const planThesis = qs("#pnk-planThesis", root);
    const planSlides = qs("#pnk-planSlides", root);
    const planCount = qs("#pnk-planCount", root);
    const planRebuild = qs("#pnk-planRebuild", root);
    const planSaveDraft = qs("#pnk-planSaveDraft", root);
    const planStart = qs("#pnk-planStart", root);
    const statusWrap = qs("#pnk-planStatus", root);
    const statusText = qs("#pnk-planStatusText", root);
    let draft = loadDraft();

    function showEmpty(){
      root.classList.add("is-empty");
      if (empty) empty.hidden = false;
      if (editor) editor.hidden = true;
      if (planRebuild) planRebuild.disabled = true;
      if (planTyping) planTyping.textContent = "Структура ещё не создана";
      if (planSubtitle) planSubtitle.textContent = "Вернитесь к настройкам и подготовьте первый черновик.";
    }

    function collectPlan(){
      if (!draft || !draft.deckPlan || !planSlides || !planQuestion || !planThesis) return null;
      const plan = JSON.parse(JSON.stringify(draft.deckPlan));
      plan.centralQuestion = planQuestion.value.trim();
      plan.thesis = planThesis.value.trim();
      plan.source = "user_edited";

      qsa(".pnk-plan-slide", planSlides).forEach(card => {
        const idx = Number(card.dataset.index || "0");
        if (!plan.slides[idx]) return;
        const f = card._pnkFields;
        plan.slides[idx].slideType = f.type.value;
        if (debugPlan && f.role) {
          plan.slides[idx].role = f.role.value.trim() || plan.slides[idx].role || "evidence_mechanism";
        }
        plan.slides[idx].titleIntent = f.title.value.trim();
        plan.slides[idx].claim = f.claim.value.trim();
        plan.slides[idx].mustInclude = splitLines(f.include.value);
        plan.slides[idx].mustAvoid = splitLines(f.avoid.value);
      });

      return plan;
    }

    function persistCurrentDraft(){
      const plan = collectPlan();
      if (!plan || !draft) return false;
      draft.deckPlan = plan;
      if (draft.planForUi) {
        draft.planForUi.centralQuestion = plan.centralQuestion;
        draft.planForUi.thesis = plan.thesis;
        draft.planForUi.slides = plan.slides;
      }
      draft.updatedAt = new Date().toISOString();
      saveDraft(draft);
      return true;
    }

    function renderPlan(){
      if (!draft || !draft.deckPlan) {
        showEmpty();
        return;
      }

      const plan = draft.deckPlan;
      const uiSlides = draft.planForUi && Array.isArray(draft.planForUi.slides) ? draft.planForUi.slides : plan.slides;
      root.classList.remove("is-empty");
      if (empty) empty.hidden = true;
      if (editor) editor.hidden = false;
      if (planRebuild) planRebuild.disabled = false;
      if (planTyping) void typeText(planTyping, "Структура готова к проверке", 18);
      if (planSubtitle) planSubtitle.textContent = "Проверьте ключевую мысль и последовательность слайдов.";
      if (planQuestion) planQuestion.value = plan.centralQuestion || "";
      if (planThesis) planThesis.value = plan.thesis || "";
      if (planCount) planCount.textContent = formatSlideCount(uiSlides.length);
      if (planSlides) planSlides.innerHTML = "";

      uiSlides.forEach((uiSlide, idx) => {
        const canonical = (plan.slides && plan.slides[idx]) ? plan.slides[idx] : uiSlide;
        const card = document.createElement("article");
        card.className = "pnk-plan-slide";
        card.dataset.index = String(idx);

        const head = document.createElement("div");
        head.className = "pnk-plan-slide__head";

        const identity = document.createElement("div");
        identity.className = "pnk-plan-slide__identity";

        const num = document.createElement("div");
        num.className = "pnk-plan-slide__num";
        num.textContent = String(uiSlide.slide || canonical.slide || (idx + 1)).padStart(2, "0");

        const slideLabel = document.createElement("div");
        slideLabel.className = "pnk-plan-slide__label";
        const slideLabelCaption = document.createElement("span");
        slideLabelCaption.textContent = "Слайд";
        const slideLabelType = document.createElement("strong");
        const updateSlideLabel = () => {
          slideLabelType.textContent = deckPlanSlideTypeLabels[type.value] || type.value || "Содержание";
        };
        slideLabel.appendChild(slideLabelCaption);
        slideLabel.appendChild(slideLabelType);

        identity.appendChild(num);
        identity.appendChild(slideLabel);

        const type = document.createElement("select");
        type.className = "pnk-select pnk-plan-slide__type";
        deckPlanSlideTypes.forEach(t => type.appendChild(option(t, deckPlanSlideTypeLabels[t] || t, t === (canonical.slideType || uiSlide.slideType))));
        updateSlideLabel();
        type.addEventListener("change", updateSlideLabel);

        const typeWrap = document.createElement("label");
        typeWrap.className = "pnk-plan-slide__type-field";
        const typeLabel = document.createElement("span");
        typeLabel.className = "pnk-label";
        typeLabel.textContent = "Тип слайда";
        typeWrap.appendChild(typeLabel);
        typeWrap.appendChild(type);

        head.appendChild(identity);
        head.appendChild(typeWrap);

        let role = null;
        if (debugPlan) {
          role = renderInput("pnk-input pnk-plan-slide__role", canonical.role || uiSlide.role || "");
          role.placeholder = "Роль слайда";
          const roleWrap = document.createElement("label");
          roleWrap.className = "pnk-plan-slide__role-field";
          const roleLabel = document.createElement("span");
          roleLabel.className = "pnk-label";
          roleLabel.textContent = "Служебная роль";
          roleWrap.appendChild(roleLabel);
          roleWrap.appendChild(role);
          head.appendChild(roleWrap);
        }

        const title = renderInput("pnk-input", canonical.titleIntent || uiSlide.titleIntent || "");
        title.placeholder = "Задача заголовка";
        const claim = renderTextArea("pnk-textarea pnk-textarea--small", canonical.claim || uiSlide.claim || "", 3);
        claim.placeholder = "Что слайд должен доказать";
        const include = renderTextArea("pnk-textarea pnk-textarea--small", (canonical.mustInclude || uiSlide.mustInclude || []).join("\n"), 4);
        include.placeholder = "Что включить: каждый пункт с новой строки";
        const avoid = renderTextArea("pnk-textarea pnk-textarea--small", (canonical.mustAvoid || uiSlide.mustAvoid || []).join("\n"), 3);
        avoid.placeholder = "Чего избегать: каждый пункт с новой строки";

        card.appendChild(head);
        const body = document.createElement("div");
        body.className = "pnk-plan-slide__body";
        [
          ["Задача", title, "task"],
          ["Тезис слайда", claim, "claim"],
          ["Обязательно включить", include, "include"],
          ["Не использовать", avoid, "avoid"]
        ].forEach(([labelText, field, fieldType]) => {
          const wrap = document.createElement("label");
          wrap.className = "pnk-plan-field pnk-plan-field--" + fieldType;
          const label = document.createElement("span");
          label.className = "pnk-label";
          label.textContent = labelText;
          wrap.appendChild(label);
          wrap.appendChild(field);
          body.appendChild(wrap);
        });
        card.appendChild(body);

        card._pnkFields = { type, role, title, claim, include, avoid };
        planSlides.appendChild(card);
      });

    }

    if (planSaveDraft) {
      planSaveDraft.addEventListener("click", () => {
        if (persistCurrentDraft()) setStatus(statusWrap, statusText, "Правки сохранены в черновике.");
      });
    }

    if (planRebuild) {
      planRebuild.addEventListener("click", async () => {
        if (!draft || !draft.form) {
          showEmpty();
          return;
        }

        planRebuild.disabled = true;
        if (planStart) planStart.disabled = true;
        try{
          draft = await generateDeckPlanFromFormData(draft.form, statusWrap, statusText);
          saveDraft(draft);
          renderPlan();
          const source = draft.diagnostics && draft.diagnostics.source ? String(draft.diagnostics.source) : "plan";
          setStatus(statusWrap, statusText, source === "llm" ? "План пересобран." : "План пересобран fallback-режимом.");
        }catch(err){
          console.error(err);
          setStatus(statusWrap, statusText, (err && err.message) ? err.message : "Ошибка пересборки плана");
        }finally{
          planRebuild.disabled = false;
          if (planStart) planStart.disabled = false;
        }
      });
    }

    if (editor) {
      editor.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (!draft || !draft.form) return;
        const plan = collectPlan();
        if (!plan) return;

        if (planStart) planStart.disabled = true;
        if (planRebuild) planRebuild.disabled = true;
        setStatus(statusWrap, statusText, "Ставим в очередь по утвержденному плану…");

        try{
          const fd = new FormData();
          fd.append("nonce", nonce);
          fd.append("action", "generate_presentation_orchestrator");
          fd.append("request_id", getGenerationRequestId());
          fd.append("presentation_text", String(draft.form.presentation_text || ""));
          fd.append("theme", String(draft.form.theme || "teacher-dark"));
          fd.append("deck_plan", JSON.stringify(plan));

          const res = await fetchJsonSafe(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
          if (!res.success){
            const msg = (res.data && res.data.message) ? res.data.message : "Ошибка";
            setStatus(statusWrap, statusText, msg);
            if (res.data && res.data.redirect) setTimeout(() => { window.location.href = res.data.redirect; }, 800);
            else {
              if (planStart) planStart.disabled = false;
              if (planRebuild) planRebuild.disabled = false;
            }
            return;
          }

          pendingGenerationRequestId = "";
          persistCurrentDraft();
          const waitingUrl = cfg.waitingUrl || "/waiting/";
          window.location.href = waitingUrl + "?presentation_id=" + encodeURIComponent(String(res.data.presentation_id));
        }catch(err){
          console.error(err);
          setStatus(statusWrap, statusText, (err && err.message) ? err.message : "Ошибка соединения");
          if (planStart) planStart.disabled = false;
          if (planRebuild) planRebuild.disabled = false;
        }
      });
    }

    renderPlan();
  }

  function initCabinet(){
    const root = qs("#pnk-cabinet");
    if (!root) return;

    qsa(".pnk-open-btn", root).forEach(btn => {
      btn.addEventListener("click", async () => {
        const pid = btn.getAttribute("data-presentation-id");
        if (!pid) return;

        const canOpen = (btn.getAttribute("data-can-open") === "1");
        const waitingUrl = btn.getAttribute("data-waiting-url") || "";
        if (!canOpen && waitingUrl){
          window.location.href = waitingUrl;
          return;
        }

        const old = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Открываем…";

        try{
          const res = await postAjax("presentation_bridge", { presentation_id: pid });
          if (!res.success){
            const msg = (res.data && res.data.message) ? res.data.message : "Bridge error";
            alert(msg);
            btn.disabled = false;
            btn.textContent = old;
            return;
          }
          window.location.href = res.data.redirectUrl;
        }catch(err){
          console.error(err);
          alert((err && err.message) ? err.message : "Ошибка открытия");
          btn.disabled = false;
          btn.textContent = old;
        }
      });
    });
  }

  async function pollDone(presentationId, onProgress){
    const start = Date.now();
    const maxMs = 12 * 60 * 1000;
    let delay = 2200;

    while(true){
      if (Date.now() - start > maxMs) throw new Error("Превышено время ожидания.");

      const res = await postAjax("presentation_status", { presentation_id: presentationId });
      if (!res.success){
        const msg = (res.data && res.data.message) ? res.data.message : "Ошибка статуса";
        throw new Error(msg);
      }

      const st = (res.data && res.data.status) ? String(res.data.status) : "";
      if (onProgress) onProgress(st);

      if (st === "done") return res.data;
      if (st === "failed") {
        const msg = (res.data && res.data.message) ? res.data.message : "Ошибка генерации";
        throw new Error(msg);
      }

      await sleep(delay);
      delay = Math.min(7000, Math.floor(delay * 1.12));
    }
  }

  async function bridgeAndRedirect(presentationId){
    const res = await postAjax("presentation_bridge", { presentation_id: presentationId });
    if (!res.success){
      const msg = (res.data && res.data.message) ? res.data.message : "Bridge error";
      throw new Error(msg);
    }
    window.location.href = res.data.redirectUrl;
  }

  function initWaitingPage(){
    const root = qs("#pnk-waiting");
    if (!root) return;

    const statusEl = qs("#pnk-waitingStatus", root);
    const pidFromData = root.getAttribute("data-pid");
    const pidFromUrl = new URLSearchParams(window.location.search).get("presentation_id");
    const pid = pidFromUrl || pidFromData;
    const set = (t) => { if (statusEl) statusEl.textContent = t; };

    (async function(){
      try{
        if (!pid) throw new Error("Нет presentation_id");
        set("Генерируем…");

        await pollDone(pid, (st) => {
          if (st === "pending") set("В очереди…");
          else if (st === "processing") set("Генерируется…");
          else if (st) set("Статус: " + st);
        });

        set("Готово. Открываем в редакторе…");
        await bridgeAndRedirect(pid);
      }catch(err){
        console.error(err);
        set("Ошибка: " + ((err && err.message) ? err.message : "unknown"));
      }
    })();
  }

  function initOpenEditorPage(){
    const root = qs("#pnk-openEditor");
    if (!root) return;

    const pid = root.getAttribute("data-pid");
    const auto = root.getAttribute("data-auto") === "1";
    const btn = qs("#pnk-openBtn", root);
    const statusEl = qs("#pnk-openStatus", root);
    const set = (t) => { if (statusEl) statusEl.textContent = t; };

    async function openNow(){
      if (!pid) return;
      if (btn) { btn.disabled = true; btn.textContent = "Открываем…"; }
      set("Открываем…");

      try{
        await bridgeAndRedirect(pid);
      }catch(err){
        console.error(err);
        set("Ошибка: " + ((err && err.message) ? err.message : "unknown"));
        if (btn) { btn.disabled = false; btn.textContent = "Открыть в редакторе"; }
      }
    }

    if (btn) btn.addEventListener("click", openNow);
    if (auto) setTimeout(openNow, 0);
  }

  document.addEventListener("DOMContentLoaded", () => {
    initForm();
    initPlanPage();
    initCabinet();
    initWaitingPage();
    initOpenEditorPage();
  });

})();
