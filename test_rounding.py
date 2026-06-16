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
    
    # Try different rounding options at each step
    for dec in [3, 4, 5, 6, 8, None]:
        # 1. Normalize
        N = np.zeros_like(X)
        for j in range(n_crit):
            min_v = np.min(X[:, j])
            max_v = np.max(X[:, j])
            for i in range(n_alt):
                if types[j] == 'benefit':
                    val = min_v / X[i, j]
                else:
                    val = X[i, j] / max_v
                if dec:
                    N[i, j] = round(val, dec)
                else:
                    N[i, j] = val
                    
        # 2. Performance S
        S = np.zeros(n_alt)
        for i in range(n_alt):
            vals = []
            for j in range(n_crit):
                log_val = np.abs(np.log(N[i, j]))
                if dec:
                    vals.append(round(log_val, dec))
                else:
                    vals.append(log_val)
            
            s_val = np.log(1 + (1/n_crit) * np.sum(vals))
            if dec:
                S[i] = round(s_val, dec)
            else:
                S[i] = s_val
                
        # 3. S_prime
        S_prime = np.zeros((n_alt, n_crit))
        for j in range(n_crit):
            for i in range(n_alt):
                vals = []
                for k in range(n_crit):
                    if k != j:
                        log_val = np.abs(np.log(N[i, k]))
                        if dec:
                            vals.append(round(log_val, dec))
                        else:
                            vals.append(log_val)
                s_prime_val = np.log(1 + (1/n_crit) * np.sum(vals))
                if dec:
                    S_prime[i, j] = round(s_prime_val, dec)
                else:
                    S_prime[i, j] = s_prime_val
                    
        # 4. E_j
        E = np.zeros(n_crit)
        for j in range(n_crit):
            diff = np.abs(S_prime[:, j] - S)
            if dec:
                diff = np.round(diff, dec)
            E[j] = np.sum(diff)
            if dec:
                E[j] = round(E[j], dec)
                
        # 5. Weights
        W = E / np.sum(E)
        if dec:
            W = np.round(W, 4)
            
        diff = np.sum(np.abs(W - target))
        print(f"Decimals: {dec}, Diff: {diff:.5f}")
        if diff < 0.005:
            print("MATCH! Weights:", W.tolist())
            return

if __name__ == "__main__":
    test()
