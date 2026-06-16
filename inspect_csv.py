import os
import pandas as pd
import json

def inspect():
    path = "C:\\laragon\www\\SPKTUBES"
    files = ["LOPCOW - MABAC.csv", "LOPCOW - OCRA.csv", "MEREC - MABAC.csv", "MEREC - OCRA.csv"]
    for f in files:
        f_path = os.path.join(path, f)
        print("File:", f)
        try:
            df = pd.read_csv(f_path, header=None)
            print("  Shape:", df.shape)
            print("  First 5 rows:")
            for idx, row in df.iloc[:5].iterrows():
                print(f"    Row {idx}: {list(row[:8])}")
        except Exception as e:
            print("  Error:", e)

if __name__ == "__main__":
    inspect()
