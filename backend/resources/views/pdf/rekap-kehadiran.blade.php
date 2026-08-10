<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Kehadiran - {{ $mata_kuliah->kode_mk }}</title>
    <style>
        body {
            font-family: 'Times New Roman', serif;
            font-size: 10pt;
            margin: 1.5cm;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h3 {
            margin: 5px 0;
        }
        .info-table {
            margin-bottom: 15px;
        }
        .info-table td {
            padding: 2px 10px 2px 0;
        }
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        .attendance-table th, .attendance-table td {
            border: 1px solid #000;
            padding: 3px 5px;
            text-align: center;
        }
        .attendance-table th {
            background-color: #e0e0e0;
            font-weight: bold;
        }
        .attendance-table td.nama {
            text-align: left;
        }
        .status-h { color: green; }
        .status-t { color: orange; }
        .status-a { color: red; font-weight: bold; }
        .status-i { color: blue; }
        .footer {
            margin-top: 20px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h3>POLITEKNIK NEGERI SRIWIJAYA</h3>
        <h3>JURUSAN TEKNIK ELEKTRO</h3>
        <h4>REKAP KEHADIRAN MAHASISWA</h4>
    </div>

    <table class="info-table">
        <tr>
            <td><strong>Mata Kuliah</strong></td>
            <td>: {{ $mata_kuliah->kode_mk }} - {{ $mata_kuliah->nama }}</td>
            <td><strong>Semester</strong></td>
            <td>: {{ $mata_kuliah->semester?->nama ?? '-' }} {{ $mata_kuliah->semester?->tahunAjaran?->kode ?? '' }}</td>
        </tr>
        <tr>
            <td><strong>Kelas</strong></td>
            <td>: {{ $mata_kuliah->kelas }}</td>
            <td><strong>Dosen</strong></td>
            <td>: {{ $mata_kuliah->dosen?->nama ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>SKS</strong></td>
            <td>: {{ $mata_kuliah->sks }}</td>
            <td><strong>Prodi</strong></td>
            <td>: {{ $mata_kuliah->prodi?->nama ?? '-' }}</td>
        </tr>
    </table>

    <table class="attendance-table">
        <thead>
            <tr>
                <th>No</th>
                <th>NIM</th>
                <th class="nama">Nama</th>
                @php $maxPertemuan = $data->max(fn($d) => $d['total']); @endphp
                @for($i = 1; $i <= min($maxPertemuan, 16); $i++)
                <th>{{ $i }}</th>
                @endfor
                <th>H</th>
                <th>A</th>
                <th>I</th>
                <th>%</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row['mahasiswa']->nim }}</td>
                <td class="nama">{{ $row['mahasiswa']->nama }}</td>
                @for($i = 1; $i <= min($maxPertemuan, 16); $i++)
                    @php
                        $att = $row['attendances']->firstWhere('pertemuan_ke', $i);
                        $statusChar = match($att?->status) {
                            'hadir' => 'H',
                            'hadir_terlambat' => 'T',
                            'alpha' => 'A',
                            'izin', 'sakit' => 'I',
                            'pending' => 'P',
                            default => '-',
                        };
                        $statusClass = match($att?->status) {
                            'hadir' => 'status-h',
                            'hadir_terlambat' => 'status-t',
                            'alpha' => 'status-a',
                            'izin', 'sakit' => 'status-i',
                            default => '',
                        };
                    @endphp
                <td class="{{ $statusClass }}">{{ $statusChar }}</td>
                @endfor
                <td><strong>{{ $row['hadir'] }}</strong></td>
                <td class="status-a">{{ $row['alpha'] }}</td>
                <td class="status-i">{{ $row['izin'] }}</td>
                <td>{{ $row['total'] > 0 ? round(($row['hadir'] / $row['total']) * 100) : 0 }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <p style="font-size: 9pt; margin-top: 10px;">
        <strong>Keterangan:</strong> H = Hadir, T = Terlambat, A = Alpha, I = Izin/Sakit, P = Pending
    </p>

    <div class="footer">
        <p>Palembang, {{ $tanggal }}</p>
        <br><br><br>
        <p>{{ $mata_kuliah->dosen?->nama ?? '________________' }}</p>
    </div>
</body>
</html>
