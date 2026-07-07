/**
 * chat.js
 * Powers the AI Chat Assistant page (chat.html).
 * Sends user messages to backend/ai/chat_assistant.php which calls
 * the Gemini API with the note content as system context.
 * Only answers questions about the uploaded note content.
 */

// const CHAT_AI_API = "../backend/ai/chat_assistant.php";
const CHAT_AI_API = "http://localhost:8000/SmartStudyCompanion/backend/ai/chat_assistant.php";

// const CHAT_AI_API = "http://localhost:8000/backend/ai/chat_assistant.php";

// ─── State ────────────────────────────────────────────────────────────────────

const chatState = {
  noteId:   null,
  noteName: "",
  history:  [],  // [{role: "user"|"model", content: string, time: Date}]
  loading:  false,
};

// ─── Time Formatting ──────────────────────────────────────────────────────────

function formatTime(date) {
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

// ─── Message Rendering ────────────────────────────────────────────────────────

/**
 * Creates and appends a single message bubble to #chat-messages.
 * @param {"user"|"model"} role
 * @param {string} text
 * @param {Date} time
 * @returns {HTMLElement} the created message element
 */
function appendMessage(role, text, time = new Date()) {
  const container = document.getElementById("chat-messages");
  if (!container) return null;

  const wrapper = document.createElement("div");
  wrapper.className = `chat-msg-wrapper ${role}`;

  // Convert newlines to <br> and escape HTML to prevent XSS
  // const safeText = escapeHtml(text).replace(/\n/g, "<br>");

  // wrapper.innerHTML = `
  //   <div class="chat-msg ${role}">
  //     <div class="chat-msg-text">${safeText}</div>
  //     <div class="chat-msg-time">${formatTime(time)}</div>
  //   </div>`;

  // Render markdown for model responses, escape HTML for user messages
// let renderedText;
// if (role === "model") {
//   renderedText = marked.parse(text);
// } else {
//   renderedText = escapeHtml(text).replace(/\n/g, "<br>");
// }

// wrapper.innerHTML = `
//   <div class="chat-msg ${role}">
//     <div class="chat-msg-text markdown-body">${renderedText}</div>
//     <div class="chat-msg-time">${formatTime(time)}</div>
//   </div>`;

//   container.appendChild(wrapper);
//   scrollToBottom();
//   return wrapper;


  let renderedText;
if (role === "model") {
  // Step 1: Extract LaTeX blocks before marked processes them
  // (marked would corrupt \[ \] and \( \) syntax)
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

  // Step 3: Restore LaTeX blocks (now inside rendered HTML)
  html = html.replace(/%%LATEX_BLOCK_(\d+)%%/g, (_, i) => latexBlocks[parseInt(i)]);

  renderedText = html;
} else {
  renderedText = escapeHtml(text).replace(/\n/g, "<br>");
}

wrapper.innerHTML = `
  <div class="chat-msg ${role}">
    <div class="chat-msg-text markdown-body">${renderedText}</div>
    <div class="chat-msg-time">${formatTime(time)}</div>
  </div>`;

container.appendChild(wrapper);
scrollToBottom();

// Typeset MathJax after inserting into DOM
// if (role === "model" && window.MathJax) {
//   MathJax.typesetPromise([wrapper]).then(() => scrollToBottom());
// }


if (role === "model" && window.MathJax) {
  MathJax.startup.promise.then(() => {
    MathJax.typesetPromise([wrapper]).then(() => scrollToBottom());
  });
}

return wrapper;
}

/**
 * Shows or removes the "AI is typing…" indicator.
 * @param {boolean} show
 */
function showTypingIndicator(show) {
  const existing = document.getElementById("typing-indicator");

  if (show && !existing) {
    const container = document.getElementById("chat-messages");
    if (!container) return;

    const el = document.createElement("div");
    el.id        = "typing-indicator";
    el.className = "chat-msg-wrapper model";
    el.innerHTML = `
      <div class="chat-msg model typing">
        <span class="dot"></span>
        <span class="dot"></span>
        <span class="dot"></span>
      </div>`;
    container.appendChild(el);
    scrollToBottom();
  } else if (!show && existing) {
    existing.remove();
  }
}

/**
 * Scrolls the chat messages container to the bottom.
 */
function scrollToBottom() {
  const container = document.getElementById("chat-messages");
  if (container) container.scrollTop = container.scrollHeight;
}

// ─── Send Message ─────────────────────────────────────────────────────────────

/**
 * Reads the input field, sends the message, and renders the response.
 * Guards against double-sends while loading.
 */
async function sendMessage() {
  if (chatState.loading) return;

  const inputEl = document.getElementById("chat-input");
  if (!inputEl) return;

  const text = inputEl.value.trim();
  if (!text) return;

  // Clear input immediately for a snappy feel
  inputEl.value = "";
  inputEl.focus();

  // Render user bubble
  const now = new Date();
  appendMessage("user", text, now);

  // Track in history
  chatState.history.push({ role: "user", content: text, time: now });

  // Send to backend
  chatState.loading = true;
  setSendButtonState(true);
  showTypingIndicator(true);

  const response = await callChatAPI(text);

  showTypingIndicator(false);
  chatState.loading = false;
  setSendButtonState(false);

  // Render AI response
  const aiTime = new Date();
  appendMessage("model", response, aiTime);
  chatState.history.push({ role: "model", content: response, time: aiTime });
}

/**
 * Calls the backend chat endpoint.
 * The PHP script injects the note content as system context so the AI
 * stays scoped to the uploaded material.
 *
 * @param {string} userMessage - the latest user message
 * @returns {Promise<string>} AI response text
 */
async function callChatAPI(userMessage) {
  try {
    // Build conversation history for the API (exclude time field)
    const apiHistory = chatState.history.map(({ role, content }) => ({ role, content }));

    const res = await fetch(CHAT_AI_API, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({
        note_id: chatState.noteId,
        message: userMessage,
        history: apiHistory.slice(0, -1), // exclude the message we just pushed
      }),
    });

    // const data = await res.json();
    const raw = await res.text();
    // console.log(raw);

    const data = JSON.parse(raw);

    if (data.success) {
      return data.reply;
    } else {
      return data.message || "I'm sorry, I couldn't process that request. Please try again.";
    }
  } catch (err) {
    console.error("Chat API error:", err);
    return "Sorry, I encountered a network error. Please check your connection and try again.";
  }
}

