#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Analisis FAR/FRR/EER sistem verifikasi wajah MobileFaceNet.
Data impostor: 1.485 pasangan lintas-user asli (embedding production, server 21 Sep 2026).
Data genuine: opsional CSV (kolom face_distance) dari attendance_logs bila sudah dikumpulkan.
Output: tabel sweep threshold, EER, dan rekomendasi theta.
"""
import csv
import math
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

IMPOSTOR_FILE = Path(__file__).parent / "impostor_distances_20260921.txt"
GENUINE_FILE = Path(__file__).parent / "genuine_distances.csv"  # opsional

def load_impostor():
    vals = []
    for line in IMPOSTOR_FILE.read_text().splitlines():
        line = line.strip()
        if line:
            vals.append(float(line))
    return sorted(vals)

def load_genuine():
    if not GENUINE_FILE.exists():
        return None
    vals = []
    with GENUINE_FILE.open() as f:
        reader = csv.DictReader(f)
        col = "face_distance" if "face_distance" in (reader.fieldnames or []) else None
        for row in reader:
            if col and row[col]:
                vals.append(float(row[col]))
    return sorted(vals) if vals else None

def far_at(distances, theta):
    accepted = sum(1 for d in distances if d <= theta)
    return accepted / len(distances), accepted

def frr_at(distances, theta):
    rejected = sum(1 for d in distances if d > theta)
    return rejected / len(distances), rejected

def main():
    imp = load_impostor()
    gen = load_genuine()
    print(f"N impostor pairs : {len(imp)}")
    print(f"N genuine scores : {len(gen) if gen is not None else 0} {'(FRR belum bisa dihitung)' if gen is None else ''}")
    print(f"Impostor min/mean/max: {imp[0]:.4f} / {sum(imp)/len(imp):.4f} / {imp[-1]:.4f}")
    if gen:
        print(f"Genuine  min/mean/max: {gen[0]:.4f} / {sum(gen)/len(gen):.4f} / {gen[-1]:.4f}")
    print()
    print("theta |  FAR      | FRR      | (FAR count / FRR count)")
    print("------+-----------+----------+------------------------")
    best = None
    for i in range(6, 25):  # 0.30 .. 1.20 step 0.05
        theta = i * 0.05
        far, fc = far_at(imp, theta)
        if gen is not None:
            frr, rc = frr_at(gen, theta)
            diff = abs(far - frr)
            if best is None or diff < best[3]:
                best = (theta, far, frr, diff)
            print(f"{theta:.2f} | {far*100:8.4f}% | {frr*100:7.4f}% | {fc:5d} / {rc:5d}")
        else:
            print(f"{theta:.2f} | {far*100:8.4f}% |     n/a  | {fc:5d}")
        if i == 12:  # theta 0.60 aktif
            pass
    # highlight theta aktif
    far6, fc6 = far_at(imp, 0.60)
    print()
    print(f"Threshold aktif 0.600 -> FAR = {far6*100:.4f}% ({fc6}/{len(imp)})")
    if gen is not None:
        frr6, rc6 = frr_at(gen, 0.60)
        print(f"Threshold aktif 0.600 -> FRR = {frr6*100:.4f}% ({rc6}/{len(gen)})")
        if best:
            print(f"EER ~ theta {best[0]:.2f} (FAR={best[1]*100:.2f}%, FRR={best[2]*100:.2f}%)")
    # distribusi kumulatif impostor untuk kurva ROC sisi FAR
    print()
    print("Kurva FAR (untuk gambar paper):")
    for i in range(6, 49):  # 0.30 .. 2.40 step 0.05
        theta = i * 0.05
        far, _ = far_at(imp, theta)
        print(f"  {theta:.2f},{far*100:.4f}")

if __name__ == "__main__":
    main()
