# #!/usr/bin/env python3
# """
# ai/extract_pdf.py
# ═══════════════════════════════════════════════════════════════
# Extracts clean, readable text from a PDF file and prints the
# result as a JSON object to stdout.

# Called by backend/notes/upload_note.php via shell_exec():
#     python3 ai/extract_pdf.py /path/to/uploaded/file.pdf

# Output (stdout) — always valid JSON:
#     Success → { "success": true,  "text": "...", "pages": int, "method": "..." }
#     Failure → { "success": false, "message": "..." }

# Extraction strategy (tries in order):
#     1. pdfplumber   — best quality, handles complex layouts & tables
#     2. PyPDF2/pypdf — lightweight fallback for simple PDFs
#     3. pdfminer.six — deep fallback for edge-case PDFs

# Install dependencies:
#     pip install pdfplumber PyPDF2 pdfminer.six
# ═══════════════════════════════════════════════════════════════
# """

# from __future__ import annotations

# import json
# import os
# import re
# import sys
# import unicodedata


# # ════════════════════════════════════════════════════════════════
# # 1.  JSON output helpers
# # ════════════════════════════════════════════════════════════════

# def output_success(text: str, pages: int, method: str) -> None:
#     """Print a success JSON response to stdout and exit 0."""
#     print(json.dumps(
#         {"success": True, "text": text, "pages": pages, "method": method},
#         # ensure_ascii=False,
#         ensure_ascii=True,
#     ))
#     sys.exit(0)


# def output_failure(message: str) -> None:
#     """Print a failure JSON response to stdout and exit 1."""
#     print(json.dumps({"success": False, "message": message}, ensure_ascii=False))
#     sys.exit(1)


# # ════════════════════════════════════════════════════════════════
# # 2.  Text cleaning
# # ════════════════════════════════════════════════════════════════

# # Characters / patterns that are PDF artefacts, not real content
# _ZERO_WIDTH = "\u00ad\u200b\u200c\u200d\u200e\u200f\ufeff"
# _DECORATIVE  = re.compile(r"^[\s\-=_*#~.]{3,}$")
# _BROKEN_WORD = re.compile(r"-\n\s*")   # "infor-\nmation" → "information"


# def clean_text(raw: str) -> str:
#     """
#     Normalises raw PDF text into clean, readable content:
#       • Unicode NFC normalisation
#       • Removes zero-width / invisible characters
#       • Converts non-breaking spaces to regular spaces
#       • Joins words hyphenated across line breaks
#       • Converts form-feeds to newlines
#       • Strips trailing whitespace per line
#       • Removes purely decorative separator lines
#       • Collapses runs of 3+ blank lines into 2
#     """
#     if not raw:
#         return ""

#     text = unicodedata.normalize("NFC", raw)

#     # Replace / strip invisible artefact characters
#     text = text.replace("\u00a0", " ")      # non-breaking space
#     for ch in _ZERO_WIDTH:
#         text = text.replace(ch, "")

#     text = text.replace("\f", "\n")         # form-feed → newline
#     text = _BROKEN_WORD.sub("", text)       # join hyphenated words

#     # Process line by line
#     cleaned: list[str] = []
#     blank_run = 0

#     for line in text.splitlines():
#         line = line.rstrip()

#         if line.strip() == "":
#             blank_run += 1
#             if blank_run <= 2:
#                 cleaned.append("")
#         else:
#             blank_run = 0
#             if not _DECORATIVE.match(line):
#                 cleaned.append(line)

#     return "\n".join(cleaned).strip()


# def is_useful(text: str, min_chars: int = 80) -> bool:
#     """
#     Returns True when extracted text looks like real human-readable content.
#     Rejects near-empty results from image-only / encrypted PDFs.
#     """
#     stripped = text.strip()
#     if len(stripped) < min_chars:
#         return False
#     # At least half the minimum chars must be alphabetic
#     alpha_chars = sum(1 for c in stripped if c.isalpha())
#     return alpha_chars >= min_chars // 2


# # ════════════════════════════════════════════════════════════════
# # 3.  Extraction backends
# # ════════════════════════════════════════════════════════════════

# def _extract_pdfplumber(path: str) -> tuple[str, int]:
#     """
#     Primary extractor — pdfplumber.
#     Best for complex layouts, multi-column text, and tables.
#     Raises ImportError if library not installed.
#     """
#     import pdfplumber  # type: ignore  # noqa: PLC0415

#     parts: list[str] = []

