#!/usr/bin/env python
"""
ai/generate_quiz.py
═══════════════════════════════════════════════════════════════
Generates multiple-choice quiz questions from study-note content
using the OpenAI API.

Called by backend/ai/generate_quiz.php via shell_exec():
    python3 ai/generate_quiz.py '<json_payload>'

Input  — single CLI argument: JSON string
    {
        "content"  : "<full note text>",         (required)
        "note_name": "<display name>",            (optional)
        "count"    : 10,                          (optional, default 10)
        "difficulty": "mixed"                     (optional: easy|medium|hard|mixed)
    }

Output (stdout) — always valid JSON:
    Success → {
        "success"   : true,
        "questions" : [
            {
                "question" : "What is ...?",
                "options"  : ["A. ...", "B. ...", "C. ...", "D. ..."],
                "answer"   : 0,          ← 0-based index of correct option
                "explanation": "..."     ← why the answer is correct
            },
            ...
        ],
        "count": int
    }
    Failure → { "success": false, "message": "..." }

Environment variables:
    OPENAI_API_KEY   Your OpenAI API key (required)
    OPENAI_MODEL     Model name (default: gpt-4o-mini)

Install dependencies:
    pip install openai
═══════════════════════════════════════════════════════════════
"""

from __future__ import annotations

import json
import os
import re
import sys
import textwrap
import time


# ════════════════════════════════════════════════════════════════
# 1.  Output helpers
# ════════════════════════════════════════════════════════════════

def output_success(questions: list[dict]) -> None:
    print(json.dumps(
        {"success": True, "questions": questions, "count": len(questions)},
        ensure_ascii=False,
    ))
    sys.exit(0)


def output_failure(message: str) -> None:
    print(json.dumps({"success": False, "message": message}, ensure_ascii=False))
    sys.exit(1)


# ════════════════════════════════════════════════════════════════
# 2.  Input parsing & validation
# ════════════════════════════════════════════════════════════════

MAX_INPUT_CHARS    = 80_000
MIN_CONTENT_CHARS  = 100
MAX_QUESTIONS      = 30
MIN_QUESTIONS      = 2
VALID_DIFFICULTIES = {"easy", "medium", "hard", "mixed"}


# def parse_args() -> dict:
#     """
#     Parses the single CLI JSON argument.
#     Returns validated dict: content, note_name, count, difficulty.
#     """
#     if len(sys.argv) < 2:
#         output_failure(
#             "Usage: python generate_quiz.py '<json_payload>'\n"
#             "Required key: content. Optional: note_name, count, difficulty"
#         )

#     try:
#         data = json.loads(sys.argv[1].strip())
#     except json.JSONDecodeError as exc:
#         output_failure(f"Invalid JSON argument: {exc}")

#     if not isinstance(data, dict):
#         output_failure("Argument must be a JSON object.")

#     # ── content ───────────────────────────────────────────────
#     content = str(data.get("content", "")).strip()
#     if len(content) < MIN_CONTENT_CHARS:
#         output_failure(
#             f"Note content is too short to generate questions "
#             f"(minimum {MIN_CONTENT_CHARS} characters)."
#         )
#     if len(content) > MAX_INPUT_CHARS:
#         content = content[:MAX_INPUT_CHARS]

#     # ── note_name ────────────────────────────────────────────
#     note_name = str(data.get("note_name", "the uploaded notes")).strip()

#     # ── count ─────────────────────────────────────────────────
#     try:
#         count = int(data.get("count", 10))
#         count = max(MIN_QUESTIONS, min(MAX_QUESTIONS, count))
#     except (TypeError, ValueError):
#         count = 10

#     # ── difficulty ────────────────────────────────────────────
#     difficulty = str(data.get("difficulty", "mixed")).strip().lower()
#     if difficulty not in VALID_DIFFICULTIES:
#         difficulty = "mixed"

#     return {
#         "content":    content,
#         "note_name":  note_name,
#         "count":      count,
#         "difficulty": difficulty,
#     }


def parse_args() -> dict:
    import argparse

    # Support --file (Windows temp file method) or direct JSON arg
    if '--file' in sys.argv:
        parser = argparse.ArgumentParser()
        parser.add_argument('--file', required=True)
        args = parser.parse_args()
        try:
            with open(args.file, 'r', encoding='utf-8') as f:
                raw = f.read().strip()
        except OSError as exc:
            output_failure(f"Could not read input file: {exc}")
    elif len(sys.argv) >= 2:
        raw = sys.argv[1].strip()
    else:
        output_failure(
            "Usage: python generate_quiz.py '<json_payload>'\n"
            "Required key: content. Optional: note_name, count, difficulty"
        )

    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        output_failure(f"Invalid JSON argument: {exc}")

    if not isinstance(data, dict):
        output_failure("Argument must be a JSON object.")

    # ── content ───────────────────────────────────────────────
    content = str(data.get("content", "")).strip()
    if len(content) < MIN_CONTENT_CHARS:
        output_failure(
            f"Note content is too short to generate questions "
            f"(minimum {MIN_CONTENT_CHARS} characters)."
        )
    if len(content) > MAX_INPUT_CHARS:
        content = content[:MAX_INPUT_CHARS]

    # ── note_name ─────────────────────────────────────────────
    note_name = str(data.get("note_name", "the uploaded notes")).strip()

    # ── count ─────────────────────────────────────────────────
    try:
        count = int(data.get("count", 10))
        count = max(MIN_QUESTIONS, min(MAX_QUESTIONS, count))
    except (TypeError, ValueError):
        count = 10

    # ── difficulty ────────────────────────────────────────────
    difficulty = str(data.get("difficulty", "mixed")).strip().lower()
    if difficulty not in VALID_DIFFICULTIES:
        difficulty = "mixed"

    return {
        "content":    content,
        "note_name":  note_name,
        "count":      count,
        "difficulty": difficulty,
    }

