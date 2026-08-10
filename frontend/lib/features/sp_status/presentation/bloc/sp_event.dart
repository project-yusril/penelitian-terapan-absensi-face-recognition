import 'package:equatable/equatable.dart';

abstract class SpEvent extends Equatable {
  const SpEvent();
  @override
  List<Object?> get props => [];
}

class LoadMySpRecords extends SpEvent {}

class RefreshMySpRecords extends SpEvent {}
