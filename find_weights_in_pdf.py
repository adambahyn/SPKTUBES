def search():
    with open("search_output.txt", "r", encoding="utf-8") as f:
        text = f.read()
        
    # Search for weight values
    import re
    matches = re.findall(r"0\.\d{3,4}", text)
    print("Found weights in PDF text:")
    for m in list(set(matches))[:30]:
        print(" -", m)

if __name__ == "__main__":
    search()
