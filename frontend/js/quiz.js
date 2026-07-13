const QUIZ_API = "https://smartstudy-backend-oekm.onrender.com/backend/quiz";
const AI_API   = "https://smartstudy-backend-oekm.onrender.com/backend/ai";

const quizState = {
  questions:    [],  
  current:      0,
  score:        0,
  timeLimit:    60,   // seconds per question 
  timeLeft:     60,
  timerId:      null,
  answered:     false,
  noteId:       null,
  noteName:     "",
};

async function generateQuiz(noteId, count = 10) {
  showQuizLoading(true);
  clearQuizError();

    try {
        const response = await fetch(
            "https://smartstudy-backend-oekm.onrender.com/backend/ai/generate_quiz.php",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": "Bearer " + getToken()
                },
                body: JSON.stringify({
                    note_id: noteId,
                    count: parseInt(count)
                })
            }
        );

        const rawText = await response.text();

        let data;

        try {
            data = JSON.parse(rawText);
        } catch (e) {
            throw new Error("Backend did not return valid JSON");
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || "Quiz generation failed");
        }

        if (Array.isArray(data.questions) && data.questions.length > 0) {
            return data.questions;
        }

        throw new Error("No questions returned");

    } catch (error) {

        console.error("Quiz generation error:", error);

        showQuizError(error.message || "Network error");

        return null;
    }
    finally {
    showQuizLoading(false);
  }
}

function startTimer() {
  clearInterval(quizState.timerId);
  quizState.timeLeft = quizState.timeLimit;
  updateTimerDisplay();

  quizState.timerId = setInterval(() => {
    quizState.timeLeft--;
    updateTimerDisplay();

    if (quizState.timeLeft <= 0) {
      clearInterval(quizState.timerId);
      if (!quizState.answered) {
        handleAnswer(null);
      }
    }
  }, 1000);
}

function stopTimer() {
  clearInterval(quizState.timerId);
}

function updateTimerDisplay() {
  const el = document.getElementById("quiz-timer");
  if (!el) return;
  el.textContent = `⏱ ${quizState.timeLeft}s`;
  el.style.color = quizState.timeLeft <= 10 ? "var(--color-danger, #ef4444)" : "";
}

function startQuiz() {
  quizState.current  = 0;
  quizState.score    = 0;
  quizState.answered = false;
  showScreen("quiz-question-screen");
  renderQuestion();
}

function renderQuestion() {
  const q       = quizState.questions[quizState.current];
  const total   = quizState.questions.length;
  const current = quizState.current + 1;
  const letters = ["A", "B", "C", "D", "E"];

  const progressBar = document.getElementById("quiz-progress-bar");
  if (progressBar) {
    progressBar.style.width = `${((current - 1) / total) * 100}%`;
  }

  const counterEl = document.getElementById("quiz-counter");
  if (counterEl) counterEl.textContent = `Question ${current} of ${total}`;

  const questionEl = document.getElementById("quiz-question-text");
  if (questionEl) questionEl.textContent = q.question;

  const optionsEl = document.getElementById("quiz-options");
  if (optionsEl) {
    optionsEl.innerHTML = q.options
      .map(
        (opt, i) => `
        <button class="option-btn" id="option-${i}" onclick="handleAnswer(${i})">
          <span class="option-letter">${letters[i]}</span>
          <span class="option-text">${escapeHtml(opt.replace(/^[A-E]\.\s*/, ""))}</span>
        </button>`
      )
      .join("");
  }

  quizState.answered = false;
  startTimer();
}

function handleAnswer(selectedIndex) {
  if (quizState.answered) return;
  quizState.answered = true;
  stopTimer();

  const q           = quizState.questions[quizState.current];
  const correct     = q.answer; 
  const isCorrect   = selectedIndex === correct;

  if (isCorrect) quizState.score++;

  const buttons = document.querySelectorAll(".option-btn");
  buttons.forEach((btn, i) => {
    btn.disabled = true;
    if (i === correct) {
      btn.classList.add("correct");
    } else if (i === selectedIndex && !isCorrect) {
      btn.classList.add("wrong");
    }
  });

  setTimeout(() => {
    quizState.current++;
    if (quizState.current >= quizState.questions.length) {
      finishQuiz();
    } else {
      renderQuestion();
    }
  }, 1300);
}

async function finishQuiz() {
  stopTimer();
  showScreen("quiz-result-screen");
  renderResult();
  await saveQuizResult();
}

