from __future__ import annotations
import io
import json
import os
import re
import sys
import textwrap
import time

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')


def output_success(summary: str) -> None:
    word_count = len(summary.split())
    print(json.dumps(
        {"success": True, "summary": summary, "word_count": word_count},
        ensure_ascii=False,
    ))
    sys.exit(0)

def output_failure(message: str) -> None:
    print(json.dumps({"success": False, "message": message}, ensure_ascii=False))
    sys.exit(1)


MAX_INPUT_CHARS   = 120_000
MIN_CONTENT_CHARS = 50

def parse_args() -> dict:
    try:
        raw = sys.stdin.read().strip()
        if not raw:
            output_failure("No input received on stdin.")
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        output_failure(f"Invalid JSON on stdin: {exc}")

    if not isinstance(data, dict):
        output_failure("Input must be a JSON object.")

    content = str(data.get("content", "")).strip()
    if len(content) < MIN_CONTENT_CHARS:
        output_failure("Note content is too short to summarise.")
    if len(content) > MAX_INPUT_CHARS:
        content = content[:MAX_INPUT_CHARS]

    note_name = str(data.get("note_name", "the uploaded notes")).strip() or "the uploaded notes"

    try:
        max_words = int(data.get("max_words", 600))
        max_words = max(100, min(1500, max_words))
    except (TypeError, ValueError):
        max_words = 600

    return {"content": content, "note_name": note_name, "max_words": max_words}

SUMMARY_SYSTEM = textwrap.dedent("""\
    You are an expert academic tutor creating study summaries for students.

    FORMATTING RULES — follow these exactly:
    ─────────────────────────────────────────
    1. Use ## for main section headings (e.g. ## Vectors)
    2. Use ### for sub-section headings (e.g. ### Dot Product Formula)
    3. Use bullet points (-) for lists of facts, properties, or steps
    4. Use **bold** for key terms being defined
    5. Write formulas in clean LaTeX:
       - Inline math: \\( formula \\)
       - Display (block) math: \\[ formula \\]
    6. For simple variable names in plain text (like "matrix A" or "vector v"),
       write them in plain English — do NOT wrap single letters in \\( \\) unless
       they appear inside an actual formula.
    7. End with a ## Key Takeaways section with 3–5 bullet points.

    CONTENT RULES:
    ──────────────
    - Explain concepts clearly in plain English — avoid academic jargon
    - Do NOT start with "Here is your summary" or any preamble
    - Do NOT repeat the same point more than once
    - Write full sentences in paragraphs; use bullets for lists only
""")


def build_prompt(content: str, note_name: str, max_words: int):
    return textwrap.dedent(f"""\
        Create a comprehensive study summary for "{note_name}".
        Target length: approximately {max_words} words.

        ── Study Material ────────────────────────────────────────
        {content}
        ─────────────────────────────────────────────────────────

        Follow the formatting and content rules exactly.
        Make it easy for a student to read and understand.
    """)

DEFAULT_MODEL   = "gpt-4o-mini"
MAX_RETRIES     = 3
RETRY_DELAY_SEC = 2.0

def call_openai(prompt: str, system_prompt: str):
    try:
        from openai import OpenAI, AuthenticationError, RateLimitError, APIError
    except ImportError:
        raise RuntimeError("openai is not installed. Run: pip install openai")

    api_key = os.getenv("OPENAI_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY environment variable is not set.")

    client     = OpenAI(api_key=api_key)
    model_name = os.getenv("OPENAI_MODEL", DEFAULT_MODEL).strip()
    last_error = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = client.chat.completions.create(
                model=model_name,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user",   "content": prompt},
                ],
                temperature=0.4,
                top_p=0.9,
                max_tokens=2048,
            )
            text = response.choices[0].message.content
            if text:
                return text.strip()
            raise RuntimeError("OpenAI returned an empty response.")

        except AuthenticationError as exc:
            raise RuntimeError(f"Invalid OpenAI API key: {exc}") from exc

        except RateLimitError as exc:
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

        except APIError as exc:
            last_error = exc
            if "content_policy" in str(exc).lower():
                raise RuntimeError("OpenAI blocked the request due to content policy.") from exc
            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

        except Exception as exc:
            last_error = exc
            if attempt < MAX_RETRIES:
                time.sleep(RETRY_DELAY_SEC * (2 ** (attempt - 1)))

    raise RuntimeError(f"OpenAI API failed after {MAX_RETRIES} attempts. Last error: {last_error}")

def post_process(summary: str):
    for pat in [
        r"^(here\s+is\s+)?(a\s+)?(comprehensive\s+)?(study\s+)?summary[:\s]+",
        r"^summary[:\s]+",
        r"^okay[,.]?\s+",
        r"^sure[,.]?\s+",
    ]:
        summary = re.sub(pat, "", summary, flags=re.IGNORECASE).strip()
    summary = re.sub(r"([^\n])\n(#{1,3}\s)", r"\1\n\n\2", summary)
    summary = re.sub(r"\n{3,}", "\n\n", summary)
    return summary.strip()

def main() -> None:
    args = parse_args()

    prompt = build_prompt(
        content   = args["content"],
        note_name = args["note_name"],
        max_words = args["max_words"],
    )

    try:
        raw_summary = call_openai(prompt, SUMMARY_SYSTEM)
    except RuntimeError as exc:
        output_failure(str(exc))

    summary = post_process(raw_summary)

    if not summary:
        output_failure("OpenAI returned an empty summary. Please try again.")

    output_success(summary)


if __name__ == "__main__":
    main()
