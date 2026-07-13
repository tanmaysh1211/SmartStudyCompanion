from __future__ import annotations
import json
import logging
import os
import re
import time
from dataclasses import dataclass, field
from typing import Any

try:
    from dotenv import load_dotenv 
    load_dotenv(
        dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"),
        override=False,
    )
except ImportError:
    pass

logging.basicConfig(
    level=logging.WARNING,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("openai_client")

class GeminiError(RuntimeError):

class GeminiSafetyError(GeminiError):

class GeminiAuthError(GeminiError):

class GeminiQuotaError(GeminiError):


@dataclass
class GeminiConfig:
    model: str = field(
        default_factory=lambda: os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    )
    temperature: float = field(
        default_factory=lambda: float(os.getenv("OPENAI_TEMPERATURE", "0.4"))
    )

    max_output_tokens: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_MAX_TOKENS", "2048"))
    )

    max_retries: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_MAX_RETRIES", "3"))
    )

    retry_base_delay: float = 2.0   
    timeout: int = field(
        default_factory=lambda: int(os.getenv("OPENAI_TIMEOUT", "60"))
    )

    top_p: float = 0.90
    top_k: int   = 40

    def __post_init__(self) -> None:
        self.temperature       = max(0.0, min(2.0, self.temperature))
        self.max_output_tokens = max(1, min(16384, self.max_output_tokens))
        self.max_retries       = max(1, min(10, self.max_retries))
        self.timeout           = max(10, self.timeout)

