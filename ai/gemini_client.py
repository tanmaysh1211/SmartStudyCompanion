#!/usr/bin/env python3
"""
ai/gemini_client.py  (OpenAI edition)
═══════════════════════════════════════════════════════════════
Drop-in replacement for the Ollama client.
Uses OpenAI's API instead of a local Ollama server.

PUBLIC API IS IDENTICAL — no changes needed in any script
that already imports this module:

    from gemini_client import GeminiClient, GeminiError
    client = GeminiClient()
    text   = client.generate(prompt="Summarise this: ...")
    reply  = client.chat(message="Explain X", history=[...])
    data   = client.generate_json(prompt="Return JSON: ...")

Install:
    pip install openai python-dotenv

Get your API key:
    https://platform.openai.com/api-keys

Environment variables (.env):
    OPENAI_API_KEY      Your OpenAI API key (required)
    OPENAI_MODEL        Model name (default: gpt-4o-mini)
    OPENAI_TEMPERATURE  0.0 – 1.0 (default: 0.4)
    OPENAI_MAX_TOKENS   Max output tokens (default: 2048)
    OPENAI_MAX_RETRIES  Retry attempts (default: 3)
    OPENAI_TIMEOUT      Request timeout seconds (default: 60)
═══════════════════════════════════════════════════════════════
"""

from __future__ import annotations

import json
import logging
import os
import re
import time
from dataclasses import dataclass, field
from typing import Any

# ── Optional .env loading ──────────────────────────────────────
try:
    from dotenv import load_dotenv  # type: ignore
    load_dotenv(
        dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"),
        override=False,
    )
except ImportError:
    pass

# ── Logging ───────────────────────────────────────────────────
logging.basicConfig(
    level=logging.WARNING,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("openai_client")


# ════════════════════════════════════════════════════════════════
# Exceptions  (same names as before for drop-in compatibility)
# ════════════════════════════════════════════════════════════════

class GeminiError(RuntimeError):
    """Base exception for all AI client failures."""

class GeminiSafetyError(GeminiError):
    """Content blocked by safety/content filters."""

class GeminiAuthError(GeminiError):
    """Invalid or missing API key."""

class GeminiQuotaError(GeminiError):
    """Rate limit or quota exceeded."""


# ════════════════════════════════════════════════════════════════
# Configuration
# ════════════════════════════════════════════════════════════════

@dataclass
class GeminiConfig:
    """
    OpenAI client configuration loaded from environment variables.
    Field names kept compatible with previous Gemini/Ollama versions.
    """

    # OpenAI model
    model: str = field(
        default_factory=lambda: os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    )

    # Generation parameters
    temperature: float = field(
        default_factory=lambda: float(os.getenv("OPENAI_TEMPERATURE", "0.4"))
    )

    max_output_tokens: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_MAX_TOKENS", "2048"))
    )

    # Retry settings
    max_retries: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_MAX_RETRIES", "3"))
    )

    retry_base_delay: float = 2.0   # seconds between retries

    # Request timeout in seconds
    timeout: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_TIMEOUT", "60"))
    )

    # Keep these for API compatibility (not used by OpenAI)
    top_p: float = 0.90
    top_k: int   = 40

    def __post_init__(self) -> None:
        self.temperature       = max(0.0, min(2.0, self.temperature))
        self.max_output_tokens = max(1, min(16384, self.max_output_tokens))
        self.max_retries       = max(1, min(10, self.max_retries))
        self.timeout           = max(10, self.timeout)


# ════════════════════════════════════════════════════════════════
# GeminiClient  (OpenAI backend)
# ════════════════════════════════════════════════════════════════