// ─── UI Helpers ───────────────────────────────────────────────────────────────

/**
 * Toggles the send button between enabled and loading states.
 * @param {boolean} loading
 */
function setSendButtonState(loading) {
  const btn = document.getElementById("chat-send-btn");
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading
    ? `<span class="spinner-sm"></span>`
    : `<span>➤</span>`;
}

/**
 * Renders a welcome message from the AI in the chat window.
 */
function renderWelcomeMessage() {
  const noteName = chatState.noteName || "your uploaded notes";
  appendMessage(
    "model",
    `Hi! I'm your AI study assistant. I've read "${noteName}" and I'm ready to help.\n\nYou can ask me to:\n• Explain any concept from the notes\n• Clarify difficult sections\n• Give examples for topics covered\n• Quiz you with quick verbal questions\n\nNote: I can only answer questions about the uploaded content.`,
    new Date()
  );
}

/**
 * Clears the entire conversation history and re-shows the welcome message.
 */
function clearConversation() {
  chatState.history = [];
  const container = document.getElementById("chat-messages");
  if (container) container.innerHTML = "";
  renderWelcomeMessage();
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

// ─── Suggested Questions ──────────────────────────────────────────────────────

const SUGGESTED_PROMPTS = [
  "Summarize the main topics covered",
  "What are the key definitions I should know?",
  "Give me a quick quiz question",
  "Explain the most complex concept simply",
];

/**
 * Renders clickable suggested prompt chips above the input.
 */
function renderSuggestedPrompts() {
  const container = document.getElementById("suggested-prompts");
  if (!container) return;

  container.innerHTML = SUGGESTED_PROMPTS.map(
    (p) => `<button class="prompt-chip" onclick="usePrompt('${escapeHtml(p)}')">${escapeHtml(p)}</button>`
  ).join("");
}

/**
 * Fills the chat input with a suggested prompt.
 * @param {string} text
 */
function usePrompt(text) {
  const inputEl = document.getElementById("chat-input");
  if (inputEl) {
    inputEl.value = text;
    inputEl.focus();
  }
}

// ─── Page Init ────────────────────────────────────────────────────────────────

/**
 * Initialises the chat page (chat.html).
 * Reads note_id from the URL, loads note metadata, and wires up the UI.
 * Call from main.js on DOMContentLoaded.
 */
async function initChatPage() {
  requireAuth();

  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    appendMessage("model", "No note selected. Please go back and choose a note to chat about.");
    return;
  }

  chatState.noteId = noteId;

  // Load note name
  const note = await fetchNoteById(noteId);
  if (note) {
    chatState.noteName = note.name;
    const titleEl = document.getElementById("chat-note-title");
    if (titleEl) titleEl.textContent = `Ask questions about: ${note.name}`;
  }

  // Wire up send button
  const sendBtn = document.getElementById("chat-send-btn");
  if (sendBtn) sendBtn.addEventListener("click", sendMessage);

  // Wire up Enter key on input (Shift+Enter = newline)
  const inputEl = document.getElementById("chat-input");
  if (inputEl) {
    inputEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    // Auto-resize textarea as user types
    inputEl.addEventListener("input", () => {
      inputEl.style.height = "auto";
      inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + "px";
    });
  }

  // Wire up clear button
  const clearBtn = document.getElementById("clear-chat-btn");
  if (clearBtn) clearBtn.addEventListener("click", clearConversation);

  // Render suggested prompts and welcome message
  renderSuggestedPrompts();
  renderWelcomeMessage();
}

// ─── Export ───────────────────────────────────────────────────────────────────
// export { initChatPage, sendMessage, clearConversation };