#     with pdfplumber.open(path) as pdf:
#         page_count = len(pdf.pages)

#         for i, page in enumerate(pdf.pages):
#             # extract_text with layout=True preserves reading order better
#             page_text = page.extract_text(
#                 x_tolerance=2,
#                 y_tolerance=2,
#                 layout=True,
#                 x_density=7.25,
#                 y_density=13,
#             )
#             if page_text and page_text.strip():
#                 parts.append(f"[Page {i + 1}]\n{page_text.strip()}")

#             # Flatten any tables found on the page
#             for table in page.extract_tables() or []:
#                 rows: list[str] = []
#                 for row in table:
#                     if row:
#                         cells = [str(c).strip() if c else "" for c in row]
#                         rows.append(" | ".join(cells))
#                 if rows:
#                     parts.append("\n".join(rows))

#     return "\n\n".join(parts), page_count


# def _extract_pypdf2(path: str) -> tuple[str, int]:
#     """
#     Fallback extractor — PyPDF2 / pypdf.
#     Good for simple single-column PDFs without complex layout.
#     Raises ImportError if neither library is installed.
#     """
#     try:
#         from pypdf import PdfReader  # type: ignore  # noqa: PLC0415
#     except ImportError:
#         from PyPDF2 import PdfReader  # type: ignore  # noqa: PLC0415

#     reader = PdfReader(path)
#     page_count = len(reader.pages)
#     parts: list[str] = []

#     for i, page in enumerate(reader.pages):
#         page_text = page.extract_text() or ""
#         if page_text.strip():
#             parts.append(f"[Page {i + 1}]\n{page_text.strip()}")

#     return "\n\n".join(parts), page_count


# def _extract_pdfminer(path: str) -> tuple[str, int]:
#     """
#     Deep fallback — pdfminer.six.
#     Slower but handles edge-case PDFs that other libraries cannot.
#     Raises ImportError if pdfminer.six is not installed.
#     """
#     from pdfminer.high_level import extract_pages, extract_text  # type: ignore  # noqa: PLC0415

#     raw_text   = extract_text(path)
#     page_count = sum(1 for _ in extract_pages(path))
#     return raw_text, page_count


# # ════════════════════════════════════════════════════════════════
# # 4.  Input validation
# # ════════════════════════════════════════════════════════════════

# MAX_PDF_BYTES = 50 * 1024 * 1024  # 50 MB


# def validate_path(pdf_path: str) -> None:
#     """
#     Validates the supplied file path.
#     Calls output_failure() and exits on any problem.
#     """
#     if not pdf_path:
#         output_failure("No PDF path provided. Usage: python3 extract_pdf.py <path>")

#     if not os.path.isfile(pdf_path):
#         output_failure(f"File not found: {pdf_path}")

#     if not os.access(pdf_path, os.R_OK):
#         output_failure(f"File is not readable: {pdf_path}")

#     # Magic-byte check — PDF files start with the bytes %PDF
#     try:
#         with open(pdf_path, "rb") as fh:
#             magic = fh.read(4)
#         if magic != b"%PDF":
#             output_failure(
#                 f"File does not appear to be a valid PDF "
#                 f"(expected '%PDF' header, got {magic!r})."
#             )
#     except OSError as exc:
#         output_failure(f"Cannot open file: {exc}")

#     # Size guard — reject extremely large PDFs to prevent memory issues
#     file_size = os.path.getsize(pdf_path)
#     if file_size > MAX_PDF_BYTES:
#         size_mb = file_size / 1024 / 1024
#         output_failure(
#             f"PDF is too large ({size_mb:.1f} MB). "
#             f"Maximum allowed size is {MAX_PDF_BYTES // (1024 * 1024)} MB."
#         )


# # ════════════════════════════════════════════════════════════════
# # 5.  Main
# # ════════════════════════════════════════════════════════════════

# def main() -> None:
#     # ── CLI argument ──────────────────────────────────────────
#     if len(sys.argv) < 2:
#         output_failure("Usage: python3 extract_pdf.py <path_to_pdf>")

#     pdf_path = sys.argv[1]

#     # ── Validate path ─────────────────────────────────────────
#     validate_path(pdf_path)

#     # ── Try each extraction backend in preference order ───────
#     backends = [
#         ("pdfplumber", _extract_pdfplumber),
#         ("PyPDF2",     _extract_pypdf2),
#         ("pdfminer",   _extract_pdfminer),
#     ]

#     errors: list[str] = []

