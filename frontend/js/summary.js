/**
 * summary.js
 * Handles AI-generated summaries of uploaded notes.
 */

const SUMMARY_API = "https://smartstudy-backend-oekm.onrender.com/backend/ai/generate_summary.php";

// ─── State ────────────────────────────────────────────────────────────────────

const summaryState = {
  noteId:      null,
  noteName:    "",
  summaryText: "",
  loading:     false,
};

// ─── Summary Generation ───────────────────────────────────────────────────────

async function generateSummary(noteId, regenerate = false) {
  if (summaryState.loading) return null;
  summaryState.loading = true;

  setSummaryLoading(true);
  clearSummaryError();
  clearSummaryContent();

  try {
    const res = await fetch(SUMMARY_API, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({ note_id: noteId, regenerate }),
    });

    const data = await res.json();

    if (data.success && data.summary) {
      summaryState.summaryText = data.summary;
      renderSummary(data.summary);
      return data.summary;
    } else {
      showSummaryError(data.message || "Failed to generate summary. Please try again.");
      return null;
    }
  } catch (err) {
    console.error("Summary generation error:", err);
    showSummaryError("Network error. Please check your connection and try again.");
    return null;
  } finally {
    summaryState.loading = false;
    setSummaryLoading(false);
  }
}

// ─── Rendering ────────────────────────────────────────────────────────────────

/**
 * Renders the summary using marked.js (already loaded on summary.html)
 * with LaTeX protection for MathJax, same approach as chat.js.
 */
function renderSummary(text) {
  const container = document.getElementById("summary-content");
  if (!container) return;

  container.innerHTML = markdownWithLatex(text);
  container.style.display = "block";

  // Show action buttons and keyword search
  const actionsEl = document.getElementById("summary-actions");
  if (actionsEl) actionsEl.style.display = "flex";

  const searchBar = document.getElementById("summary-search-bar");
  if (searchBar) searchBar.style.display = "block";

  // Typeset MathJax after DOM insertion
  if (window.MathJax) {
    MathJax.startup.promise.then(() => {
      MathJax.typesetPromise([container]);
    });
  }
}

/**
 * Converts markdown + LaTeX to HTML.
 * Protects LaTeX blocks before passing through marked.js,
 * then restores them so MathJax can render them.
 */
function markdownWithLatex(text) {
  if (typeof marked === "undefined") {
    // Fallback if marked.js not loaded — basic render
    return basicMarkdownToHtml(text);
  }

  // Step 1: Protect LaTeX blocks from marked
  const latexBlocks = [];

  let protected_text = text
    // Protect display math \[ ... \]
    .replace(/\\\[([\s\S]*?)\\\]/g, (match) => {
      latexBlocks.push(match);
      return `%%LATEX_BLOCK_${latexBlocks.length - 1}%%`;
    })
    // Protect inline math \( ... \)
    .replace(/\\\(([\s\S]*?)\\\)/g, (match) => {
      latexBlocks.push(match);
      return `%%LATEX_BLOCK_${latexBlocks.length - 1}%%`;
    });

  // Step 2: Run marked on the protected text
  let html = marked.parse(protected_text);

  // Step 3: Restore LaTeX blocks
  html = html.replace(/%%LATEX_BLOCK_(\d+)%%/g, (_, i) => latexBlocks[parseInt(i)]);

  return html;
}

/**
 * Fallback basic markdown renderer (if marked.js not available).
 */
