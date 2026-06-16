import numpy as np
import pandas as pd

def test_variants():
    # Read LOPCOW - MABAC.csv
    df = pd.read_csv("LOPCOW - MABAC.csv", header=None, encoding='utf-8')
    
    types_raw = list(df.iloc[2, 1:15].dropna().values)
    types = []
    for t in types_raw:
        if 'Benefit' in str(t):
            types.append('benefit')
        else:
            types.append('cost')
            
    matrix = []
    for i in range(3, 19):
        vals = []
        for val in df.iloc[i, 1:15].values:
            val_clean = str(val).replace(',', '')
            vals.append(float(val_clean))
        matrix.append(vals)
        
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    
    # Target weights
    target = [0.0970, 0.0790, 0.0100, 0.1140, 0.0830, 0.0580, 0.0190, 0.1000, 0.0650, 0.0120, 0.0570, 0.0719, 0.1010, 0.1340]
    
    # Try different normalization combinations
    # Variant A: Standard MEREC (Benefit: min/x, Cost: x/max)
    # Variant B: Opposite MEREC (Benefit: x/max, Cost: min/x)
    # Variant C: Max-Min Normalization (like LOPCOW)
    
    normalizations = ["standard", "opposite", "maxmin"]
    scaling_methods = ["div_m", "div_m_minus_1", "no_div"]
    
    for norm in normalizations:
        for scale in scaling_methods:
            # 1. Normalize
            N = np.zeros_like(X)
            for j in range(n_crit):
                min_val = np.min(X[:, j])
                max_val = np.max(X[:, j])
                
                if norm == "standard":
                    if types[j] == 'benefit':
                        N[:, j] = min_val / X[:, j]
                    else:
                        N[:, j] = X[:, j] / max_val
                elif norm == "opposite":
                    if types[j] == 'benefit':
                        N[:, j] = X[:, j] / max_val
                    else:
                        N[:, j] = min_val / X[:, j]
                elif norm == "maxmin":
                    if types[j] == 'benefit':
                        N[:, j] = (X[:, j] - min_val) / (max_val - min_val)
                    else:
                        N[:, j] = (max_val - X[:, j]) / (max_val - min_val)
            
            # Avoid log of 0
            N = np.where(N == 0, 1e-9, N)
            
            # 2. Performance S
            S = np.zeros(n_alt)
            for i in range(n_alt):
                vals = np.abs(np.log(N[i, :]))
                if scale == "div_m":
                    S[i] = np.log(1 + (1/n_crit) * np.sum(vals))
                elif scale == "div_m_minus_1":
                    S[i] = np.log(1 + (1/(n_crit-1)) * np.sum(vals))
                else:
                    S[i] = np.log(1 + np.sum(vals))
                    
            # 3. Performance S_prime
            S_prime = np.zeros((n_alt, n_crit))
            for j in range(n_crit):
                for i in range(n_alt):
                    vals = [np.abs(np.log(N[i, k])) for k in range(n_crit) if k != j]
                    if scale == "div_m":
                        S_prime[i, j] = np.log(1 + (1/n_crit) * np.sum(vals))
                    elif scale == "div_m_minus_1":
                        S_prime[i, j] = np.log(1 + (1/(n_crit-1)) * np.sum(vals))
                    else:
                        S_prime[i, j] = np.log(1 + np.sum(vals))
                        
            # 4. Removal effect E
            E = np.sum(np.abs(S_prime - S.reshape(-1, 1)), axis=0)
            
            # 5. Weights
            if np.sum(E) > 0:
                W = E / np.sum(E)
            else:
                W = np.zeros(n_crit)
                
            # Compare with target
            diff = np.sum(np.abs(W - target))
            if diff < 0.05:
                print(f"MATCH FOUND! Norm: {norm}, Scale: {scale}, Diff: {diff:.5f}")
                print("Calculated Weights:", [round(w, 4) for w in W])
                print("Target Weights:    ", target)
                return
            else:
                pass
                
    print("No close match found.")

if __name__ == "__main__":
    test_variants()