class GeminiClient:
    """
    OpenAI-backed AI client with identical public API to the
    previous Gemini/Ollama versions.

    Public methods:
        generate()       — single-turn text generation
        chat()           — multi-turn conversation
        generate_json()  — structured JSON output
        ping()           — API key validation
    """

    def __init__(self, config: GeminiConfig | None = None) -> None:
        """
        Initialises the OpenAI client.

        Raises:
            ImportError:    If openai library not installed.
            GeminiAuthError: If OPENAI_API_KEY is missing.
        """
        self.config = config or GeminiConfig()
        self._client = self._build_client()

    # ── Private: build OpenAI client ──────────────────────────

    def _build_client(self) -> Any:
        """Imports openai and creates the client with the API key."""
        try:
            from openai import OpenAI  # type: ignore
        except ImportError as exc:
            raise ImportError(
                "The `openai` library is not installed.\n"
                "Run:  pip install openai"
            ) from exc

        api_key = os.getenv("OPENAI_API_KEY", "").strip()

        if not api_key:
            raise GeminiAuthError(
                "OPENAI_API_KEY environment variable is not set.\n\n"
                "Fix:\n"
                "  1. Go to https://platform.openai.com/api-keys\n"
                "  2. Create a new API key\n"
                "  3. Add to your .env file:\n"
                "       OPENAI_API_KEY=sk-your_key_here\n"
            )

        if not api_key.startswith("sk-"):
            log.warning(
                "OPENAI_API_KEY does not start with 'sk-' — "
                "double-check that it is a valid OpenAI key."
            )

        from openai import OpenAI  # type: ignore
        client = OpenAI(
            api_key=api_key,
            timeout=self.config.timeout,
            max_retries=0,  # we handle retries ourselves
        )

        log.debug("OpenAI client created. Model: %s", self.config.model)
        return client

    # ── Private: classify error ───────────────────────────────

    @staticmethod
    def _classify_error(exc: Exception) -> str:
        """
        Returns: 'auth' | 'quota' | 'safety' | 'timeout' | 'transient'
        """
        msg      = str(exc).lower()
        exc_type = type(exc).__name__.lower()

        # Auth errors
        if any(k in msg for k in (
            "invalid api key", "incorrect api key",
            "authentication", "unauthorized", "401",
        )):
            return "auth"

        # Quota / rate limit
        if any(k in msg for k in (
            "rate limit", "quota", "insufficient_quota",
            "429", "too many requests",
        )):
            return "quota"

        # Safety / content policy
        if any(k in msg for k in (
            "content_policy", "content filter",
            "safety", "flagged",
        )):
            return "safety"

        # Timeout
        if any(k in (msg, exc_type) for k in (
            "timeout", "timed out", "read timeout",
        )):
            return "timeout"

        return "transient"

    # ── Private: retry wrapper ────────────────────────────────

    def _with_retry(self, fn: Any, *args: Any, **kwargs: Any) -> Any:
        """
        Calls fn(*args, **kwargs) with retry logic.
        Retries on quota and transient errors.
        Fails immediately on auth and safety errors.
        """
        last_exc: Exception | None = None

        for attempt in range(1, self.config.max_retries + 1):
            try:
                return fn(*args, **kwargs)

            except Exception as exc:  # noqa: BLE001
                last_exc = exc
                kind     = self._classify_error(exc)

                log.warning(
                    "OpenAI call failed (attempt %d/%d, kind=%s): %s",
                    attempt, self.config.max_retries, kind, exc,
                )

                # ── Non-retryable ─────────────────────────────
                if kind == "auth":
                    raise GeminiAuthError(
                        f"OpenAI API key is invalid or missing.\n"
                        f"Check OPENAI_API_KEY in your .env file.\n"
                        f"Get a key at: https://platform.openai.com/api-keys\n"
                        f"Detail: {exc}"
                    ) from exc

                if kind == "safety":
                    raise GeminiSafetyError(
                        "OpenAI content policy blocked this request. "
                        "The note content may contain policy-violating text."
                    ) from exc

                # ── Quota — wait longer before retry ─────────
                if kind == "quota":
                    log.warning(
                        "Rate limit hit (attempt %d). "
                        "Waiting before retry…", attempt,
                    )
                    if attempt < self.config.max_retries:
                        time.sleep(self.config.retry_base_delay * attempt * 2)
                    continue

                # ── Timeout / transient — retry with back-off ─
                if attempt < self.config.max_retries:
                    delay = self.config.retry_base_delay * (2 ** (attempt - 1))
                    log.info("Retrying in %.1f seconds…", delay)
                    time.sleep(delay)

        raise GeminiError(
            f"OpenAI API failed after {self.config.max_retries} attempts.\n"
            f"Last error: {last_exc}"
        ) from last_exc

    # ── Private: extract text from response ───────────────────

    @staticmethod
    def _extract_text(response: Any) -> str:
        """Extracts text content from an OpenAI ChatCompletion response."""
        try:
            text = response.choices[0].message.content
            if text:
                return text.strip()
        except (AttributeError, IndexError, TypeError):
            pass
        raise GeminiError("OpenAI returned a response with no text content.")

    # ════════════════════════════════════════════════════════════
    # PUBLIC: generate  (single-turn)
    # ════════════════════════════════════════════════════════════

    def generate(
        self,
        prompt: str,
        system: str | None = None,
        temperature: float | None = None,
        max_tokens: int | None = None,
    ) -> str:
        """
        Sends a single prompt to OpenAI and returns the response text.

        Args:
            prompt:      The user prompt / instruction.
            system:      Optional system instruction.
            temperature: Per-call override.
            max_tokens:  Per-call override.

        Returns: Generated text (stripped string).
        Raises:  GeminiAuthError, GeminiSafetyError, GeminiError.
        """
        if not prompt or not prompt.strip():
            raise GeminiError("prompt must not be empty.")

        messages: list[dict] = []
        if system:
            messages.append({"role": "system", "content": system})
        messages.append({"role": "user", "content": prompt})

        def _call() -> str:
            response = self._client.chat.completions.create(
                model       = self.config.model,
                messages    = messages,
                temperature = temperature if temperature is not None else self.config.temperature,
                max_tokens  = max_tokens  if max_tokens  is not None else self.config.max_output_tokens,
            )
            return self._extract_text(response)

        return self._with_retry(_call)

    # ════════════════════════════════════════════════════════════
    # PUBLIC: chat  (multi-turn)
    # ════════════════════════════════════════════════════════════

    def chat(
        self,
        message: str,
        system: str | None = None,
        history: list[dict] | None = None,
        temperature: float | None = None,
        max_tokens: int | None = None,
    ) -> str:
        """
        Sends a message in a multi-turn chat session.

        history format: [{"role": "user"|"assistant", "content": "..."}]
        Note: "model" role is auto-converted to "assistant" for OpenAI.
        """
        if not message or not message.strip():
            raise GeminiError("message must not be empty.")

        messages: list[dict] = []

        if system:
            messages.append({"role": "system", "content": system})

        for turn in (history or []):
            role    = str(turn.get("role", "")).strip().lower()
            content = str(turn.get("content", "")).strip()

            # Normalise role names
            if role in ("model", "ai", "bot"):
                role = "assistant"

            if role not in ("user", "assistant") or not content:
                continue

            messages.append({"role": role, "content": content})

        messages.append({"role": "user", "content": message})

        def _call() -> str:
            response = self._client.chat.completions.create(
                model       = self.config.model,
                messages    = messages,
                temperature = temperature if temperature is not None else self.config.temperature,
                max_tokens  = max_tokens  if max_tokens  is not None else self.config.max_output_tokens,
            )
            return self._extract_text(response)

        return self._with_retry(_call)

    # ════════════════════════════════════════════════════════════
    # PUBLIC: generate_json  (structured JSON output)
    # ════════════════════════════════════════════════════════════

    def generate_json(
        self,
        prompt: str,
        system: str | None = None,
        temperature: float | None = None,
    ) -> Any:
        """
        Generates structured JSON using OpenAI's response_format=json_object.
        This guarantees valid JSON output — no markdown fences to strip.
        """
        if not prompt or not prompt.strip():
            raise GeminiError("prompt must not be empty.")

        # OpenAI JSON mode requires the word "json" in system or user message
        system_with_json = (system or "") + "\nYou must respond with valid JSON only."
        messages = [
            {"role": "system", "content": system_with_json},
            {"role": "user",   "content": prompt},
        ]

        def _call() -> Any:
            response = self._client.chat.completions.create(
                model           = self.config.model,
                messages        = messages,
                temperature     = temperature if temperature is not None else 0.2,
                max_tokens      = self.config.max_output_tokens,
                response_format = {"type": "json_object"},  # OpenAI JSON mode
            )
            content = self._extract_text(response)

            # Strip any accidental markdown fences
            cleaned = re.sub(r"```(?:json)?\s*", "", content, flags=re.IGNORECASE)
            cleaned = cleaned.replace("```", "").strip()

            try:
                return json.loads(cleaned)
            except json.JSONDecodeError as exc:
                raise GeminiError(
                    f"OpenAI returned invalid JSON: {exc}\n"
                    f"Raw: {content[:300]!r}"
                ) from exc

        return self._with_retry(_call)

    # ════════════════════════════════════════════════════════════
    # PUBLIC: ping
    # ════════════════════════════════════════════════════════════

    def ping(self) -> bool:
        """
        Validates the API key with a minimal request.
        Returns True on success, raises GeminiAuthError on failure.
        """
        try:
            self._client.chat.completions.create(
                model      = self.config.model,
                messages   = [{"role": "user", "content": "Hi"}],
                max_tokens = 5,
            )
            return True
        except GeminiAuthError:
            raise
        except GeminiSafetyError:
            return True   # key is valid even if content was flagged
        except Exception as exc:
            kind = self._classify_error(exc)
            if kind == "auth":
                raise GeminiAuthError(f"API key validation failed: {exc}") from exc
            return True   # other errors don't mean key is invalid

    def __repr__(self) -> str:
        return (
            f"GeminiClient[OpenAI]("
            f"model={self.config.model!r}, "
            f"temperature={self.config.temperature}, "
            f"timeout={self.config.timeout}s)"
        )


