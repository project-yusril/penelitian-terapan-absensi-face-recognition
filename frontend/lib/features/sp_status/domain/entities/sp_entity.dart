import 'package:equatable/equatable.dart';

class SpRecord extends Equatable {
  final int id;
  final int userId;
  final int semesterId;
  final String spLevel;
  final String nomorSurat;
  final String tanggalTerbit;
  final int totalAlphaJam;
  final String rincianAlpha;
  final String status;
  final String? documentPath;
  final String? signedKaprodiAt;
  final String? signedKajurAt;
  final String createdAt;

  const SpRecord({
    required this.id,
    required this.userId,
    required this.semesterId,
    required this.spLevel,
    required this.nomorSurat,
    required this.tanggalTerbit,
    required this.totalAlphaJam,
    required this.rincianAlpha,
    required this.status,
    this.documentPath,
    this.signedKaprodiAt,
    this.signedKajurAt,
    required this.createdAt,
  });

  bool get isFinal => status == 'final';
  bool get isDraft => status == 'draft';
  bool get isCancelled => status == 'dibatalkan';
  bool get isPending =>
      status == 'menunggu_kaprodi' || status == 'menunggu_kajur';
  bool get hasDocument => documentPath != null && documentPath!.isNotEmpty;

  String get spLevelLabel {
    switch (spLevel) {
      case 'sp1':
        return 'SP 1';
      case 'sp2':
        return 'SP 2';
      case 'sp3':
        return 'SP 3';
      case 'do':
        return 'DO';
      default:
        return spLevel.toUpperCase();
    }
  }

  String get statusLabel {
    switch (status) {
      case 'draft':
        return 'Draft';
      case 'menunggu_kaprodi':
        return 'Menunggu Kaprodi';
      case 'menunggu_kajur':
        return 'Menunggu Kajur';
      case 'final':
        return 'Final';
      case 'dibatalkan':
        return 'Dibatalkan';
      default:
        return status;
    }
  }

  @override
  List<Object?> get props => [id, userId, semesterId, spLevel, status];
}