# ════════════════════════════════════════════════════════════════
# 3.  Prompt builder
# ════════════════════════════════════════════════════════════════

QUIZ_SYSTEM = textwrap.dedent("""\
    You are an expert educator who creates high-quality multiple-choice quiz
    questions to test students' understanding of their study material.

    Rules for every question you generate:
    1. Questions must be based ONLY on the provided study content — no outside facts.
    2. Each question must have exactly 4 answer options labelled A, B, C, D.
    3. Exactly one option must be correct; the other three must be plausible distractors.
    4. Distractors should test for common misunderstandings, NOT be obviously wrong.
    5. Avoid trivial true/false style questions — test comprehension and application.
    6. Do NOT repeat concepts across questions — cover different parts of the material.
    7. Include a brief explanation (1–2 sentences) for why the correct answer is right.
    8. Return ONLY a valid JSON array — no markdown fences, no preamble text, nothing else.
""")


_DIFFICULTY_INSTRUCTIONS = {
    "easy":   "Generate straightforward recall questions suitable for a first-time reader.",
    "medium": "Generate questions that require understanding, not just memorisation.",
    "hard":   "Generate analytical questions that require applying or evaluating concepts.",
    "mixed":  "Mix approximately 30% easy recall, 50% comprehension, and 20% analysis questions.",
}


def build_prompt(content: str, note_name: str, count: int, difficulty: str) -> str:
    diff_instruction = _DIFFICULTY_INSTRUCTIONS.get(difficulty, _DIFFICULTY_INSTRUCTIONS["mixed"])

    return textwrap.dedent(f"""\
        Generate exactly {count} multiple-choice quiz questions about "{note_name}".

        Difficulty: {difficulty.upper()} — {diff_instruction}

        ── Study Content ────────────────────────────────────────────
        {content}
        ────────────────────────────────────────────────────────────

        Return ONLY a valid JSON array with exactly {count} objects.
        Each object must follow this schema exactly:
        {{
            "question"   : "Full question text ending with a question mark?",
            "options"    : [
                "A. First option text",
                "B. Second option text",
                "C. Third option text",
                "D. Fourth option text"
            ],
            "answer"     : 0,
            "explanation": "Brief explanation of why the correct answer is right."
        }}

        The "answer" field is the 0-based index of the correct option
        (0 = A, 1 = B, 2 = C, 3 = D).

        IMPORTANT:
        - Output ONLY the JSON array — no markdown, no backticks, no extra text.
        - The array must start with [ and end with ].
        - Vary which index (0, 1, 2, or 3) is the correct answer across questions.
        - Do not make the last option always correct.
    """)


# ════════════════════════════════════════════════════════════════
# 4.  OpenAI API call
# ════════════════════════════════════════════════════════════════

DEFAULT_MODEL   = "gpt-4o-mini"
MAX_RETRIES     = 3
RETRY_DELAY_SEC = 2.0


def call_openai(prompt: str, system_prompt: str) -> str:
    """
    Calls the OpenAI Chat Completions API and returns the raw text response.
    Raises RuntimeError on unrecoverable failures.
    """
    try:
        from openai import OpenAI, AuthenticationError, RateLimitError, APIError  # type: ignore
    except ImportError:
        raise RuntimeError(
            "openai is not installed. "
            "Run: pip install openai"
        )

    api_key = os.getenv("OPENAI_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError(
            "OPENAI_API_KEY environment variable is not set. "
            "Get your key at https://platform.openai.com/api-keys"
        )

    client = OpenAI(api_key=api_key)
    model_name = os.getenv("OPENAI_MODEL", DEFAULT_MODEL).strip()

    last_error: Exception | None = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.chat.completions.create(
                model=model_name,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user",   "content": prompt},
                ],
                temperature=0.2,      # low = deterministic, structured JSON output
                top_p=0.95,
                max_tokens=4096,
            )

            text = response.choices[0].message.content
            if text:
                return text.strip()

            raise RuntimeError("OpenAI returned an empty response.")

        except AuthenticationError as exc:
            # Non-retryable — bad API key
            raise RuntimeError(f"Invalid OpenAI API key: {exc}") from exc

        except RateLimitError as exc:
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

        except APIError as exc:
            last_error = exc
            err_str = str(exc).lower()

            # Non-retryable content policy violation
            if "content_policy" in err_str or "content policy" in err_str:
                raise RuntimeError(
                    "OpenAI blocked the request due to content policy. "
                    "Check the note content for policy violations."
                ) from exc

            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

    raise RuntimeError(
        f"OpenAI API failed after {MAX_RETRIES} attempts. "
        f"Last error: {last_error}"
    )


