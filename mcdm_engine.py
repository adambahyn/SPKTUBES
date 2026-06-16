import sys
import json
import numpy as np
import pandas as pd
from scipy.stats import spearmanr

def calculate_merec(matrix, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    N = np.zeros_like(X)
    for j in range(n_crit):
        min_val = np.min(X[:, j])
        max_val = np.max(X[:, j])
        if min_val == 0 and types[j].lower() == 'cost': min_val = 1e-6
        if max_val == 0 and types[j].lower() == 'benefit': max_val = 1e-6
        for i in range(n_alt):
            if types[j].lower() == 'benefit':
                N[i, j] = min_val / X[i, j] if X[i,j] != 0 else 0
            else:
                N[i, j] = X[i, j] / max_val if max_val != 0 else 0
    S = np.zeros(n_alt)
    for i in range(n_alt):
        vals = [np.abs(np.log(N[i,j] if N[i,j] > 0 else 1e-6)) for j in range(n_crit)]
        S[i] = np.log(1 + (1/n_crit) * sum(vals))
    S_prime = np.zeros((n_alt, n_crit))
    for j in range(n_crit):
        for i in range(n_alt):
            vals = [np.abs(np.log(N[i,k] if N[i,k] > 0 else 1e-6)) for k in range(n_crit) if k != j]
            S_prime[i, j] = np.log(1 + (1/n_crit) * sum(vals))
    E = np.sum(np.abs(S_prime - S.reshape(-1, 1)), axis=0)
    return (E / np.sum(E)).tolist() if np.sum(E) != 0 else (np.ones(n_crit)/n_crit).tolist()

def calculate_lopcow(matrix, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    P = np.zeros_like(X)
    for j in range(n_crit):
        sigma = np.std(X[:, j], ddof=0)
        mean_val = np.mean(X[:, j])
        if sigma == 0:
            P[:, j] = 0
        else:
            P[:, j] = np.log(1 + np.abs(X[:, j] - mean_val) / sigma)
    E = np.sum(P, axis=0)
    return (E / np.sum(E)).tolist() if np.sum(E) != 0 else (np.ones(n_crit)/n_crit).tolist()

def calculate_mabac(matrix, weights, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    N = np.zeros_like(X)
    for j in range(n_crit):
        max_val = np.max(X[:, j])
        min_val = np.min(X[:, j])
        denom = max_val - min_val if max_val != min_val else 1e-6
        for i in range(n_alt):
            if types[j].lower() == 'benefit':
                N[i, j] = (X[i, j] - min_val) / denom
            else:
                N[i, j] = (max_val - X[i, j]) / denom
    V = np.zeros_like(N)
    for j in range(n_crit):
        V[:, j] = weights[j] * (N[:, j] + 1)
    G = np.prod(V, axis=0) ** (1/n_alt)
    Q = V - G
    S = np.sum(Q, axis=1)
    ranks = rank_scores(S, descending=True)
    return S.tolist(), ranks

def calculate_ocra(matrix, weights, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    I_val = np.zeros(n_alt)
    O_val = np.zeros(n_alt)
    for j in range(n_crit):
        min_val = np.min(X[:, j]) if np.min(X[:, j]) != 0 else 1e-6
        max_val = np.max(X[:, j])
        for i in range(n_alt):
            if types[j].lower() == 'cost':
                I_val[i] += weights[j] * ((max_val - X[i, j]) / min_val)
            else:
                O_val[i] += weights[j] * ((X[i, j] - min_val) / min_val)
    I_bar = I_val - np.min(I_val)
    O_bar = O_val - np.min(O_val)
    P = (I_bar + O_bar) - np.min(I_bar + O_bar)
    ranks = rank_scores(P, descending=True)
    return P.tolist(), ranks

def rank_scores(scores, descending=True):
    arr = np.array(scores)
    order = arr.argsort()
    if descending:
        order = order[::-1]
    ranks = np.empty_like(order)
    ranks[order] = np.arange(1, len(scores) + 1)
    return ranks.tolist()

def main():
    try:
        if len(sys.argv) > 1:
            input_data = json.loads(sys.argv[1])
        else:
            input_data = json.loads(sys.stdin.read())
            
        matrix = input_data['matrix']
        types = input_data['criteria_types']
        alts = input_data.get('alternatives', [f"A{i+1}" for i in range(len(matrix))])
        
        merec_w = calculate_merec(matrix, types)
        lopcow_w = calculate_lopcow(matrix, types)
        
        mabac_merec_scores, mabac_merec_ranks = calculate_mabac(matrix, merec_w, types)
        ocra_merec_scores, ocra_merec_ranks = calculate_ocra(matrix, merec_w, types)
        mabac_lopcow_scores, mabac_lopcow_ranks = calculate_mabac(matrix, lopcow_w, types)
        ocra_lopcow_scores, ocra_lopcow_ranks = calculate_ocra(matrix, lopcow_w, types)
        
        # Consistency Test (Spearman)
        combos = {
            "MEREC-MABAC": mabac_merec_ranks,
            "MEREC-OCRA": ocra_merec_ranks,
            "LOPCOW-MABAC": mabac_lopcow_ranks,
            "LOPCOW-OCRA": ocra_lopcow_ranks
        }
        
        # Criteria Names
        n_crit = len(matrix[0])
        criteria_names = input_data.get('criteria_names', [f"C{i+1}" for i in range(n_crit)])

        # Spearman correlation table for criteria
        spearman_matrix = {}
        X = np.array(matrix, dtype=float)
        for idx1, c1 in enumerate(criteria_names):
            spearman_matrix[c1] = {}
            for idx2, c2 in enumerate(criteria_names):
                corr, _ = spearmanr(X[:, idx1], X[:, idx2])
                spearman_matrix[c1][c2] = float(corr) if not np.isnan(corr) else 0.0
                
        # Separate Spearman correlation for rankings (needed for best combo)
        ranking_spearman = {}
        keys = list(combos.keys())
        for k1 in keys:
            ranking_spearman[k1] = {}
            for k2 in keys:
                corr, _ = spearmanr(combos[k1], combos[k2])
                ranking_spearman[k1][k2] = float(corr) if not np.isnan(corr) else 0.0
        
        # Best Combination Selection
        avg_corrs = {k: np.mean(list(v.values())) for k, v in ranking_spearman.items()}
        best_combo = max(avg_corrs, key=avg_corrs.get)
        
        # Stability Test: Remove first alternative, recalculate ranks
        stab_matrix = matrix[1:]
        stab_merec_w = calculate_merec(stab_matrix, types)
        _, stab_mabac_merec_ranks = calculate_mabac(stab_matrix, stab_merec_w, types)
        corr_stab, _ = spearmanr(mabac_merec_ranks[1:], stab_mabac_merec_ranks)
        stability_score = float(corr_stab) if not np.isnan(corr_stab) else 0.0

        output = {
            "weights": {
                "MEREC": merec_w,
                "LOPCOW": lopcow_w
            },
            "scores": {
                "MEREC-MABAC": mabac_merec_scores,
                "MEREC-OCRA": ocra_merec_scores,
                "LOPCOW-MABAC": mabac_lopcow_scores,
                "LOPCOW-OCRA": ocra_lopcow_scores
            },
            "ranks": combos,
            "spearman_correlation": spearman_matrix,
            "stability_score": stability_score,
            "best_combination": best_combo,
            "alternatives": alts
        }
        
        print(json.dumps(output))
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()