# ════════════════════════════════════════════════════════════════
# Module-level singleton
# ════════════════════════════════════════════════════════════════

_DEFAULT_CLIENT: GeminiClient | None = None

def get_client(config: GeminiConfig | None = None) -> GeminiClient:
    """Returns (or creates) the module-level GeminiClient singleton."""
    global _DEFAULT_CLIENT  # noqa: PLW0603
    if _DEFAULT_CLIENT is None:
        _DEFAULT_CLIENT = GeminiClient(config)
    return _DEFAULT_CLIENT


# ════════════════════════════════════════════════════════════════
# Self-test  (python ai/gemini_client.py)
# ════════════════════════════════════════════════════════════════

if __name__ == "__main__":
    import sys

    print("── OpenAI Client self-test ─────────────────────────────")

    try:
        client = GeminiClient()
        print(f"Client: {client}")

        # Ping
        print("\n[1/3] API key validation…")
        client.ping()
        print("      ✓ API key is valid")

        # Single-turn generation
        print("\n[2/3] Single-turn generation…")
        text = client.generate(
            prompt      = "List 3 study tips in one sentence each.",
            system      = "You are a helpful study assistant. Be brief.",
            temperature = 0.3,
            max_tokens  = 100,
        )
        print(f"      Response:\n{text}\n")

        # Multi-turn chat
        print("[3/3] Multi-turn chat…")
        reply = client.chat(
            message = "What topic did I just ask you about?",
            system  = "You are a helpful assistant.",
            history = [
                {"role": "user",      "content": "Tell me about binary search trees."},
                {"role": "assistant", "content": "BSTs are data structures where left < root < right."},
            ],
        )
        print(f"      Chat reply: {reply}\n")

        print("── All tests passed ✓ ─────────────────────────────────")

    except GeminiAuthError as exc:
        print(f"\n✗ Auth error:\n{exc}", file=sys.stderr)
        sys.exit(1)
    except GeminiError as exc:
        print(f"\n✗ Error:\n{exc}", file=sys.stderr)
        sys.exit(1)