#     for method_name, extractor in backends:
#         try:
#             raw_text, page_count = extractor(pdf_path)
#             cleaned = clean_text(raw_text)

#             if is_useful(cleaned):
#                 output_success(cleaned, page_count, method_name)   # exits here
#             else:
#                 errors.append(
#                     f"{method_name}: only {len(cleaned.strip())} usable chars extracted"
#                 )

#         except ImportError:
#             errors.append(f"{method_name}: library not installed")
#         except Exception as exc:  # noqa: BLE001
#             errors.append(f"{method_name}: {exc}")

#     # ── All backends failed ───────────────────────────────────
#     output_failure(
#         "Could not extract readable text from this PDF. "
#         "The file may contain only scanned images (no embedded text). "
#         f"Tried: {' | '.join(errors)}"
#     )


# if __name__ == "__main__":
#     main()












#!/usr/bin/env python3
"""
ai/extract_pdf.py
═══════════════════════════════════════════════════════════════
Extracts text from a PDF file, preserving visual formatting
(alignment, font size, bold) and outputs it as HTML.

Called by backend/notes/upload_note.php via shell_exec():
    python3 ai/extract_pdf.py /path/to/uploaded/file.pdf

Output (stdout) — always valid JSON:
    Success → { "success": true, "text": "<html>...", "pages": int, "method": "..." }
    Failure → { "success": false, "message": "..." }

Install dependencies:
    pip install pdfplumber PyPDF2 pdfminer.six
═══════════════════════════════════════════════════════════════
"""

from __future__ import annotations

import html
import json
import os
import re
import sys
import unicodedata


# ════════════════════════════════════════════════════════════════
# 1.  JSON output helpers
# ════════════════════════════════════════════════════════════════

def output_success(text: str, pages: int, method: str) -> None:
    print(json.dumps(
        {"success": True, "text": text, "pages": pages, "method": method},
        ensure_ascii=True,
    ))
    sys.exit(0)


def output_failure(message: str) -> None:
    print(json.dumps({"success": False, "message": message}, ensure_ascii=False))
    sys.exit(1)


# ════════════════════════════════════════════════════════════════
# 2.  HTML extraction with formatting
# ════════════════════════════════════════════════════════════════

# def _extract_pdfplumber_html(path: str) -> tuple[str, int]:
#     """
#     Primary extractor — pdfplumber with HTML output.
#     Detects font size, bold, and text alignment per line,
#     producing structured HTML that mirrors the PDF's visual layout.
#     """
#     import pdfplumber  # type: ignore

#     pages_html: list[str] = []

#     with pdfplumber.open(path) as pdf:
#         page_count = len(pdf.pages)

#         for page_num, page in enumerate(pdf.pages, start=1):
#             page_width = float(page.width)

#             # extract_words gives us per-word bbox + font metadata
#             words = page.extract_words(
#                 x_tolerance=3,
#                 y_tolerance=5,
#                 keep_blank_chars=False,
#                 use_text_flow=False,
#                 extra_attrs=["size", "fontname"],
#             )

#             if not words:
#                 continue

#             # ── Group words into lines by quantised y-position ──
#             line_buckets: dict[int, list[dict]] = {}
#             for word in words:
#                 bucket = round(float(word.get("top", 0)) / 4) * 4
#                 line_buckets.setdefault(bucket, []).append(word)

#             html_parts: list[str] = [
#                 f'<div class="pdf-page" data-page="{page_num}">'
#             ]

#             prev_bottom = None

#             for y in sorted(line_buckets.keys()):
#                 line_words = sorted(line_buckets[y], key=lambda w: float(w.get("x0", 0)))

#                 # Add vertical spacing between paragraphs
#                 if prev_bottom is not None:
#                     gap = y - prev_bottom
#                     if gap > 18:
#                         html_parts.append('<div class="pdf-spacer"></div>')

#                 prev_bottom = max(
#                     float(w.get("bottom", y)) for w in line_words
#                 )

#                 # line_text = " ".join(w["text"] for w in line_words)
#                 # if not line_text.strip():
#                 #     continue

#                 line_text = " ".join(w["text"] for w in line_words)
#                 if not line_text.strip():
#                     continue
#                 # Skip lines that are just bullet/nav artifacts
#                 if line_text.strip() in ("n", "•", "■", "▪", "◆", "▸", "➤"):
#                     continue

