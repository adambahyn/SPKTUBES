import pandas as pd
import numpy as np

def debug():
    # Load LOPCOW - MABAC.csv (Decision Matrix)
    df_dm = pd.read_csv("LOPCOW - MABAC.csv", header=None, encoding='utf-8')
    # Load MEREC - MABAC.csv (Weighted Matrix V and Ranks)
    df_v = pd.read_csv("MEREC - MABAC.csv", header=None, encoding='utf-8')
    
    # Iraq raw data (A1 to A14)
    x = [float(str(val).replace(',', '')) for val in df_dm.iloc[3, 1:15].values]
    # Iraq V values (A1 to A14)
    v = [float(str(val).replace(',', '')) for val in df_v.iloc[3, 1:15].values]
    # Weights
    w = [float(str(val).replace(',', '')) for val in df_v.iloc[2, 1:15].values]
    
    print("Iraq raw X: ", x)
    print("Iraq V:     ", v)
    print("Weights W:  ", w)
    
    # Calculate implied N_ij = V_ij / W_ij - 1
    implied_n = []
    for j in range(14):
        implied_n.append(v[j] / w[j] - 1)
        
    print("\nImplied N for Iraq: ", [round(n, 5) for n in implied_n])
    
    # Min/Max from DM
    mins = [float(str(val).replace(',', '')) for val in df_dm.iloc[19, 1:15].values]
    maxs = [float(str(val).replace(',', '')) for val in df_dm.iloc[20, 1:15].values]
    
    # Calculate MABAC N standard
    types = []
    for j in range(14):
        t_raw = df_dm.iloc[2, j+1]
        if 'Benefit' in str(t_raw):
            types.append('benefit')
        else:
            types.append('cost')
            
    std_n = []
    for j in range(14):
        min_v = mins[j]
        max_v = maxs[j]
        denom = max_v - min_v if max_v != min_v else 1.0
        if types[j] == 'benefit':
            std_n.append((x[j] - min_v) / denom)
        else:
            std_n.append((max_v - x[j]) / denom)
            
    print("Standard MABAC N:   ", [round(n, 5) for n in std_n])
    
    # Difference
    diff = [round(std_n[j] - implied_n[j], 5) for j in range(14)]
    print("Difference (Std - Implied):", diff)

if __name__ == "__main__":
    debug()
