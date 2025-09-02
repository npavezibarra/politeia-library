/**
 * post-reading.js
 * Toggle Start/Finish + botón flotante alineado al contenido + barra de progreso de scroll
 */
document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".politeia-post-reading-wrap");
  const btn  = document.querySelector(".politeia-post-reading-btn");
  if (!wrap || typeof politeiaPostReading === "undefined") return;

  /* --------- Inyectar barra de progreso (sin tocar PHP) --------- */
  let progress = document.createElement("div");
  progress.className = "politeia-scroll-progress";
  let progressBar = document.createElement("div");
  progressBar.className = "politeia-scroll-progress__bar";
  progress.appendChild(progressBar);
  wrap.appendChild(progress);

  /* ----------------- Floating / Fixed (alineado al contenido) ----------------- */
  const FIXED_TOP = 107; // px bajo tu navbar
  let initialTop = wrap.getBoundingClientRect().top + window.scrollY;

  function getContentContainer() {
    const selectors = [
      ".entry-content",
      "article .entry-content",
      ".single-post .entry-content",
      ".post-content",
      ".site-main .entry-content",
      ".site-main",
      "main"
    ];
    for (const sel of selectors) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return wrap.parentElement || document.body;
  }

  function computeAlignment() {
    const ref  = getContentContainer();
    const rect = ref.getBoundingClientRect();
    const cs   = getComputedStyle(ref);
    const padL = parseFloat(cs.paddingLeft)  || 0;
    const padR = parseFloat(cs.paddingRight) || 0;

    // Alineación de la barra interna al texto del contenido
    const left  = rect.left + window.scrollX + padL;
    const width = rect.width - padL - padR;

    wrap.style.setProperty("--politeia-fixed-left", left + "px");
    wrap.style.setProperty("--politeia-fixed-width", width + "px");

    // Punto de enganche del wrapper
    initialTop = wrap.getBoundingClientRect().top + window.scrollY;

    // Recalcular progreso tras cambios de layout
    updateScrollProgress();
  }

  function onScroll() {
    if (window.scrollY + FIXED_TOP >= initialTop) {
      wrap.classList.add("is-fixed");
    } else {
      wrap.classList.remove("is-fixed");
    }
    updateScrollProgress();
  }

  /* ----------------- Progreso de scroll sobre the_content ----------------- */
  function clamp01(x){ return Math.max(0, Math.min(1, x)); }

  function getContentMetrics() {
    const ref = getContentContainer();
    const rect = ref.getBoundingClientRect();
    const top  = rect.top + window.scrollY;
    const h    = ref.scrollHeight || ref.offsetHeight || rect.height || 1;
    return { top, height: h };
  }

  function updateScrollProgress() {
    const { top, height } = getContentMetrics();
    const viewportBottom  = window.scrollY + window.innerHeight;

    // Progreso: cuánto del contenido quedó por encima del fondo de la pantalla
    const raw = (viewportBottom - top) / height;
    const p   = clamp01(raw);

    progressBar.style.width = (p * 100).toFixed(2) + "%";

    // Al llegar (o casi) al final, marca complete
    const COMPLETE_EPS = 0.995; // tolerancia
    wrap.classList.toggle("is-complete", p >= COMPLETE_EPS);

    // (Opcional) dispara un evento para enganchar futuras métricas/persistencia
    if (p >= COMPLETE_EPS && !updateScrollProgress._fired) {
      updateScrollProgress._fired = true;
      window.dispatchEvent(new CustomEvent("politeia:postReadingScrollComplete", {
        detail: { postId: politeiaPostReading.postId }
      }));
    }
  }

  // init + listeners
  computeAlignment();
  onScroll();
  window.addEventListener("resize", () => { computeAlignment(); onScroll(); });
  window.addEventListener("orientationchange", () => { computeAlignment(); onScroll(); });
  window.addEventListener("scroll", onScroll, { passive: true });

  /* ----------------------- Estado inicial del botón ----------------------- */
  if (btn && politeiaPostReading.isLoggedIn) {
    const isStarted = politeiaPostReading.initial &&
                      politeiaPostReading.initial.status === "started";
    if (isStarted) {
      btn.textContent = "Finish Reading";
      btn.classList.add("is-finished");
    } else {
      btn.textContent = politeiaPostReading.hasCompleted
        ? "Start Reading Again"
        : "Start Reading";
      btn.classList.remove("is-finished");
    }
  }
  if (btn && !politeiaPostReading.isLoggedIn) btn.disabled = true;

  /* ----------------------- Toggle Start / Finish ----------------------- */
  if (btn) {
    btn.addEventListener("click", async () => {
      if (!politeiaPostReading.isLoggedIn) return;

      btn.disabled = true;
      const postId = politeiaPostReading.postId;
      const restUrl = politeiaPostReading.restUrl;
      const nonce = politeiaPostReading.nonce;

      try {
        const action = btn.classList.contains("is-finished") ? "finish" : "start";
        const res = await fetch(restUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
          body: JSON.stringify({ post_id: postId, action })
        });
        if (!res.ok) throw new Error("Request failed");
        const data = await res.json();

        if (data.status === "started") {
          btn.textContent = "Finish Reading";
          btn.classList.add("is-finished");
        } else if (data.status === "finished") {
          btn.textContent = "Start Reading Again";
          btn.classList.remove("is-finished");
          politeiaPostReading.hasCompleted = true;
        }
      } catch (e) {
        console.error(e);
        alert("Hubo un problema al registrar la lectura.");
      } finally {
        btn.disabled = false;
      }
    });
  }
});
