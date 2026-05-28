// /**
//  * main.js
//  * App entry point. Detects which page is loaded and initialises
//  * the correct module(s). Also handles shared UI (navbar, report page).
//  *
//  * Script load order in every HTML file:
//  *   <script src="../js/auth.js"></script>
//  *   <script src="../js/upload.js"></script>
//  *   <script src="../js/summary.js"></script>
//  *   <script src="../js/quiz.js"></script>
//  *   <script src="../js/chat.js"></script>
//  *   <script src="../js/main.js"></script>   ← always last
//  */

// // ─── Page Detection ───────────────────────────────────────────────────────────

// /**
//  * Returns the current page filename without the path.
//  * e.g. "dashboard.html", "quiz.html", "login.html"
//  */
// function getCurrentPage() {
//   return window.location.pathname.split("/").pop() || "index.html";
// }

// // ─── Shared UI ────────────────────────────────────────────────────────────────

// /**
//  * Injects the navbar into #navbar-placeholder (if it exists).
//  * The navbar HTML is kept in a single place (navbar.html) and
//  * pulled in on every page at runtime so changes propagate everywhere.
//  *
//  * Falls back to rendering inline if fetch fails (e.g. when running
//  * from the file:// protocol during development).
//  */
// async function loadNavbar() {
//   const placeholder = document.getElementById("navbar-placeholder");
//   if (!placeholder) return;

//   try {
//     const res = await fetch("navbar.html");
//     if (!res.ok) throw new Error("navbar.html not found");
//     placeholder.innerHTML = await res.text();
//   } catch {
//     // Inline fallback navbar
//     placeholder.innerHTML = buildInlineNavbar();
//   }

//   // After navbar is in the DOM, bind shared elements
//   renderUserInfo();
//   bindLogoutButtons();
//   highlightActiveNavLink();
// }

// /**
//  * Returns a minimal inline navbar string as a fallback.
//  */
// function buildInlineNavbar() {
//   const user = getUser();
//   return `
//     <nav class="nav">
//       <a class="nav-brand" href="dashboard.html">📚 StudyAI</a>
//       <div class="nav-links">
//         <a class="nav-link" href="dashboard.html">Home</a>
//         <a class="nav-link" href="upload.html">Upload Notes</a>
//         <a class="nav-link" href="report.html">Report</a>
//       </div>
//       <div class="nav-right">
//         <span class="user-name">${user ? user.name : ""}</span>
//         <button class="logout-btn btn-logout">Logout</button>
//       </div>
//     </nav>`;
// }

// /**
//  * Adds the "active" class to the nav link matching the current page.
//  */
// function highlightActiveNavLink() {
//   const page = getCurrentPage();
//   document.querySelectorAll(".nav-link").forEach((link) => {
//     const href = link.getAttribute("href") || "";
//     if (href.endsWith(page)) {
//       link.classList.add("active");
//     } else {
//       link.classList.remove("active");
//     }
//   });
// }

// // ─── Report Page ──────────────────────────────────────────────────────────────

// /**
//  * Fetches quiz results from the backend and renders the Report page.
//  */
// async function initReportPage() {
//   requireAuth();

//   try {
//     // const res = await fetch("../backend/quiz/get_results.php", {
//     //   headers: { Authorization: `Bearer ${getToken()}` },
//     // });
//     const res = await fetch(
//   "http://localhost:8000/SmartStudyCompanion/backend/quiz/get_results.php",
//   {
//     headers: {
//       Authorization: `Bearer ${getToken()}`
//     }
//   }
// );
//     const data = await res.json();

//     if (data.success) {
//       renderReportStats(data.stats);
//       renderQuizHistory(data.results);
//     } else {
//       console.error("Failed to load report:", data.message);
//     }
//   } catch (err) {
//     console.error("Report fetch error:", err);
//   }
// }

// /**
//  * Renders the three summary stat cards on the report page.
//  * @param {{ notes_count: number, quizzes_taken: number, avg_score: number }} stats
//  */
// function renderReportStats(stats) {
//   const set = (id, val) => {
//     const el = document.getElementById(id);
//     if (el) el.textContent = val;
//   };

//   set("stat-notes",   stats.notes_count   ?? 0);
//   set("stat-quizzes", stats.quizzes_taken  ?? 0);
//   set("stat-avg",     `${stats.avg_score   ?? 0}%`);
// }

