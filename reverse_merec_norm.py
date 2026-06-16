import numpy as np
import pandas as pd

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
    
    target = [0.0970, 0.0790, 0.0100, 0.1140, 0.0830, 0.0580, 0.0190, 0.1000, 0.0650, 0.0120, 0.0570, 0.0719, 0.1010, 0.1340]
    
    # Try normalization:
    # 1. max-min: (x-min)/(max-min) for benefit, (max-x)/(max-min) for cost
    # 2. simple: x/max for benefit, min/x for cost
    # 3. simple2: x/sum for benefit, (1/x)/sum(1/x) for cost
    # 4. vector: x / sqrt(sum(x^2))
    # 5. standard MEREC: min/x for benefit, x/max for cost
    
    norms = []
    
    # maxmin
    N_maxmin = np.zeros_like(X)
    for j in range(n_crit):
        min_v = np.min(X[:, j])
        max_v = np.max(X[:, j])
        if types[j] == 'benefit':
            N_maxmin[:, j] = (X[:, j] - min_v) / (max_v - min_v)
        else:
            N_maxmin[:, j] = (max_v - X[:, j]) / (max_v - min_v)
    norms.append(("maxmin", N_maxmin))
    
    # standard
    N_std = np.zeros_like(X)
    for j in range(n_crit):
        min_v = np.min(X[:, j])
        max_v = np.max(X[:, j])
        if types[j] == 'benefit':
            N_std[:, j] = min_v / X[:, j]
        else:
            N_std[:, j] = X[:, j] / max_v
    norms.append(("standard_merec", N_std))
    
    # simple linear (x/max)
    N_linear = np.zeros_like(X)
    for j in range(n_crit):
        min_v = np.min(X[:, j])
        max_v = np.max(X[:, j])
        if types[j] == 'benefit':
            N_linear[:, j] = X[:, j] / max_v
        else:
            N_linear[:, j] = min_v / X[:, j]
    norms.append(("linear_x_max", N_linear))

    # vector normalization
    N_vec = np.zeros_like(X)
    for j in range(n_crit):
        denom = np.sqrt(np.sum(X[:, j]**2))
        N_vec[:, j] = X[:, j] / denom
    norms.append(("vector_norm", N_vec))

    for norm_name, N in norms:
        # Avoid exact 0 or 1 for log
        N = np.where(N == 0, 1e-6, N)
        N = np.where(N == 1, 1 - 1e-6, N) # if needed
        
        # Test scale
        for scale in [True, False]:
            S = np.zeros(n_alt)
            for i in range(n_alt):
                vals = np.abs(np.log(N[i, :]))
                if scale:
                    S[i] = np.log(1 + (1/n_crit) * np.sum(vals))
                else:
                    S[i] = np.log(1 + np.sum(vals))
                    
            S_prime = np.zeros((n_alt, n_crit))
            for j in range(n_crit):
                for i in range(n_alt):
                    vals = [np.abs(np.log(N[i, k])) for k in range(n_crit) if k != j]
                    if scale:
                        S_prime[i, j] = np.log(1 + (1/n_crit) * np.sum(vals))
                    else:
                        S_prime[i, j] = np.log(1 + np.sum(vals))
                        
            E = np.sum(np.abs(S_prime - S.reshape(-1, 1)), axis=0)
            if np.sum(E) > 0:
                W = E / np.sum(E)
            else:
                continue
                
            diff = np.sum(np.abs(W - target))
            print(f"Norm: {norm_name}, Scale: {scale}, Diff: {diff:.5f}")
            if diff < 0.005:
                print("MATCH! Weights:", [round(w, 4) for w in W])
                return

if __name__ == "__main__":
    test()
