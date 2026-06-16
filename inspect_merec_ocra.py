import pandas as pd
import os
import sys

sys.stdout.reconfigure(encoding='utf-8')

def inspect():
    df = pd.read_csv("MEREC - OCRA.csv", header=None, encoding='utf-8')
    print("Shape:", df.shape)
    for idx, row in df.iterrows():
        print(f"Row {idx:02d}: {list(row.dropna())[:10]}")

if __name__ == "__main__":
    inspect()