// /**
//  * Renders the quiz history list on the report page.
//  * @param {Array} results - array of quiz result objects from the backend
//  */
// function renderQuizHistory(results) {
//   const container = document.getElementById("quiz-history-list");
//   if (!container) return;

//   if (!results || results.length === 0) {
//     container.innerHTML = `
//       <div class="empty-state">
//         <p>No quizzes taken yet. <a href="dashboard.html">Go upload some notes →</a></p>
//       </div>`;
//     return;
//   }

//   container.innerHTML = results
//     .slice()
//     .reverse() // most recent first
//     .map((r) => {
//       const pct   = r.percent ?? Math.round((r.score / r.total) * 100);
//       const badge = pct >= 80 ? "badge-good" : pct >= 50 ? "badge-ok" : "badge-poor";
//       const label = pct >= 80 ? "Excellent"  : pct >= 50 ? "Good"      : "Needs Work";

//       return `
//         <div class="history-item">
//           <div class="history-score">${pct}%</div>
//           <div class="history-info">
//             <div class="history-note">${escapeHtml(r.note_name)}</div>
//             <div class="history-date">${r.date} · ${r.score}/${r.total} correct</div>
//           </div>
//           <span class="history-badge ${badge}">${label}</span>
//         </div>`;
//     })
//     .join("");
// }

// // ─── View Note Page ───────────────────────────────────────────────────────────

// /**
//  * Initialises the View Note page (view-note.html).
//  * Loads note metadata from the URL param, renders file info,
//  * content preview, and wires up the three action buttons.
//  */
// async function initViewNotePage() {
//   requireAuth();

//   const params = new URLSearchParams(window.location.search);
//   const noteId = params.get("id");

//   if (!noteId) {
//     window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
//     return;
//   }

//   const note = await fetchNoteById(noteId);
//   if (!note) {
//     alert("Note not found.");
//     window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
//     return;
//   }

//   // Populate file info panel
//   setTextContent("file-info-name",    note.name);
//   setTextContent("file-info-size",    note.file_size);
//   setTextContent("file-info-type",    note.file_type);
//   setTextContent("file-info-date",    note.upload_date);

//   // Show content preview (first 3 000 chars)
//   // const previewEl = document.getElementById("note-preview-text");
//   // if (previewEl) {
//   //   const preview = note.content.slice(0, 3000);
//   //   previewEl.textContent = preview + (note.content.length > 3000 ? "\n\n[…truncated]" : "");
//   // }

// const previewEl = document.getElementById("note-preview-text");
// if (previewEl) {
//     const raw = note.content.slice(0, 6000);
//     // Decode HTML entities that got double-escaped during PDF extraction + JSON encoding
//     const decoded = raw
//         .replace(/&lt;/g, "<")
//         .replace(/&gt;/g, ">")
//         .replace(/&amp;/g, "&")
//         .replace(/&quot;/g, '"')
//         .replace(/&#39;/g, "'");
//     previewEl.innerHTML = decoded + (note.content.length > 6000
//         ? '<p class="preview-truncated">[… truncated — download to see full content]</p>'
//         : "");
// }

//   // Wire up action buttons — each redirects to the dedicated feature page
//   wireLinkButton("btn-generate-summary", `summary.html?id=${noteId}`);
//   wireLinkButton("btn-generate-quiz",    `quiz.html?id=${noteId}`);
//   wireLinkButton("btn-ask-ai",           `chat.html?id=${noteId}`);

//   // Inline summary init if the summary section is embedded in view-note.html
//   const summarySection = document.getElementById("summary-section");
//   if (summarySection) {
//     await initSummarySection(noteId, note.name);
//   }
// }

// function setTextContent(id, value) {
//   const el = document.getElementById(id);
//   if (el) el.textContent = value ?? "—";
// }

// function wireLinkButton(btnId, href) {
//   const btn = document.getElementById(btnId);
//   if (btn) btn.addEventListener("click", () => { window.location.href = href; });
// }

// // ─── Dashboard Page ───────────────────────────────────────────────────────────

// /**
//  * Initialises the dashboard / home page.
//  * Shows recent notes and links to feature pages.
//  */
// async function initDashboardPage() {
//   requireAuth();
//   renderUserInfo();

