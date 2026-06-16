import numpy as np
import pandas as pd

def test():
    # Read Decision Matrix
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
    
    # Try different combinations:
    # 1. log bases: natural ln vs log10
    # 2. division in S_i: 1/n_crit, 1/(n_crit-1), or no division
    # 3. division in S_prime: 1/n_crit, 1/(n_crit-1), or no division
    # 4. normalization: standard (min/x for benefit, x/max for cost), standard but benefit/cost swapped
    # 5. absolute value: with or without ABS in E_j or inside ln
    
    import itertools
    log_funcs = [("ln", np.log), ("log10", np.log10)]
    div_s = [("div_m", lambda s, m: s/m), ("div_m_1", lambda s, m: s/(m-1)), ("no_div", lambda s, m: s)]
    div_sp = [("div_m", lambda s, m: s/m), ("div_m_1", lambda s, m: s/(m-1)), ("no_div", lambda s, m: s)]
    abs_options = [("with_abs", lambda x: np.abs(x)), ("no_abs", lambda x: x)]
    
    for (log_name, log_fn), (ds_name, ds_fn), (dsp_name, dsp_fn), (abs_name, abs_fn) in itertools.product(log_funcs, div_s, div_sp, abs_options):
        # Normalize
        N = np.zeros_like(X)
        for j in range(n_crit):
            min_v = np.min(X[:, j])
            max_v = np.max(X[:, j])
            if types[j] == 'benefit':
                N[:, j] = min_v / X[:, j]
            else:
                N[:, j] = X[:, j] / max_v
        
        # Avoid log(0)
        N = np.where(N == 0, 1e-9, N)
        
        # S_i
        S = np.zeros(n_alt)
        for i in range(n_alt):
            term = np.sum(abs_fn(log_fn(N[i, :])))
            S[i] = log_fn(1 + ds_fn(term, n_crit))
            
        # S_prime
        S_prime = np.zeros((n_alt, n_crit))
        for j in range(n_crit):
            for i in range(n_alt):
                term = np.sum([abs_fn(log_fn(N[i, k])) for k in range(n_crit) if k != j])
                S_prime[i, j] = log_fn(1 + dsp_fn(term, n_crit))
                
        # E_j
        E = np.sum(np.abs(S_prime - S.reshape(-1, 1)), axis=0)
        
        if np.sum(E) > 0:
            W = E / np.sum(E)
        else:
            continue
            
        diff = np.sum(np.abs(W - target))
        if diff < 0.01:
            print(f"EXACT MATCH! Log: {log_name}, S_div: {ds_name}, S_prime_div: {dsp_name}, Abs: {abs_name}, Diff: {diff:.5f}")
            print("Calculated Weights:", [round(w, 4) for w in W])
            return

    # What if they normalized MABAC-style or LOPCOW-style first?
    # What if they used standard Normalization: x/sum(x) or (x-min)/(max-min)?
    print("No exact match found in basic search.")

if __name__ == "__main__":
    test()