function basicMarkdownToHtml(md) {
  let html = escapeHtml(md);

  // Headings (### → h4, ## → h3, # → h2)
  html = html.replace(/^###\s+(.+)$/gm, "<h4>$1</h4>");
  html = html.replace(/^##\s+(.+)$/gm,  "<h3>$1</h3>");
  html = html.replace(/^#\s+(.+)$/gm,   "<h2>$1</h2>");

  // Bold and italic
  html = html.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
  html = html.replace(/\*(.+?)\*/g,     "<em>$1</em>");

  // Unordered lists
  html = html.replace(/(?:^|\n)((?:[-*]\s.+\n?)+)/g, (match, list) => {
    const items = list
      .split("\n")
      .filter((l) => /^[-*]\s/.test(l))
      .map((l) => `<li>${l.slice(2)}</li>`)
      .join("");
    return `\n<ul>${items}</ul>`;
  });

  // Paragraphs
  html = html
    .split(/\n{2,}/)
    .map((block) => {
      if (block.startsWith("<h") || block.startsWith("<ul")) return block;
      const trimmed = block.trim().replace(/\n/g, "<br>");
      return trimmed ? `<p>${trimmed}</p>` : "";
    })
    .join("\n");

  return html;
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

// ─── Copy & Download ──────────────────────────────────────────────────────────

async function copySummary() {
  if (!summaryState.summaryText) return;

  try {
    await navigator.clipboard.writeText(summaryState.summaryText);
    showCopyFeedback();
  } catch {
    const el = document.createElement("textarea");
    el.value = summaryState.summaryText;
    document.body.appendChild(el);
    el.select();
    document.execCommand("copy");
    document.body.removeChild(el);
    showCopyFeedback();
  }
}

function showCopyFeedback() {
  const btn = document.getElementById("copy-summary-btn");
  if (!btn) return;
  const originalText = btn.textContent;
  btn.textContent = "✓ Copied!";
  btn.disabled = true;
  setTimeout(() => {
    btn.textContent = originalText;
    btn.disabled = false;
  }, 2000);
}

function downloadSummary() {
  if (!summaryState.summaryText) return;

  const filename = `${summaryState.noteName || "summary"}_summary.txt`;
  const blob     = new Blob([summaryState.summaryText], { type: "text/plain" });
  const url      = URL.createObjectURL(blob);

  const a   = document.createElement("a");
  a.href    = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ─── UI Helpers ───────────────────────────────────────────────────────────────

function setSummaryLoading(show) {
  const loadingEl  = document.getElementById("summary-loading");
  const generateEl = document.getElementById("generate-summary-btn");

  if (loadingEl)  loadingEl.style.display  = show ? "flex" : "none";
  if (generateEl) {
    generateEl.disabled    = show;
    generateEl.textContent = show ? "Generating…" : "🧠 Generate Summary";
  }
}

function showSummaryError(message) {
  const el = document.getElementById("summary-error");
  if (el) { el.textContent = message; el.style.display = "block"; }
}

function clearSummaryError() {
  const el = document.getElementById("summary-error");
  if (el) { el.textContent = ""; el.style.display = "none"; }
}

function clearSummaryContent() {
  const container = document.getElementById("summary-content");
  if (container) { container.innerHTML = ""; container.style.display = "none"; }
  const actionsEl = document.getElementById("summary-actions");
  if (actionsEl) actionsEl.style.display = "none";
  const searchBar = document.getElementById("summary-search-bar");
  if (searchBar) searchBar.style.display = "none";
}

// ─── Keyword Highlighting ─────────────────────────────────────────────────────

function highlightKeyword(term) {
  const container = document.getElementById("summary-content");
  if (!container) return;

  // Re-render clean version first
  renderSummary(summaryState.summaryText);
  if (!term.trim()) return;

  const regex = new RegExp(`(${escapeRegex(term)})`, "gi");
  container.innerHTML = container.innerHTML.replace(
    regex,
    `<mark class="keyword-highlight">$1</mark>`
  );
}

function escapeRegex(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

// ─── Page Init ────────────────────────────────────────────────────────────────

async function initSummarySection(noteId, noteName) {
  summaryState.noteId   = noteId;
  summaryState.noteName = noteName;

  const generateBtn = document.getElementById("generate-summary-btn");
  if (generateBtn) {
    generateBtn.addEventListener("click", () => generateSummary(noteId, false));
  }

  // Regenerate button (inside actions bar)
  const regenBtn = document.getElementById("generate-summary-btn-2");
  if (regenBtn) {
    regenBtn.addEventListener("click", () => generateSummary(noteId, true));
  }

  const copyBtn = document.getElementById("copy-summary-btn");
  if (copyBtn) copyBtn.addEventListener("click", copySummary);

  const downloadBtn = document.getElementById("download-summary-btn");
  if (downloadBtn) downloadBtn.addEventListener("click", downloadSummary);

  const searchInput = document.getElementById("summary-search");
  if (searchInput) {
    searchInput.addEventListener("input", () => highlightKeyword(searchInput.value));
  }
}

async function initSummaryPage() {
  requireAuth();

  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    showSummaryError("No note selected. Please go back and choose a note.");
    return;
  }

  const note = await fetchNoteById(noteId);
  if (note) {
    summaryState.noteName = note.name;
    const titleEl = document.getElementById("summary-note-title");
    if (titleEl) titleEl.textContent = note.name;
  }

  await initSummarySection(noteId, summaryState.noteName);

  // Auto-generate on page load
  await generateSummary(noteId, false);
}