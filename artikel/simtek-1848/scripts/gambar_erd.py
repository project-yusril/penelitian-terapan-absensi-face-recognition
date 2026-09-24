"""ERD skema terbaru dengan posisi grid manual (neato -n2, splines=ortho)."""
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
GAMBAR = HERE.parent / 'gambar'
# butuh Graphviz: set GRAPHVIZ_BIN ke folder bin Graphviz, atau pastikan 'neato' ada di PATH
GV = os.environ.get('GRAPHVIZ_BIN', '')
NEATO = os.path.join(GV, 'neato') if GV else (shutil.which('neato') or 'neato')
C = {'A': '#2f5d8a', 'B': '#3d7a3a', 'C': '#7a3f86', 'D': '#9a6b12'}
E = {
    'prodis': ('A', ['PK id', 'kode, nama']),
    'users': ('A', ['PK id', 'FK prodi_id', 'nama, nim/nidn', 'role (user_roles)', 'status']),
    'face_embeddings': ('A', ['PK id', 'FK user_id', 'embedding_ciphertext', 'status']),
    'semesters': ('B', ['PK id', 'FK tahun_ajaran_id', 'nama, status']),
    'kelas': ('B', ['PK id', 'FK prodi_id', 'FK semester_id', 'tingkat, nama']),
    'mahasiswa_kelas': ('B', ['PK id', 'FK user_id', 'FK kelas_id', 'FK semester_id']),
    'mata_kuliahs': ('B', ['PK id', 'FK semester_id', 'FK prodi_id', 'kode_mk, nama, sks']),
    'geofences': ('B', ['PK id', 'FK prodi_id', 'latitude, longitude', 'radius']),
    'jadwals': ('B', ['PK id', 'FK mata_kuliah_id', 'FK kelas_id', 'FK dosen_id', 'FK geofence_id', 'hari, jam_mulai, jam_selesai']),
    'attendance_permits': ('C', ['PK id', 'FK user_id', 'FK jadwal_id', 'FK attendance_id', 'token_hash, action', 'capture_expires_at, consumed_at']),
    'attendances': ('C', ['PK id', 'FK user_id', 'FK jadwal_id', 'FK mata_kuliah_id', 'tanggal, status', 'checkin_time, checkout_time']),
    'attendance_logs': ('C', ['PK id', 'FK attendance_id', 'FK user_id', 'action, face_distance', 'gps_accuracy']),
    'alpha_accumulations': ('D', ['PK id', 'FK user_id', 'FK semester_id', 'total_alpha_jam, sp_status']),
    'sp_records': ('D', ['PK id', 'FK user_id', 'FK semester_id', 'sp_level, status']),
}
R = [('prodis', 'users'), ('users', 'face_embeddings'), ('prodis', 'kelas'), ('semesters', 'kelas'),
     ('users', 'mahasiswa_kelas'), ('kelas', 'mahasiswa_kelas'), ('semesters', 'mahasiswa_kelas'),
     ('semesters', 'mata_kuliahs'), ('prodis', 'mata_kuliahs'), ('prodis', 'geofences'),
     ('mata_kuliahs', 'jadwals'), ('kelas', 'jadwals'), ('users', 'jadwals'), ('geofences', 'jadwals'),
     ('users', 'attendance_permits'), ('jadwals', 'attendance_permits'), ('attendances', 'attendance_permits'),
     ('users', 'attendances'), ('jadwals', 'attendances'), ('mata_kuliahs', 'attendances'),
     ('attendances', 'attendance_logs'), ('users', 'attendance_logs'),
     ('users', 'alpha_accumulations'), ('semesters', 'alpha_accumulations'),
     ('users', 'sp_records'), ('semesters', 'sp_records')]
# grid: kolom (inci, pusat), baris (inci, pusat; y ke atas)
COLX = [0.9, 2.75, 4.75, 6.85, 8.95]
ROWY = [3.75, 2.1, 0.55]
POS = {
    'semesters': (0, 0), 'mata_kuliahs': (1, 0), 'jadwals': (2, 0), 'attendance_permits': (3, 0), 'alpha_accumulations': (4, 0),
    'kelas': (0, 1), 'mahasiswa_kelas': (1, 1), 'geofences': (2, 1), 'attendances': (3, 1), 'sp_records': (4, 1),
    'prodis': (0, 2), 'users': (1, 2), 'face_embeddings': (2, 2), 'attendance_logs': (3, 2),
}
POS.update(eval(sys.argv[1]) if len(sys.argv) > 1 else {})


def label(name, g, attrs):
    dark = C[g]
    rows = ''.join(
        f'<TR><TD ALIGN="LEFT" BALIGN="LEFT">{"<B>" + a[:2] + "</B>" + a[2:] if a[:2] in ("PK", "FK") else a}</TD></TR>'
        for a in attrs)
    return (f'<<TABLE BORDER="1" CELLBORDER="0" CELLSPACING="0" CELLPADDING="1.2" COLOR="{dark}" BGCOLOR="white">'
            f'<TR><TD BGCOLOR="{dark}"><FONT COLOR="white"><B>{name}</B></FONT></TD></TR>{rows}</TABLE>>')


out = ['digraph ERD {', 'graph [splines=ortho, pad=0.04, outputorder=edgesfirst];',
       'node [shape=plain, fontname="Arial", fontsize=9];',
       'edge [dir=both, arrowtail=tee, arrowhead=crow, arrowsize=0.55, penwidth=0.6, color="#4d4d4d"];']
for n, (g, attrs) in E.items():
    c, r = POS[n]
    out.append(f'{n} [label={label(n, g, attrs)}, pos="{COLX[c] * 72:.0f},{ROWY[r] * 72:.0f}!"];')
for a, b in R:
    out.append(f'{a} -> {b};')
out.append('}')
DOT = Path(tempfile.gettempdir()) / 'gambar-2-erd.dot'
OUT = GAMBAR / 'gambar-2-erd.png'
DOT.write_text('\n'.join(out), encoding='utf-8')
subprocess.run([NEATO, '-n2', '-Tpng', '-Gdpi=300', str(DOT), '-o', str(OUT)], check=True)
from PIL import Image
im = Image.open(OUT); print('px', im.size, 'inch', im.size[0] / 300, im.size[1] / 300)
