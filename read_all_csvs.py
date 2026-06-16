import os
import pandas as pd

def read_all():
    path = "C:\\laragon\\www\\SPKTUBES"
    files = ["LOPCOW - MABAC.csv", "LOPCOW - OCRA.csv", "MEREC - MABAC.csv", "MEREC - OCRA.csv"]
    for f in files:
        f_path = os.path.join(path, f)
        print("="*40)
        print("FILE:", f)
        if not os.path.exists(f_path):
            print("  Not found!")
            continue
        try:
            df = pd.read_csv(f_path, header=None)
            print("  Shape:", df.shape)
            print("  Row 0:", list(df.iloc[0].dropna())[:5])
            print("  Row 1:", list(df.iloc[1].dropna())[:5])
            print("  Row 2:", list(df.iloc[2].dropna())[:5])
            print("  Row 3:", list(df.iloc[3].dropna())[:5])
            print("  Row 4:", list(df.iloc[4].dropna())[:5])
        except Exception as e:
            print("  Error:", str(e))

if __name__ == "__main__":
    read_all()