//   // Load recent notes into the hero/recent section
//   const recentContainer = document.getElementById("recent-notes");
//   if (recentContainer) {
//     const notes = await fetchNotes();
//     renderNotesGrid(notes.slice(-3), recentContainer, (id) => {
//       window.location.href = `view-note.html?id=${id}`;
//     });
//   }
// }

// // ─── Global Helper ────────────────────────────────────────────────────────────

// function escapeHtml(str) {
//   return String(str)
//     .replace(/&/g, "&amp;")
//     .replace(/</g, "&lt;")
//     .replace(/>/g, "&gt;")
//     .replace(/"/g, "&quot;");
// }

// // ─── Router ───────────────────────────────────────────────────────────────────

// /**
//  * Main entry point. Detects the current page and calls the appropriate init.
//  */
// async function main() {
//   const page = getCurrentPage();

//   // Load navbar on all pages except login
//   if (page !== "login.html") {
//     await loadNavbar();
//   }

//   switch (page) {
//     case "login.html":
//       bindLoginForm();
//       bindSignupForm();
//       setupLoginTabs();
//       break;

//     case "index.html":
//     case "dashboard.html":
//       await initDashboardPage();
//       break;

//     case "upload.html":
//       initUploadPage();
//       break;

//     case "view-note.html":
//       await initViewNotePage();
//       break;

//     case "summary.html":
//       await initSummaryPage();
//       break;

//     case "quiz.html":
//       await initQuizPage();
//       break;

//     case "chat.html":
//       await initChatPage();
//       break;

//     case "report.html":
//       await initReportPage();
//       break;

//     default:
//       console.info(`main.js: no init defined for page "${page}"`);
//   }
// }

// // ─── Login Page Tabs ──────────────────────────────────────────────────────────

// /**
//  * Sets up the Login / Sign Up tab switcher on login.html.
//  */
// function setupLoginTabs() {
//   const loginTab  = document.getElementById("tab-login");
//   const signupTab = document.getElementById("tab-signup");
//   const loginForm  = document.getElementById("login-form");
//   const signupForm = document.getElementById("signup-form");

//   if (!loginTab || !signupTab) return;

//   loginTab.addEventListener("click", () => {
//     loginTab.classList.add("active");
//     signupTab.classList.remove("active");
//     if (loginForm)  loginForm.style.display  = "block";
//     if (signupForm) signupForm.style.display = "none";
//   });

//   signupTab.addEventListener("click", () => {
//     signupTab.classList.add("active");
//     loginTab.classList.remove("active");
//     if (signupForm) signupForm.style.display = "block";
//     if (loginForm)  loginForm.style.display  = "none";
//   });
// }

// // ─── Kick off ─────────────────────────────────────────────────────────────────
// document.addEventListener("DOMContentLoaded", main);















/**
 * main.js  —  Smart Study Companion
 * App entry point + custom delete modal + empty state renderer.
 */

// ─── Page Detection ───────────────────────────────────────────────────────────

function getCurrentPage() {
  return window.location.pathname.split("/").pop() || "index.html";
}

// ─── Shared UI ────────────────────────────────────────────────────────────────

async function loadNavbar() {
  const placeholder = document.getElementById("navbar-placeholder");
  if (!placeholder) return;

  try {
    const res = await fetch("navbar.html");
    if (!res.ok) throw new Error("navbar.html not found");
    placeholder.innerHTML = await res.text();
  } catch {
    placeholder.innerHTML = buildInlineNavbar();
  }

  renderUserInfo();
  bindLogoutButtons();
  highlightActiveNavLink();
}

function buildInlineNavbar() {
  const user = getUser();
  return `
    <nav class="nav">
      <a class="nav-brand" href="dashboard.html">📚 StudyAI</a>
      <div class="nav-links">
        <a class="nav-link" href="dashboard.html">Home</a>
        <a class="nav-link" href="upload.html">Upload Notes</a>
        <a class="nav-link" href="report.html">Report</a>
      </div>
      <div class="nav-right">
        <span class="user-name">${user ? user.name : ""}</span>
        <button class="logout-btn btn-logout">Logout</button>
      </div>
    </nav>`;
}

