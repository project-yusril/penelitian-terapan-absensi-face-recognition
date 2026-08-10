import 'dart:typed_data';
import 'package:equatable/equatable.dart';

abstract class FaceEvent extends Equatable {
  const FaceEvent();
  @override
  List<Object?> get props => [];
}

class StartEnrollment extends FaceEvent {}

class EnrollmentPhotoTaken extends FaceEvent {
  final Uint8List imageBytes;
  final List<double> embedding;
  const EnrollmentPhotoTaken({
    required this.imageBytes,
    required this.embedding,
  });
}

class SubmitEnrollment extends FaceEvent {
  final List<double> embedding;
  final bool livenessPassed;
  final String livenessChallenge;
  final String deviceModel;
  final String deviceOs;
  final Uint8List? fotoEnrollment;

  const SubmitEnrollment({
    required this.embedding,
    required this.livenessPassed,
    required this.livenessChallenge,
    required this.deviceModel,
    required this.deviceOs,
    this.fotoEnrollment,
  });
}

class CheckEnrollmentStatus extends FaceEvent {}

class LoadReferenceEmbedding extends FaceEvent {}

class StartFaceVerification extends FaceEvent {}

class FaceVerificationCompleted extends FaceEvent {
  final double distance;
  final double threshold;
  final bool isMatch;
  final int inferenceTimeMs;
  const FaceVerificationCompleted({
    required this.distance,
    required this.threshold,
    required this.isMatch,
    required this.inferenceTimeMs,
  });
}

class ResetFaceState extends FaceEvent {}