#                 # ── Font metrics ──────────────────────────────
#                 sizes = [float(w.get("size", 12) or 12) for w in line_words]
#                 avg_size = sum(sizes) / len(sizes)
#                 max_size = max(sizes)

#                 fontnames = [str(w.get("fontname", "") or "") for w in line_words]
#                 is_bold = any(
#                     "bold" in fn.lower() or "Bold" in fn or fn.endswith("B")
#                     for fn in fontnames
#                 )

#                 # ── Alignment detection ───────────────────────
#                 x0 = float(line_words[0].get("x0", 0))
#                 x1 = float(line_words[-1].get("x1", page_width))
#                 line_center = (x0 + x1) / 2
#                 page_center = page_width / 2

#                 center_offset = abs(line_center - page_center) / page_width
#                 right_margin  = page_width - x1

#                 if center_offset < 0.12:
#                     alignment = "center"
#                 elif right_margin < (page_width * 0.12) and x0 < (page_width * 0.15):
#                     alignment = "justify"
#                 elif x0 > (page_width * 0.55):
#                     alignment = "right"
#                 else:
#                     alignment = "left"

#                 # ── Build CSS style string ────────────────────
#                 style_parts = [f"text-align:{alignment}"]

#                 if max_size >= 20:
#                     style_parts.append(f"font-size:{round(max_size)}px")
#                     style_parts.append("font-weight:bold")
#                 elif max_size >= 15:
#                     style_parts.append(f"font-size:{round(max_size)}px")
#                     if is_bold:
#                         style_parts.append("font-weight:bold")
#                 elif is_bold:
#                     style_parts.append("font-weight:600")

#                 # Colour hint: red text common in MIT/academic covers
#                 for w in line_words:
#                     fn = str(w.get("fontname", ""))
#                     # pdfplumber doesn't expose colour directly — skip colour detection

#                 style = ";".join(style_parts)

#                 # ── Choose HTML tag ───────────────────────────
#                 safe_text = html.escape(line_text)

#                 if max_size >= 20:
#                     tag = "h1"
#                 elif max_size >= 16:
#                     tag = "h2"
#                 elif max_size >= 13 and is_bold:
#                     tag = "h3"
#                 else:
#                     tag = "p"

#                 html_parts.append(f'<{tag} style="{style}">{safe_text}</{tag}>')

#             html_parts.append("</div>")
#             pages_html.append("\n".join(html_parts))

#     return "\n\n".join(pages_html), page_count






def _extract_pdfplumber_html(path: str) -> tuple[str, int]:
    """
    Renders each PDF page as a base64 PNG image — pixel-perfect output.
    """
    from pdf2image import convert_from_path  # type: ignore
    import io
    import base64

    # ── Change this to your actual poppler bin path ──
    # POPPLER_PATH = r"C:\xampp\htdocs\SmartStudyCompanion\poppler\Library\bin"
    import platform
    import os

    if platform.system() == "Windows":
        POPPLER_PATH = os.path.join(
            os.path.dirname(__file__),
            "..",
            "poppler",
            "Library",
            "bin"
        )
    else:
        POPPLER_PATH = None

    pages = convert_from_path(
        path,
        dpi=100,
        poppler_path=POPPLER_PATH,
    )

    page_count = len(pages)
    html_parts: list[str] = []

    for i, page_img in enumerate(pages, start=1):
        buf = io.BytesIO()
        page_img.save(buf, format="PNG", optimize=True)
        b64 = base64.b64encode(buf.getvalue()).decode("ascii")
        html_parts.append(
            f'<div class="pdf-page" data-page="{i}" style="padding:0;background:#fff;">'
            f'<img src="data:image/png;base64,{b64}" '
            f'style="width:100%;height:auto;display:block;" />'
            f'</div>'
        )

    return "\n".join(html_parts), page_count


# ════════════════════════════════════════════════════════════════
# 3.  Plain-text fallbacks (PyPDF2 / pdfminer) → wrap in <pre>
# ════════════════════════════════════════════════════════════════

_ZERO_WIDTH = "\u00ad\u200b\u200c\u200d\u200e\u200f\ufeff"
_BROKEN_WORD = re.compile(r"-\n\s*")


def _clean_plain(raw: str) -> str:
    text = unicodedata.normalize("NFC", raw)
    text = text.replace("\u00a0", " ")
    for ch in _ZERO_WIDTH:
        text = text.replace(ch, "")
    text = text.replace("\f", "\n")
    text = _BROKEN_WORD.sub("", text)
    lines, blank = [], 0
    for line in text.splitlines():
        line = line.rstrip()
        if not line:
            blank += 1
            if blank <= 2:
                lines.append("")
        else:
            blank = 0
            lines.append(line)
    return "\n".join(lines).strip()


