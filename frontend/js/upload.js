const NOTES_API = "https://smartstudy-backend-oekm.onrender.com/backend/notes";
let selectedFiles = []; 

function formatFileSize(bytes) {
  if (bytes < 1024)        return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function getFileType(file) {
  if (file.type === "application/pdf")  return "PDF Document";
  if (file.type === "text/plain")       return "Text File";
  if (file.name.endsWith(".md"))        return "Markdown File";
  return "Document";
}

function isAllowedType(file) {
  const allowed = ["application/pdf", "text/plain"];
  return allowed.includes(file.type) || file.name.endsWith(".md");
}

function showError(message) {
  const el = document.getElementById("upload-error");
  if (el) { el.textContent = message; el.style.display = "block"; }
}

function showSuccess(message) {
  const el = document.getElementById("upload-success");
  if (el) { el.textContent = message; el.style.display = "block"; }
}

function clearMessages() {
  ["upload-error", "upload-success"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) { el.textContent = ""; el.style.display = "none"; }
  });
}

function renderFileList() {
  const container = document.getElementById("file-list");
  if (!container) return;

  if (selectedFiles.length === 0) {
    container.innerHTML = "";
    return;
  }

  container.innerHTML = selectedFiles
    .map(
      (file, index) => `
      <div class="file-item" id="file-item-${index}">
        <span class="file-icon">📄</span>
        <div class="file-details">
          <span class="file-name">${file.name}</span>
          <span class="file-meta">${getFileType(file)} · ${formatFileSize(file.size)}</span>
        </div>
        <button class="file-remove-btn" onclick="removeFile(${index})" title="Remove file">✕</button>
      </div>`
    )
    .join("");
}

function removeFile(index) {
  selectedFiles.splice(index, 1);
  renderFileList();
}

function addFiles(files) {
  const arr = Array.from(files);
  let rejected = 0;

  arr.forEach((file) => {
    if (!isAllowedType(file)) {
      rejected++;
      return;
    }
    const exists = selectedFiles.some((f) => f.name === file.name);
    if (!exists) selectedFiles.push(file);
  });

  if (rejected > 0) {
    showError(`${rejected} file(s) skipped — only PDF and TXT files are allowed.`);
  }

  renderFileList();
}

function initDragDrop(zone) {
  if (!zone) return;

  zone.addEventListener("dragover", (e) => {
    e.preventDefault();
    zone.classList.add("drag-over");
  });

  zone.addEventListener("dragleave", () => {
    zone.classList.remove("drag-over");
  });

  zone.addEventListener("drop", (e) => {
    e.preventDefault();
    zone.classList.remove("drag-over");
    clearMessages();
    addFiles(e.dataTransfer.files);
  });

  zone.addEventListener("click", () => {
    const input = document.getElementById("file-input");
    if (input) input.click();
  });
}

function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload  = () => resolve(reader.result);
    reader.onerror = () => reject(new Error("Failed to read file."));
    reader.readAsText(file);
  });
}

