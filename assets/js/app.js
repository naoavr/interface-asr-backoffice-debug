(() => {
  "use strict";

  // ── CONFIG ──────────────────────────────────────────────
  const CONFIG = Object.freeze({
    WHISPER_API_BASE: "http://10.0.1.250:9000",
    ASR_ENDPOINT:     "proxy.php",
    EMAIL_ENDPOINT:   "send-email.php",
    MAX_FILE_SIZE_MB: 500,
    HEALTH_INTERVAL:  30_000,
    HEALTH_TIMEOUT:   3_000,
    ALLOWED_EXTENSIONS: new Set([
      "mp3","wav","ogg","flac","m4a","wma","aac",
      "mp4","webm","mkv","avi","mov"
    ]),
  });

  // ── DOM REFS ────────────────────────────────────────────
  const $ = (sel) => document.querySelector(sel);
  const filesInput    = $("#audioFiles");
  const processBtn    = $("#processBtn");
  const cancelBtn     = $("#cancelBtn");
  const statusEl      = $("#status");
  const errorEl       = $("#error");
  const successEl     = $("#success");
  const outputEl      = $("#output");
  const taskSelect    = $("#task");
  const languageInput = $("#language");
  const spinnerEl     = $("#spinner");
  const serverDot     = $("#serverDot");
  const serverLabel   = $("#serverLabel");
  const queueList     = $("#queueList");
  const emailInput    = $("#recipientEmail");
  const dropZone      = $("#dropZone");
  const fileCountEl   = $("#fileCount");
  const copyBtn       = $("#copyBtn");
  const progressContainer = $("#progressContainer");
  const progressBar   = $("#progressBar");
  const confirmModal  = $("#confirmModal");
  const confirmMsg    = $("#confirmMessage");
  const confirmYes    = $("#confirmYes");
  const confirmNo     = $("#confirmNo");

  // ── STATE ───────────────────────────────────────────────
  let abortController = null;
  let isProcessing    = false;
  let healthTimer     = null;

  // ── UTILITIES ───────────────────────────────────────────
  function setStatus(text, loading = false) {
    statusEl.textContent = text;
    spinnerEl.classList.toggle("active", loading);
  }

  function clearMessages() {
    errorEl.textContent   = "";
    successEl.textContent = "";
  }

  function showError(msg) {
    errorEl.textContent = msg;
  }

  function showSuccess(msg) {
    successEl.textContent = msg;
  }

  function setServerState(online) {
    serverDot.classList.toggle("on", online);
    serverLabel.textContent = online ? "Online" : "Offline";
  }

  function formatBytes(bytes) {
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1_048_576) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / 1_048_576).toFixed(1) + " MB";
  }

  function getExtension(filename) {
    return (filename.split(".").pop() || "").toLowerCase();
  }

  function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  // ── FILE VALIDATION ─────────────────────────────────────
  function validateFiles(files) {
    const errors = [];
    const maxBytes = CONFIG.MAX_FILE_SIZE_MB * 1_048_576;

    for (const file of files) {
      const ext = getExtension(file.name);
      if (!CONFIG.ALLOWED_EXTENSIONS.has(ext)) {
        errors.push(`"${file.name}" — formato .${ext} não suportado`);
      } else if (file.size > maxBytes) {
        errors.push(`"${file.name}" — excede ${CONFIG.MAX_FILE_SIZE_MB} MB (${formatBytes(file.size)})`);
      } else if (file.size === 0) {
        errors.push(`"${file.name}" — ficheiro vazio`);
      }
    }
    return errors;
  }

  // ── DRAG & DROP ─────────────────────────────────────────
  function updateFileCount() {
    const count = filesInput.files.length;
    fileCountEl.textContent = count
      ? `${count} ficheiro(s) selecionado(s)`
      : "";
  }

  dropZone.addEventListener("dragover", (e) => {
    e.preventDefault();
    dropZone.classList.add("drag-over");
  });
  dropZone.addEventListener("dragleave", () => {
    dropZone.classList.remove("drag-over");
  });
  dropZone.addEventListener("drop", (e) => {
    e.preventDefault();
    dropZone.classList.remove("drag-over");
    if (e.dataTransfer.files.length) {
      filesInput.files = e.dataTransfer.files;
      updateFileCount();
    }
  });
  filesInput.addEventListener("change", updateFileCount);

  // ── HEALTH CHECK ────────────────────────────────────────
  async function checkServer() {
    try {
      await fetch(CONFIG.WHISPER_API_BASE, {
        method: "GET",
        mode: "no-cors",
        signal: AbortSignal.timeout(CONFIG.HEALTH_TIMEOUT),
      });
      setServerState(true);
    } catch {
      setServerState(false);
    }
  }
  checkServer();
  healthTimer = setInterval(checkServer, CONFIG.HEALTH_INTERVAL);

  // ── QUEUE UI ────────────────────────────────────────────
  function buildQueue(files) {
    queueList.innerHTML = "";
    Array.from(files).forEach((file, i) => {
      const item = document.createElement("div");
      item.className = "queue-item";
      item.id = `queue-item-${i}`;
      item.innerHTML =
        `<span class="badge waiting" id="badge-${i}">espera</span>` +
        `<span class="file-name">${escapeHtml(file.name)}</span>` +
        `<span class="file-size">${formatBytes(file.size)}</span>`;
      queueList.appendChild(item);
    });
  }

  const BADGE_LABELS = Object.freeze({
    waiting: "espera",
    active:  "a processar",
    done:    "concluído",
    error:   "erro",
  });

  function setBadge(index, state) {
    const badge = document.getElementById(`badge-${index}`);
    if (!badge) return;
    badge.className = `badge ${state}`;
    badge.textContent = BADGE_LABELS[state] ?? state;
  }

  // ── PROGRESS ────────────────────────────────────────────
  function setProgress(pct) {
    progressBar.style.width = `${Math.min(100, Math.max(0, pct))}%`;
  }

  function showProgress(show) {
    progressContainer.classList.toggle("active", show);
    if (!show) setProgress(0);
  }

  // ── CONFIRM DIALOG ──────────────────────────────────────
  function confirm(message) {
    return new Promise((resolve) => {
      confirmMsg.textContent = message;
      confirmModal.classList.add("open");

      function cleanup(result) {
        confirmModal.classList.remove("open");
        confirmYes.removeEventListener("click", onYes);
        confirmNo.removeEventListener("click", onNo);
        resolve(result);
      }
      function onYes() { cleanup(true);  }
      function onNo()  { cleanup(false); }

      confirmYes.addEventListener("click", onYes);
      confirmNo.addEventListener("click", onNo);
    });
  }

  // ── COPY RESULT ─────────────────────────────────────────
  copyBtn.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(outputEl.textContent);
      copyBtn.textContent = "✅ Copiado!";
      setTimeout(() => { copyBtn.textContent = "📋 Copiar"; }, 1500);
    } catch {
      copyBtn.textContent = "❌ Erro";
      setTimeout(() => { copyBtn.textContent = "📋 Copiar"; }, 1500);
    }
  });

  // ── TRANSCRIBE ONE FILE (with upload progress) ──────────
  function transcribeFile(file, { task, language, signal }) {
    return new Promise((resolve, reject) => {
      const formData = new FormData();
      formData.append("audio_file", file);
      formData.append("task", task);
      if (language) formData.append("language", language);
      formData.append("output", "json");

      const xhr = new XMLHttpRequest();
      xhr.open("POST", CONFIG.ASR_ENDPOINT);
      xhr.responseType = "json";

      signal?.addEventListener("abort", () => xhr.abort());

      xhr.upload.addEventListener("progress", (e) => {
        if (e.lengthComputable) {
          setProgress((e.loaded / e.total) * 100);
        }
      });

      xhr.addEventListener("load", () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          const data = xhr.response;
          resolve(data?.text ?? JSON.stringify(data, null, 2));
        } else {
          const detail = xhr.response?.detail || `Erro HTTP ${xhr.status}`;
          reject(new Error(detail));
        }
      });

      xhr.addEventListener("error", () => reject(new Error("Erro de rede")));
      xhr.addEventListener("abort", () => reject(new DOMException("Cancelado", "AbortError")));
      xhr.addEventListener("timeout", () => reject(new Error("Timeout")));

      xhr.send(formData);
    });
  }

  // ── MAIN PROCESS ───────────────────────────────────────
  processBtn.addEventListener("click", async () => {
    clearMessages();
    outputEl.textContent = "";
    copyBtn.style.display = "none";

    const files    = filesInput.files;
    const email    = emailInput.value.trim();
    const task     = taskSelect.value;
    const language = languageInput.value.trim();

    if (!files.length) {
      showError("Seleciona pelo menos um ficheiro de áudio/vídeo.");
      setStatus("Nenhum ficheiro selecionado.");
      return;
    }

    if (!email) {
      showError("Introduz o email do destinatário.");
      emailInput.focus();
      return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showError("Formato de email inválido.");
      emailInput.focus();
      return;
    }

    const fileErrors = validateFiles(files);
    if (fileErrors.length) {
      showError("Ficheiros inválidos:\n• " + fileErrors.join("\n• "));
      return;
    }

    const totalSize = Array.from(files).reduce((s, f) => s + f.size, 0);
    const ok = await confirm(
      `Processar ${files.length} ficheiro(s) (${formatBytes(totalSize)}) e enviar resultado para ${email}?`
    );
    if (!ok) return;

    buildQueue(files);
    processBtn.disabled = true;
    cancelBtn.classList.add("visible");
    isProcessing = true;
    abortController = new AbortController();

    const results = [];

    for (let i = 0; i < files.length; i++) {
      if (abortController.signal.aborted) break;

      const file = files[i];
      setBadge(i, "active");
      setStatus(`A processar ficheiro ${i + 1} de ${files.length}: ${file.name}`, true);
      showProgress(true);

      try {
        const text = await transcribeFile(file, {
          task,
          language,
          signal: abortController.signal,
        });

        results.push({ name: file.name, text });
        setBadge(i, "done");
        setServerState(true);
        outputEl.textContent += `=== ${file.name} ===\n${text}\n\n`;
        outputEl.scrollTop = outputEl.scrollHeight;
      } catch (err) {
        if (err.name === "AbortError") {
          setBadge(i, "error");
          results.push({ name: file.name, text: "[CANCELADO]" });
          break;
        }
        results.push({ name: file.name, text: `[ERRO: ${err.message}]` });
        setBadge(i, "error");
      } finally {
        showProgress(false);
      }
    }

    if (abortController.signal.aborted) {
      for (let j = 0; j < files.length; j++) {
        const badge = document.getElementById(`badge-${j}`);
        if (badge && badge.classList.contains("waiting")) {
          setBadge(j, "error");
        }
      }
      setStatus("Processamento cancelado.", false);
      showError("Operação cancelada pelo utilizador.");
      processBtn.disabled = false;
      cancelBtn.classList.remove("visible");
      isProcessing = false;
      if (outputEl.textContent.trim()) copyBtn.style.display = "inline-flex";
      return;
    }

    // ── Send email com HTML ───────────────────────────────
    setStatus("A enviar email…", true);

    const emailHeaderHtml = `
      <div style="text-align:center;margin-bottom:16px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <div style="font-weight:600;font-size:14px;color:#111827;">
          Núcleo de Apoio Operativo
        </div>
        <div style="font-size:13px;color:#4b5563;margin-top:2px;">
          Comando Territorial da Guarda Nacional Republicana de Aveiro
        </div>
      </div>
    `;

    const filesHtml = results.map(r => `
      <div style="margin-top:12px;">
        <div style="font-weight:600;font-size:14px;margin-bottom:4px;">
          ${escapeHtml(r.name)}
        </div>
        <pre style="white-space:pre-wrap;font-size:13px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
${escapeHtml(r.text)}
        </pre>
      </div>
    `).join("");

    const emailBodyHtml = emailHeaderHtml + filesHtml;

    try {
      const emailRes = await fetch(CONFIG.EMAIL_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          to: email,
          results,
          html: emailBodyHtml
        }),
      });
      if (!emailRes.ok) throw new Error(`Falha ao enviar email (${emailRes.status})`);

      showSuccess(`Email enviado com sucesso para ${email}`);
      setStatus("Processamento e envio concluídos.", false);
    } catch (err) {
      showError(`Erro ao enviar email: ${err.message}`);
      setStatus("Processamento concluído mas email falhou.", false);
    }

    processBtn.disabled = false;
    cancelBtn.classList.remove("visible");
    isProcessing = false;
    if (outputEl.textContent.trim()) copyBtn.style.display = "inline-flex";
  });

  // ── CANCEL ──────────────────────────────────────────────
  cancelBtn.addEventListener("click", () => {
    if (abortController) abortController.abort();
  });

  // ── WARN ON PAGE LEAVE DURING PROCESSING ───────────────
  window.addEventListener("beforeunload", (e) => {
    if (isProcessing) {
      e.preventDefault();
      e.returnValue = "";
    }
  });

  // ── CLEANUP ON PAGE UNLOAD ──────────────────────────────
  window.addEventListener("unload", () => {
    if (healthTimer) clearInterval(healthTimer);
  });
})();