def _plain_to_html(raw: str, page_label: str) -> str:
    """Wrap plain-text page content in a minimal HTML structure."""
    safe = html.escape(raw)
    return (
        f'<div class="pdf-page" data-page="{page_label}">'
        f'<pre class="pdf-plain">{safe}</pre>'
        f"</div>"
    )


def _extract_pypdf2_html(path: str) -> tuple[str, int]:
    try:
        from pypdf import PdfReader  # type: ignore
    except ImportError:
        from PyPDF2 import PdfReader  # type: ignore

    reader = PdfReader(path)
    page_count = len(reader.pages)
    parts: list[str] = []

    for i, page in enumerate(reader.pages):
        raw = page.extract_text() or ""
        cleaned = _clean_plain(raw)
        if cleaned:
            parts.append(_plain_to_html(cleaned, str(i + 1)))

    return "\n\n".join(parts), page_count


def _extract_pdfminer_html(path: str) -> tuple[str, int]:
    from pdfminer.high_level import extract_pages, extract_text  # type: ignore

    raw = extract_text(path)
    page_count = sum(1 for _ in extract_pages(path))
    cleaned = _clean_plain(raw)
    return _plain_to_html(cleaned, "1"), page_count


# ════════════════════════════════════════════════════════════════
# 4.  Usefulness check
# ════════════════════════════════════════════════════════════════

# def is_useful(html_str: str, min_chars: int = 80) -> bool:
#     # Strip tags to measure real character content
#     text = re.sub(r"<[^>]+>", "", html_str)
#     stripped = text.strip()
#     if len(stripped) < min_chars:
#         return False
#     return sum(1 for c in stripped if c.isalpha()) >= min_chars // 2




def is_useful(html_str: str, min_chars: int = 80) -> bool:
    # Image-based pages always pass
    if 'data:image/png;base64,' in html_str:
        return True
    # Strip tags to measure real character content
    text = re.sub(r"<[^>]+>", "", html_str)
    stripped = text.strip()
    if len(stripped) < min_chars:
        return False
    return sum(1 for c in stripped if c.isalpha()) >= min_chars // 2

# ════════════════════════════════════════════════════════════════
# 5.  Input validation
# ════════════════════════════════════════════════════════════════

MAX_PDF_BYTES = 50 * 1024 * 1024  # 50 MB


def validate_path(pdf_path: str) -> None:
    if not pdf_path:
        output_failure("No PDF path provided.")
    if not os.path.isfile(pdf_path):
        output_failure(f"File not found: {pdf_path}")
    if not os.access(pdf_path, os.R_OK):
        output_failure(f"File is not readable: {pdf_path}")
    try:
        with open(pdf_path, "rb") as fh:
            magic = fh.read(4)
        if magic != b"%PDF":
            output_failure(f"Not a valid PDF (got {magic!r}).")
    except OSError as exc:
        output_failure(f"Cannot open file: {exc}")
    size = os.path.getsize(pdf_path)
    if size > MAX_PDF_BYTES:
        output_failure(f"PDF too large ({size/1024/1024:.1f} MB). Max 50 MB.")


# ════════════════════════════════════════════════════════════════
# 6.  Main
# ════════════════════════════════════════════════════════════════

def main() -> None:
    if len(sys.argv) < 2:
        output_failure("Usage: python3 extract_pdf.py <path_to_pdf>")

    pdf_path = sys.argv[1]
    validate_path(pdf_path)

    backends = [
        ("pdfplumber", _extract_pdfplumber_html),
        ("PyPDF2",     _extract_pypdf2_html),
        ("pdfminer",   _extract_pdfminer_html),
    ]

    errors: list[str] = []

    for method_name, extractor in backends:
        try:
            html_str, page_count = extractor(pdf_path)
            if is_useful(html_str):
                output_success(html_str, page_count, method_name)
            else:
                errors.append(f"{method_name}: too little content")
        except ImportError:
            errors.append(f"{method_name}: library not installed")
        except Exception as exc:  # noqa: BLE001
            errors.append(f"{method_name}: {exc}")

    output_failure(
        "Could not extract readable text. File may be image-only. "
        f"Tried: {' | '.join(errors)}"
    )


if __name__ == "__main__":
    main()