class GeminiClient:
    def __init__(self, config: GeminiConfig | None = None) -> None:
        self.config = config or GeminiConfig()
        self._client = self._build_client()

    def _build_client(self) -> Any:
        try:
            from openai import OpenAI  
        except ImportError as exc:
            raise ImportError(
                "The `openai` library is not installed.\n" "Run:  pip install openai"
            ) from exc

        api_key = os.getenv("OPENAI_API_KEY", "").strip()

        if not api_key:
            raise GeminiAuthError("OPENAI_API_KEY environment variable is not set.\n\n")

        if not api_key.startswith("sk-"):
            log.warning(
                "OPENAI_API_KEY does not start with 'sk-' — "
                "double-check that it is a valid OpenAI key."
            )

        from openai import OpenAI  
        client = OpenAI(
            api_key=api_key,
            timeout=self.config.timeout,
            max_retries=0,  
        )

        log.debug("OpenAI client created. Model: %s", self.config.model)
        return client

    @staticmethod
    def _classify_error(exc: Exception):
        msg      = str(exc).lower()
        exc_type = type(exc).__name__.lower()

        if any(k in msg for k in (
            "invalid api key", "incorrect api key",
            "authentication", "unauthorized", "401",
        )):
            return "auth"

        if any(k in msg for k in (
            "rate limit", "quota", "insufficient_quota",
            "429", "too many requests",
        )):
            return "quota"

        if any(k in msg for k in (
            "content_policy", "content filter",
            "safety", "flagged",
        )):
            return "safety"

        if any(k in (msg, exc_type) for k in (
            "timeout", "timed out", "read timeout",
        )):
            return "timeout"

        return "transient"

    def _with_retry(self, fn: Any, *args: Any, **kwargs: Any) -> Any:
        last_exc: Exception | None = None

        for attempt in range(1, self.config.max_retries + 1):
            try:
                return fn(*args, **kwargs)

            except Exception as exc:  
                last_exc = exc
                kind     = self._classify_error(exc)

                log.warning(
                    "OpenAI call failed (attempt %d/%d, kind=%s): %s",
                    attempt, self.config.max_retries, kind, exc,
                )

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

                if kind == "quota":
                    log.warning(
                        "Rate limit hit (attempt %d). "
                        "Waiting before retry…", attempt,
                    )
                    if attempt < self.config.max_retries:
                        time.sleep(self.config.retry_base_delay * attempt * 2)
                    continue

                if attempt < self.config.max_retries:
                    delay = self.config.retry_base_delay * (2 ** (attempt - 1))
                    log.info("Retrying in %.1f seconds…", delay)
                    time.sleep(delay)

        raise GeminiError(
            f"OpenAI API failed after {self.config.max_retries} attempts.\n"
            f"Last error: {last_exc}"
        ) from last_exc

    @staticmethod
    def _extract_text(response: Any)
        try:
            text = response.choices[0].message.content
            if text:
                return text.strip()
        except (AttributeError, IndexError, TypeError):
            pass
        raise GeminiError("OpenAI returned a response with no text content.")

    def generate(self,prompt: str,system: str | None = None,temperature: float | None = None,max_tokens: int | None = None):
        if not prompt or not prompt.strip():
            raise GeminiError("prompt must not be empty.")
        messages: list[dict] = []
        if system:
            messages.append({"role": "system", "content": system})
        messages.append({"role": "user", "content": prompt})

        def _call():
            response = self._client.chat.completions.create(
                model       = self.config.model,
                messages    = messages,
                temperature = temperature if temperature is not None else self.config.temperature,
                max_tokens  = max_tokens  if max_tokens  is not None else self.config.max_output_tokens,
            )
            return self._extract_text(response)

        return self._with_retry(_call)

    def chat(self,message: str,system: str | None = None,history: list[dict] | None = None,temperature: float | None = None,max_tokens: int | None = None):
        if not message or not message.strip():
            raise GeminiError("message must not be empty.")
        messages: list[dict] = []
        if system:
            messages.append({"role": "system", "content": system})
        for turn in (history or []):
            role    = str(turn.get("role", "")).strip().lower()
            content = str(turn.get("content", "")).strip()
            if role in ("model", "ai", "bot"):
                role = "assistant"
            if role not in ("user", "assistant") or not content:
                continue

            messages.append({"role": role, "content": content})

        messages.append({"role": "user", "content": message})

        def _call(): response = self._client.chat.completions.create(
                model       = self.config.model,
                messages    = messages,
                temperature = temperature if temperature is not None else self.config.temperature,
                max_tokens  = max_tokens  if max_tokens  is not None else self.config.max_output_tokens,
            )
            return self._extract_text(response)

        return self._with_retry(_call)

    def generate_json(
        self,
        prompt: str,
        system: str | None = None,
        temperature: float | None = None,
    ) -> Any:
        if not prompt or not prompt.strip():
            raise GeminiError("prompt must not be empty.")

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
                response_format = {"type": "json_object"},  
            )
            content = self._extract_text(response)
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

    def ping(self) -> bool:
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
            return True   
        except Exception as exc:
            kind = self._classify_error(exc)
            if kind == "auth":
                raise GeminiAuthError(f"API key validation failed: {exc}") from exc
            return True  

    def __repr__(self):
        return (
            f"GeminiClient[OpenAI]("
            f"model={self.config.model!r}, "
            f"temperature={self.config.temperature}, "
            f"timeout={self.config.timeout}s)"
        )

_DEFAULT_CLIENT: GeminiClient | None = None

def get_client(config: GeminiConfig | None = None) -> GeminiClient:
    global _DEFAULT_CLIENT 
    if _DEFAULT_CLIENT is None:
        _DEFAULT_CLIENT = GeminiClient(config)
    return _DEFAULT_CLIENT


if __name__ == "__main__":
    import sys

    try:
        client = GeminiClient()
        print(f"Client: {client}")
        client.ping()
        print("API key is valid")

        text = client.generate(
            prompt      = "List 3 study tips in one sentence each.",
            system      = "You are a helpful study assistant. Be brief.",
            temperature = 0.3,
            max_tokens  = 100,
        )
        print(f" Response:\n{text}\n")

        reply = client.chat(
            message = "What topic did I just ask you about?",
            system  = "You are a helpful assistant.",
            history = [
                {"role": "user",      "content": "Tell me about binary search trees."},
                {"role": "assistant", "content": "BSTs are data structures where left < root < right."},
            ],
        )
        print(f" Chat reply: {reply}\n")

    except GeminiAuthError as exc:
        print(f"\n Auth error:\n{exc}", file=sys.stderr)
        sys.exit(1)
    except GeminiError as exc:
        print(f"\n Error:\n{exc}", file=sys.stderr)
        sys.exit(1)
