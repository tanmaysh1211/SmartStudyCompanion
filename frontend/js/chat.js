const CHAT_AI_API = "https://smartstudy-backend-oekm.onrender.com/backend/ai/chat_assistant.php";
const chatState = {
  noteId:   null,
  noteName: "",
  history:  [],  // [{role: "user"|"model", content: string, time: Date}]
  loading:  false,
};

function formatTime(date) {
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function appendMessage(role, text, time = new Date()) {
  const container = document.getElementById("chat-messages");
  if (!container) return null;

  const wrapper = document.createElement("div");
  wrapper.className = `chat-msg-wrapper ${role}`;

  let renderedText;
if (role === "model") {
  const latexBlocks = [];
  let protected_text = text

    .replace(/\\\[([\s\S]*?)\\\]/g, (match) => {
      latexBlocks.push(match);
      return `%%LATEX_BLOCK_${latexBlocks.length - 1}%%`;
    })
    .replace(/\\\(([\s\S]*?)\\\)/g, (match) => {
      latexBlocks.push(match);
      return `%%LATEX_BLOCK_${latexBlocks.length - 1}%%`;
    });

  let html = marked.parse(protected_text);

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

if (role === "model" && window.MathJax) {
  MathJax.startup.promise.then(() => {
    MathJax.typesetPromise([wrapper]).then(() => scrollToBottom());
  });
}

return wrapper;
}

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

function scrollToBottom() {
  const container = document.getElementById("chat-messages");
  if (container) container.scrollTop = container.scrollHeight;
}

async function sendMessage() {
  if (chatState.loading) return;

  const inputEl = document.getElementById("chat-input");
  if (!inputEl) return;

  const text = inputEl.value.trim();
  if (!text) return;

  inputEl.value = "";
  inputEl.focus();

  const now = new Date();
  appendMessage("user", text, now);

  chatState.history.push({ role: "user", content: text, time: now });

  chatState.loading = true;
  setSendButtonState(true);
  showTypingIndicator(true);

  const response = await callChatAPI(text);

  showTypingIndicator(false);
  chatState.loading = false;
  setSendButtonState(false);

  const aiTime = new Date();
  appendMessage("model", response, aiTime);
  chatState.history.push({ role: "model", content: response, time: aiTime });
}

async function callChatAPI(userMessage) {
  try {
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
        history: apiHistory.slice(0, -1), 
      }),
    });

    const raw = await res.text();
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

function setSendButtonState(loading) {
  const btn = document.getElementById("chat-send-btn");
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading
    ? `<span class="spinner-sm"></span>`
    : `<span>➤</span>`;
}

function renderWelcomeMessage() {
  const noteName = chatState.noteName || "your uploaded notes";
  appendMessage(
    "model",
    `Hi! I'm your AI study assistant. I've read "${noteName}" and I'm ready to help.\n\nYou can ask me to:\n• Explain any concept from the notes\n• Clarify difficult sections\n• Give examples for topics covered\n• Quiz you with quick verbal questions\n\nNote: I can only answer questions about the uploaded content.`,
    new Date()
  );
}

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

const SUGGESTED_PROMPTS = [
  "Summarize the main topics covered",
  "What are the key definitions I should know?",
  "Give me a quick quiz question",
  "Explain the most complex concept simply",
];

function renderSuggestedPrompts() {
  const container = document.getElementById("suggested-prompts");
  if (!container) return;

  container.innerHTML = SUGGESTED_PROMPTS.map(
    (p) => `<button class="prompt-chip" onclick="usePrompt('${escapeHtml(p)}')">${escapeHtml(p)}</button>`
  ).join("");
}

function usePrompt(text) {
  const inputEl = document.getElementById("chat-input");
  if (inputEl) {
    inputEl.value = text;
    inputEl.focus();
  }
}

async function initChatPage() {
  requireAuth();

  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    appendMessage("model", "No note selected. Please go back and choose a note to chat about.");
    return;
  }

  chatState.noteId = noteId;

  const note = await fetchNoteById(noteId);
  if (note) {
    chatState.noteName = note.name;
    const titleEl = document.getElementById("chat-note-title");
    if (titleEl) titleEl.textContent = `Ask questions about: ${note.name}`;
  }

  const sendBtn = document.getElementById("chat-send-btn");
  if (sendBtn) sendBtn.addEventListener("click", sendMessage);

  const inputEl = document.getElementById("chat-input");
  if (inputEl) {
    inputEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    inputEl.addEventListener("input", () => {
      inputEl.style.height = "auto";
      inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + "px";
    });
  }

  const clearBtn = document.getElementById("clear-chat-btn");
  if (clearBtn) clearBtn.addEventListener("click", clearConversation);

  renderSuggestedPrompts();
  renderWelcomeMessage();
}
