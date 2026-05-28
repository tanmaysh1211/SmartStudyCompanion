#!/usr/bin/env python3
"""
ai/test_pdf2image.py
════════════════════
Run this script FIRST to diagnose your pdf2image setup.

Usage:
    python test_pdf2image.py

It will tell you EXACTLY what is wrong and how to fix it.
"""
import sys
import os

print("=" * 60)
print("pdf2image Diagnostic Tool")
print("=" * 60)

# ── 1. Check pdf2image is installed ──────────────────────────
print("\n[1] Checking pdf2image library...")
try:
    import pdf2image
    # print(f"    ✓ pdf2image installed: version {pdf2image.__version__}")
    print("    ✓ pdf2image installed")
except ImportError:
    print("    ✗ pdf2image NOT installed!")
    print("    FIX: Run in your venv:")
    print("         pip install pdf2image pillow")
    sys.exit(1)

# ── 2. Check Pillow ───────────────────────────────────────────
print("\n[2] Checking Pillow...")
try:
    from PIL import Image
    import PIL
    print(f"    ✓ Pillow installed: version {PIL.__version__}")
except ImportError:
    print("    ✗ Pillow NOT installed!")
    print("    FIX: pip install pillow")
    sys.exit(1)

# ── 3. Check Poppler ──────────────────────────────────────────
POPPLER_PATH = r"C:\xampp\htdocs\SmartStudyCompanion\poppler\Library\bin"

print(f"\n[3] Checking Poppler at: {POPPLER_PATH}")
if os.path.isdir(POPPLER_PATH):
    print(f"    ✓ Directory exists")
    # List pdftoppm.exe / pdfinfo.exe
    expected = ["pdftoppm.exe", "pdfinfo.exe"]
    for exe in expected:
        full = os.path.join(POPPLER_PATH, exe)
        if os.path.isfile(full):
            print(f"    ✓ Found: {exe}")
        else:
            print(f"    ✗ Missing: {exe}")
            print(f"      FIX: Download Poppler for Windows from:")
            print(f"           https://github.com/oschwartz10612/poppler-windows/releases")
            print(f"           Extract and put the bin/ contents in: {POPPLER_PATH}")
else:
    print(f"    ✗ Directory does NOT exist: {POPPLER_PATH}")
    print(f"    FIX:")
    print(f"      1. Download Poppler for Windows:")
    print(f"         https://github.com/oschwartz10612/poppler-windows/releases")
    print(f"      2. Extract the zip")
    print(f"      3. Copy the 'Library/bin' folder contents to:")
    print(f"         {POPPLER_PATH}")
    print(f"      OR update POPPLER_PATH in extract_pdf.py to the correct location")

# ── 4. Quick conversion test ──────────────────────────────────
pdf_path = sys.argv[1] if len(sys.argv) > 1 else None

if pdf_path:
    print(f"\n[4] Testing PDF conversion: {pdf_path}")
    if not os.path.isfile(pdf_path):
        print(f"    ✗ File not found: {pdf_path}")
    else:
        try:
            from pdf2image import convert_from_path
            pages = convert_from_path(
                pdf_path,
                dpi=72,              # low DPI just for testing — fast
                poppler_path=POPPLER_PATH,
                first_page=1,
                last_page=1,         # only convert page 1
            )
            if pages:
                w, h = pages[0].size
                print(f"    ✓ SUCCESS! Page 1 converted: {w}x{h} pixels")
                print(f"    ✓ pdf2image is working correctly!")
            else:
                print(f"    ✗ convert_from_path returned empty list")
        except Exception as e:
            print(f"    ✗ FAILED: {type(e).__name__}: {e}")
            print(f"\n    This is the exact error your PHP is hiding.")
else:
    print(f"\n[4] PDF conversion test SKIPPED (no PDF path given)")
    print(f"    Run with a PDF to test: python test_pdf2image.py C:\\path\\to\\test.pdf")

print("\n" + "=" * 60)
print("Diagnostic complete.")
print("=" * 60)