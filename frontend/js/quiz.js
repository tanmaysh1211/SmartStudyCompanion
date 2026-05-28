/**
 * quiz.js
 * Handles AI quiz generation (via backend/ai/generate_quiz.py),
 * quiz UI (questions, timer, scoring), and saving results to the DB.
 */

// const QUIZ_API = "../backend/quiz";
// const AI_API   = "../backend/ai";

const QUIZ_API = "http://localhost:8000/SmartStudyCompanion/backend/quiz";
const AI_API   = "http://localhost:8000/SmartStudyCompanion/backend/ai";


// const QUIZ_API = "http://localhost:8000/backend/quiz";
// const AI_API   = "http://localhost:8000/backend/ai";

// ─── State ────────────────────────────────────────────────────────────────────

const quizState = {
  questions:    [],   // Array of { question, options: [], answer: number }
  current:      0,
  score:        0,
  timeLimit:    60,   // seconds per question (user-configurable)
  timeLeft:     60,
  timerId:      null,
  answered:     false,
  noteId:       null,
  noteName:     "",
};

// ─── Quiz Generation ──────────────────────────────────────────────────────────

/**
 * Calls the backend AI endpoint to generate quiz questions for a note.
 * The PHP endpoint shells out to generate_quiz.py which calls the Gemini API.
 *
 * @param {string|number} noteId
 * @param {number} count - number of questions to generate (default 10)
 * @returns {Promise<Array|null>} array of question objects or null on error
 */
async function generateQuiz(noteId, count = 10) {
  showQuizLoading(true);
  clearQuizError();

  // try {
  //   const res = await fetch(`${AI_API}/generate_quiz.php`, {
  //     method: "POST",
  //     headers: {
  //       "Content-Type": "application/json",
  //       Authorization: `Bearer ${getToken()}`,
  //     },
  //     body: JSON.stringify({ note_id: noteId, count }),
  //   });

  //   const data = await res.json();

  //   if (data.success && Array.isArray(data.questions) && data.questions.length > 0) {
  //     return data.questions;
  //   } else {
  //     // showQuizError(data.message || "Failed to generate quiz. Please try again.");
  //     console.log(data);
  //     showQuizError(data.message || "Failed to generate quiz.");
  //     return null;
  //   }
  // } catch (err) {
  //   console.error("Quiz generation error:", err);
  //   showQuizError("Network error. Please check your connection.");
  //   return null;
  // } 
    try {
        const response = await fetch(
            "http://localhost:8000/SmartStudyCompanion/backend/ai/generate_quiz.php",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    // "Authorization": "Bearer " + localStorage.getItem("token")
                    "Authorization": "Bearer " + getToken()
                },
                body: JSON.stringify({
                    note_id: noteId,
                    count: parseInt(count)
                })
            }
        );

        const rawText = await response.text();

        console.log("RAW RESPONSE =", rawText);

        let data;

        try {
            data = JSON.parse(rawText);
        } catch (e) {
            throw new Error("Backend did not return valid JSON");
        }

        console.log("PARSED DATA =", data);

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

// ─── Timer ────────────────────────────────────────────────────────────────────

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
        // Time ran out — auto-advance with no answer selected
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
  // Turn red when < 10 seconds left
  el.style.color = quizState.timeLeft <= 10 ? "var(--color-danger, #ef4444)" : "";
}

// ─── Quiz Flow ────────────────────────────────────────────────────────────────

/**
 * Starts the quiz from the beginning.
 */
function startQuiz() {
  quizState.current  = 0;
  quizState.score    = 0;
  quizState.answered = false;
  showScreen("quiz-question-screen");
  renderQuestion();
}

/**
 * Renders the current question to the DOM.
 */
function renderQuestion() {
  const q       = quizState.questions[quizState.current];
  const total   = quizState.questions.length;
  const current = quizState.current + 1;
  const letters = ["A", "B", "C", "D", "E"];

  // Progress bar
  const progressBar = document.getElementById("quiz-progress-bar");
  if (progressBar) {
    progressBar.style.width = `${((current - 1) / total) * 100}%`;
  }

  // Question counter
  const counterEl = document.getElementById("quiz-counter");
  if (counterEl) counterEl.textContent = `Question ${current} of ${total}`;

  // Question text
  const questionEl = document.getElementById("quiz-question-text");
  if (questionEl) questionEl.textContent = q.question;

  // Options
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

  // Reset state for new question
  quizState.answered = false;

  // Start the per-question timer
  startTimer();
}

/**
 * Handles the user selecting an answer (or null for timeout).
 * @param {number|null} selectedIndex - index into options array, or null
 */
function handleAnswer(selectedIndex) {
  if (quizState.answered) return;
  quizState.answered = true;
  stopTimer();

  const q           = quizState.questions[quizState.current];
  const correct     = q.answer; // 0-based index of correct option
  const isCorrect   = selectedIndex === correct;

  if (isCorrect) quizState.score++;

  // Visual feedback on option buttons
  const buttons = document.querySelectorAll(".option-btn");
  buttons.forEach((btn, i) => {
    btn.disabled = true;
    if (i === correct) {
      btn.classList.add("correct");
    } else if (i === selectedIndex && !isCorrect) {
      btn.classList.add("wrong");
    }
  });

  // Advance after a short delay so the user can see the result
  setTimeout(() => {
    quizState.current++;
    if (quizState.current >= quizState.questions.length) {
      finishQuiz();
    } else {
      renderQuestion();
    }
  }, 1300);
}

