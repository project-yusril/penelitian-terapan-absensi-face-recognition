<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] ?? 'Rekap Kehadiran' }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #64748b; font-size: 11px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; }
        th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; }
        td.num, th.num { text-align: center; }
        .meta { margin-top: 14px; font-size: 10px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] ?? 'Rekap Kehadiran' }}</h1>
    <p class="sub">
        @if(!empty($report['subtitle'])){{ $report['subtitle'] }} · @endif
        @if(!empty($report['total_pertemuan']))Total pertemuan: {{ $report['total_pertemuan'] }} · @endif
        @if(isset($report['avg_kehadiran']))Rata-rata kehadiran: {{ $report['avg_kehadiran'] }}%@endif
    </p>

    @if(($report['type'] ?? '') === 'prodi')
        <table>
            <thead>
                <tr>
                    <th>No</th><th>Program Studi</th><th class="num">Mahasiswa</th>
                    <th class="num">Total Absensi</th><th class="num">% Hadir</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $i => $r)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $r['prodi'] }}</td>
                    <td class="num">{{ $r['mahasiswa'] }}</td>
                    <td class="num">{{ $r['total_absensi'] }}</td>
                    <td class="num">{{ $r['persentase'] }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @elseif(($report['type'] ?? '') === 'mahasiswa')
        <table>
            <thead>
                <tr>
                    <th>No</th><th>Mata Kuliah</th><th class="num">Total</th>
                    <th class="num">Hadir</th><th class="num">Terlambat</th>
                    <th class="num">Alpha</th><th class="num">Izin/Sakit</th><th class="num">% Hadir</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $i => $r)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $r['mata_kuliah'] }}</td>
                    <td class="num">{{ $r['total'] }}</td>
                    <td class="num">{{ $r['hadir'] }}</td>
                    <td class="num">{{ $r['terlambat'] }}</td>
                    <td class="num">{{ $r['alpha'] }}</td>
                    <td class="num">{{ $r['izin_sakit'] }}</td>
                    <td class="num">{{ $r['persentase'] }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table>
            <thead>
                <tr>
                    <th>No</th><th>Nama</th><th>NIM</th><th>Kelas</th><th class="num">Total</th>
                    <th class="num">Hadir</th><th class="num">Terlambat</th>
                    <th class="num">Alpha</th><th class="num">Izin/Sakit</th><th class="num">% Hadir</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $i => $r)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $r['nama'] }}</td>
                    <td>{{ $r['nim'] }}</td>
                    <td>{{ $r['kelas'] }}</td>
                    <td class="num">{{ $r['total'] }}</td>
                    <td class="num">{{ $r['hadir'] }}</td>
                    <td class="num">{{ $r['terlambat'] }}</td>
                    <td class="num">{{ $r['alpha'] }}</td>
                    <td class="num">{{ $r['izin_sakit'] }}</td>
                    <td class="num">{{ $r['persentase'] }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="meta">Dicetak: {{ $generatedAt }} · Sistem Absensi Mahasiswa — Politeknik Negeri Pontianak</p>
</body>
</html>
