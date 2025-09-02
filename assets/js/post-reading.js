/**
 * post-reading.js
 * Toggle Start/Finish + botón flotante alineado al contenido + barra de progreso
 * - Progreso empieza en 0% (S/E exactos)
 * - Sin salto: usa spacer cuando el wrapper pasa a fixed
 * - rAF para scroll suave
 */
document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".politeia-post-reading-wrap");
  const btn  = document.querySelector(".politeia-post-reading-btn");
  if (!wrap || typeof politeiaPostReading === "undefined") return;

  /* ---------- Inyectar barra de progreso (sin tocar PHP) ---------- */
  const progress = document.createElement("div");
  progress.className = "politeia-scroll-progress";
  const progressBar = document.createElement("div");
  progressBar.className = "politeia-scroll-progress__bar";
  progress.appendChild(progressBar);
  wrap.appendChild(progress);

  /* ---------- Spacer para evitar salto en layout ---------- */
  const spacer = document.createElement("div");
  spacer.className = "politeia-post-reading-spacer";
  wrap.parentNode.insertBefore(spacer, wrap.nextSibling);

  /* ---------- Alineación al contenido y lógica fixed ---------- */
  const FIXED_TOP = 107; // offset bajo tu navbar
  let initialTop = wrap.getBoundingClientRect().top + window.scrollY;
  let ticking = false;   // rAF throttle

  function getContentContainer() {
    const sels = [
      ".entry-content",
      "article .entry-content",
      ".single-post .entry-content",
      ".post-content",
      ".site-main .entry-content",
      ".site-main",
      "main"
    ];
    for (const s of sels) {
      const el = document.querySelector(s);
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

    const left  = rect.left + window.scrollX + padL;         // borde del texto
    const width = Math.max(0, rect.width - padL - padR);     // ancho interior

    wrap.style.setProperty("--politeia-fixed-left", left + "px");
    wrap.style.setProperty("--politeia-fixed-width", width + "px");

    // punto original donde está el wrapper en flujo normal
    initialTop = wrap.getBoundingClientRect().top + window.scrollY;

    // ajustar spacer si ya está fijo
    if (wrap.classList.contains("is-fixed")) {
      spacer.style.height = wrap.offsetHeight + "px";
    }

    updateScrollProgress(); // re-evaluar progreso por si cambió layout
  }

  function updateFixedState(scrollY) {
    const shouldFix = scrollY + FIXED_TOP >= initialTop;
    if (shouldFix && !wrap.classList.contains("is-fixed")) {
      wrap.classList.add("is-fixed");
      spacer.style.height = wrap.offsetHeight + "px"; // reserva espacio
    } else if (!shouldFix && wrap.classList.contains("is-fixed")) {
      wrap.classList.remove("is-fixed");
      spacer.style.height = "0px";
    }
  }

  /* ---------- Progreso: empieza en 0% y termina en 100% ---------- */
  function clamp01(x){ return Math.max(0, Math.min(1, x)); }

  function getContentMetrics() {
    const ref = getContentContainer();
    const rect = ref.getBoundingClientRect();
    const top  = rect.top + window.scrollY;
    // scrollHeight captura el contenido real (mejor vs. margins)
    const height = ref.scrollHeight || rect.height || 1;
    return { top, height };
  }

  function computeProgress(scrollY) {
    const { top, height } = getContentMetrics();

    // S: cuando el top del contenido llega justo debajo del header fijo
    const S = top - FIXED_TOP;

    // E: cuando el bottom del contenido coincide con el bottom del viewport
    const E = top + height - window.innerHeight;

    const denom = Math.max(E - S, 1); // evita /0 e inversiones si el contenido es corto
    return clamp01((scrollY - S) / denom);
  }

  function updateScrollProgress() {
    const p = computeProgress(window.scrollY);
    progressBar.style.width = (p * 100).toFixed(2) + "%";

    const COMPLETE_EPS = 0.995;
    wrap.classList.toggle("is-complete", p >= COMPLETE_EPS);

    // Evento opcional al completar
    if (p >= COMPLETE_EPS && !updateScrollProgress._fired) {
      updateScrollProgress._fired = true;
      window.dispatchEvent(new CustomEvent("politeia:postReadingScrollComplete", {
        detail: { postId: politeiaPostReading.postId }
      }));
    }
  }

  function onScrollRaf() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const y = window.scrollY;
      updateFixedState(y);
      updateScrollProgress();
      ticking = false;
    });
  }

  // init
  computeAlignment();
  updateFixedState(window.scrollY);
  updateScrollProgress();

  window.addEventListener("resize", () => { computeAlignment(); onScrollRaf(); });
  window.addEventListener("orientationchange", () => { computeAlignment(); onScrollRaf(); });
  window.addEventListener("scroll", onScrollRaf, { passive: true });

  /* ---------- Estado inicial y toggle del botón ---------- */
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

        // el alto del wrap puede cambiar (texto del botón), actualiza spacer
        if (wrap.classList.contains("is-fixed")) {
          spacer.style.height = wrap.offsetHeight + "px";
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