async function uploadNote() {
  const noteName   = document.getElementById("note-name")?.value.trim();
  const pastedText = document.getElementById("paste-text")?.value.trim();
  const token      = getToken();

  if (!noteName) {
    showError("Please enter a note name.");
    return { success: false, message: "Note name is required." };
  }

  if (selectedFiles.length === 0 && !pastedText) {
    showError("Please upload a file or paste your notes.");
    return { success: false, message: "No content provided." };
  }

  const formData = new FormData();
  formData.append("note_name", noteName);
  if (pastedText) formData.append("pasted_text", pastedText);

  selectedFiles.forEach((file) => {
    formData.append("files[]", file, file.name);
  });

  setUploadButtonState(true);
  clearMessages();

  try {
    const res = await fetch(`${NOTES_API}/upload_note.php`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}` },
      body: formData,
    });

    const data = await res.json();

    if (data.success) {
      showSuccess(`"${noteName}" uploaded successfully!`);
      resetUploadForm();
      return { success: true, note_id: data.note_id, message: data.message };
    } else {
      showError(data.message || "Upload failed. Please try again.");
      return { success: false, message: data.message };
    }
  } catch (err) {
    console.error("Upload error:", err);
    showError("Network error. Please check your connection.");
    return { success: false, message: "Network error." };
  } finally {
    setUploadButtonState(false);
  }
}

function setUploadButtonState(loading) {
  const btn = document.getElementById("upload-btn");
  if (!btn) return;
  btn.disabled    = loading;
  btn.textContent = loading ? "Uploading…" : "📤 Upload Note";
}

function resetUploadForm() {
  selectedFiles = [];
  renderFileList();
  const nameEl = document.getElementById("note-name");
  const textEl = document.getElementById("paste-text");
  if (nameEl) nameEl.value = "";
  if (textEl) textEl.value = "";
}

async function fetchNotes() {
  try {
    const res = await fetch(`${NOTES_API}/get_notes.php`, {
      headers: { Authorization: `Bearer ${getToken()}` },
    });
    const data = await res.json();
    return data.success ? data.notes : [];
  } catch {
    return [];
  }
}

async function fetchNoteById(noteId) {
  try {
    const res = await fetch(`${NOTES_API}/get_note_by_id.php?id=${noteId}`, {
      headers: { Authorization: `Bearer ${getToken()}` },
    });
    const data = await res.json();
    return data.success ? data.note : null;
  } catch {
    return null;
  }
}

async function deleteNote(noteId) {
  try {
    const res = await fetch(`${NOTES_API}/delete_note.php`, {
      method: "DELETE",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({ note_id: noteId }),
    });
    const data = await res.json();
    return data.success;
  } catch {
    return false;
  }
}

async function renameNote(noteId, newName) {
  try {
    const res = await fetch(`${NOTES_API}/rename_note.php`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({ note_id: noteId, new_name: newName }),
    });
    const data = await res.json();
    return data.success;
  } catch {
    return false;
  }
}

function renderNotesGrid(notes, container, onView) {
  if (!container) return;

  if (notes.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">📭</div>
        <p>No notes yet. <a href="upload.html">Upload your first note →</a></p>
      </div>`;
    return;
  }

  container.innerHTML = notes
    .map(
      (note) => `
      <div class="note-card" data-note-id="${note.id}">
        <div class="note-card-icon">📄</div>
        <div class="note-card-name">${escapeHtml(note.name)}</div>
        <div class="note-card-meta">
        <span>${escapeHtml(note.file_type)} · ${escapeHtml(note.file_size)}</span>
        <span>${escapeHtml(note.upload_date)}</span></div>
        <div class="note-card-actions">
          <button class="note-action-btn" onclick="handleViewNote('${note.id}')">View</button>
          <button class="note-action-btn danger" onclick="handleDeleteNote('${note.id}', '${escapeHtml(note.name)}')">Delete</button>
        </div>
      </div>`
    )
    .join("");
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

async function handleViewNote(noteId) {
  window.location.href = `view-note.html?id=${noteId}`;
}

async function handleDeleteNote(noteId, noteName) {
  if (!confirm(`Delete "${noteName}"? This cannot be undone.`)) return;
  const ok = await deleteNote(noteId);
  if (ok) {
    const card = document.querySelector(`[data-note-id="${noteId}"]`);
    if (card) card.remove();
  } else {
    alert("Failed to delete note. Please try again.");
  }
}

function initUploadPage() {
  requireAuth();

  const zone      = document.getElementById("upload-zone");
  const fileInput = document.getElementById("file-input");
  const uploadBtn = document.getElementById("upload-btn");

  initDragDrop(zone);

  if (fileInput) {
    fileInput.addEventListener("change", () => {
      clearMessages();
      addFiles(fileInput.files);
      fileInput.value = ""; 
    });
  }

  if (uploadBtn) {
    uploadBtn.addEventListener("click", uploadNote);
  }
}

async function initNotesPage() {
  requireAuth();
  const container = document.getElementById("notes-grid");
  const notes     = await fetchNotes();
  renderNotesGrid(notes, container, handleViewNote);
}
