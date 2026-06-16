import re

def find():
    with open("search_output.txt", "r", encoding="utf-8") as f:
        text = f.read()
        
    steps = [
        "Step 2: Normalization of the decision matrix.",
        "Step 3: Calculation of the initial performance score.",
        "Step 4: Performance evaluation with eliminated criteria.",
        "Step 5: Overall performance", # try substring
        "Step 6: Normalization of criterion weights."
    ]
    
    out = []
    for s in steps:
        pos = text.find(s)
        if pos != -1:
            out.append(f"\n=== {s} ===")
            out.append(text[pos:pos+1500])
        else:
            # try finding via regex
            matches = [m.start() for m in re.finditer(re.escape(s[:15]), text)]
            if matches:
                out.append(f"\n=== Regex Match for: {s} ===")
                out.append(text[matches[0]:matches[0]+1500])
            else:
                out.append(f"Not found: {s}")

    with open("formulas_output.txt", "w", encoding="utf-8") as f_out:
        f_out.write("\n".join(out))

if __name__ == "__main__":
    find()
