import re

def search():
    with open("extracted.txt", "r", encoding="utf-8") as f:
        text = f.read()
        
    # Find MABAC
    matches = [m.start() for m in re.finditer("MABAC", text, re.IGNORECASE)]
    print(f"Found {len(matches)} occurrences of MABAC.")
    for idx, m in enumerate(matches[:5]):
        start = max(0, m - 300)
        end = min(len(text), m + 1000)
        print(f"\n--- Occurrence {idx+1} ---")
        print(text[start:end])

if __name__ == "__main__":
    search()