function highlightActiveNavLink() {
  const page = getCurrentPage();
  document.querySelectorAll(".nav-link").forEach((link) => {
    const href = link.getAttribute("href") || "";
    link.classList.toggle("active", href.endsWith(page));
  });
}

// ─── Delete Confirmation Modal ────────────────────────────────────────────────

function getOrCreateDeleteModal() {
  const MODAL_ID = "delete-confirm-modal";
  let overlay = document.getElementById(MODAL_ID);
  if (overlay) return overlay;

  overlay = document.createElement("div");
  overlay.id = MODAL_ID;
  overlay.className = "modal-overlay";
  overlay.setAttribute("role", "dialog");
  overlay.setAttribute("aria-modal", "true");
  overlay.setAttribute("aria-labelledby", "modal-title-text");

  overlay.innerHTML = `
    <div class="modal-card">

      <div class="modal-header">
        <div class="modal-icon-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6M14 11v6"/>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
        </div>
        <div class="modal-title" id="modal-title-text">Confirm Deletion</div>
      </div>

      <div class="modal-body">Are you sure you want to permanently delete this note?</div>
      <span class="modal-note-name" id="modal-note-name-label"></span>

      <div class="modal-warning">
        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        This action cannot be undone.
      </div>

      <div class="modal-divider"></div>

      <div class="modal-actions">
        <button class="modal-btn-cancel" id="modal-cancel-btn" type="button">Cancel</button>
        <button class="modal-btn-delete" id="modal-delete-btn" type="button">
          <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6M14 11v6"/>
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
          </svg>
          Delete Permanently
        </button>
      </div>

    </div>`;

  document.body.appendChild(overlay);
  return overlay;
}

function confirmDeleteModal(noteName) {
  return new Promise((resolve) => {
    const overlay   = getOrCreateDeleteModal();
    const nameEl    = overlay.querySelector("#modal-note-name-label");
    const cancelBtn = overlay.querySelector("#modal-cancel-btn");
    const deleteBtn = overlay.querySelector("#modal-delete-btn");

    // Reset button every time (in case a previous delete left it loading)
    deleteBtn.classList.remove("loading");
    deleteBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
        <path d="M10 11v6M14 11v6"/>
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
      </svg>
      Delete Permanently`;

    nameEl.textContent = noteName || "this note";
    requestAnimationFrame(() => overlay.classList.add("modal-open"));

    function close(result) {
      overlay.classList.remove("modal-open");
      overlay.addEventListener("transitionend", cleanup, { once: true });
      resolve(result);
    }

    function cleanup() {
      cancelBtn.removeEventListener("click",  onCancel);
      deleteBtn.removeEventListener("click",  onDelete);
      overlay.removeEventListener("click",    onBackdrop);
      document.removeEventListener("keydown", onKey);
    }

    const onCancel   = () => close(false);
    const onDelete   = () => close(true);
    const onBackdrop = (e) => { if (e.target === overlay) close(false); };
    const onKey      = (e) => { if (e.key === "Escape")   close(false); };

    cancelBtn.addEventListener("click",  onCancel);
    deleteBtn.addEventListener("click",  onDelete);
    overlay.addEventListener("click",    onBackdrop);
    document.addEventListener("keydown", onKey);

    setTimeout(() => cancelBtn.focus(), 50);
  });
}

// ─── Empty State ──────────────────────────────────────────────────────────────

function renderEmptyNotesState(container) {
  container.innerHTML = `
    <div class="notes-empty-state">
      <img
        src="../assets/empty-clipboard.png"
        alt="No notes"
        class="empty-clipboard-img"
        onerror="this.outerHTML='<div style=\'font-size:3.5rem;margin-bottom:0.25rem\'>📋</div>'"
      />
      <div class="empty-title">You have no notes yet</div>
      <div class="empty-sub">Upload your first study note to get started with AI summaries, quizzes, and more.</div>
      <a href="upload.html" class="btn-primary btn-sm" style="margin-top:0.25rem;">+ Upload Notes</a>
    </div>`;
}

// ─── Safe JSON fetch helper ───────────────────────────────────────────────────
// Catches the case where PHP returns an HTML error page instead of JSON.

async function safeFetchJson(url, options) {
  const res = await fetch(url, options);
  const text = await res.text();

  // If the response starts with "<" it's HTML (a PHP error page), not JSON
  const trimmed = text.trim();
  if (trimmed.startsWith("<")) {
    console.error("Backend returned HTML instead of JSON. Raw response:", trimmed.slice(0, 400));
    return {
      success: false,
      message: `Server error (HTTP ${res.status}). Check PHP error logs.`,
      _rawHtml: trimmed,
    };
  }

  try {
    return JSON.parse(trimmed);
  } catch (e) {
    console.error("JSON parse error. Raw response:", trimmed.slice(0, 400));
    return { success: false, message: "Invalid server response. Please try again." };
  }
}

// ─── Note Deletion ────────────────────────────────────────────────────────────

async function handleNoteDelete(noteId, noteName, cardElement, gridElement) {
  const confirmed = await confirmDeleteModal(noteName);
  if (!confirmed) return;

  // Show loading on button
  const deleteBtn = document.querySelector("#modal-delete-btn");
  if (deleteBtn) {
    deleteBtn.classList.add("loading");
    deleteBtn.textContent = "Deleting…";
  }

  let data;
  try {
    data = await safeFetchJson(
      "http://localhost:8000/SmartStudyCompanion/backend/notes/delete_note.php",
      {
        method: "DELETE",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${getToken()}`,
        },
        body: JSON.stringify({ note_id: Number(noteId) }),
      }
    );
  } catch (err) {
    // Network-level failure (server down, CORS, etc.)
    console.error("Delete network error:", err);
    _closeModalAndAlert("Network error. Please check your connection and try again.", deleteBtn);
    return;
  }

  if (!data.success) {
    _closeModalAndAlert(data.message || "Failed to delete note. Please try again.", deleteBtn);
    return;
  }

  // ── Success ──────────────────────────────────────────────────────────────
  const overlay = document.getElementById("delete-confirm-modal");
  if (overlay) overlay.classList.remove("modal-open");

  // Animate card out
  cardElement.style.transition = "opacity 0.22s ease, transform 0.22s ease";
  cardElement.style.opacity    = "0";
  cardElement.style.transform  = "scale(0.93)";

  setTimeout(() => {
    cardElement.remove();
    if (gridElement && gridElement.querySelectorAll(".note-card").length === 0) {
      renderEmptyNotesState(gridElement);
    }
  }, 240);
}

