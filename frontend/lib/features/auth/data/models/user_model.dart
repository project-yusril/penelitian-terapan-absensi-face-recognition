import '../../domain/entities/user.dart';

/// Parse nilai yang bisa datang sebagai int atau String dari API → int?.
int? _toIntOrNull(dynamic value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value.toString());
}

class UserModel extends User {
  const UserModel({
    required super.id,
    required super.nama,
    required super.email,
    super.nim,
    super.nidn,
    super.noHp,
    super.prodiId,
    super.prodiNama,
    super.kelas,
    super.angkatan,
    super.semester,
    super.fotoProfil,
    super.fotoEnrollment,
    super.fotoEnrollmentUrl,
    required super.status,

    required super.mustChangePassword,
    required super.enrollmentStatus,
    required super.roles,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'] ?? 0,
      nama: json['nama'] ?? '',
      email: json['email'] ?? '',
      nim: json['nim'],
      nidn: json['nidn'],
      noHp: json['no_hp'],
      prodiId: json['prodi']?['id'] ?? json['prodi_id'],
      prodiNama: json['prodi']?['nama'],
      kelas: json['kelas']?.toString(),
      angkatan: _toIntOrNull(json['angkatan']),
      semester: _toIntOrNull(json['semester']),

      fotoProfil: json['foto_profil'],
      fotoEnrollment: json['foto_enrollment'],
      fotoEnrollmentUrl: json['foto_enrollment_url'],
      status: json['status'] ?? 'aktif',

      mustChangePassword: json['must_change_password'] ?? false,
      enrollmentStatus: json['enrollment_status'] ?? 'belum',
      roles:
          (json['roles'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          [],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'nama': nama,
      'email': email,
      'nim': nim,
      'nidn': nidn,
      'no_hp': noHp,
      'prodi_id': prodiId,
      'prodi': prodiNama != null ? {'id': prodiId, 'nama': prodiNama} : null,
      'kelas': kelas,
      'angkatan': angkatan,
      'semester': semester,
      'foto_profil': fotoProfil,
      'foto_enrollment': fotoEnrollment,
      'status': status,
      'must_change_password': mustChangePassword,
      'enrollment_status': enrollmentStatus,
      'roles': roles,
    };
  }
}
