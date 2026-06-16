import PyPDF2
import re

def search():
    with open("journal.pone.0324538.pdf", "rb") as f:
        reader = PyPDF2.PdfReader(f)
        text = ""
        for page in reader.pages:
            text += page.extract_text() + "\n"
            
    # Search for all strings matching weight formats
    # Let's search for "Table" and "weight"
    matches = [m.start() for m in re.finditer(r"weight", text, re.IGNORECASE)]
    print(f"Found {len(matches)} occurrences of 'weight'.")
    out = []
    for m in matches:
        start = max(0, m - 200)
        end = min(len(text), m + 800)
        segment = text[start:end]
        if "0." in segment:
            out.append(segment)
            
    with open("pdf_weights_search.txt", "w", encoding="utf-8") as f_out:
        f_out.write("\n\n=== SEGMENT ===\n\n".join(out))

if __name__ == "__main__":
    search()
