import sys
import os

try:
    import pdf2image
except ImportError:
    print("FIX: Run in your venv:")
    print("pip install pdf2image pillow")
    sys.exit(1)

try:
    from PIL import Image
    import PIL
except ImportError:
    print("    FIX: pip install pillow")
    sys.exit(1)

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

if POPPLER_PATH and os.path.isdir(POPPLER_PATH):
    print(f"Directory exists")
    expected = ["pdftoppm.exe", "pdfinfo.exe"]
    for exe in expected:
        full = os.path.join(POPPLER_PATH, exe)
        if os.path.isfile(full):
            print(f"Found: {exe}")
        else:
            print(f"Missing: {exe}")

else:
    if POPPLER_PATH is None:
        print("Linux detected.Using system-installed Poppler.")
    else:
        print(f"Directory does NOT exist: {POPPLER_PATH}")

pdf_path = sys.argv[1] if len(sys.argv) > 1 else None

if pdf_path:
    print(f"\n[4] Testing PDF conversion: {pdf_path}")
    if not os.path.isfile(pdf_path):
        print(f"File not found: {pdf_path}")
    else:
        try:
            from pdf2image import convert_from_path
            pages = convert_from_path(
                pdf_path,
                dpi=72,             
                poppler_path=POPPLER_PATH,
                first_page=1,
                last_page=1,         
            )
            if pages:
                w, h = pages[0].size
                print(f"SUCCESS! Page 1 converted: {w}x{h} pixels")
                print(f"pdf2image is working correctly!")
            else:
                print(f"convert_from_path returned empty list")
        except Exception as e:
            print(f"\n    This is the exact error your PHP is hiding.")
else:
    print(f"\n[4] PDF conversion test SKIPPED (no PDF path given)")
    print(f" Run with a PDF to test: python test_pdf2image.py C:\\path\\to\\test.pdf")
