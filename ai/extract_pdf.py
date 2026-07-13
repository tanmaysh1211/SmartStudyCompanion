from __future__ import annotations
import html
import json
import os
import re
import sys
import unicodedata

def output_success(text: str, pages: int, method: str) -> None:
    print(json.dumps(
        {"success": True, "text": text, "pages": pages, "method": method},
        ensure_ascii=True,
    ))
    sys.exit(0)

def output_failure(message: str) -> None:
    print(json.dumps({"success": False, "message": message}, ensure_ascii=False))
    sys.exit(1)

def _extract_pdfplumber_html(path: str) -> tuple[str, int]:
    from pdf2image import convert_from_path  
    import io
    import base64
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
    safe = html.escape(raw)
    return (
        f'<div class="pdf-page" data-page="{page_label}">'
        f'<pre class="pdf-plain">{safe}</pre>'
        f"</div>"
    )

def _extract_pypdf2_html(path: str) -> tuple[str, int]:
    try:
        from pypdf import PdfReader  
    except ImportError:
        from PyPDF2 import PdfReader 

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
    from pdfminer.high_level import extract_pages, extract_text  
    raw = extract_text(path)
    page_count = sum(1 for _ in extract_pages(path))
    cleaned = _clean_plain(raw)
    return _plain_to_html(cleaned, "1"), page_count

def is_useful(html_str: str, min_chars: int = 80) -> bool:
    if 'data:image/png;base64,' in html_str:
        return True
    text = re.sub(r"<[^>]+>", "", html_str)
    stripped = text.strip()
    if len(stripped) < min_chars:
        return False
    return sum(1 for c in stripped if c.isalpha()) >= min_chars // 2

MAX_PDF_BYTES = 50 * 1024 * 1024 

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
        except Exception as exc:  
            errors.append(f"{method_name}: {exc}")

    output_failure(
        "Could not extract readable text. File may be image-only. "
        f"Tried: {' | '.join(errors)}"
    )


if __name__ == "__main__":
    main()
