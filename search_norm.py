import PyPDF2
import re
import sys

def search():
    with open("journal.pone.0324538.pdf", "rb") as f:
        reader = PyPDF2.PdfReader(f)
        text = ""
        for page in reader.pages:
            text += page.extract_text() + "\n"
        
    out = []
    # Find formulas of MEREC
    matches = [m.start() for m in re.finditer("normalization", text, re.IGNORECASE)]
    out.append(f"Found {len(matches)} occurrences of normalization.")
    for idx, m in enumerate(matches):
        start = max(0, m - 300)
        end = min(len(text), m + 1000)
        out.append(f"\n--- Occurrence {idx+1} ---")
        out.append(text[start:end])
        
    # Also search for MEREC formulas
    matches_merec = [m.start() for m in re.finditer("MEREC", text)]
    out.append(f"Found {len(matches_merec)} occurrences of MEREC.")
    for idx, m in enumerate(matches_merec):
        start = max(0, m - 300)
        end = min(len(text), m + 1000)
        out.append(f"\n--- MEREC Occurrence {idx+1} ---")
        out.append(text[start:end])

    with open("search_output.txt", "w", encoding="utf-8") as f_out:
        f_out.write("\n".join(out))

if __name__ == "__main__":
    search()
