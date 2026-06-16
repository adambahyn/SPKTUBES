import os
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
    w = (E / np.sum(E)).tolist() if np.sum(E) != 0 else (np.ones(n_crit)/n_crit).tolist()
    return {
        "weights": w,
        "N": N.tolist(),
        "S": S.tolist(),
        "S_prime": S_prime.tolist(),
        "E": E.tolist()
    }

def calculate_lopcow(matrix, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    P = np.zeros_like(X)
    sigmas = []
    means = []
    for j in range(n_crit):
        sigma = np.std(X[:, j], ddof=0)
        mean_val = np.mean(X[:, j])
        sigmas.append(float(sigma))
        means.append(float(mean_val))
        if sigma == 0:
            P[:, j] = 0
        else:
            P[:, j] = np.log(1 + np.abs(X[:, j] - mean_val) / sigma)
    E = np.sum(P, axis=0)
    w = (E / np.sum(E)).tolist() if np.sum(E) != 0 else (np.ones(n_crit)/n_crit).tolist()
    return {
        "weights": w,
        "P": P.tolist(),
        "sigmas": sigmas,
        "means": means,
        "E": E.tolist()
    }

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
    # Using arithmetic mean for BAA to match Excel's simpler sheet computation
    G = np.mean(V, axis=0) 
    Q = V - G
    S = np.sum(Q, axis=1)
    ranks = rank_scores(S, descending=True)
    return {
        "scores": S.tolist(),
        "ranks": ranks,
        "N": N.tolist(),
        "V": V.tolist(),
        "G": G.tolist(),
        "Q": Q.tolist()
    }

def calculate_ocra(matrix, weights, types):
    X = np.array(matrix, dtype=float)
    n_alt, n_crit = X.shape
    I_val = np.zeros(n_alt)
    O_val = np.zeros(n_alt)
    I_details = np.zeros_like(X)
    O_details = np.zeros_like(X)
    for j in range(n_crit):
        min_val = np.min(X[:, j]) if np.min(X[:, j]) != 0 else 1e-6
        max_val = np.max(X[:, j])
        for i in range(n_alt):
            if types[j].lower() == 'cost':
                term = weights[j] * ((max_val - X[i, j]) / min_val)
                I_val[i] += term
                I_details[i, j] = term
            else:
                term = weights[j] * ((X[i, j] - min_val) / min_val)
                O_val[i] += term
                O_details[i, j] = term
    I_bar = I_val - np.min(I_val)
    O_bar = O_val - np.min(O_val)
    P = (I_bar + O_bar) - np.min(I_bar + O_bar)
    ranks = rank_scores(P, descending=True)
    return {
        "scores": P.tolist(),
        "ranks": ranks,
        "I_val": I_val.tolist(),
        "O_val": O_val.tolist(),
        "I_bar": I_bar.tolist(),
        "O_bar": O_bar.tolist(),
        "I_details": I_details.tolist(),
        "O_details": O_details.tolist()
    }

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
        # Check if stdin has JSON configuration when running in simulation mode
        input_data = {}
        if len(sys.argv) > 1 and sys.argv[1] == "--simulation":
            try:
                stdin_text = sys.stdin.read().strip()
                if stdin_text:
                    input_data = json.loads(stdin_text)
            except Exception:
                pass
                
        disabled_criteria = input_data.get("disabled_criteria", []) # List of criterion names, e.g. ["A3"]
        custom_types = input_data.get("criteria_types", {}) # Dict: {"A1": "benefit", "A4": "cost"}
        
        path = "C:\\laragon\\www\\SPKTUBES"
        
        # 1. Parse Decision Matrix from LOPCOW - MABAC.csv
        lopcow_mabac_path = os.path.join(path, "LOPCOW - MABAC.csv")
        df_matrix = pd.read_csv(lopcow_mabac_path, header=None, encoding='utf-8')
        
        criteria_names_raw = list(df_matrix.iloc[1, 1:15].dropna().values)
        types_raw = list(df_matrix.iloc[2, 1:15].dropna().values)
        
        # Build raw alternatives and matrix
        alternatives = []
        matrix_raw = []
        for i in range(3, 19):
            alt = df_matrix.iloc[i, 0]
            alternatives.append(alt)
            vals = []
            for val in df_matrix.iloc[i, 1:15].values:
                val_clean = str(val).replace(',', '')
                vals.append(float(val_clean))
            matrix_raw.append(vals)
            
        # Standard types
        types_map = {}
        for idx, name in enumerate(criteria_names_raw):
            t_raw = types_raw[idx]
            if 'Benefit' in str(t_raw):
                types_map[name] = 'benefit'
            else:
                types_map[name] = 'cost'
                
        # Override types if customized
        for name, t_custom in custom_types.items():
            if name in types_map:
                types_map[name] = t_custom.lower()
                
        # Filter active criteria
        active_indices = []
        criteria_names = []
        types = []
        for idx, name in enumerate(criteria_names_raw):
            if name not in disabled_criteria:
                active_indices.append(idx)
                criteria_names.append(name)
                types.append(types_map[name])
                
        # Reconstruct matrix with active criteria only
        matrix = []
        for row in matrix_raw:
            matrix.append([row[idx] for idx in active_indices])
            
        X = np.array(matrix, dtype=float)
        
        # 2. Get MEREC & LOPCOW calculations (detailed)
        merec_details = calculate_merec(matrix, types)
        lopcow_details = calculate_lopcow(matrix, types)
        
        # Determine baseline weights
        is_simulation = len(disabled_criteria) > 0 or len(custom_types) > 0
        
        if not is_simulation:
            # Baseline mode: load from MEREC - MABAC.csv
            merec_mabac_path = os.path.join(path, "MEREC - MABAC.csv")
            df_merec_mabac = pd.read_csv(merec_mabac_path, header=None, encoding='utf-8')
            merec_w = []
            for val in df_merec_mabac.iloc[2, 1:15].values:
                val_clean = str(val).replace(',', '.')
                merec_w.append(float(val_clean))
        else:
            merec_w = merec_details["weights"]
            
        lopcow_w = lopcow_details["weights"]
        
        # 3. Calculate dynamic MABAC & OCRA rankings for the active weights
        mabac_merec_details = calculate_mabac(matrix, merec_w, types)
        ocra_merec_details = calculate_ocra(matrix, merec_w, types)
        mabac_lopcow_details = calculate_mabac(matrix, lopcow_w, types)
        ocra_lopcow_details = calculate_ocra(matrix, lopcow_w, types)
        
        # Descriptive weight stats
        weight_stats = {
            "MEREC": {
                "min": float(np.min(merec_w)),
                "max": float(np.max(merec_w)),
                "sd": float(np.std(merec_w)),
                "entropy": float(-np.sum([w * np.log(w) for w in merec_w if w > 0]))
            },
            "LOPCOW": {
                "min": float(np.min(lopcow_w)),
                "max": float(np.max(lopcow_w)),
                "sd": float(np.std(lopcow_w)),
                "entropy": float(-np.sum([w * np.log(w) for w in lopcow_w if w > 0]))
            }
        }
        
        # 4. Load baseline rankings or calculate simulation rankings
        merec_ocra_path = os.path.join(path, "MEREC - OCRA.csv")
        df_ranks = pd.read_csv(merec_ocra_path, header=None, encoding='utf-8')
        
        if not is_simulation:
            # Baseline mode: use rankings from Excel
            ranks_dict = {
                "MEREC-OCRA": [int(x) for x in df_ranks.iloc[3:19, 0].values],
                "Rank Jurnal": [int(x) for x in df_ranks.iloc[3:19, 1].values],
                "LOPCOW-MABAC": [int(x) for x in df_ranks.iloc[3:19, 2].values],
                "MEREC-MABAC": [int(x) for x in df_ranks.iloc[3:19, 3].values],
                "LOPCOW-OCRA": [int(x) for x in df_ranks.iloc[3:19, 4].values],
                "BORDA": [int(x) for x in df_ranks.iloc[3:19, 5].values],
            }
        else:
            # Simulation mode: use recalculated rankings
            sim_ranks = [
                mabac_merec_details["ranks"],
                ocra_merec_details["ranks"],
                mabac_lopcow_details["ranks"],
                ocra_lopcow_details["ranks"]
            ]
            n_alts = len(alternatives)
            borda_scores = np.zeros(n_alts)
            for r in sim_ranks:
                for idx, val in enumerate(r):
                    borda_scores[idx] += (n_alts - val)
            borda_order = borda_scores.argsort()[::-1]
            borda_ranks = np.empty_like(borda_order)
            borda_ranks[borda_order] = np.arange(1, n_alts + 1)
            
            ranks_dict = {
                "MEREC-OCRA": ocra_merec_details["ranks"],
                "Rank Jurnal": [int(x) for x in df_ranks.iloc[3:19, 1].values],
                "LOPCOW-MABAC": mabac_lopcow_details["ranks"],
                "MEREC-MABAC": mabac_merec_details["ranks"],
                "LOPCOW-OCRA": ocra_lopcow_details["ranks"],
                "BORDA": borda_ranks.tolist(),
            }
            
        # Spearman correlation table
        spearman_results = {}
        for k1, v1 in ranks_dict.items():
            spearman_results[k1] = {}
            for k2, v2 in ranks_dict.items():
                corr, p_val = spearmanr(v1, v2)
                spearman_results[k1][k2] = {
                    "coefficient": float(corr) if not np.isnan(corr) else 0.0,
                    "p_value": float(p_val) if not np.isnan(p_val) else 0.0
                }
                
        # 5. Stability Test (Drop-One Alternative)
        stability_indices = {
            "MEREC-MABAC": [],
            "MEREC-OCRA": [],
            "LOPCOW-MABAC": [],
            "LOPCOW-OCRA": []
        }
        
        for k in range(len(alternatives)):
            sub_matrix = np.delete(X, k, axis=0).tolist()
            sub_alts = alternatives[:k] + alternatives[k+1:]
            
            sub_merec = calculate_merec(sub_matrix, types)
            sub_lopcow = calculate_lopcow(sub_matrix, types)
            
            sub_merec_w = sub_merec["weights"]
            sub_lopcow_w = sub_lopcow["weights"]
            
            sub_mabac_merec = calculate_mabac(sub_matrix, sub_merec_w, types)
            sub_ocra_merec = calculate_ocra(sub_matrix, sub_merec_w, types)
            sub_mabac_lopcow = calculate_mabac(sub_matrix, sub_lopcow_w, types)
            sub_ocra_lopcow = calculate_ocra(sub_matrix, sub_lopcow_w, types)
            
            def get_sub_ranks(method_key):
                orig = ranks_dict[method_key][:k] + ranks_dict[method_key][k+1:]
                return rank_scores(orig, descending=False)
                
            c_mm, _ = spearmanr(get_sub_ranks("MEREC-MABAC"), sub_mabac_merec["ranks"])
            c_mo, _ = spearmanr(get_sub_ranks("MEREC-OCRA"), sub_ocra_merec["ranks"])
            c_lm, _ = spearmanr(get_sub_ranks("LOPCOW-MABAC"), sub_mabac_lopcow["ranks"])
            c_lo, _ = spearmanr(get_sub_ranks("LOPCOW-OCRA"), sub_ocra_lopcow["ranks"])
            
            stability_indices["MEREC-MABAC"].append(float(c_mm) if not np.isnan(c_mm) else 1.0)
            stability_indices["MEREC-OCRA"].append(float(c_mo) if not np.isnan(c_mo) else 1.0)
            stability_indices["LOPCOW-MABAC"].append(float(c_lm) if not np.isnan(c_lm) else 1.0)
            stability_indices["LOPCOW-OCRA"].append(float(c_lo) if not np.isnan(c_lo) else 1.0)
            
        stability_summary = {k: float(np.mean(v)) for k, v in stability_indices.items()}
        
        # 6. Sensitivity Analysis: Perturb the top criterion of MEREC and LOPCOW
        merec_top_idx = int(np.argmax(merec_w))
        lopcow_top_idx = int(np.argmax(lopcow_w))
        
        perturbations = [-0.20, -0.10, 0.0, 0.10, 0.20]
        sensitivity_results = {
            "MEREC-MABAC": [],
            "MEREC-OCRA": [],
            "LOPCOW-MABAC": [],
            "LOPCOW-OCRA": []
        }
        
        # Perturb MEREC
        base_merec_mabac = ranks_dict["MEREC-MABAC"]
        base_merec_ocra = ranks_dict["MEREC-OCRA"]
        for p in perturbations:
            w_new = np.array(merec_w)
            w_new[merec_top_idx] = max(1e-6, w_new[merec_top_idx] * (1 + p))
            sum_others = np.sum(w_new) - w_new[merec_top_idx]
            if sum_others > 0:
                scale = (1.0 - w_new[merec_top_idx]) / sum_others
                for idx in range(len(w_new)):
                    if idx != merec_top_idx:
                        w_new[idx] *= scale
            w_new = w_new / np.sum(w_new)
            
            r_mm = calculate_mabac(matrix, w_new.tolist(), types)["ranks"]
            r_mo = calculate_ocra(matrix, w_new.tolist(), types)["ranks"]
            
            c_mm, _ = spearmanr(base_merec_mabac, r_mm)
            c_mo, _ = spearmanr(base_merec_ocra, r_mo)
            sensitivity_results["MEREC-MABAC"].append(float(c_mm))
            sensitivity_results["MEREC-OCRA"].append(float(c_mo))
            
        # Perturb LOPCOW
        base_lopcow_mabac = ranks_dict["LOPCOW-MABAC"]
        base_lopcow_ocra = ranks_dict["LOPCOW-OCRA"]
        for p in perturbations:
            w_new = np.array(lopcow_w)
            w_new[lopcow_top_idx] = max(1e-6, w_new[lopcow_top_idx] * (1 + p))
            sum_others = np.sum(w_new) - w_new[lopcow_top_idx]
            if sum_others > 0:
                scale = (1.0 - w_new[lopcow_top_idx]) / sum_others
                for idx in range(len(w_new)):
                    if idx != lopcow_top_idx:
                        w_new[idx] *= scale
            w_new = w_new / np.sum(w_new)
            
            r_lm = calculate_mabac(matrix, w_new.tolist(), types)["ranks"]
            r_lo = calculate_ocra(matrix, w_new.tolist(), types)["ranks"]
            
            c_lm, _ = spearmanr(base_lopcow_mabac, r_lm)
            c_lo, _ = spearmanr(base_lopcow_ocra, r_lo)
            sensitivity_results["LOPCOW-MABAC"].append(float(c_lm))
            sensitivity_results["LOPCOW-OCRA"].append(float(c_lo))
            
        # Best combination evaluation
        score_eval = {}
        for combo in ["MEREC-MABAC", "MEREC-OCRA", "LOPCOW-MABAC", "LOPCOW-OCRA"]:
            avg_corr_with_jurnal = spearman_results[combo]["Rank Jurnal"]["coefficient"]
            stab = stability_summary[combo]
            score_eval[combo] = 0.5 * avg_corr_with_jurnal + 0.5 * stab
        best_combo = max(score_eval, key=score_eval.get)
        
        output = {
            "criteria_names": criteria_names,
            "criteria_types": types,
            "all_criteria_names_raw": criteria_names_raw,
            "disabled_criteria": disabled_criteria,
            "alternatives": alternatives,
            "decision_matrix": matrix,
            "weights": {
                "MEREC": merec_w,
                "LOPCOW": lopcow_w
            },
            "weight_statistics": weight_stats,
            "ranks": ranks_dict,
            "spearman_correlation": spearman_results,
            "stability_scores": stability_summary,
            "stability_raw": stability_indices,
            "sensitivity_results": sensitivity_results,
            "sensitivity_perturbations": perturbations,
            "best_combination": best_combo,
            "is_simulation": is_simulation,
            "intermediates": {
                "N_merec": merec_details["N"],
                "S_merec": merec_details["S"],
                "S_prime_merec": merec_details["S_prime"],
                "E_merec": merec_details["E"],
                "P_lopcow": lopcow_details["P"],
                "sigmas_lopcow": lopcow_details["sigmas"],
                "means_lopcow": lopcow_details["means"],
                "E_lopcow": lopcow_details["E"],
                "mabac_merec": {
                    "N": mabac_merec_details["N"],
                    "V": mabac_merec_details["V"],
                    "G": mabac_merec_details["G"],
                    "Q": mabac_merec_details["Q"],
                    "scores": mabac_merec_details["scores"]
                },
                "ocra_merec": {
                    "I_val": ocra_merec_details["I_val"],
                    "O_val": ocra_merec_details["O_val"],
                    "I_bar": ocra_merec_details["I_bar"],
                    "O_bar": ocra_merec_details["O_bar"],
                    "I_details": ocra_merec_details["I_details"],
                    "O_details": ocra_merec_details["O_details"],
                    "scores": ocra_merec_details["scores"]
                },
                "mabac_lopcow": {
                    "N": mabac_lopcow_details["N"],
                    "V": mabac_lopcow_details["V"],
                    "G": mabac_lopcow_details["G"],
                    "Q": mabac_lopcow_details["Q"],
                    "scores": mabac_lopcow_details["scores"]
                },
                "ocra_lopcow": {
                    "I_val": ocra_lopcow_details["I_val"],
                    "O_val": ocra_lopcow_details["O_val"],
                    "I_bar": ocra_lopcow_details["I_bar"],
                    "O_bar": ocra_lopcow_details["O_bar"],
                    "I_details": ocra_lopcow_details["I_details"],
                    "O_details": ocra_lopcow_details["O_details"],
                    "scores": ocra_lopcow_details["scores"]
                }
            }
        }
        print(json.dumps(output))
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()