# ════════════════════════════════════════════════════════════════
# 5.  JSON parsing & validation
# ════════════════════════════════════════════════════════════════

def extract_json_array(raw: str) -> str:
    """
    Extracts the first JSON array from a raw string.
    Handles cases where the model wraps the array in markdown fences
    or adds preamble text.
    """
    # Strip markdown code fences: ```json ... ``` or ``` ... ```
    raw = re.sub(r"```(?:json)?\s*", "", raw, flags=re.IGNORECASE)
    raw = raw.replace("```", "").strip()

    # Find the outermost [ ... ] array
    start = raw.find("[")
    if start == -1:
        return raw  # let the caller handle the parse error

    # Walk forward to find the matching closing bracket
    depth = 0
    for i, ch in enumerate(raw[start:], start=start):
        if ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                return raw[start: i + 1]

    return raw[start:]  # malformed — return what we have


def validate_question(q: dict, index: int) -> str | None:
    """
    Validates one question object.
    Returns an error string if invalid, None if OK.
    """
    if not isinstance(q, dict):
        return f"Question {index + 1}: must be an object, got {type(q).__name__}"

    question_text = q.get("question", "")
    if not isinstance(question_text, str) or len(question_text.strip()) < 5:
        return f"Question {index + 1}: 'question' field is missing or too short"

    options = q.get("options")
    if not isinstance(options, list) or len(options) != 4:
        return (
            f"Question {index + 1}: 'options' must be an array of exactly 4 strings, "
            f"got {len(options) if isinstance(options, list) else type(options).__name__}"
        )

    for opt_i, opt in enumerate(options):
        if not isinstance(opt, str) or not opt.strip():
            return f"Question {index + 1}: option {opt_i} is not a non-empty string"

    answer = q.get("answer")
    if not isinstance(answer, int) or answer not in (0, 1, 2, 3):
        return (
            f"Question {index + 1}: 'answer' must be an integer 0–3, "
            f"got {answer!r}"
        )

    return None  # valid


def parse_and_validate(raw_response: str, expected_count: int) -> list[dict]:
    """
    Parses the raw OpenAI response into a validated list of question dicts.
    Raises ValueError with a descriptive message on any problem.
    """
    json_str = extract_json_array(raw_response)

    try:
        questions = json.loads(json_str)
    except json.JSONDecodeError as exc:
        raise ValueError(
            f"OpenAI did not return valid JSON. "
            f"Parse error: {exc}. "
            f"Raw (first 300 chars): {raw_response[:300]!r}"
        ) from exc

    if not isinstance(questions, list):
        raise ValueError(
            f"Expected a JSON array, got {type(questions).__name__}."
        )

    if len(questions) == 0:
        raise ValueError("OpenAI returned an empty questions array.")

    validated: list[dict] = []
    errors: list[str] = []

    for i, q in enumerate(questions):
        err = validate_question(q, i)
        if err:
            errors.append(err)
            continue

        validated.append({
            "question":    q["question"].strip(),
            "options":     [str(opt).strip() for opt in q["options"]],
            "answer":      int(q["answer"]),
            "explanation": str(q.get("explanation", "")).strip(),
        })

    if errors:
        if len(validated) < 2:
            raise ValueError(
                f"Too many invalid questions. Errors: {'; '.join(errors[:3])}"
            )

    return validated[:expected_count]


# ════════════════════════════════════════════════════════════════
# 6.  Main
# ════════════════════════════════════════════════════════════════

def main() -> None:
    # ── Parse args ────────────────────────────────────────────
    args = parse_args()

    # ── Build prompt ──────────────────────────────────────────
    prompt = build_prompt(
        content    = args["content"],
        note_name  = args["note_name"],
        count      = args["count"],
        difficulty = args["difficulty"],
    )

    # ── Call OpenAI (with retry on JSON parse failure) ────────
    questions: list[dict] = []
    last_error = ""

    for attempt in range(1, 4):    # up to 3 full generation attempts
        try:
            raw = call_openai(prompt, QUIZ_SYSTEM)
            questions = parse_and_validate(raw, args["count"])
            break  # success

        except RuntimeError as exc:
            output_failure(str(exc))   # non-retryable API error

        except ValueError as exc:
            last_error = str(exc)
            if attempt < 3:
                # Add an explicit reminder to the prompt and retry
                prompt += (
                    f"\n\nIMPORTANT: Your previous response was invalid. "
                    f"Error: {last_error}. "
                    f"Return ONLY a JSON array — no markdown, no extra text."
                )
                time.sleep(1.0)

    if not questions:
        output_failure(
            f"Failed to generate valid quiz questions after 3 attempts. "
            f"Last error: {last_error}"
        )

    output_success(questions)


if __name__ == "__main__":
    main()