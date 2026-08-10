<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Peringatan {{ $sp_record->level }}</title>
    <style>
        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            line-height: 1.5;
            margin: 2cm;
        }
        .header {
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            font-size: 14pt;
        }
        .header h3 {
            margin: 5px 0;
            font-size: 16pt;
        }
        .header p {
            margin: 2px 0;
            font-size: 10pt;
        }
        .nomor-surat {
            text-align: center;
            margin: 20px 0;
        }
        .nomor-surat h3 {
            text-decoration: underline;
            margin-bottom: 5px;
        }
        .content {
            text-align: justify;
        }
        .content p {
            margin: 10px 0;
            text-indent: 40px;
        }
        .data-mahasiswa {
            margin: 15px 0 15px 40px;
        }
        .data-mahasiswa table {
            border-collapse: collapse;
        }
        .data-mahasiswa td {
            padding: 3px 10px 3px 0;
            vertical-align: top;
        }
        .data-mahasiswa td:first-child {
            width: 150px;
        }
        .rincian-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .rincian-table th, .rincian-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            text-align: center;
        }
        .rincian-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .rincian-table td:nth-child(2) {
            text-align: left;
        }
        .signature-section {
            margin-top: 40px;
            width: 100%;
        }
        .signature-row {
            display: table;
            width: 100%;
        }
        .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
        }
        .signature-space {
            height: 60px;
        }
        .footer-note {
            margin-top: 30px;
            font-size: 10pt;
            font-style: italic;
        }
    </style>
</head>
<body>
    {{-- HEADER --}}
    <div class="header">
        <h2>KEMENTERIAN PENDIDIKAN, KEBUDAYAAN, RISET DAN TEKNOLOGI</h2>
        <h3>{{ strtoupper($institution['name']) }}</h3>
        <h2>{{ strtoupper($institution['jurusan']) }}</h2>
        <p>{{ $institution['alamat'] }}</p>
    </div>

    {{-- NOMOR SURAT --}}
    <div class="nomor-surat">
        <h3>SURAT PERINGATAN ({{ $sp_record->level }})</h3>
        <p>Nomor: {{ $sp_record->nomor_surat }}</p>
    </div>

    {{-- CONTENT --}}
    <div class="content">
        <p>Berdasarkan hasil evaluasi kehadiran pada semester {{ $semester?->nama ?? '-' }} Tahun Ajaran {{ $semester?->tahunAjaran?->kode ?? '-' }}, dengan ini kami sampaikan bahwa mahasiswa:</p>

        <div class="data-mahasiswa">
            <table>
                <tr>
                    <td>Nama</td>
                    <td>: {{ $user->nama }}</td>
                </tr>
                <tr>
                    <td>NIM</td>
                    <td>: {{ $user->nim }}</td>
                </tr>
                <tr>
                    <td>Program Studi</td>
                    <td>: {{ $user->prodi?->nama ?? '-' }}</td>
                </tr>
                <tr>
                    <td>Kelas</td>
                    <td>: {{ $user->kelas ?? '-' }}</td>
                </tr>
                <tr>
                    <td>Semester</td>
                    <td>: {{ $user->semester ?? '-' }}</td>
                </tr>
            </table>
        </div>

        <p>Telah tercatat memiliki total ketidakhadiran (alpha) sebanyak <strong>{{ $sp_record->total_alpha }} kali</strong> yang telah melampaui batas toleransi untuk level <strong>{{ $sp_record->level }}</strong>.</p>

        <p>Rincian ketidakhadiran per mata kuliah:</p>

        <table class="rincian-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Mata Kuliah</th>
                    <th>SKS</th>
                    <th>Jumlah Alpha</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rincian as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item['kode_mk'] ?? '' }} - {{ $item['nama_mk'] ?? '' }}</td>
                    <td>{{ $item['sks'] ?? '' }}</td>
                    <td>{{ $item['total_alpha_menit'] ?? $item['jumlah_alpha'] ?? 0 }} menit</td>
                </tr>
                @endforeach
                <tr>
                    <td colspan="3"><strong>Total</strong></td>
                    <td><strong>{{ number_format($sp_record->total_alpha_jam, 1) }} jam</strong></td>
                </tr>
            </tbody>
        </table>

        @if($sp_record->level === 'SP1')
        <p>Dengan ini diberikan Surat Peringatan Pertama (SP1). Mahasiswa yang bersangkutan diharapkan segera memperbaiki kehadiran dan menemui Dosen Pembimbing Akademik.</p>
        @elseif($sp_record->level === 'SP2')
        <p>Dengan ini diberikan Surat Peringatan Kedua (SP2). Mahasiswa yang bersangkutan <strong>WAJIB</strong> menemui Kaprodi dan Dosen Pembimbing Akademik dalam waktu 3 hari kerja.</p>
        @elseif($sp_record->level === 'SP3')
        <p>Dengan ini diberikan Surat Peringatan Ketiga (SP3). Apabila mahasiswa yang bersangkutan tidak memperbaiki kehadiran, maka akan dikenakan sanksi <strong>Drop Out (DO)</strong> dari program studi.</p>
        @endif

        <p>Demikian surat peringatan ini dibuat untuk dapat ditindaklanjuti sebagaimana mestinya.</p>
    </div>

    {{-- TANDA TANGAN --}}
    <div class="signature-section">
        <p style="text-align: right;">Pontianak, {{ $tanggal }}</p>

        <div class="signature-row">
            <div class="signature-col">
                <p>Mengetahui,</p>
                <p>Ketua Jurusan Teknik Elektro</p>
                @if(!empty($kajur_signature))
                    <div style="height: 60px; margin: 5px 0;">
                        <img src="data:image/png;base64,{{ $kajur_signature }}" style="max-height: 50px; max-width: 180px;" alt="Tanda Tangan Kajur">
                    </div>
                @else
                    <div class="signature-space"></div>
                @endif
                <p><u>{{ $kajur_name ?? '________________________' }}</u></p>
                @if(!empty($signed_kajur_at))
                    <p style="font-size: 9pt; color: #666;">Ditandatangani: {{ \Illuminate\Support\Carbon::parse($signed_kajur_at)->locale('id')->isoFormat('D MMMM Y HH:mm') }}</p>
                @endif
            </div>
            <div class="signature-col">
                <p>&nbsp;</p>
                <p>Ketua Program Studi {{ $user->prodi?->nama ?? '' }}</p>
                @if(!empty($kaprodi_signature))
                    <div style="height: 60px; margin: 5px 0;">
                        <img src="data:image/png;base64,{{ $kaprodi_signature }}" style="max-height: 50px; max-width: 180px;" alt="Tanda Tangan Kaprodi">
                    </div>
                @else
                    <div class="signature-space"></div>
                @endif
                <p><u>{{ $kaprodi_name ?? '________________________' }}</u></p>
                @if(!empty($signed_kaprodi_at))
                    <p style="font-size: 9pt; color: #666;">Ditandatangani: {{ \Illuminate\Support\Carbon::parse($signed_kaprodi_at)->locale('id')->isoFormat('D MMMM Y HH:mm') }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="footer-note">
        <p>Tembusan:</p>
        <ol>
            <li>Mahasiswa yang bersangkutan</li>
            <li>Orang tua/wali mahasiswa</li>
            <li>Dosen Pembimbing Akademik</li>
            <li>Arsip</li>
        </ol>
    </div>
</body>
</html>