/**
 * Called when all questions have been answered.
 * Shows the result screen and saves the score to the DB.
 */
async function finishQuiz() {
  stopTimer();
  showScreen("quiz-result-screen");
  renderResult();
  await saveQuizResult();
}

/**
 * Renders the result screen with score and feedback.
 */
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

// ─── Save Results ─────────────────────────────────────────────────────────────

/**
 * Posts the quiz result to the backend (backend/quiz/save_result.php)
 * so it can be shown in the Report page.
 */
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
    // Non-fatal — the user can still see their score on screen
  }
}

// ─── Screen Management ────────────────────────────────────────────────────────

/**
 * Shows one screen div and hides all others.
 * Screens: "quiz-start-screen" | "quiz-question-screen" | "quiz-result-screen"
 * @param {string} screenId
 */
function showScreen(screenId) {
  ["quiz-start-screen", "quiz-question-screen", "quiz-result-screen"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === screenId ? "block" : "none";
  });
}

// ─── UI Helpers ───────────────────────────────────────────────────────────────

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

// ─── Page Init ────────────────────────────────────────────────────────────────

/**
 * Initialises the quiz page (quiz.html).
 * Reads note_id from the URL query string, loads note metadata,
 * and wires up all buttons.
 *
 * Call from main.js on DOMContentLoaded.
 */
async function initQuizPage() {
  requireAuth();

  const params = new URLSearchParams(window.location.search);
  const noteId = params.get("id");

  if (!noteId) {
    showQuizError("No note selected. Please go back and choose a note.");
    return;
  }

  quizState.noteId = noteId;

  // Load note name for display
  const note = await fetchNoteById(noteId);
  if (note) {
    quizState.noteName = note.name;
    const nameEl = document.getElementById("quiz-note-name");
    if (nameEl) nameEl.textContent = note.name;
  }

  // Time-per-question selector
  const timeSelect = document.getElementById("quiz-time-select");
  if (timeSelect) {
    timeSelect.addEventListener("change", () => {
      quizState.timeLimit = parseInt(timeSelect.value, 10);
    });
  }

  // "Generate Quiz" / "Start Quiz" button on start screen
  // const generateBtn = document.getElementById("generate-quiz-btn");
  // if (generateBtn) {
  //   generateBtn.addEventListener("click", async () => {
  //     const countEl = document.getElementById("quiz-count");
  //     const count   = countEl ? parseInt(countEl.value, 10) || 10 : 10;
  //     const questions = await generateQuiz(noteId, count);
  //     if (questions) {
  //       quizState.questions = questions;
  //       // Update the badge showing question count
  //       const countBadge = document.getElementById("quiz-question-count");
  //       // if (countBadge) countBadge.textContent = `Questions: ${questions.length}`;
  //       if (countBadge) countBadge.textContent = questions.length;
  //     }
  //   });
  // }


const generateBtn = document.getElementById("generate-quiz-btn");
if (generateBtn) {
    generateBtn.addEventListener("click", async () => {
        const countEl = document.getElementById("quiz-count");
        const count   = countEl ? parseInt(countEl.value, 10) || 10 : 10;
        const questions = await generateQuiz(noteId, count);
        if (questions) {
            quizState.questions = questions;
            // Fix 1: just show the number, no duplicate "Questions:" prefix
            const countBadge = document.getElementById("quiz-question-count");
            if (countBadge) countBadge.textContent = questions.length;
            // Fix 2: enable Start button
            const startButton = document.getElementById("start-quiz-btn");
            if (startButton) startButton.disabled = false;
        }
    });
}

  // "Start Quiz" button
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

  // "Regenerate Quiz" button (on start screen)
  const regenBtn = document.getElementById("regen-quiz-btn");
  if (regenBtn) {
    regenBtn.addEventListener("click", async () => {
      const countEl  = document.getElementById("quiz-count");
      const count    = countEl ? parseInt(countEl.value, 10) || 10 : 10;
      const questions = await generateQuiz(noteId, count);
      // if (questions) quizState.questions = questions;
      if (questions) {
            quizState.questions = questions;
            const countBadge = document.getElementById("quiz-question-count");
            if (countBadge) countBadge.textContent = questions.length;
            const startButton = document.getElementById("start-quiz-btn");
            if (startButton) startButton.disabled = false;
        }
    });
  }

  // "Try Again" button (on result screen)
  const tryAgainBtn = document.getElementById("try-again-btn");
  if (tryAgainBtn) {
    tryAgainBtn.addEventListener("click", () => {
      showScreen("quiz-start-screen");
    });
  }

  // "Back to Note" button (on result screen)
  const backBtn = document.getElementById("back-to-note-btn");
  if (backBtn) {
    backBtn.addEventListener("click", () => {
      window.location.href = `view-note.html?id=${noteId}`;
    });
  }

  showScreen("quiz-start-screen");
}

// ─── Export ───────────────────────────────────────────────────────────────────
// export { initQuizPage, generateQuiz, startQuiz, handleAnswer };