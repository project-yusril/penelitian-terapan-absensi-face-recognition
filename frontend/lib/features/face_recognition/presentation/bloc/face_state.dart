import 'package:equatable/equatable.dart';

abstract class FaceState extends Equatable {
  const FaceState();
  @override
  List<Object?> get props => [];
}

class FaceInitial extends FaceState {}

class FaceLoading extends FaceState {}

class EnrollmentReady extends FaceState {}

class EnrollmentSubmitted extends FaceState {
  final String message;
  const EnrollmentSubmitted(this.message);
}

class EnrollmentStatusLoaded extends FaceState {
  final String status;
  const EnrollmentStatusLoaded(this.status);
}

class ReferenceEmbeddingLoaded extends FaceState {
  final List<double> embedding;
  const ReferenceEmbeddingLoaded(this.embedding);
}

class FaceVerificationResult extends FaceState {
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
}

class FaceError extends FaceState {
  final String message;
  final String? code;
  const FaceError(this.message, {this.code});
}