function _closeModalAndAlert(message, deleteBtn) {
  // Restore button
  if (deleteBtn) {
    deleteBtn.classList.remove("loading");
    deleteBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;min-width:13px;">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
        <path d="M10 11v6M14 11v6"/>
        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
      </svg>
      Delete Permanently`;
  }
  const overlay = document.getElementById("delete-confirm-modal");
  if (overlay) overlay.classList.remove("modal-open");
  setTimeout(() => alert(message), 300);
}

// ─── Notes Grid Renderer ──────────────────────────────────────────────────────

function renderNotesGrid(notes, container, onView) {
  if (!container) return;

  if (!notes || notes.length === 0) {
    renderEmptyNotesState(container);
    return;
  }

  container.innerHTML = notes
    .map((note) => {
      const date = note.upload_date
        ? new Date(note.upload_date).toLocaleDateString("en-US", {
            month: "2-digit", day: "2-digit", year: "numeric",
          })
        : "";
      return `
        <div class="note-card" data-note-id="${note.id}" data-note-name="${escapeHtml(note.name)}">
          <div class="note-card-icon">📄</div>
          <div class="note-card-name" title="${escapeHtml(note.name)}">${escapeHtml(note.name)}</div>
          <div class="note-card-meta">${escapeHtml(note.file_type || "Document")} · ${escapeHtml(note.file_size || "")} · ${date}</div>
          <div class="note-card-actions">
            <button class="note-action-btn view-btn"   data-id="${note.id}">View</button>
            <button class="note-action-btn delete-btn danger" data-id="${note.id}" data-name="${escapeHtml(note.name)}">Delete</button>
          </div>
        </div>`;
    })
    .join("");

  container.querySelectorAll(".view-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      onView(btn.dataset.id);
    });
  });

  container.querySelectorAll(".delete-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      handleNoteDelete(btn.dataset.id, btn.dataset.name, btn.closest(".note-card"), container);
    });
  });

  container.querySelectorAll(".note-card").forEach((card) => {
    card.addEventListener("click", () => onView(card.dataset.noteId));
  });
}

// ─── Report Page ──────────────────────────────────────────────────────────────

async function initReportPage() {
  requireAuth();
  try {
    const data = await safeFetchJson(
      "http://localhost:8000/SmartStudyCompanion/backend/quiz/get_results.php",
      { headers: { Authorization: `Bearer ${getToken()}` } }
    );
    if (data.success) {
      renderReportStats(data.stats);
      renderQuizHistory(data.results);
    } else {
      console.error("Failed to load report:", data.message);
    }
  } catch (err) {
    console.error("Report fetch error:", err);
  }
}

function renderReportStats(stats) {
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set("stat-notes",   stats.notes_count   ?? 0);
  set("stat-quizzes", stats.quizzes_taken  ?? 0);
  set("stat-avg",     `${stats.avg_score   ?? 0}%`);
}

function renderQuizHistory(results) {
  const container = document.getElementById("quiz-history-list");
  if (!container) return;

  if (!results || results.length === 0) {
    container.innerHTML = `<div class="empty-state"><p>No quizzes taken yet. <a href="dashboard.html">Go upload some notes →</a></p></div>`;
    return;
  }

  container.innerHTML = results
    .slice().reverse()
    .map((r) => {
      const pct   = r.percent ?? Math.round((r.score / r.total) * 100);
      const badge = pct >= 80 ? "badge-good" : pct >= 50 ? "badge-ok" : "badge-poor";
      const label = pct >= 80 ? "Excellent"  : pct >= 50 ? "Good"     : "Needs Work";
      return `
        <div class="history-item">
          <div class="history-score">${pct}%</div>
          <div class="history-info">
            <div class="history-note">${escapeHtml(r.note_name)}</div>
            <div class="history-date">${r.date} · ${r.score}/${r.total} correct</div>
          </div>
          <span class="history-badge ${badge}">${label}</span>
        </div>`;
    }).join("");
}

// ─── View Note Page ───────────────────────────────────────────────────────────

// async function initViewNotePage() {
//   requireAuth();
//   const params = new URLSearchParams(window.location.search);
//   const noteId = params.get("id");

//   if (!noteId) {
//     window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
//     return;
//   }

//   const note = await fetchNoteById(noteId);
//   if (!note) {
//     alert("Note not found.");
//     window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
//     return;
//   }

//   setTextContent("file-info-name", note.name);
//   setTextContent("file-info-size", note.file_size);
//   setTextContent("file-info-type", note.file_type);
//   setTextContent("file-info-date", note.upload_date);

//   const previewEl = document.getElementById("note-preview-text");
//   if (previewEl) {
//     const raw = note.content.slice(0, 6000);
//     const decoded = raw
//       .replace(/&lt;/g, "<").replace(/&gt;/g, ">")
//       .replace(/&amp;/g, "&").replace(/&quot;/g, '"').replace(/&#39;/g, "'");
//     previewEl.innerHTML = decoded + (note.content.length > 6000
//       ? '<p class="preview-truncated">[… truncated — download to see full content]</p>' : "");
//   }

//   wireLinkButton("btn-generate-summary", `summary.html?id=${noteId}`);
//   wireLinkButton("btn-generate-quiz",    `quiz.html?id=${noteId}`);
//   wireLinkButton("btn-ask-ai",           `chat.html?id=${noteId}`);

//   const summarySection = document.getElementById("summary-section");
//   if (summarySection) await initSummarySection(noteId, note.name);
// }


// ─── View Note Page ───────────────────────────────────────────────────────────
// FIXED VERSION — replace the initViewNotePage function in main.js with this.
//
// KEY FIXES:
//   1. Removed .slice(0, 6000) — this was cutting base64 image strings in half,
//      producing broken <img> tags and corrupt data URIs.
//   2. Removed the manual entity replacement (replace /&lt;/g etc.) — this was
//      double-decoding HTML entities, corrupting valid HTML tags produced by the
//      Python extractor (pdf2image base64 output). The content from the DB is
//      already valid HTML — just set it directly via innerHTML.
//   3. Added isHtml detection: if content starts with '<' it is HTML (PDF pages
//      as images); otherwise treat as plain text and use textContent.
// ─────────────────────────────────────────────────────────────────────────────

async function initViewNotePage() {
  requireAuth();
  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
    return;
  }

  const note = await fetchNoteById(noteId);
  if (!note) {
    alert("Note not found.");
    window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
    return;
  }

  setTextContent("file-info-name", note.name);
  setTextContent("file-info-size", note.file_size);
  setTextContent("file-info-type", note.file_type);
  setTextContent("file-info-date", note.upload_date);

  const previewEl = document.getElementById("note-preview-text");
  if (previewEl && note.content) {
    const content = note.content;

    // Detect whether the stored content is HTML (PDF image pages) or plain text.
    // pdf2image output always starts with a <div class="pdf-page"> tag.
    // Plain text / pasted content never starts with '<'.
    const isHtml = content.trim().startsWith('<');

    if (isHtml) {
      // ── PDF rendered as images ──────────────────────────────────────────
      // Set the full content via innerHTML — do NOT slice or replace entities.
      // The content contains data:image/png;base64,... URIs that must be intact.
      previewEl.innerHTML = content;
    } else {
      // ── Plain text or pasted notes ──────────────────────────────────────
      // Use textContent so nothing is interpreted as HTML (safe from XSS too).
      previewEl.style.whiteSpace = 'pre-wrap';
      previewEl.style.padding = '1.25rem';
      previewEl.textContent = content;
    }
  }

  wireLinkButton("btn-generate-summary", `summary.html?id=${noteId}`);
  wireLinkButton("btn-generate-quiz",    `quiz.html?id=${noteId}`);
  wireLinkButton("btn-ask-ai",           `chat.html?id=${noteId}`);

  const summarySection = document.getElementById("summary-section");
  if (summarySection) await initSummarySection(noteId, note.name);
}

function setTextContent(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value ?? "—";
}

function wireLinkButton(btnId, href) {
  const btn = document.getElementById(btnId);
  if (btn) btn.addEventListener("click", () => { window.location.href = href; });
}

// ─── Dashboard Page ───────────────────────────────────────────────────────────

async function initDashboardPage() {
  requireAuth();
  renderUserInfo();

  const notesGrid = document.getElementById("notes-grid") || document.getElementById("recent-notes");
  if (!notesGrid) return;

  const notes = await fetchNotes();

  if (!notes || notes.length === 0) {
    renderEmptyNotesState(notesGrid);
    return;
  }

  renderNotesGrid(notes, notesGrid, (id) => {
    window.location.href = `view-note.html?id=${id}`;
  });
}

// ─── Global Helpers ───────────────────────────────────────────────────────────

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;")
    .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// ─── Router ───────────────────────────────────────────────────────────────────

async function main() {
  const page = getCurrentPage();
  if (page !== "login.html") await loadNavbar();

  switch (page) {
    case "login.html":
      bindLoginForm(); bindSignupForm(); setupLoginTabs(); break;
    case "index.html":
    case "dashboard.html":
      await initDashboardPage(); break;
    case "upload.html":
      initUploadPage(); break;
    case "view-note.html":
      await initViewNotePage(); break;
    case "summary.html":
      await initSummaryPage(); break;
    case "quiz.html":
      await initQuizPage(); break;
    case "chat.html":
      await initChatPage(); break;
    case "report.html":
      await initReportPage(); break;
    default:
      console.info(`main.js: no init defined for page "${page}"`);
  }
}

// ─── Login Page Tabs ──────────────────────────────────────────────────────────

function setupLoginTabs() {
  const loginTab   = document.getElementById("tab-login");
  const signupTab  = document.getElementById("tab-signup");
  const loginForm  = document.getElementById("login-form");
  const signupForm = document.getElementById("signup-form");
  if (!loginTab || !signupTab) return;

  loginTab.addEventListener("click", () => {
    loginTab.classList.add("active"); signupTab.classList.remove("active");
    if (loginForm)  loginForm.style.display  = "block";
    if (signupForm) signupForm.style.display = "none";
  });
  signupTab.addEventListener("click", () => {
    signupTab.classList.add("active"); loginTab.classList.remove("active");
    if (signupForm) signupForm.style.display = "block";
    if (loginForm)  loginForm.style.display  = "none";
  });
}

document.addEventListener("DOMContentLoaded", main);