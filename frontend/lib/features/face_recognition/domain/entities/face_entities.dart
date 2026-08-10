import 'package:equatable/equatable.dart';

class FaceEmbedding extends Equatable {
  final int? id;
  final int userId;
  final List<double> embedding;
  final int version;
  final String status;

  const FaceEmbedding({
    this.id,
    required this.userId,
    required this.embedding,
    required this.version,
    required this.status,
  });

  @override
  List<Object?> get props => [id, userId, version, status];
}

class FaceVerificationResult extends Equatable {
  final double distance;
  final double threshold;
  final bool isMatch;
  final int inferenceTimeMs;

  const FaceVerificationResult({
    required this.distance,
    required this.threshold,
    required this.isMatch,
    required this.inferenceTimeMs,
  });

  @override
  List<Object?> get props => [distance, threshold, isMatch];
}
