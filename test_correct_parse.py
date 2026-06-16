import pandas as pd
import numpy as np

def test():
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
            val_clean = str(val).replace(',', '.')
            vals.append(float(val_clean))
        matrix.append(vals)
        
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    
    # Calculate MEREC
    N = np.zeros_like(X)
    for j in range(n_crit):
        min_val = np.min(X[:, j])
        max_val = np.max(X[:, j])
        for i in range(n_alt):
            if types[j] == 'benefit':
                N[i, j] = min_val / X[i, j]
            else:
                N[i, j] = X[i, j] / max_val
                
    S = np.zeros(n_alt)
    for i in range(n_alt):
        vals = [np.abs(np.log(N[i, j])) for j in range(n_crit)]
        S[i] = np.log(1 + (1/n_crit) * sum(vals))
        
    S_prime = np.zeros((n_alt, n_crit))
    for j in range(n_crit):
        for i in range(n_alt):
            vals = [np.abs(np.log(N[i, k])) for k in range(n_crit) if k != j]
            S_prime[i, j] = np.log(1 + (1/n_crit) * sum(vals))
            
    E = np.sum(np.abs(S_prime - S.reshape(-1, 1)), axis=0)
    W = E / np.sum(E)
    
    target = [0.0970, 0.0790, 0.0100, 0.1140, 0.0830, 0.0580, 0.0190, 0.1000, 0.0650, 0.0120, 0.0570, 0.0719, 0.1010, 0.1340]
    
    print("Calculated MEREC:")
    print([round(w, 4) for w in W])
    print("Target MEREC:")
    print(target)
    
    # Check LOPCOW
    P = np.zeros_like(X)
    for j in range(n_crit):
        mean_val = np.mean(X[:, j])
        sigma = np.std(X[:, j], ddof=0)
        P[:, j] = np.log(1 + np.abs(X[:, j] - mean_val) / sigma)
    E_lop = np.sum(P, axis=0)
    W_lop = E_lop / np.sum(E_lop)
    
    # Read LOPCOW weights from LOPCOW - OCRA.csv or similar if there
    print("\nCalculated LOPCOW:")
    print([round(w, 4) for w in W_lop])

if __name__ == "__main__":
    test()