function renderResult() {
  const total   = quizState.questions.length;
  const score   = quizState.score;
  const percent = Math.round((score / total) * 100);

  const scoreEl   = document.getElementById("result-score");
  const percentEl = document.getElementById("result-percent");
  const msgEl     = document.getElementById("result-message");
  const emojiEl   = document.getElementById("result-emoji");

  if (scoreEl)   scoreEl.textContent   = `${score}/${total}`;
  if (percentEl) percentEl.textContent = `${percent}%`;

  let emoji = "💪";
  let msg   = "Keep practicing — you'll get there!";
  if (percent >= 90) { emoji = "🎉"; msg = "Outstanding! Perfect mastery!"; }
  else if (percent >= 75) { emoji = "🌟"; msg = "Great job! You know this well!"; }
  else if (percent >= 50) { emoji = "👍"; msg = "Good effort! Review the missed topics."; }

  if (emojiEl) emojiEl.textContent = emoji;
  if (msgEl)   msgEl.textContent   = msg;
}

async function saveQuizResult() {
  try {
    await fetch(`${QUIZ_API}/save_result.php`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken()}`,
      },
      body: JSON.stringify({
        note_id:  quizState.noteId,
        score:    quizState.score,
        total:    quizState.questions.length,
        percent:  Math.round((quizState.score / quizState.questions.length) * 100),
      }),
    });
  } catch (err) {
    console.error("Failed to save quiz result:", err);
  }
}

function showScreen(screenId) {
  ["quiz-start-screen", "quiz-question-screen", "quiz-result-screen"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === screenId ? "block" : "none";
  });
}

function showQuizLoading(show) {
  const el = document.getElementById("quiz-loading");
  if (el) el.style.display = show ? "flex" : "none";
}

function showQuizError(message) {
  const el = document.getElementById("quiz-error");
  if (el) { el.textContent = message; el.style.display = "block"; }
}

function clearQuizError() {
  const el = document.getElementById("quiz-error");
  if (el) { el.textContent = ""; el.style.display = "none"; }
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

async function initQuizPage() {
  requireAuth();

  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    showQuizError("No note selected. Please go back and choose a note.");
    return;
  }

  quizState.noteId = noteId;
  const note = await fetchNoteById(noteId);
  if (note) {
    quizState.noteName = note.name;
    const nameEl = document.getElementById("quiz-note-name");
    if (nameEl) nameEl.textContent = note.name;
  }
  const timeSelect = document.getElementById("quiz-time-select");
  if (timeSelect) {
    timeSelect.addEventListener("change", () => {
      quizState.timeLimit = parseInt(timeSelect.value, 10);
    });
  }

const generateBtn = document.getElementById("generate-quiz-btn");
if (generateBtn) {
    generateBtn.addEventListener("click", async () => {
        const countEl = document.getElementById("quiz-count");
        const count   = countEl ? parseInt(countEl.value, 10) || 10 : 10;
        const questions = await generateQuiz(noteId, count);
        if (questions) {
            quizState.questions = questions;
            const countBadge = document.getElementById("quiz-question-count");
            if (countBadge) countBadge.textContent = questions.length;
            const startButton = document.getElementById("start-quiz-btn");
            if (startButton) startButton.disabled = false;
        }
    });
}

  const startBtn = document.getElementById("start-quiz-btn");
  if (startBtn) {
    startBtn.addEventListener("click", () => {
      if (quizState.questions.length === 0) {
        showQuizError("Please generate a quiz first.");
        return;
      }
      clearQuizError();
      startQuiz();
    });
  }

  const regenBtn = document.getElementById("regen-quiz-btn");
  if (regenBtn) {
    regenBtn.addEventListener("click", async () => {
      const countEl  = document.getElementById("quiz-count");
      const count    = countEl ? parseInt(countEl.value, 10) || 10 : 10;
      const questions = await generateQuiz(noteId, count);
      if (questions) {
            quizState.questions = questions;
            const countBadge = document.getElementById("quiz-question-count");
            if (countBadge) countBadge.textContent = questions.length;
            const startButton = document.getElementById("start-quiz-btn");
            if (startButton) startButton.disabled = false;
        }
    });
  }

  const tryAgainBtn = document.getElementById("try-again-btn");
  if (tryAgainBtn) {
    tryAgainBtn.addEventListener("click", () => {
      showScreen("quiz-start-screen");
    });
  }

  const backBtn = document.getElementById("back-to-note-btn");
  if (backBtn) {
    backBtn.addEventListener("click", () => {
      window.location.href = `view-note.html?id=${noteId}`;
    });
  }

  showScreen("quiz-start-screen");
}
