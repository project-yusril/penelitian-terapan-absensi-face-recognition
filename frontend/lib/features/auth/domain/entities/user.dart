import 'package:equatable/equatable.dart';

class User extends Equatable {
  final int id;
  final String nama;
  final String email;
  final String? nim;
  final String? nidn;
  final String? noHp;
  final int? prodiId;
  final String? prodiNama;
  final String? kelas;
  final int? angkatan;
  final int? semester;
  final String? fotoProfil;
  final String? fotoEnrollment;
  final String? fotoEnrollmentUrl;

  final String status;
  final bool mustChangePassword;
  final String enrollmentStatus;
  final List<String> roles;

  const User({
    required this.id,
    required this.nama,
    required this.email,
    this.nim,
    this.nidn,
    this.noHp,
    this.prodiId,
    this.prodiNama,
    this.kelas,
    this.angkatan,
    this.semester,
    this.fotoProfil,
    this.fotoEnrollment,
    this.fotoEnrollmentUrl,
    required this.status,

    required this.mustChangePassword,
    required this.enrollmentStatus,
    required this.roles,
  });

  bool get isMahasiswa => roles.contains('mahasiswa');
  bool get isDosen => roles.contains('dosen');
  bool get isAdmin => roles.contains('admin') || roles.contains('super_admin');
  bool get isKaprodi => roles.contains('kaprodi');
  bool get isKajur => roles.contains('ketua_jurusan');
  bool get isOrangTua => roles.contains('orang_tua');

  String get identifier => nim ?? nidn ?? email;
  String get roleDisplay =>
      roles.isNotEmpty ? roles.first.replaceAll('_', ' ').toUpperCase() : '';

  @override
  List<Object?> get props => [
    id,
    nama,
    email,
    nim,
    nidn,
    noHp,
    prodiId,
    prodiNama,
    kelas,
    angkatan,
    semester,
    fotoProfil,
    fotoEnrollment,
    fotoEnrollmentUrl,
    status,

    mustChangePassword,
    enrollmentStatus,
    roles,
  ];
}
