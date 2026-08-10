<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Templates
    |--------------------------------------------------------------------------
    |
    | Template notifikasi yang digunakan oleh sistem.
    | Format: {variable} akan diganti dengan nilai sesungguhnya.
    |
    */

    'sp_warning' => [
        'approaching_sp1' => [
            'title' => 'Peringatan: Anda mendekati SP1',
            'body_mahasiswa' => 'Akumulasi alpha Anda sudah {total_alpha_jam} jam. Batas SP1 adalah {threshold} jam. Segera perbaiki kehadiran Anda.',
            'body_admin' => 'Mahasiswa {nama} ({nim}) mendekati batas SP1. Total alpha: {total_alpha_jam} jam.',
        ],
        'approaching_sp2' => [
            'title' => 'Peringatan: Anda mendekati SP2',
            'body_mahasiswa' => 'Akumulasi alpha Anda sudah {total_alpha_jam} jam. Batas SP2 adalah {threshold} jam.',
            'body_admin' => 'Mahasiswa {nama} ({nim}) mendekati batas SP2. Total alpha: {total_alpha_jam} jam.',
        ],
        'approaching_sp3' => [
            'title' => 'Peringatan: Anda mendekati SP3',
            'body_mahasiswa' => 'Akumulasi alpha Anda sudah {total_alpha_jam} jam. Batas SP3 adalah {threshold} jam.',
            'body_admin' => 'Mahasiswa {nama} ({nim}) mendekati batas SP3. Total alpha: {total_alpha_jam} jam.',
        ],
        'approaching_do' => [
            'title' => '[URGENT] Peringatan Keras: Anda mendekati DO',
            'body_mahasiswa' => 'PERINGATAN KERAS: Akumulasi alpha Anda sudah {total_alpha_jam} jam. Batas DO adalah {threshold} jam.',
            'body_admin' => '[URGENT] Mahasiswa {nama} ({nim}) mendekati batas DO. Total alpha: {total_alpha_jam} jam.',
        ],
    ],

    'sp_issued' => [
        'sp1' => [
            'title' => 'Anda menerima Surat Peringatan 1 (SP1)',
            'body_mahasiswa' => 'Anda menerima Surat Peringatan 1. Total alpha: {total_alpha_jam} jam. Segera temui dosen pembimbing akademik.',
            'body_admin' => 'Mahasiswa {nama} ({nim}) dari prodi {prodi} masuk kategori SP1. Total alpha: {total_alpha_jam} jam.',
        ],
        'sp2' => [
            'title' => 'Anda menerima Surat Peringatan 2 (SP2)',
            'body_mahasiswa' => 'Anda menerima Surat Peringatan 2. Total alpha: {total_alpha_jam} jam. WAJIB menemui Kaprodi dalam 3 hari kerja.',
            'body_admin' => 'Mahasiswa {nama} ({nim}) dari prodi {prodi} masuk kategori SP2. Total alpha: {total_alpha_jam} jam.',
        ],
        'sp3' => [
            'title' => '[URGENT] Anda menerima Surat Peringatan 3 (SP3)',
            'body_mahasiswa' => 'Anda menerima Surat Peringatan 3. Total alpha: {total_alpha_jam} jam. Apabila tidak diperbaiki, akan dikenakan DO.',
            'body_admin' => '[URGENT] Mahasiswa {nama} ({nim}) dari prodi {prodi} masuk kategori SP3. Total alpha: {total_alpha_jam} jam.',
        ],
        'do' => [
            'title' => '[URGENT] Anda telah mencapai batas Drop Out (DO)',
            'body_mahasiswa' => 'Anda telah mencapai batas Drop Out. Total alpha: {total_alpha_jam} jam. Hubungi admin prodi segera.',
            'body_admin' => '[URGENT] Mahasiswa {nama} ({nim}) dari prodi {prodi} masuk kategori DO. Total alpha: {total_alpha_jam} jam.',
        ],
    ],

    'approval' => [
        'pending_created' => [
            'title' => 'Kehadiran menunggu persetujuan',
            'body' => 'Mahasiswa {nama} ({nim}) memiliki kehadiran yang menunggu persetujuan Anda untuk {mata_kuliah}.',
        ],
        'approved' => [
            'title' => 'Kehadiran disetujui',
            'body' => 'Kehadiran Anda untuk {mata_kuliah} pada {tanggal} telah disetujui oleh dosen.',
        ],
        'rejected' => [
            'title' => 'Kehadiran ditolak',
            'body' => 'Kehadiran Anda untuk {mata_kuliah} pada {tanggal} ditolak oleh dosen. Alasan: {alasan}.',
        ],
    ],

    'enrollment' => [
        'approved' => [
            'title' => 'Enrollment wajah disetujui',
            'body' => 'Enrollment wajah Anda telah disetujui. Anda sekarang dapat melakukan absensi.',
        ],
        'rejected' => [
            'title' => 'Enrollment wajah ditolak',
            'body' => 'Enrollment wajah Anda ditolak. Alasan: {alasan}. Silakan lakukan enrollment ulang.',
        ],
    ],

    'leave_request' => [
        'approved' => [
            'title' => 'Pengajuan izin/sakit disetujui',
            'body' => 'Pengajuan {jenis} Anda untuk {mata_kuliah} pada {tanggal} telah disetujui.',
        ],
        'rejected' => [
            'title' => 'Pengajuan izin/sakit ditolak',
            'body' => 'Pengajuan {jenis} Anda untuk {mata_kuliah} pada {tanggal} ditolak. Alasan: {alasan}.',
        ],
    ],

    'attendance_reminder' => [
        'title' => 'Reminder: Kelas akan dimulai',
        'body' => '{mata_kuliah} ({kode_mk}) akan dimulai 15 menit lagi di {ruangan}. Jangan lupa absen!',
    ],

    'sp_document' => [
        'needs_signature_kaprodi' => [
            'title' => 'Dokumen {level} perlu tanda tangan',
            'body' => 'Surat Peringatan {level} untuk {nama_mahasiswa} ({nim}) menunggu tanda tangan Anda.',
        ],
        'needs_signature_kajur' => [
            'title' => 'Dokumen {level} perlu tanda tangan Kajur',
            'body' => 'Surat Peringatan {level} untuk {nama_mahasiswa} ({nim}) menunggu tanda tangan "Diketahui" dari Anda.',
        ],
        'finalized' => [
            'title' => 'Surat Peringatan {level} telah final',
            'body' => 'Surat Peringatan {level} untuk Anda telah ditandatangani dan dapat diunduh.',
        ],
    ],

];
